"""
Hub URI reconciliation against id.loc.gov.

Walks (:ns0__Hub) nodes in Neo4j, HEAD-checks each uri, follows redirects,
writes back upstream_status / canonical_uri / last_verified.

See README.md for usage.
"""
from __future__ import annotations

import argparse
import datetime as dt
import json
import os
import signal
import sys
import time
from concurrent.futures import ThreadPoolExecutor, as_completed
from typing import Iterable

import requests

# Set by SIGINT handler so the sweep loop can exit cleanly between batches.
_SHUTDOWN = False


def _install_signal_handler() -> None:
    def handler(signum, frame):
        global _SHUTDOWN
        if _SHUTDOWN:
            print("\nForced exit.", file=sys.stderr)
            sys.exit(130)
        _SHUTDOWN = True
        print(
            "\nShutdown requested — finishing current batch, then exiting. "
            "Press Ctrl-C again to force.",
            file=sys.stderr,
        )
    signal.signal(signal.SIGINT, handler)
    signal.signal(signal.SIGTERM, handler)

NEO4J_URL = os.environ.get("NEO4J_URL", "http://localhost:7474")
NEO4J_DB = os.environ.get("NEO4J_DB", "neo4j")
NEO4J_USER = os.environ.get("NEO4J_USER", "neo4j")
NEO4J_PASSWORD = os.environ.get("NEO4J_PASSWORD", "bibframe123")

USER_AGENT = "BibframeHub-Reconcile/0.1 (+https://github.com/jameshahn/vufind-plugin)"
HEAD_TIMEOUT = 10  # seconds
MAX_REDIRECTS = 5
LABEL_ENDPOINT = "https://id.loc.gov/resources/hubs/label/"
# In-process cache of agent_uri -> preferred label string (or None if unresolvable)
_AGENT_LABEL_CACHE: dict[str, str | None] = {}

SCHEMA_STATEMENTS = [
    "CREATE INDEX hub_status IF NOT EXISTS FOR (h:ns0__Hub) ON (h.upstream_status)",
    "CREATE INDEX hub_canonical IF NOT EXISTS FOR (h:ns0__Hub) ON (h.canonical_uri)",
]


# ---------------------------------------------------------------------------
# Neo4j HTTP helpers
# ---------------------------------------------------------------------------

def cypher(statement: str, params: dict | None = None) -> list[dict]:
    """Run a single Cypher statement, return rows as dicts keyed by column name."""
    payload = {
        "statements": [{
            "statement": statement,
            "parameters": params or {},
        }],
    }
    resp = requests.post(
        f"{NEO4J_URL}/db/{NEO4J_DB}/tx/commit",
        auth=(NEO4J_USER, NEO4J_PASSWORD),
        headers={"Content-Type": "application/json"},
        json=payload,
        timeout=120,
    )
    resp.raise_for_status()
    body = resp.json()
    if body.get("errors"):
        raise RuntimeError(f"Neo4j error: {body['errors']}")
    out = []
    for result in body.get("results", []):
        cols = result.get("columns", [])
        for row in result.get("data", []):
            out.append(dict(zip(cols, row["row"])))
    return out


def apply_schema() -> None:
    for stmt in SCHEMA_STATEMENTS:
        print(f"  {stmt}")
        cypher(stmt)


# ---------------------------------------------------------------------------
# HEAD checking
# ---------------------------------------------------------------------------

