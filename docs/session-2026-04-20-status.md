# Session status — 2026-04-20

Pick-up notes for tomorrow. Summarizes what shipped today, what proved out, and
what's on deck.

## TL;DR

- **Perf**: fixed. Record pages load in ~0.4s warm (was 10–27s).
- **Correctness**: plugin works end-to-end on 2/4 demo records. The other 2
  hit the Neo4j fallback path, and every URI in the bulk TTL snapshot is stale,
  so the hard-validation rule ("no link → not a Hub") drops everything.
- **Next critical path**: graph reconciliation (separate project already
  sketched in [docs/follow-on-bibframe-hub-graph.md](follow-on-bibframe-hub-graph.md)).

## What shipped today

### Performance optimizations

Before: Hamlet record page = ~12s warm, ~15s cold.
After: Hamlet record page = ~0.4s warm, ~13s first cold hit (then cached).

Three independent bottlenecks identified via timed instrumentation (see the
`[BFH … s]` error_log lines still present in
[module/BibframeHub/src/BibframeHub/Related/BibframeHub.php](../module/BibframeHub/src/BibframeHub/Related/BibframeHub.php)):

1. **Per-related-Hub enrichment** was issuing 3 Neo4j round-trips per result
   (title + agents + media) × 58 related Hubs = 174 HTTP calls.
   - Fix: `Neo4jService::getHubsBulk()` — one `UNWIND $uris` Cypher returns
     title + agents + media for every target in ~30ms.
   - Scorer callbacks now serve from an in-memory map instead of calling back
     into Neo4j.
   - Applied to both `fetchAndScoreViaRdf` and `fetchAndScoreViaNeo4j`.

2. **`getRelationshipTypeFrequencies`** was aggregating over 138K edges every
   request (~2s). Per-instance cache didn't survive across PHP-FPM requests.
   - Fix: persist to `/vufind-local/cache/bibframehub_rel_frequencies.json` with
     24h TTL, wired through `Neo4jServiceFactory`.

3. **Live RDF fetches of "bad" Hubs** were taking 10–30s on id.loc.gov for
   certain URIs that do exist but return giant empty-relations payloads.
   - Fix: negative cache in `fetchAndScoreViaRdf` —
     `/vufind-local/cache/bibframehub_empty_rdf.json`, 24h TTL.
   - After first hit, those URIs are skipped and we jump straight to Neo4j.

4. **URI validation** was sequential HEAD-checking ~30 URIs @ 300ms each.
   - Fix: `headCheckUrisParallel()` via `curl_multi`, 10 at a time, 5s timeout.

### Correctness

User rule: **"if a Hub URI doesn't resolve on id.loc.gov, it's not a Hub we can
link to, so drop it."**

- Removed the soft-validate path that was showing unlinkable titles.
- Neo4j-sourced primary Hub URIs are also HEAD-checked before being rendered.

## Current record-page behavior

Measured at end of session with all caches warm:

| Record | Resolution path | Found | Displayed |
|---|---|---|---|
| test-palinuro-001 | MARC 240 `$1` → RDF fast-lane | 13 | 13 ✅ |
| test-gatsby-001 | title → Neo4j → RDF | 9 | 9 ✅ |
| test-hamlet-001 | title → Neo4j fallback | 58 | 0 ❌ |
| test-pandp-001 | title → Neo4j fallback | ~30 | 0 ❌ |

Hamlet's 58 related Hubs exist in Neo4j (bulk TTL snapshot) but their URIs
return 404 on id.loc.gov now. Same for P&P. This is the data-quality problem
the follow-on project addresses.

## Files changed today

- [module/BibframeHub/src/BibframeHub/Related/BibframeHub.php](../module/BibframeHub/src/BibframeHub/Related/BibframeHub.php)
  - Added negative RDF cache (`emptyRdfCache` + load/save helpers)
  - Switched both scoring paths to bulk Neo4j prefetch
  - Replaced sequential HEAD validation with `curl_multi`
  - **Still contains `[BFH …]` `error_log` timing instrumentation** — decide
    whether to keep or strip tomorrow.
