# Neo4j Hub Graph Topology

Verified 2026-04-25 against the live `neo4j-hubs` container (Neo4j 5.26, n10s 5.26.0)
after the label-recovery sweep completed.

## Hub population

| Status | Count | Notes |
| --- | --- | --- |
| `redirect` (recovered, has `canonical_uri`) | 494,817 | Live on id.loc.gov via 30x or label-endpoint match |
| `gone` (no canonical recovered) | 2,109,226 | Genuinely dead; no label match |
| NULL `upstream_status` | 48,944 | Bnodes — filtered by reconciler |
| `error` | 3 | Transient failures during sweep |
| **Total `:ns0__Hub` nodes** | **~2.65M** | (snapshot from earlier bulk TTL load) |

id.loc.gov reports ~2.93M Hubs, so the snapshot is missing ~330K (~11%). See
[follow-on-activity-streams-coverage.md](follow-on-activity-streams-coverage.md)
for the planned closure of that gap.

## Reconciliation status convention (gotcha)

Recovery flips `upstream_status` from `'gone'` → `'redirect'` **and** sets
`canonical_uri`. The two are mutually exclusive after a successful recovery.

- ❌ Wrong: `WHERE upstream_status = 'gone' AND canonical_uri IS NOT NULL` returns 0.
- ✅ Right: `WHERE canonical_uri IS NOT NULL` (or `upstream_status = 'redirect'`).

## Hub→Hub direct edges (bidirectional `-[r]-` totals)

| Edge type | Count |
| --- | --- |
| `ns0__translationOf` | 941,214 |
| `ns0__relatedTo` | 158,725 |
| `ns0__arrangementOf` | ~23K (per earlier counts; not re-verified 2026-04-25) |

These are the only edges where `Neo4jService::findRelatedHubs` can land on a
sibling `:ns0__Hub` node directly.

## Typed relationships (`bflc:Relationship` chains)

Pattern:

```
(Hub)-[:ns1__relationship]->(:ns1__Relationship)-[:ns1__relation]->(target:Resource)
```

~138K relationship instances spanning ~100 types under
`http://id.loc.gov/entities/relationships/`.

**Critical finding (2026-04-25)**: zero of these targets carry a Hub URI in our
graph. Verified:

```cypher
MATCH (h:ns0__Hub)-[:ns1__relationship]->(rel)-[:ns1__relation]->(target)
WHERE target.uri STARTS WITH "http://id.loc.gov/resources/hubs/"
RETURN count(*)            // → 0
```

Targets are presumably `/resources/works/` or `/resources/instances/` URIs that
were not represented as `:ns0__Hub` nodes in the bulk load. **Implication**: the
plugin's typed-relationship traversal in Neo4j cannot cross from one Hub to
another via this path. All Neo4j-side Hub→Hub discovery currently flows through
the three direct edge types above.

## Why the demo records don't exercise canonical substitution

All four bundled demo records (`test-pandp-001`, `test-hamlet-001`,
`test-gatsby-001`, `test-palinuro-001`) resolve via the **RDF fast-lane** —
their primary Hub URI returns a populated `bf:relation` block from id.loc.gov,
so `BibframeHub::fetchAndScoreViaRdf` returns successfully and the Neo4j
fallback never runs. The label-recovery work surfaces only when:

1. A record's primary Hub URI returns no live RDF (or empty relations), **and**
2. The Neo4j fallback finds direct-edge neighbors that have
   `canonical_uri IS NOT NULL`.

To validate canonical substitution end-to-end we need to either load more MARC
records (especially older catalog data whose Hubs are stale) or construct a
synthetic test that bypasses the RDF cache.

## Query performance notes

- Always look up by URI via `:ns0__Hub {uri: '...'}` (uses the `hub_uri` index).
- Full-graph aggregations over the 117M-triple store routinely time out at
  60s+. Use the bulk fetch in `Neo4jService::getHubsBulk()` rather than
  per-Hub round trips.
- Bidirectional `-[r]-` traversals must always be bounded (`LIMIT`,
  fixed-depth pattern, or anchored to an indexed start node).
