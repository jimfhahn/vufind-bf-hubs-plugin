"""
Diff the upstream Hub URI set (from the activity-streams crawl) against
our local Neo4j graph.

Inputs:
    output/feed-events.jsonl  — produced by activity_stream_crawler.py

Outputs:
    output/upstream-uris.txt    — sorted unique URIs from the feed
    output/local-uris.txt       — sorted unique URIs from :ns0__Hub
    output/missing-local.txt    — upstream - local (candidates to ingest)
    output/extra-local.txt      — local - upstream (candidates to relabel)
    output/diff-summary.json    — counts and timing
"""

from __future__ import annotations

import argparse
import json
import logging
import sys
import time
from pathlib import Path

import requests

OUTPUT_DIR = Path(__file__).parent / "output"
JSONL_PATH = OUTPUT_DIR / "feed-events.jsonl"
UPSTREAM_PATH = OUTPUT_DIR / "upstream-uris.txt"
LOCAL_PATH = OUTPUT_DIR / "local-uris.txt"
MISSING_PATH = OUTPUT_DIR / "missing-local.txt"
EXTRA_PATH = OUTPUT_DIR / "extra-local.txt"
SUMMARY_PATH = OUTPUT_DIR / "diff-summary.json"

NEO4J_URL = "http://localhost:7474/db/neo4j/tx/commit"
NEO4J_USER = "neo4j"
NEO4J_PASSWORD = "bibframe123"

log = logging.getLogger("diff")


def load_upstream() -> set[str]:
    if not JSONL_PATH.exists():
        raise SystemExit(f"Missing {JSONL_PATH}; run activity_stream_crawler.py first")
    uris: set[str] = set()
    with JSONL_PATH.open(encoding="utf-8") as f:
        for line in f:
            try:
                rec = json.loads(line)
            except json.JSONDecodeError:
                continue
            uri = rec.get("uri")
            if uri:
                uris.add(uri)
    return uris


def load_local() -> set[str]:
    """Stream all :ns0__Hub URIs from Neo4j in batches to keep memory bounded."""
    log.info("Streaming :ns0__Hub URIs from Neo4j...")
    uris: set[str] = set()
    batch_size = 50_000
    skip = 0
    while True:
        statement = (
            "MATCH (h:ns0__Hub) "
            "WHERE h.uri STARTS WITH 'http://id.loc.gov/resources/hubs/' "
            "RETURN h.uri AS uri SKIP $skip LIMIT $limit"
        )
        r = requests.post(
            NEO4J_URL,
            auth=(NEO4J_USER, NEO4J_PASSWORD),
            json={
                "statements": [
                    {"statement": statement,
                     "parameters": {"skip": skip, "limit": batch_size}}
                ]
            },
            timeout=120,
        )
        r.raise_for_status()
        body = r.json()
        if body.get("errors"):
            raise SystemExit(f"Neo4j error: {body['errors']}")
        rows = body["results"][0]["data"]
        if not rows:
            break
        for row in rows:
            uris.add(row["row"][0])
        skip += batch_size
        log.info("  fetched %d so far...", skip)
    return uris


def write_sorted(path: Path, uris: set[str]) -> None:
    with path.open("w", encoding="utf-8") as f:
        for uri in sorted(uris):
            f.write(uri + "\n")


def main() -> int:
    parser = argparse.ArgumentParser(description=__doc__)
    parser.add_argument("--log-level", default="INFO")
    args = parser.parse_args()
    logging.basicConfig(
        level=args.log_level,
        format="%(asctime)s %(levelname)s %(message)s",
        datefmt="%H:%M:%S",
    )
    OUTPUT_DIR.mkdir(parents=True, exist_ok=True)

    t0 = time.monotonic()
    upstream = load_upstream()
    log.info("upstream URIs: %d", len(upstream))
    write_sorted(UPSTREAM_PATH, upstream)

    local = load_local()
    log.info("local URIs: %d", len(local))
    write_sorted(LOCAL_PATH, local)

    missing = upstream - local
    extra = local - upstream
    both = upstream & local
    log.info("missing_local (upstream - local): %d", len(missing))
    log.info("extra_local   (local - upstream): %d", len(extra))
    log.info("present_both: %d", len(both))

    write_sorted(MISSING_PATH, missing)
    write_sorted(EXTRA_PATH, extra)

    summary = {
        "upstream_count": len(upstream),
        "local_count": len(local),
        "missing_local_count": len(missing),
        "extra_local_count": len(extra),
        "present_both_count": len(both),
        "elapsed_seconds": round(time.monotonic() - t0, 1),
    }
    SUMMARY_PATH.write_text(json.dumps(summary, indent=2))
    log.info("summary written to %s", SUMMARY_PATH)
    return 0


if __name__ == "__main__":
    sys.exit(main())