def head_check(uri: str, session: requests.Session) -> dict:
    """
    HEAD `uri`, follow up to MAX_REDIRECTS hops manually so we can capture
    the canonical target. Returns a result dict ready to be written back.
    """
    current = uri
    visited = []
    try:
        for _ in range(MAX_REDIRECTS + 1):
            visited.append(current)
            resp = session.head(
                current,
                allow_redirects=False,
                timeout=HEAD_TIMEOUT,
                headers={"User-Agent": USER_AGENT},
            )
            status = resp.status_code

            if 200 <= status < 300:
                return {
                    "uri": uri,
                    "status": "live" if current == uri else "redirect",
                    "canonical_uri": current,
                    "http_status": status,
                }

            if status in (301, 302, 303, 307, 308):
                location = resp.headers.get("Location")
                if not location:
                    return {
                        "uri": uri,
                        "status": "error",
                        "canonical_uri": None,
                        "http_status": status,
                        "note": "redirect without Location",
                    }
                # Resolve relative redirects
                if location.startswith("/"):
                    from urllib.parse import urlparse
                    p = urlparse(current)
                    location = f"{p.scheme}://{p.netloc}{location}"
                if location in visited:
                    return {
                        "uri": uri,
                        "status": "error",
                        "canonical_uri": None,
                        "http_status": status,
                        "note": "redirect loop",
                    }
                current = location
                continue

            if status in (404, 410):
                return {
                    "uri": uri,
                    "status": "gone",
                    "canonical_uri": None,
                    "http_status": status,
                }

            if status == 429:
                # Rate-limited: honor Retry-After (seconds) up to a cap, then
                # report as error so this URI is retried on the next sweep.
                retry_after = resp.headers.get("Retry-After")
                wait = 5.0
                try:
                    if retry_after:
                        wait = min(60.0, float(retry_after))
                except ValueError:
                    pass
                time.sleep(wait)
                return {
                    "uri": uri,
                    "status": "error",
                    "canonical_uri": None,
                    "http_status": status,
                    "note": f"rate-limited, slept {wait}s",
                }

            return {
                "uri": uri,
                "status": "error",
                "canonical_uri": None,
                "http_status": status,
            }

        return {
            "uri": uri,
            "status": "error",
            "canonical_uri": None,
            "http_status": None,
            "note": "max redirects exceeded",
        }
    except requests.RequestException as e:
        return {
            "uri": uri,
            "status": "error",
            "canonical_uri": None,
            "http_status": None,
            "note": str(e)[:200],
        }


# ---------------------------------------------------------------------------
# Label-endpoint recovery (for `gone` Hubs)
# ---------------------------------------------------------------------------

def _agent_authority_url(agent_uri: str) -> str | None:
    """
    Map an agent RWO URI to its authority record URL.

    http://id.loc.gov/rwo/agents/n79032879
      -> https://id.loc.gov/authorities/names/n79032879
    """
    if "/rwo/agents/" in agent_uri:
        ident = agent_uri.rsplit("/", 1)[-1]
        return f"https://id.loc.gov/authorities/names/{ident}"
    if "/authorities/names/" in agent_uri:
        return agent_uri.replace("http://", "https://")
    return None


def get_agent_label(agent_uri: str, session: requests.Session) -> str | None:
    """HEAD the authority record, return X-PrefLabel value (cached)."""
    if agent_uri in _AGENT_LABEL_CACHE:
        return _AGENT_LABEL_CACHE[agent_uri]
    url = _agent_authority_url(agent_uri)
    if not url:
        _AGENT_LABEL_CACHE[agent_uri] = None
        return None
    try:
        resp = session.head(
            url, allow_redirects=False, timeout=HEAD_TIMEOUT,
            headers={"User-Agent": USER_AGENT},
        )
        label = resp.headers.get("X-PrefLabel")
        _AGENT_LABEL_CACHE[agent_uri] = label
        return label
    except requests.RequestException:
        _AGENT_LABEL_CACHE[agent_uri] = None
        return None


def label_lookup(label: str, session: requests.Session) -> str | None:
    """Hit the LC hubs label endpoint. Return the redirect target URI on 302."""
    from urllib.parse import quote
    url = LABEL_ENDPOINT + quote(label)
    try:
        resp = session.head(
            url, allow_redirects=False, timeout=HEAD_TIMEOUT,
            headers={"User-Agent": USER_AGENT},
        )
        if resp.status_code == 302:
            location = resp.headers.get("Location")
            if location:
                # Normalize to http:// to match Neo4j's stored URIs
                return location.replace("https://", "http://", 1)
    except requests.RequestException:
        pass
    return None


