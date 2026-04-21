# Hub URI Reconciliation

Phase 1 of the graph-reconciliation work sketched in
[`docs/follow-on-bibframe-hub-graph.md`](../../docs/follow-on-bibframe-hub-graph.md).

Walks `(:ns0__Hub)` nodes in Neo4j, HEAD-checks each `uri` against
`id.loc.gov`, follows redirects, and writes back three properties:

| Property          | Values                                              |
|-------------------|-----------------------------------------------------|
| `upstream_status` | `live` \| `redirect` \| `gone` \| `error`           |
| `canonical_uri`   | URI after redirect (or self for `live`; `null` for `gone`/`error`) |
| `last_verified`   | ISO-8601 UTC timestamp                              |

Resumable: by default, only nodes with no `upstream_status` are processed.
With `--max-age-days N`, nodes whose `last_verified` is older than `N` days
are also re-checked.

## Setup

```bash
cd tools/reconcile
python3 -m venv .venv
source .venv/bin/activate
pip install -r requirements.txt
```

## One-time Neo4j schema

```bash
python reconcile_hubs.py --apply-schema
```

Creates two indexes (idempotent):

- `hub_status` on `(:ns0__Hub).upstream_status`
- `hub_canonical` on `(:ns0__Hub).canonical_uri`

## Smoke test

Reconcile a fixed list of URIs (no graph iteration):

```bash
# Inline:
python reconcile_hubs.py --uris http://id.loc.gov/resources/hubs/abc... http://id.loc.gov/resources/hubs/def...

# Or from file (one URI per line):
python reconcile_hubs.py --uris-file seed_uris.txt
```

`seed_uris.txt` and `hamlet_uris.txt` are checked-in fixtures from the demo
records.

## Pre-flight (recommended before any long sweep)

Run a small bounded batch to confirm throughput and write-back work:

```bash
python reconcile_hubs.py --batch-size 500 --limit 500
```

Should finish in ~2 minutes at default settings (~5 req/s aggregate). Look
for `live` / `redirect` / `gone` / `error` counts in the cumulative summary.

## Full sweep (~6 days at default rate)

The sweep is long enough that you should run it in `tmux` so it survives
terminal closes, sleeps, and SSH disconnects.

```bash
tmux new -s sweep
cd /Users/jameshahn/Documents/GitHub/vufind-plugin/tools/reconcile
source .venv/bin/activate

# Default: 8 workers, 1.6s per-worker sleep -> ~5 req/s aggregate.
# tee writes a timestamped log alongside live console output.
python reconcile_hubs.py --batch-size 1000 \
  2>&1 | tee sweep-$(date +%Y%m%d).log

# Detach: Ctrl-b d
# Reattach: tmux attach -t sweep
```

**macOS sleep prevention**: in another terminal,

```bash
caffeinate -dimsu &   # holds wake assertion until you kill it
```

…or System Settings → Battery → Power Adapter → "Prevent automatic sleeping
when display is off".

### Tuning the rate

Default `--rate-limit 1.6 --workers 8` produces about 5 req/s aggregate,
which is conservative and id.loc.gov-friendly. Adjust as needed:

| Goal                       | Flags                              | Aggregate rate | 2.65M-Hub wall time |
|----------------------------|------------------------------------|----------------|---------------------|
| Conservative               | `--workers 4 --rate-limit 2.0`     | ~2 req/s       | ~15 days            |
| **Default (safe)**         | `--workers 8 --rate-limit 1.6`     | ~5 req/s       | ~6 days             |
| Aggressive (use carefully) | `--workers 8 --rate-limit 0.8`     | ~10 req/s      | ~3 days             |

If `error` results show `note: rate-limited` in batch summaries, the worker
already auto-slept via `Retry-After` and the URI is left for a future
re-sweep. Persistent 429s mean you should raise `--rate-limit`.

## Recovery scenarios

The sweep is designed to survive every interruption you're likely to hit.

