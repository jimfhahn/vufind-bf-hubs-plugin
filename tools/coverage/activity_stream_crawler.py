"""
Crawls the LC BIBFRAME Hubs Activity Streams feed and emits one JSONL
line per Hub event.

Feed root: https://id.loc.gov/resources/hubs/activitystreams/feed/
Each page: ~100 OrderedCollectionPage items with `next` link.

Output schema (one line per item):
    {"uri": str, "type": "Add"|"Update"|..., "published": str,
     "actor": str, "bf_types": [str, ...], "page": int}

Resumable via output/checkpoint.json; JSONL is append-only and idempotent
to re-runs (same URI may appear multiple times if it was Updated; that's
fine for the diff step since we deduplicate when building the URI set).
"""

from __future__ import annotations

import argparse
import json
import logging
import signal
import sys
import threading
import time
from concurrent.futures import ThreadPoolExecutor, as_completed
from pathlib import Path
from typing import Optional

import requests
from requests.adapters import HTTPAdapter
from urllib3.util.retry import Retry

FEED_ROOT = "https://id.loc.gov/resources/hubs/activitystreams/feed"
USER_AGENT = "bibframe-hub-coverage-crawler/0.1 (research; vufind-plugin)"

OUTPUT_DIR = Path(__file__).parent / "output"
JSONL_PATH = OUTPUT_DIR / "feed-events.jsonl"
CHECKPOINT_PATH = OUTPUT_DIR / "checkpoint.json"
FAILED_PAGES_PATH = OUTPUT_DIR / "failed-pages.txt"

_SHUTDOWN = threading.Event()
_WRITE_LOCK = threading.Lock()

log = logging.getLogger("crawler")


def make_session() -> requests.Session:
    s = requests.Session()
    s.headers.update({
        "User-Agent": USER_AGENT,
        "Accept": "application/activity+json, application/json",
    })
    retry = Retry(
        total=8,
        connect=5,
        read=5,
        backoff_factor=2.0,
        status_forcelist=(429, 500, 502, 503, 504),
        allowed_methods=frozenset(["GET", "HEAD"]),
        raise_on_status=False,
    )
    adapter = HTTPAdapter(max_retries=retry, pool_connections=16, pool_maxsize=16)
    s.mount("https://", adapter)
    s.mount("http://", adapter)
    return s


def fetch_total_pages(session: requests.Session) -> int:
    """Read the feed root and parse the total page count from `last`."""
    r = session.get(f"{FEED_ROOT}/", timeout=30)
    r.raise_for_status()
    body = r.json()
    last = body.get("last", "")
    # last looks like ".../feed/29347"
    try:
        return int(last.rstrip("/").rsplit("/", 1)[-1])
    except (ValueError, IndexError) as e:
        raise RuntimeError(f"Cannot parse total pages from last={last!r}") from e


def fetch_page(session: requests.Session, page: int, timeout: int = 30) -> list[dict]:
    """Fetch one page of the feed, return its `orderedItems`."""
    url = f"{FEED_ROOT}/{page}"
    r = session.get(url, timeout=timeout)
    r.raise_for_status()
    body = r.json()
    return body.get("orderedItems", []) or []


def normalize_item(item: dict, page: int) -> Optional[dict]:
    """Project an AS item into our compact JSONL schema. Drops malformed items."""
    obj = item.get("object") or {}
    uri = obj.get("id")
    if not uri or not isinstance(uri, str):
        return None
    if not uri.startswith("http://id.loc.gov/resources/hubs/"):
        return None
    bf_types = obj.get("type") or []
    if isinstance(bf_types, str):
        bf_types = [bf_types]
    return {
        "uri": uri,
        "type": item.get("type"),
        "published": item.get("published"),
        "actor": item.get("actor"),
        "bf_types": bf_types,
        "page": page,
    }


def load_checkpoint() -> dict:
    if CHECKPOINT_PATH.exists():
        try:
            return json.loads(CHECKPOINT_PATH.read_text())
        except json.JSONDecodeError:
            log.warning("Corrupt checkpoint; starting fresh")
    return {"completed_pages": [], "total_items": 0}


