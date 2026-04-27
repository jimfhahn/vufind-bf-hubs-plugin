# Activity Streams Coverage Tools

Phase 2 of the Hub graph reconciliation work — see
[`docs/follow-on-activity-streams-coverage.md`](../../docs/follow-on-activity-streams-coverage.md)
for the design.

## What this does

Crawls the [LC BIBFRAME Hubs Activity Streams feed](https://id.loc.gov/resources/hubs/activitystreams/feed/)
to enumerate every Hub URI that currently exists upstream, then diffs that
set against our Neo4j graph to find Hubs we don't have yet. The missing
set is then ingested via n10s and reconciled.

## Scripts

- `activity_stream_crawler.py` — paginated walk of the AS feed, resumable,
  emits one JSONL line per Hub event to `output/feed-events.jsonl`.
- `diff_against_graph.py` — reads the JSONL output and the live `:ns0__Hub`
  set from Neo4j, writes `output/missing-local.txt` (Hubs upstream but not
  in graph) and `output/extra-local.txt` (in graph but not upstream).
- `ingest_missing_hubs.py` — fetches `.nt` for each missing Hub, posts to
  `n10s.rdf.import.fetch`. (planned, not yet implemented.)

## Quick start

```bash
cd /Users/jameshahn/Documents/GitHub/vufind-plugin/tools/coverage
python3 -m venv .venv
source .venv/bin/activate
pip install -r requirements.txt

# Test against first 10 pages (sanity check)
python3 activity_stream_crawler.py --max-pages 10

# Full crawl (resumable; ~8h single-worker, ~1.5h with --workers 4)
python3 activity_stream_crawler.py --workers 4
```

## Resumability

The crawler writes `output/checkpoint.json` after each page completes. On
restart, it resumes from `last_completed_page + 1`. JSONL output is
append-only, so partial runs are safe.

## Estimated cost

- Feed: 29,347 pages × ~100 items each = ~2.93M Hub URIs total
- Single-worker @ 1 req/s: ~8.2 hours
- 4 workers @ 0.25s/req per worker: ~2 hours
- 8 workers: not recommended (rate-limit risk)
