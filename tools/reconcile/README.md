# Hub URI Reconciliation

Phase 1 of the graph-reconciliation work sketched in
[`docs/follow-on-bibframe-hub-graph.md`](../../docs/follow-on-bibframe-hub-graph.md).

Walks `(:ns0__Hub)` nodes in Neo4j, HEAD-checks each `uri` against
`id.loc.gov`, follows redirects, and writes back three properties:

| Property          | Values                                              |
|-------------------|-----------------------------------------------------|
| `upstream_status` | `live` \| `redirect` \| `gone` \| `error`           |
| `canonical_uri`   | URI after redirect (or self for `live`)             |
| `last_verified`   | ISO-8601 UTC timestamp                              |

Resumable: nodes verified within `--max-age-days` are skipped.

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

Run against just the URIs returned for the Hamlet + P&P records (drops them
into the script's URI seed file, no full sweep):

```bash
python reconcile_hubs.py --seed-from-records test-hamlet-001 test-pandp-001
```

## Full sweep

```bash
# Default: 8 concurrent HEAD requests, 0.4s min interval per worker (~2 req/s aggregate)
python reconcile_hubs.py --batch-size 1000

# Resume — skips nodes with last_verified < N days ago
python reconcile_hubs.py --max-age-days 30
```

## Inspecting results

```cypher
MATCH (h:ns0__Hub)
WHERE h.upstream_status IS NOT NULL
RETURN h.upstream_status, count(*)
ORDER BY count(*) DESC;
```