def _candidate_labels(
    titles: list[str],
    agent_labels: list[str],
    allow_bare_title: bool = False,
) -> list[str]:
    """
    Build label candidates in priority order:
    1. "{agent}. {title}" for each (agent, title) pair  (most specific)
    2. bare title  — ONLY if allow_bare_title is True AND no agent labels
       are available. Bare-title hits often resolve to an unrelated work
       (e.g. "Hamlet" → some other Hamlet hub, not Shakespeare's), so this
       is opt-in and should only be enabled when precision can be sacrificed.
    """
    candidates: list[str] = []
    seen: set[str] = set()

    def add(label: str) -> None:
        norm = label.strip()
        if norm and norm not in seen:
            seen.add(norm)
            candidates.append(norm)

    for agent in agent_labels:
        for title in titles:
            add(f"{agent}. {title}")
    if allow_bare_title and not agent_labels:
        for title in titles:
            add(title)
    return candidates


def validate_canonical(
    uri: str,
    agent_uris: list[str],
    session: requests.Session,
) -> bool:
    """
    Verify a recovered canonical Hub actually matches the source Hub:
      1. GETs the RDF and requires at least one <bf:relation> element
         (rejects empty placeholder Hubs).
      2. If agent_uris is non-empty, requires at least one of those agent
         URIs to appear in the RDF. Catches cross-author false positives
         (e.g. two different works with the same title but different authors
         mapping to the same label string).
    """
    rdf_url = uri.replace("http://", "https://", 1) + ".rdf"
    try:
        resp = session.get(
            rdf_url,
            timeout=HEAD_TIMEOUT,
            headers={"User-Agent": USER_AGENT, "Accept": "application/rdf+xml"},
        )
        if resp.status_code != 200:
            return False
        body = resp.content
        if b"<bf:relation" not in body:
            return False
        if agent_uris:
            # Agent URIs are stored as http://... in Neo4j but the live RDF
            # may serve https://. Normalize and match as bytes.
            for a in agent_uris:
                a_http = a.replace("https://", "http://", 1)
                a_https = a.replace("http://", "https://", 1)
                if a_http.encode() in body or a_https.encode() in body:
                    return True
            return False  # none of the agents appeared → different work
        return True
    except requests.RequestException:
        return False


# Back-compat alias (tests/other code may import has_relations).
def has_relations(uri: str, session: requests.Session) -> bool:
    return validate_canonical(uri, [], session)


def fetch_gone_hubs_with_context(
    limit: int | None,
    batch_size: int = 5000,
) -> list[dict]:
    """
    Pull a single page of `gone` Hubs along with their titles and agent URIs.
    Returns rows: {uri, titles: [...], agent_uris: [...]}.

    Each batch returns at most `batch_size` Hubs (or `limit`, whichever is
    smaller). Naturally resumable: once a Hub gets `canonical_uri` set, it
    drops out of the WHERE clause, so the next call returns the next page.
    Hubs that stay `gone` after recovery still match the WHERE, but
    `last_verified` is updated by write_results() — callers should track
    cumulative processed count and stop when an empty page is returned.
    """
    page_size = min(batch_size, limit) if limit else batch_size
    q = """
    MATCH (h:ns0__Hub)
    WHERE h.upstream_status = 'gone'
      AND h.canonical_uri IS NULL
      AND (h.last_verified IS NULL OR NOT h.last_verified ENDS WITH '_recovery_attempted')
      AND h.uri STARTS WITH 'http'
    WITH h LIMIT $limit
    OPTIONAL MATCH (h)-[:ns0__title]->(t)
    WITH h, collect(DISTINCT t.ns0__mainTitle) AS title_arrays
    OPTIONAL MATCH (h)-[:ns0__contribution]->(c)-[:ns0__agent]->(a)
    WITH h, title_arrays, collect(DISTINCT a.uri) AS agent_uris
    RETURN h.uri AS uri, title_arrays, agent_uris
    """
    rows = cypher(q, {"limit": page_size})
    out = []
    for r in rows:
        # title_arrays is a list of arrays (n10s stores as string array)
        titles: list[str] = []
        for arr in (r.get("title_arrays") or []):
            if isinstance(arr, list):
                titles.extend(s for s in arr if isinstance(s, str))
            elif isinstance(arr, str):
                titles.append(arr)
        out.append({
            "uri": r["uri"],
            "titles": list(dict.fromkeys(titles)),  # dedupe, preserve order
            "agent_uris": r.get("agent_uris") or [],
        })
    return out