def save_checkpoint(state: dict) -> None:
    tmp = CHECKPOINT_PATH.with_suffix(".tmp")
    tmp.write_text(json.dumps(state))
    tmp.replace(CHECKPOINT_PATH)


def write_lines(lines: list[str]) -> None:
    with _WRITE_LOCK:
        with JSONL_PATH.open("a", encoding="utf-8") as f:
            f.write("".join(lines))


def record_failed_page(page: int, reason: str) -> None:
    with _WRITE_LOCK:
        with FAILED_PAGES_PATH.open("a", encoding="utf-8") as f:
            f.write(f"{page}\t{reason}\n")


def crawl_page(
    session: requests.Session,
    page: int,
    rate_limit: float,
    timeout: int = 30,
) -> tuple[int, int]:
    """Fetch a single page, write items to JSONL, return (page, item_count)."""
    if _SHUTDOWN.is_set():
        return (page, 0)
    start = time.monotonic()
    try:
        items = fetch_page(session, page, timeout=timeout)
    except requests.RequestException as e:
        log.error("page %d fetch failed: %s", page, e)
        raise
    lines = []
    for item in items:
        normalized = normalize_item(item, page)
        if normalized is None:
            continue
        lines.append(json.dumps(normalized, separators=(",", ":")) + "\n")
    if lines:
        write_lines(lines)
    elapsed = time.monotonic() - start
    # Sleep to honor rate limit per worker
    sleep_for = max(0.0, rate_limit - elapsed)
    if sleep_for > 0:
        time.sleep(sleep_for)
    return (page, len(lines))


def install_signal_handlers() -> None:
    def handler(signum, frame):
        if _SHUTDOWN.is_set():
            log.warning("Second signal received, exiting hard")
            sys.exit(130)
        log.info("Shutdown flag set; finishing in-flight pages and exiting")
        _SHUTDOWN.set()
    signal.signal(signal.SIGINT, handler)
    signal.signal(signal.SIGTERM, handler)


