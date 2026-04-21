# bibframe-hub-graph

> Planning notes for a follow-on project to the VuFind BIBFRAME Hub plugin.

A Neo4j-backed service that keeps the Library of Congress BIBFRAME Hub graph
current with `id.loc.gov`, so downstream consumers (the VuFind plugin, other
discovery systems, reconciliation tools) can query Hub relationships without
worrying about stale URIs or deprecated nodes.

## Motivation

LC publishes the BIBFRAME dataset as periodic TTL bulk downloads. Loading that
into Neo4j via `n10s` works well as a snapshot, but Hubs on `id.loc.gov` are
live: they get minted, merged, deprecated, and re-URIed continuously. Within
weeks of a bulk load, a non-trivial fraction of the graph's Hub URIs return 404
or redirect to a new canonical URI.

The VuFind plugin works around this today by HEAD-checking every Hub URI before
display and dropping ones that don't resolve. That's the right behavior for a
consumer, but it means the graph itself is silently carrying dead nodes and
stale edges. A dedicated cleanup/refresh layer would make the graph honest
about its own freshness.

## Problems to solve

### 1. URI drift
A Hub still exists conceptually but lives at a new UUID. The old URI may 404,
or may redirect via 301 to the new URI. Detectable by HEAD + label-endpoint
reconciliation.

### 2. Deprecation / merge
Two Hubs are merged into one on id.loc.gov. The surviving URI is canonical;
the others 301 to it. The graph should collapse the duplicates and reroute
incoming edges.

### 3. Relationship inconsistency
The `bflc:Relationship` reification pattern in the bulk TTL is dense and
occasionally inconsistent (missing types, duplicate edges, mixed URI vs.
inline-label predicates). A normalization pass would reduce downstream parsing
complexity.

### 4. No freshness signal
Queries against the graph can't distinguish a node verified yesterday from one
untouched since the snapshot. Adding `last_verified` and `upstream_status`
properties makes freshness explicit.

## Proposed scope

### Phase 1 — URI reconciliation sweep
Walk every `(:ns0__Hub)` node, HEAD its `uri` against `id.loc.gov`, follow
redirects, update the node. Flag unresolvable ones with
`upstream_status='gone'` for review. Record `last_verified` timestamp.

Throttle to respect id.loc.gov (~2 req/s baseline, configurable). At 2.39M
Hub nodes that's roughly two weeks of continuous checking — acceptable for
the initial sweep, and incremental re-checks afterward can run in hours.

### Phase 2 — Merge collapse
For any Hub whose HEAD returned 301 to another URI already present in the
graph, rewire all incoming and outgoing edges to the surviving node and mark
the obsolete node with `upstream_status='merged'` and `merged_into` pointing
at the canonical URI. Keep the old node rather than deleting so downstream
lookups by legacy URI still work.

### Phase 3 — Relationship normalization
Audit `(:ns1__Relationship)` reification nodes for:
- missing `ns1__relation` types
- duplicate edges (same source, target, type)
- inline labels that should map to canonical relationship URIs
  (reuse the plugin's `HubRdfParser::INLINE_LABEL_MAP` as a starting point)

Emit a report; apply safe normalizations; leave ambiguous cases flagged.

### Phase 4 — Incremental refresh
Two options, to be evaluated:
- **Diff successive bulk TTL snapshots** and apply the delta as a graph patch.
  Simple, batch-oriented, lags by a snapshot cycle.
- **Subscribe to an LC update feed** if one is exposed (or pulled from
  sitemaps / recent-changes endpoints). Near-real-time, more engineering.

### Phase 5 — Schema additions
Add to every Hub node:
- `last_verified: timestamp`
- `upstream_status: 'live' | 'redirect' | 'merged' | 'gone' | 'unknown'`
- `canonical_uri: string` (self for live, target for redirect/merged)

Expose via indexes so consumers can filter:
```cypher
MATCH (h:ns0__Hub)
WHERE h.upstream_status = 'live'
  AND h.last_verified > datetime() - duration('P30D')
RETURN h
```

## Non-goals

- Replacing id.loc.gov as the source of truth. The graph is a local cache with
  freshness metadata, not a canonical registry.
- Resolving works, instances, or agents. This project is Hub-only. Those
  entities have different lifecycle patterns and probably want their own
  cleanup tooling.
- Full BIBFRAME schema validation. Out of scope; the LC platform owns that.

## Relationship to the VuFind plugin

The plugin would consume the cleaned graph directly instead of HEAD-checking
URIs at render time. A `upstream_status='live'` filter on every Hub query
removes the need for per-request validation and makes the Neo4j fallback path
as trustworthy as the live RDF fast lane.

The plugin's `HubRdfParser::INLINE_LABEL_MAP` and surprise-scoring model would
port directly into the cleanup tooling for Phase 3 normalization.

## Status

**Planning only.** No code yet. Filing this so the idea isn't lost and so the
scope is visible before committing engineering time. Estimated effort for
Phase 1 alone (the reconciliation sweep) is on the order of a week of focused
work, plus the compute time for the initial HEAD sweep.

## Related

- VuFind plugin: <https://github.com/jameshahn/vufind-plugin> *(or wherever this ends up)*
- marc2bibframe2 Hub generation issue: *(link to issue once filed)*
- Heng et al. 2026, *Managing BIBFRAME Work and Hub Entities at Scale*:
  <https://doi.org/10.1080/01639374.2026.2655113>