def recover_one(
    hub: dict,
    session: requests.Session,
    allow_bare_title: bool = False,
) -> dict:
    """
    Try label-endpoint recovery for a single gone Hub.

    Consensus policy: every candidate label is attempted, and a canonical
    is accepted only when:
      - a single canonical URI is reached by >=1 candidate(s), AND
      - no other candidate reaches a *different* valid canonical.
    If candidates disagree, the Hub stays `gone` (we don't pick a winner).
    Each candidate's target is validated via validate_canonical(), which
    requires both bf:relation content and (when agent_uris is non-empty)
    at least one matching agent URI in the target RDF.
    """
    agent_labels = [
        lbl for lbl in (get_agent_label(u, session) for u in hub["agent_uris"])
        if lbl
    ]
    candidates = _candidate_labels(
        hub["titles"], agent_labels, allow_bare_title=allow_bare_title,
    )

    # Collect (label -> canonical) for every candidate that validates.
    validated: dict[str, str] = {}
    for label in candidates:
        target = label_lookup(label, session)
        if not target:
            continue
        if not validate_canonical(target, hub["agent_uris"], session):
            continue
        validated[label] = target

    if not validated:
        return {
            "uri": hub["uri"],
            "status": "gone",
            "canonical_uri": None,
            "matched_label": None,
        }

    # Consensus: all validated targets must agree.
    unique_targets = set(validated.values())
    if len(unique_targets) != 1:
        return {
            "uri": hub["uri"],
            "status": "gone",
            "canonical_uri": None,
            "matched_label": None,
            "note": f"disagreement across {len(validated)} candidates: "
                    + ", ".join(sorted(unique_targets)),
        }

    canonical = next(iter(unique_targets))
    # Prefer the most specific matching label (first in priority order).
    first_label = next(lbl for lbl in candidates if validated.get(lbl) == canonical)
    return {
        "uri": hub["uri"],
        "status": "redirect",
        "canonical_uri": canonical,
        "matched_label": first_label,
        "candidate_count": len(validated),
    }


