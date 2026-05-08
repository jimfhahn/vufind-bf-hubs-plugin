# Neo4j Hub Graph Topology

Verified 2026-05-08 against the `neo4j-hubs-v2` container running the
2026-05-05 LC BIBFRAME Hubs bulk dump (Neo4j 5.26.24, n10s 5.26.0,
SHORTEN namespace mode, host ports 7476/7689). The 2026-04-30 graph
(`neo4j-hubs-new`, 7475/7688) is preserved alongside for comparison;
counts and schema below match both snapshots within rounding.

## Snapshot stats

| Metric | Value (2026-05-05) |
| --- | --- |
| Total triples loaded | ~152.6M |
| Total nodes | ~37.1M |
| Total relationships | ~68.8M |
| `:ns0__Hub` nodes | ~2.91M |
| `:ns0__Relation` reification nodes | ~544K |
| `bf:Relation -[:associatedResource]-> Hub` edges | ~532K |

LC reports ~2.93M live Hubs, so this snapshot is ~99% complete. There is
no longer any meaningful gap to chase via activity-stream ingestion or
label-recovery reconciliation.

## How relationships are modeled

Every Hub→Hub relationship in the new dump is a uniform reified pattern.
There are **no direct `bf:translationOf` / `bf:relatedTo` /
`bf:arrangementOf` edges between Hubs**, and the older
`bflc:Relationship` chain is gone too.

```
(source:ns0__Hub)
   -[:ns0__relation]->
      (:ns0__Relation)
         -[:ns0__associatedResource]-> (target:ns0__Hub)
         -[:ns0__relationship]-> (rt:Resource)   // typed via rt.uri
```

The relation is recorded only on the source side, so traversal must be
bidirectional to find all neighbors.

## Relationship-type URI prefixes

Relationship-type URIs come in two flavors which the plugin strips
uniformly:

| Prefix | Count | Notes |
| --- | --- | --- |
| `http://id.loc.gov/vocabulary/relationship/` | ~547,751 | Dominant; newer typed set |
| `http://id.loc.gov/entities/relationships/` | ~60,201 | Older typed set; still actively used |
| `bnode://...` | ~21,694 | Skipped by `findRelatedHubs` |

`Neo4jService::findRelatedHubs()` and
`Neo4jService::getRelationshipTypeFrequencies()` apply both
`replace()` calls to normalize to the bare slug
(e.g. `translationof`, `operaadaptationof`).

## Other Hub-side patterns (unchanged from prior dumps)

- **Titles**: `(Hub)-[:ns0__title]->(Title)` where `Title.ns0__mainTitle`
  is **STRING when single-valued and LIST<STRING> when multi-valued**
  (n10s default `handleMultival: OVERWRITE`). Cypher that reads this
  property must handle both shapes — e.g.
  `CASE WHEN valueType(t.ns0__mainTitle) STARTS WITH "LIST" THEN t.ns0__mainTitle[0] ELSE t.ns0__mainTitle END`.
  A bare `t.ns0__mainTitle[0]` will crash on STRING values with
  `String(...) is not a collection or a map`.
- **Contributors**: `(Hub)-[:ns0__contribution]->(Contribution)-[:ns0__agent]->(Agent)`.
  Agent nodes have only a `uri` (no name labels).
- **Media types**: `(Hub)-[:rdf__type]->(Resource)` where `Resource.uri`
  is one of `bf:MovingImage`, `bf:Audio`, `bf:NotatedMusic`, `bf:Text`,
  `bf:Multimedia`, `bf:Arrangement`, `bf:NotatedMovement`.
- **Identifiers**: `(Hub)-[:ns0__identifiedBy]->(Identifier)` where
  `Identifier.rdf__value` carries the LCCN/ISBN/etc.

## What was retired

Earlier work tracked legacy-Hub reconciliation:
`upstream_status` / `canonical_uri` properties, label-recovery sweeps,
the activity-stream crawler. None of that is present on this graph and
none of the code paths that referenced those properties remain in the
plugin. Both the 2026-04-30 and 2026-05-05 snapshots already mirror
live id.loc.gov closely enough that runtime canonical substitution is
no longer warranted.