| Interruption                  | What happens                                                                                       | What to do                                          |
|-------------------------------|----------------------------------------------------------------------------------------------------|-----------------------------------------------------|
| Ctrl-C (SIGINT) once          | Current batch finishes, results written, then exit cleanly                                         | Just rerun the same command                         |
| Ctrl-C twice                  | Force exit; in-flight batch results lost (next sweep re-checks them)                               | Rerun                                               |
| Laptop sleep / network drop   | HEAD requests fail; batch completes with many `error` results, written back                        | Rerun. To force re-check of errors: `--max-age-days 1` |
| Neo4j container restart       | Cypher write fails; results dumped to `sweep-failed-batch-<timestamp>.json`, script exits          | Restart Neo4j, then rerun. Dumped JSON is a manual replay aid only. |
| Disk full / OOM               | Script crashes; partial batch written if it got that far                                           | Free space, rerun                                   |
| LC returns 429 (rate-limited) | Worker honors `Retry-After` (cap 60s), reports `error`                                             | Rerun later, or raise `--rate-limit`                |

### Resuming after errors or refreshing stale data

`error` is recorded as a status, so without `--max-age-days` it would be
treated as already-processed. To re-check error nodes (or any old result):

```bash
# Force re-check of everything (incl. live nodes) -- careful, this is heavy:
python reconcile_hubs.py --max-age-days 0 --limit 1000  # bound it first

# Routine incremental refresh: re-check anything older than 30 days:
python reconcile_hubs.py --max-age-days 30
```

## Inspecting results

Quick CLI check:

```bash
curl -su neo4j:bibframe123 -H 'Content-Type: application/json' \
  http://localhost:7474/db/neo4j/tx/commit -d '{"statements":[{"statement":
  "MATCH (h:ns0__Hub) RETURN h.upstream_status AS status, count(*) AS n ORDER BY n DESC"}]}'
```

In the Neo4j browser at <http://localhost:7474>:

```cypher
// Status distribution:
MATCH (h:ns0__Hub)
RETURN h.upstream_status AS status, count(*) AS n
ORDER BY n DESC;

// Sweep progress (overall %):
MATCH (h:ns0__Hub)
RETURN
  count(h) AS total,
  count(h.upstream_status) AS verified,
  toFloat(count(h.upstream_status)) / count(h) * 100 AS pct;

// Sample of recovered redirects:
MATCH (h:ns0__Hub)
WHERE h.upstream_status = 'redirect'
RETURN h.uri, h.canonical_uri LIMIT 25;
```

A simple poll loop you can run in another tmux pane:

```bash
while sleep 300; do
  date
  curl -su neo4j:bibframe123 -H 'Content-Type: application/json' \
    http://localhost:7474/db/neo4j/tx/commit -d '{"statements":[{"statement":
    "MATCH (h:ns0__Hub) RETURN h.upstream_status AS s, count(*) AS n ORDER BY n DESC"}]}' \
    | python3 -c 'import json,sys; d=json.load(sys.stdin); [print(f"  {r[0]:8} {r[1]:>10,}") for r in d["results"][0]["data"][0]["row"] and [row["row"] for row in d["results"][0]["data"]]]'
  echo
done
```

## Phase 2 — label-endpoint recovery for `gone` Hubs

After the sweep completes, run label recovery to reclaim ~74% of the `gone`
population by HEADing the LC label endpoint with `(agent_label, title)`
candidates. The recovered canonical is GET-validated for `bf:relation`
content before acceptance.

```bash
# Test on a small slice first:
python reconcile_hubs.py --label-recovery --limit 100

# Full run (estimated ~2-3 days at default rate):
python reconcile_hubs.py --label-recovery
```

Recovered Hubs are promoted from `gone` to `redirect` with `canonical_uri`
set. The plugin's `Neo4jService::getHubsBulk()` reads both fields and
substitutes canonicals automatically.

## Environment variables

Override the defaults if Neo4j isn't on `localhost:7474` or auth differs:

| Var               | Default                  |
|-------------------|--------------------------|
| `NEO4J_URL`       | `http://localhost:7474`  |
| `NEO4J_DB`        | `neo4j`                  |
| `NEO4J_USER`      | `neo4j`                  |
| `NEO4J_PASSWORD`  | `bibframe123`            |