def run_label_recovery(args: argparse.Namespace) -> None:
    """
    Chunk-and-stream label recovery for `gone` Hubs.

    Fetches at most `args.batch_size` Hubs at a time, processes them in
    parallel, writes results, then loops until the source set is empty (or
    `args.limit` total Hubs have been processed). This avoids materializing
    all 2.6M Hubs in Python memory at once and keeps Neo4j memory bounded.

    Resumability: write_results() persists `upstream_status`,
    `canonical_uri`, and `last_verified` per Hub. Recovered Hubs (canonical
    set) drop out of the next page's WHERE naturally. Hubs that stay `gone`
    have `last_verified` suffixed with `_recovery_attempted` so they're not
    re-processed in the same run; remove that suffix to retry.
    """
    mode = "allow-bare-title" if args.allow_bare_title else "strict"
    dry = " [DRY-RUN]" if args.dry_run else ""
    page = args.batch_size
    target_total = args.limit  # may be None (= all)
    print(f"Label-endpoint recovery (mode={mode}){dry}, batch={page}, "
          f"workers={args.workers}, rate-limit={args.rate_limit}s/worker")

    session = requests.Session()
    cumulative = {"processed": 0, "recovered": 0, "disagreement": 0,
                  "validation_failed": 0}
    batch_n = 0
    t_global = time.time()

    while True:
        if _SHUTDOWN:
            print("\nShutdown flag set, stopping recovery loop.")
            break
        if target_total and cumulative["processed"] >= target_total:
            print(f"\nReached --limit={target_total}, stopping.")
            break

        remaining = (target_total - cumulative["processed"]) if target_total else None
        this_page = min(page, remaining) if remaining else page
        batch_n += 1
        t0 = time.time()
        print(f"\n[batch {batch_n}] fetching up to {this_page} `gone` Hubs...")

        try:
            hubs = fetch_gone_hubs_with_context(this_page, batch_size=this_page)
        except Exception as e:
            print(f"  ERROR: fetch failed: {e}")
            print("  Sleeping 30s and retrying...")
            time.sleep(30)
            continue

        if not hubs:
            print("  No more `gone` Hubs to recover. Done.")
            break

        print(f"  got {len(hubs)} Hubs, processing with {args.workers} workers...")
        results = []
        batch_recovered = 0
        batch_disagreement = 0
        with ThreadPoolExecutor(max_workers=args.workers) as pool:
            futs = {
                pool.submit(recover_one, h, session, args.allow_bare_title): h
                for h in hubs
            }
            for fut in as_completed(futs):
                if _SHUTDOWN:
                    pool.shutdown(wait=False, cancel_futures=True)
                    break
                try:
                    r = fut.result()
                except Exception as e:
                    print(f"  worker error: {e}")
                    continue
                results.append(r)
                time.sleep(args.rate_limit / args.workers)
                if r["canonical_uri"]:
                    batch_recovered += 1
                    print(f"  [recovered] {r['uri']}")
                    print(f"           -> {r['canonical_uri']}")
                    print(f"           via \"{r['matched_label']}\" "
                          f"(n={r.get('candidate_count', 1)})")
                elif (r.get("note") or "").startswith("disagreement"):
                    batch_disagreement += 1
                    print(f"  [disagree]  {r['uri']}")
                    print(f"              {r['note']}")

        # Mark unrecovered Hubs in this batch with a suffix so we don't
        # re-fetch them in the next page within the same run. They stay
        # `upstream_status='gone'` with no canonical_uri.
        if not args.dry_run:
            for r in results:
                if not r["canonical_uri"]:
                    r["_recovery_attempted"] = True
            try:
                write_results(results)
            except Exception as e:
                print(f"  ERROR: write_results failed: {e}")
                # Dump unwritten results so we don't lose work.
                dump = f"unwritten-batch-{batch_n}-{int(time.time())}.json"
                with open(dump, "w") as f:
                    json.dump(results, f, indent=2, default=str)
                print(f"  Results dumped to {dump}; aborting.")
                break

        # Update cumulative counters
        cumulative["processed"] += len(results)
        cumulative["recovered"] += batch_recovered
        cumulative["disagreement"] += batch_disagreement
        cumulative["validation_failed"] += (
            len(results) - batch_recovered - batch_disagreement
        )

        elapsed = time.time() - t0
        rate = len(results) / elapsed if elapsed else 0
        global_elapsed = time.time() - t_global
        print(f"  [{dt.datetime.now().strftime('%H:%M:%S')}] "
              f"batch done in {elapsed:.1f}s ({rate:.2f} Hubs/s) \u2014 "
              f"recovered={batch_recovered}, disagreement={batch_disagreement}")
        print(f"  cumulative: processed={cumulative['processed']:,} "
              f"recovered={cumulative['recovered']:,} "
              f"disagreement={cumulative['disagreement']:,} "
              f"validation_failed={cumulative['validation_failed']:,} "
              f"(elapsed {global_elapsed/60:.1f}m)")

    print("\n=== Final cumulative ===")
    for k, v in cumulative.items():
        print(f"  {k}: {v:,}")
    if cumulative["processed"]:
        pct = 100.0 * cumulative["recovered"] / cumulative["processed"]
        print(f"  recovery rate: {pct:.2f}%")
    if args.dry_run:
        print("\nDRY-RUN: no writes performed.")



# ---------------------------------------------------------------------------
# Hub iteration & write-back
# ---------------------------------------------------------------------------