def main() -> int:
    parser = argparse.ArgumentParser(description=__doc__)
    parser.add_argument("--workers", type=int, default=1,
                        help="Concurrent page fetchers (default 1; max recommended 4)")
    parser.add_argument("--rate-limit", type=float, default=1.0,
                        help="Seconds between requests per worker (default 1.0)")
    parser.add_argument("--timeout", type=int, default=60,
                        help="Per-request read timeout in seconds (default 60)")
    parser.add_argument("--max-pages", type=int, default=None,
                        help="Cap total pages crawled (for testing). Default: all.")
    parser.add_argument("--start-page", type=int, default=1,
                        help="Page to start from (overrides checkpoint).")
    parser.add_argument("--ignore-checkpoint", action="store_true",
                        help="Don't skip pages recorded in checkpoint.")
    parser.add_argument("--sweep-failed", action="store_true",
                        help="Only retry pages listed in output/failed-pages.txt.")
    parser.add_argument("--log-level", default="INFO")
    args = parser.parse_args()

    logging.basicConfig(
        level=args.log_level,
        format="%(asctime)s %(levelname)s %(message)s",
        datefmt="%H:%M:%S",
    )
    OUTPUT_DIR.mkdir(parents=True, exist_ok=True)
    install_signal_handlers()

    session = make_session()
    total_pages = fetch_total_pages(session)
    log.info("Feed reports %d total pages", total_pages)

    if args.max_pages:
        total_pages = min(total_pages, args.max_pages)

    state = load_checkpoint()
    completed = set(state.get("completed_pages", []))
    if args.ignore_checkpoint:
        completed = set()

    if args.sweep_failed:
        if not FAILED_PAGES_PATH.exists():
            log.info("No failed-pages.txt to sweep.")
            return 0
        failed_pages = set()
        for line in FAILED_PAGES_PATH.read_text().splitlines():
            try:
                failed_pages.add(int(line.split("\t", 1)[0]))
            except (ValueError, IndexError):
                continue
        pages_to_crawl = sorted(p for p in failed_pages if p not in completed)
        # Truncate the failed list; survivors will be re-recorded on failure
        FAILED_PAGES_PATH.write_text("")
        log.info("Sweep mode: retrying %d previously-failed pages", len(pages_to_crawl))
    else:
        pages_to_crawl = [
            p for p in range(args.start_page, total_pages + 1)
            if p not in completed
        ]
    log.info("Pages to crawl: %d (skipping %d already done)",
             len(pages_to_crawl), len(completed))
    if not pages_to_crawl:
        log.info("Nothing to do.")
        return 0

    items_seen = state.get("total_items", 0)
    pages_done_in_run = 0
    run_start = time.monotonic()

    if args.workers <= 1:
        # Simple sequential path — easier to follow logs
        consecutive_failures = 0
        for page in pages_to_crawl:
            if _SHUTDOWN.is_set():
                break
            try:
                _, n = crawl_page(session, page, args.rate_limit, args.timeout)
            except requests.RequestException as e:
                log.warning("page %d failed, skipping: %s", page, e)
                record_failed_page(page, str(e)[:200])
                consecutive_failures += 1
                if consecutive_failures >= 25:
                    log.error(
                        "Aborting: %d consecutive failures (likely upstream outage)",
                        consecutive_failures,
                    )
                    break
                # Backoff a bit so we don't hammer a flaky upstream
                time.sleep(min(30.0, 2.0 * consecutive_failures))
                continue
            consecutive_failures = 0
            completed.add(page)
            items_seen += n
            pages_done_in_run += 1
            # Per-page checkpoint flush: cheap and bounds loss to one page on crash
            save_checkpoint({
                "completed_pages": sorted(completed),
                "total_items": items_seen,
            })
            if pages_done_in_run % 50 == 0:
                elapsed = time.monotonic() - run_start
                rate = pages_done_in_run / elapsed if elapsed > 0 else 0
                remaining = len(pages_to_crawl) - pages_done_in_run
                eta_s = remaining / rate if rate > 0 else 0
                log.info(
                    "progress: %d/%d pages done, %d items total, %.2f pg/s, ETA %.1fm",
                    pages_done_in_run, len(pages_to_crawl), items_seen, rate, eta_s / 60,
                )
    else:
        with ThreadPoolExecutor(max_workers=args.workers) as ex:
            futures = {
                ex.submit(crawl_page, session, p, args.rate_limit * args.workers, args.timeout): p
                for p in pages_to_crawl
            }
            for fut in as_completed(futures):
                if _SHUTDOWN.is_set():
                    # Cancel pending; in-flight will finish on their own
                    for f in futures:
                        f.cancel()
                    break
                page = futures[fut]
                try:
                    _, n = fut.result()
                except Exception as e:
                    log.warning("page %d failed, skipping: %s", page, e)
                    record_failed_page(page, str(e)[:200])
                    continue
                completed.add(page)
                items_seen += n
                pages_done_in_run += 1
                if pages_done_in_run % 50 == 0:
                    elapsed = time.monotonic() - run_start
                    rate = pages_done_in_run / elapsed if elapsed > 0 else 0
                    remaining = len(pages_to_crawl) - pages_done_in_run
                    eta_s = remaining / rate if rate > 0 else 0
                    log.info(
                        "progress: %d/%d pages done, %d items total, %.2f pg/s, ETA %.1fm",
                        pages_done_in_run, len(pages_to_crawl), items_seen, rate, eta_s / 60,
                    )
                    save_checkpoint({
                        "completed_pages": sorted(completed),
                        "total_items": items_seen,
                    })

    save_checkpoint({
        "completed_pages": sorted(completed),
        "total_items": items_seen,
    })
    elapsed = time.monotonic() - run_start
    log.info(
        "Done. Pages this run: %d, items appended: %d, elapsed: %.1fm",
        pages_done_in_run, items_seen - state.get("total_items", 0), elapsed / 60,
    )
    return 0


if __name__ == "__main__":
    sys.exit(main())