- [module/BibframeHub/src/BibframeHub/Graph/Neo4jService.php](../module/BibframeHub/src/BibframeHub/Graph/Neo4jService.php)
  - Added `getHubsBulk(array $hubUris)` method
  - Added disk-persistent `frequencyCache` with TTL
- [module/BibframeHub/src/BibframeHub/Graph/Neo4jServiceFactory.php](../module/BibframeHub/src/BibframeHub/Graph/Neo4jServiceFactory.php)
  - Wires default cache path from `LOCAL_OVERRIDE_DIR`

## Outstanding work

### 1. File the marc2bibframe2 issue (user action, ~30 min)
Draft is at [heng-et-al-2026/marc2bibframe2-issue-draft-v2.md](../../heng-et-al-2026/marc2bibframe2-issue-draft-v2.md).
Validated empirically on the Heng corpus:
- Concerto: 350/400 = 87.5%
- Hamlet: 335/500 = 67.0%
- Combined: 685/900 = 76.1%
- Zero false positives

Target: <https://github.com/lcnetdev/marc2bibframe2/issues>.

Also pending: email to MJ Han / Glen Layne-Worthey at UIUC.

### 2. Graph reconciliation project (the big one)
Plan at [docs/follow-on-bibframe-hub-graph.md](follow-on-bibframe-hub-graph.md).
This is now the critical path for making Hamlet/P&P actually surface their
related works.

Five phases in the plan:
1. URI reconciliation sweep (bulk HEAD check of all 2.39M Hub URIs → status map)
2. Merge collapse (consolidate duplicate Hubs that now share a canonical URI)
3. Relationship normalization (use typed relationship URIs consistently)
4. Incremental refresh (keep graph in sync with id.loc.gov over time)
5. Schema additions (`last_verified`, `upstream_status`, `canonical_uri`)

Quickest MVP: just phase 1 + a redirect-following pass. That alone would
reclaim most of Hamlet's 58 results.

### 3. Demo/comparison with Heng's numbers
- Hamlet corpus (8,678 MARC records) not yet indexed into Docker VuFind. The
  `load_heng` function exists in [docker/entrypoint.sh](../docker/entrypoint.sh)
  but only Concerto is loaded.
- Concerto: 66/86 records loaded (others failed Solr required-field validation)
- Harvest CSV script not built yet. Goal: iterate all indexed records, capture
  `(record_id, hub_uri, num_related_works, top_rel_types)` for a side-by-side
  with Heng's 14.81% relationship-rate claim.
- Concerto records (Beethoven Op. 61, etc.) don't resolve to Hubs even though
  they should — suggest2/label cascade returns nothing. Worth debugging.

## Quick reference — useful commands

```bash
# Start the stack
cd /Users/jameshahn/Documents/GitHub/vufind-plugin && docker compose up --build

# Tail plugin timing output
docker logs vufind-plugin-vufind-1 --since 1m 2>&1 | grep -a BFH

# Clear all plugin caches
docker exec vufind-plugin-vufind-1 rm -f \
  /vufind-local/cache/bibframehub_empty_rdf.json \
  /vufind-local/cache/bibframehub_uri_validation.json \
  /vufind-local/cache/bibframehub_rel_frequencies.json

# Measure a record page
time curl -s -o /dev/null "http://localhost:4567/vufind/Record/test-hamlet-001"

# Neo4j sanity check
curl -su neo4j:bibframe123 -H 'Content-Type: application/json' \
  http://localhost:7474/db/neo4j/tx/commit \
  -d '{"statements":[{"statement":"MATCH (h:ns0__Hub) RETURN count(h)"}]}'
```

## Suggested pick-up order tomorrow

1. **Decide**: keep `[BFH]` instrumentation in `BibframeHub.php` or strip it?
   (It writes a handful of `error_log` lines per request; fine for dev, noisy
   in production.)
2. **File the marc2bibframe2 issue** — independent, unblocks LC side.
3. **Start graph reconciliation project** — this is what makes the plugin
   actually useful on the records users care about. Phase 1 alone is high
   leverage.