def fetch_hubs_to_check(
    batch_size: int,
    skip: int,
    max_age_days: int | None,
) -> list[str]:
    """Fetch a page of Hub URIs needing verification."""
    # n10s materializes some bnodes as :ns0__Hub with bnode:// URIs (~1.8%
    # of Hub nodes). Those are not addressable on id.loc.gov and would all
    # report as `error`, polluting the status distribution. Skip them.
    if max_age_days is not None:
        cutoff_iso = (
            dt.datetime.now(dt.timezone.utc) - dt.timedelta(days=max_age_days)
        ).isoformat()
        rows = cypher(
            """
            MATCH (h:ns0__Hub)
            WHERE h.uri STARTS WITH 'http'
              AND (h.upstream_status IS NULL
                   OR h.last_verified IS NULL
                   OR h.last_verified < $cutoff)
            RETURN h.uri AS uri
            ORDER BY h.uri
            SKIP $skip LIMIT $limit
            """,
            {"cutoff": cutoff_iso, "skip": skip, "limit": batch_size},
        )
    else:
        rows = cypher(
            """
            MATCH (h:ns0__Hub)
            WHERE h.uri STARTS WITH 'http'
              AND h.upstream_status IS NULL
            RETURN h.uri AS uri
            ORDER BY h.uri
            SKIP $skip LIMIT $limit
            """,
            {"skip": skip, "limit": batch_size},
        )
    return [r["uri"] for r in rows]


def write_results(results: Iterable[dict]) -> None:
    """Bulk-write reconciliation results back to the graph via UNWIND.

    If a result dict has `_recovery_attempted` truthy, the `last_verified`
    timestamp is suffixed with `_recovery_attempted` so the chunked
    label-recovery loop won't re-fetch this Hub on subsequent pages within
    the same run. (Removing that suffix manually allows a future re-attempt.)
    """
    items = []
    now = dt.datetime.now(dt.timezone.utc).isoformat()
    for r in results:
        verified = now + ("_recovery_attempted" if r.get("_recovery_attempted") else "")
        items.append({
            "uri": r["uri"],
            "status": r["status"],
            "canonical": r.get("canonical_uri"),
            "verified": verified,
        })
    if not items:
        return
    cypher(
        """
        UNWIND $items AS item
        MATCH (h:ns0__Hub {uri: item.uri})
        SET h.upstream_status = item.status,
            h.canonical_uri = item.canonical,
            h.last_verified = item.verified
        """,
        {"items": items},
    )


# ---------------------------------------------------------------------------
# Driver
# ---------------------------------------------------------------------------

def reconcile_uris(
    uris: list[str],
    workers: int,
    rate_limit_per_worker: float,
) -> list[dict]:
    """HEAD-check `uris` in parallel, return result dicts."""
    results = []
    session_local = requests.Session()  # share connection pool implicitly

    def task(uri: str) -> dict:
        out = head_check(uri, session_local)
        time.sleep(rate_limit_per_worker)
        return out

    with ThreadPoolExecutor(max_workers=workers) as pool:
        futures = {pool.submit(task, u): u for u in uris}
        for fut in as_completed(futures):
            results.append(fut.result())
    return results


def summarize(results: list[dict]) -> dict[str, int]:
    counts: dict[str, int] = {}
    for r in results:
        counts[r["status"]] = counts.get(r["status"], 0) + 1
    return counts


def run_sweep(args: argparse.Namespace) -> None:
    total = 0
    page = 0
    cumulative: dict[str, int] = {}
    while True:
        if _SHUTDOWN:
            print("Shutdown flag set, stopping sweep loop.")
            break

        # NOTE: SKIP=0 is intentional and required.
        # The WHERE clause filters out already-verified nodes, so each batch's
        # write_results() shrinks the pending set. Using a paginated SKIP would
        # incorrectly walk past unverified nodes on subsequent batches.
        uris = fetch_hubs_to_check(
            batch_size=args.batch_size,
            skip=0,
            max_age_days=args.max_age_days,
        )
        if not uris:
            print(f"No more Hubs to verify. Total processed: {total}")
            break

        print(f"[batch {page + 1}] verifying {len(uris)} URIs...", flush=True)
        t0 = time.time()
        results = reconcile_uris(uris, args.workers, args.rate_limit)
        try:
            write_results(results)
        except Exception as e:
            # Don't lose progress on a transient Neo4j hiccup. Dump to disk
            # so the operator can replay manually if needed.
            dump = f"sweep-failed-batch-{int(time.time())}.json"
            with open(dump, "w") as f:
                json.dump(results, f)
            print(f"  WRITE FAILED ({e}). Results dumped to {dump}.")
            raise
        elapsed = time.time() - t0

        counts = summarize(results)
        for k, v in counts.items():
            cumulative[k] = cumulative.get(k, 0) + v
        rate = len(uris) / elapsed if elapsed > 0 else 0
        print(
            f"  [{dt.datetime.now().strftime('%H:%M:%S')}] "
            f"done in {elapsed:.1f}s ({rate:.1f} req/s) — "
            + ", ".join(f"{k}={v}" for k, v in sorted(counts.items())),
            flush=True,
        )

        total += len(uris)
        page += 1
        if args.limit and total >= args.limit:
            print(f"Reached --limit={args.limit}, stopping.")
            break

    print("\nCumulative:")
    for k, v in sorted(cumulative.items()):
        print(f"  {k}: {v}")


