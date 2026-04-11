# Modern MARC Hub Discovery

## Overview

LC's **Modern MARC** initiative (announced March 2026) embeds BIBFRAME identifiers
directly in MARC records. This plugin exploits those identifiers to resolve
BIBFRAME Hub URIs without querying Neo4j or the LC suggest2 API — a "fast lane"
that is both faster and more reliable than the legacy resolution cascade.

## MARC Fields for Hub Discovery

### 240/130 — Uniform Title (Primary Fast Lane)

The most direct path. Modern MARC populates `$1` (Real World Object URI) in
the uniform title field with a Hub URI:

```
240 10 $a Palinuro de México. $l English
       $1 http://id.loc.gov/resources/hubs/c9f0947b-4df1-d719-a1c1-ab80fccaed59
```

- **Subfield `$1`** carries the Hub URI (Real World Object URI per RDA Registry)
- **Subfield `$0`** may also carry a Hub URI in some records
- The plugin checks both `$0` and `$1`, preferring whichever resolves to
  `id.loc.gov/resources/hubs/`
- This is the **only** field observed to carry direct Hub URIs in current
  Modern MARC production records

**Implementation:** `getFirstHubUriFromField()` in `BibframeHub.php`

### 758 — Resource Identifier (Relationship Metadata)

New field in Modern MARC that links a record to its BIBFRAME Work and Instance
entities. Potentially rich relationship source but requires traversal to reach Hubs.

```
758  $1 http://id.loc.gov/resources/works/7624041
     $4 http://id.loc.gov/ontologies/bibframe/instanceOf
758  $1 http://id.loc.gov/resources/instances/7748090
     $4 http://id.loc.gov/ontologies/bibframe/hasInstance
```

Key subfields:
- `$a` — Label (human-readable title)
- `$i` — Relationship label (e.g., "Has work manifested", "Expression of (work)")
- `$0` — Authority URI
- `$1` — Entity URI (usually `/resources/works/` or `/resources/instances/`)
- `$4` — Relationship type URI (e.g., `bf:instanceOf`)

**Important findings:**
- 758 URIs point to **Works** and **Instances**, not Hubs
- To get from a Work URI to a Hub URI requires traversal:
  1. `HEAD https://id.loc.gov/resources/works/{id}` → `x-preflabel` header (AAP)
  2. `GET https://id.loc.gov/resources/hubs/label/{url_encoded_aap}` → 302 redirect to Hub URI
- The plugin currently extracts 758 data for self-hub detection but does **not** perform
  work→hub traversal (potential future enhancement)
- Self-hub detection: 758 with `$i` containing "Has work manifested" or "Expression of"
  identifies the work's own hub

**Implementation:** `extract758Relations()` in `BibframeHub.php`

### 7XX — Added Entry Fields (Future Potential)

Modern MARC records include `$0` and `$1` subfields on contributor fields
(100, 700, etc.) pointing to LC Name Authority URIs. These are not Hub URIs
but could enrich author-distance scoring:

```
100 1  $a Del Paso, Fernando, $d 1935-2018.
       $0 http://id.loc.gov/authorities/names/n80040486
       $1 http://id.loc.gov/rwo/agents/n80040486
700 1  $a Grossman, Elizabeth, $d 1945- $e translator.
       $0 http://id.loc.gov/authorities/names/n87869825
       $1 http://id.loc.gov/rwo/agents/n87869825
```

Not currently used for hub discovery, but the authority URIs could enable
precise author-distance calculations without relying on Neo4j agent lookups.

## Resolution Cascade

When `init()` processes a record, hub resolution follows this priority:

```
1. Fast lane:  240/130 $0/$1 → Hub URI directly from MARC
2. Fast lane:  758 self-hub detection → Hub URI from $0/$1
3. Legacy:     LCCN → Neo4j `findHubByLccn()`
4. Legacy:     Title → Neo4j `findHubByTitle()`
5. Legacy:     Title → LC suggest2 API
```

Once a Hub URI is resolved (by any method), relationship discovery follows:

```
1. RDF-first:  Fetch {hubUri}.rdf from id.loc.gov → parse relationships
2. Fallback:   Re-resolve via suggest2 → try RDF again
3. Fallback:   Neo4j graph query for relationships
```

## BIBFRAME Entity Model (as observed)

```
Hub ──bf:hasExpression──▶ Work ──bf:hasInstance──▶ Instance
 │                         ▲                        ▲
 │                         │                        │
 └──bf:relation──▶ Hub    758 $1 (/works/)     758 $1 (/instances/)
                          240 $1 (/hubs/)
```

- **Hub**: Authoritative Access Point (e.g., "Palinuro de México. English")
- **Work**: Expression of a hub with specific attributes
- **Instance**: Physical/digital manifestation
- MARC 240/130 `$1` points to the **Hub** (direct)
- MARC 758 `$1` points to **Work** or **Instance** (indirect, requires traversal)
- Hub→Hub relationships are in the Hub's RDF via `bf:relation`

## Test Record

**Palinuro of Mexico** (LCCN 2025027149) — English translation of Fernando del
Paso's novel. Cataloged with Modern MARC fields in 2025-2026.

- Hub URI: `http://id.loc.gov/resources/hubs/c9f0947b-4df1-d719-a1c1-ab80fccaed59`
- Source: 240 `$1` (direct Hub URI)
- Relationships found via RDF: 1 Translation (original Spanish work,
  hub `f0cd9458-c33a-9cd8-7adf-79a596f387b0` — "Palinuro de México")
- Solr ID: `test-palinuro-001`

## Findings and Caveats

1. **`$1` not `$0`**: LC uses `$1` (Real World Object URI) for Hub URIs in 240/130,
   not `$0` (record control number). Both should be checked.

2. **758 lacks Hub URIs**: Despite being the richest new field, 758 carries
   Work/Instance URIs, not Hub URIs. Direct hub discovery requires 240/130.

3. **Translation hubs have few relationships**: The Palinuro hub has only 2 relations
   (both translation links). Classic literature hubs (Hamlet, P&P) are far richer
   for testing the surprise scoring model.

4. **Tier 5 results matter**: The template previously filtered results with
   `score < 20`, which hid all tier 5 (Translation, base 10) results. This filter
   has been removed — all scored results now display. Volume is controlled by
   `maxDisplayResults` (default 15).

5. **suggest2 API unreliability**: The LC suggest2 API returns 503 intermittently.
   The Modern MARC fast lane eliminates this dependency entirely for records that
   carry Hub URIs.

6. **Work→Hub traversal**: A future enhancement could use the 758 Work URI to
   discover the Hub via HEAD request (`x-preflabel` header) → label endpoint
   (302 redirect). This would provide a second fast lane for records that have
   758 but not 240 `$1`.