def run_seed_uris(args: argparse.Namespace, uris: list[str]) -> None:
    """Smoke-test path: reconcile a fixed list of URIs."""
    print(f"Reconciling {len(uris)} URIs...")
    results = reconcile_uris(uris, args.workers, args.rate_limit)
    write_results(results)

    print("\nResults:")
    for r in sorted(results, key=lambda x: x["uri"]):
        target = r.get("canonical_uri") or "-"
        note = f" ({r['note']})" if r.get("note") else ""
        print(
            f"  [{r['status']:8}] {r['uri']}"
            + (f"\n             -> {target}" if target != r["uri"] and target != "-" else "")
            + note
        )

    print("\nSummary:")
    for k, v in sorted(summarize(results).items()):
        print(f"  {k}: {v}")


# ---------------------------------------------------------------------------
# CLI
# ---------------------------------------------------------------------------

def parse_args() -> argparse.Namespace:
    p = argparse.ArgumentParser(description=__doc__)
    p.add_argument("--apply-schema", action="store_true",
                   help="Create indexes on upstream_status / canonical_uri then exit")
    p.add_argument("--label-recovery", action="store_true",
                   help="Re-process Hubs marked `gone`: try label-endpoint lookup with "
                        "(agent label, title) combinations to find a current canonical URI")
    p.add_argument("--allow-bare-title", action="store_true",
                   help="(label-recovery only) Also try bare-title candidates when a "
                        "Hub has no agent labels. Off by default because bare-title "
                        "hits are high-risk for false positives.")
    p.add_argument("--dry-run", action="store_true",
                   help="(label-recovery only) Report proposed recoveries without "
                        "writing to Neo4j. Useful for smoke-testing precision changes.")
    p.add_argument("--batch-size", type=int, default=1000,
                   help="Hubs fetched per Cypher page (default: 1000)")
    p.add_argument("--workers", type=int, default=8,
                   help="Concurrent HEAD requests (default: 8)")
    p.add_argument("--rate-limit", type=float, default=1.6,
                   help="Per-worker sleep after each request, seconds (default: 1.6, "
                        "yielding ~5 req/s aggregate at 8 workers)")
    p.add_argument("--max-age-days", type=int, default=None,
                   help="Re-verify Hubs whose last_verified is older than N days. "
                        "If unset, only Hubs with no upstream_status are verified.")
    p.add_argument("--limit", type=int, default=None,
                   help="Stop after processing this many Hubs (sweep mode)")
    p.add_argument("--uris", nargs="*", default=None,
                   help="Reconcile only the given URIs (smoke test)")
    p.add_argument("--uris-file", type=str, default=None,
                   help="Read URIs from file (one per line)")
    return p.parse_args()


def main() -> int:
    args = parse_args()
    _install_signal_handler()

    if args.apply_schema:
        print("Applying schema...")
        apply_schema()
        print("Done.")
        return 0

    if args.label_recovery:
        run_label_recovery(args)
        return 0

    seed_uris: list[str] = []
    if args.uris:
        seed_uris.extend(args.uris)
    if args.uris_file:
        with open(args.uris_file) as f:
            seed_uris.extend(line.strip() for line in f if line.strip())

    if seed_uris:
        run_seed_uris(args, seed_uris)
    else:
        run_sweep(args)

    return 0


if __name__ == "__main__":
    sys.exit(main())
