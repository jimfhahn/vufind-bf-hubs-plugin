# VuFind BIBFRAME Hub Plugin

A VuFind plugin that surfaces **surprising, non-obvious work relationships** using Library of Congress BIBFRAME Hubs. Instead of showing the predictable (translations, series membership), the plugin prioritizes creative transformations, cross-medium adaptations, and unexpected connections between works.

## Design Principle

> "Prioritize things that are interesting or non-obvious to the user — something surprising should be the key metric."

A patron viewing *Pride and Prejudice* should see *Death Comes to Pemberley* (P.D. James derivative), the 2005 Keira Knightley film, *Pride and Prejudice and Zombies* (parody), and *Bride & Prejudice* (Bollywood adaptation) — rather than drowning in 30+ translations and a series container.

## Architecture

```
┌─────────────────────────────────────────────────┐
│  VuFind Record View                             │
│  ┌───────────────────────────────────────────┐  │
│  │ Related: BibframeHub (sidebar panel)      │  │
│  │  → "Death Comes to Pemberley" [derivative]│  │
│  │  → "P&P (Film, 2005)" [adaptation]       │  │
│  │  → "P&P and Zombies" [parody]            │  │
│  └───────────────────────────────────────────┘  │
└────────────┬────────────────────────────────────┘
             │ init($settings, $driver)
             ▼
┌────────────────────────────┐
│ Related\BibframeHub        │ VuFind Related plugin
│  → extracts MARC fields    │
│  → resolves Hub URI        │
│  → queries graph           │
│  → scores by surprise      │
│  → returns top N           │
└────────────┬───────────────┘
             │
     ┌───────┴────────┐
     ▼                ▼
┌──────────┐  ┌──────────────┐
│ HubClient│  │ Neo4jService │  ← primary data source
│ (LC API) │  │ (graph DB)   │
│ suggest2 │  │ 2.6M Hubs    │
│ fallback │  │ 117M triples │
└──────────┘  └──────────────┘
```

### Data Flow

1. **MARC → Hub URI**: Match a VuFind MARC record to a BIBFRAME Hub in Neo4j via LCCN, title+contributor, or LC suggest2 API fallback.
2. **Graph traversal**: Query all Hub-to-Hub relationships (direct edges + typed `bflc:relationship` links).
3. **Surprise scoring**: Score each related Hub on a 0–100 scale using four signals.
4. **Present top N**: Return the highest-scoring connections to the template.

## Surprise Scoring Model

Four signals combine into a 0–100 score:

### 1. Relationship Type Tier (base 10–90 points)

| Tier | Score | Examples | Rationale |
|------|-------|----------|-----------|
| **1** | 90 | `inspirationfor`, `parodyof`, `derivative`, `graphicnovelizationof`, `critiqueof` | Creative transformation — a different lens on the same material |
| **2** | 75 | `adaptedasmotionpicture`, `operaadaptationof`, `variationsbasedon`, `dramatizationof` | Cross-medium jump to a different art form |
| **3** | 55 | `sequel`, `prequel`, `basedon`, `adaptedas`, `librettofor`, `remakeof` | Narrative continuation or direct adaptation |
| **4** | 30 | `continuedby`, `expandedversionof`, `revisionof`, `supplementto`, `mergerof` | Serial/structural relationship |
| **5** | 10 | `translator`, `translationof`, `editor`, `inseries`, `containerof`, `filmdirector` | Predictable — the kind of thing catalogs already show |

### 2. Rarity Bonus (0–10 points)

Log-inverse of the relationship type's frequency in the full dataset. `translator` (21K occurrences) → ~0 bonus. `inspirationfor` (33 occurrences) → ~10 bonus.

### 3. Author Distance (0–15 points)

| Scenario | Bonus |
|----------|-------|
| Completely different author(s) | 15 |
| Overlapping but not identical | 8 |
| Same author | 0 |
| Can't determine | 5 |

### 4. Medium Crossing (0–10 points)

Based on `rdf:type` on Hub nodes: `MovingImage`, `Audio`, `NotatedMusic`, `Multimedia`, etc.

| Scenario | Bonus |
|----------|-------|
| Source and target have different media | 10 |
| Source is generic, target has specific medium | 8 |
| Reverse | 3 |
| Same or unknown | 0 |

### Example: Pride and Prejudice Results

| Score | Relationship | Title |
|-------|-------------|-------|
| 100 | `inspirationfor` | Darcy series (diff. author) |
| 100 | `derivative` | Death Comes to Pemberley (diff. author) |
| 100 | `parodyof` | Pride and Prejudice and Zombies |
| 100 | `adaptedasmotionpicture` | P&P (Film, 2005) (diff. author, MovingImage) |
| 100 | `adaptedastelevisionprogram` | P&P (TV, 1995) (diff. author, MovingImage) |
| 74 | `sequel` | Pemberley, or P&P Continued (diff. author) |
| 15 | `translationOf` | Stolz und Vorurteil |
| 15 | `translationOf` | 傲慢与偏见 |

## Neo4j Graph (BIBFRAME Hubs Dataset)

### Setup

```bash
# Pull and run Neo4j with n10s plugin
docker run -d --name neo4j-hubs \
  -p 7474:7474 -p 7687:7687 \
  -e NEO4J_AUTH=neo4j/bibframe123 \
  -e NEO4J_PLUGINS='["n10s"]' \
  -v $(pwd)/data:/import \
  neo4j:5.26

# Configure n10s
# (via Neo4j browser at localhost:7474)
CALL n10s.graphconfig.init({handleVocabUris: "SHORTEN"});
CREATE CONSTRAINT n10s_unique_uri FOR (r:Resource) REQUIRE r.uri IS UNIQUE;

# Import the full dataset (~20 min)
CALL n10s.rdf.import.fetch("file:///import/hubs.bibframe.ttl", "Turtle", {commitSize: 25000});

# Create Hub index for fast lookups
CREATE INDEX hub_uri FOR (h:ns0__Hub) ON (h.uri);
```

### Graph Statistics

| Metric | Value |
|--------|-------|
| Total triples | 117.5M |
| Total nodes | 28.8M |
| Hub nodes (`ns0__Hub`) | 2.39M |
| Title nodes | 2.65M |
| Contribution nodes | 2.09M |
| LCCN nodes | 1.56M |

### Schema (n10s namespace mapping)

| Prefix | Full URI |
|--------|----------|
| `ns0` | `http://id.loc.gov/ontologies/bibframe/` |
| `ns1` | `http://id.loc.gov/ontologies/bflc/` |
| `ns2` | `http://id.loc.gov/vocabulary/mnotetype/` |
| `ns3` | `http://purl.org/dc/terms/` |

### Key Relationships Between Hubs

**Direct edges:**

| Edge Type | Count | Notes |
|-----------|-------|-------|
| `ns0__translationOf` | 470K | Hub → Hub |
| `ns0__relatedTo` | 80K | Generic relation |
| `ns0__arrangementOf` | 23K | Musical arrangements |

**Typed relationships** (via `bflc:relationship` → `bflc:Relationship` → `bflc:relation`):

138K+ typed edges using ~100+ relationship types from `http://id.loc.gov/entities/relationships/`. Top types:

| Type | Count | Tier |
|------|-------|------|
| translator | 21,330 | 5 |
| editor | 9,783 | 5 |
| inseries | 8,252 | 5 |
| containerof / containedin | 6K each | 5 |
| filmdirector | 2,744 | 5 |
| basedon | 1,652 | 3 |
| continuedby | 1,376 | 4 |
| motionpictureadaptationof | 879 | 2 |
| adaptedasmotionpicture | 568 | 2 |
| librettofor | 467 | 2 |
| derivative | 432 | 1 |
| sequel / sequelto | 273 / 318 | 3 |
| variationsbasedon | 218 | 2 |
| operaadaptationof | 214 | 2 |
| parodyof | 36 | 1 |
| inspirationfor | 33 | 1 |
| graphicnovelizationof | 41 | 1 |
| novelizationof | 19 | 1 |

### How Data is Stored

- **Hub nodes** have only a `uri` property (the UUID-based LC URI).
- **Titles**: `(Hub)-[:ns0__title]->(Title)` where Title has `ns0__mainTitle` as a string array property.
- **Contributors**: `(Hub)-[:ns0__contribution]->(Contribution)-[:ns0__agent]->(Agent)` where Agent has a `uri` pointing to `http://id.loc.gov/rwo/agents/{naf_id}`. Agent nodes do **not** have name labels in this dataset.
- **Media types**: `(Hub)-[:rdf__type]->(Resource)` — the typing node's `uri` is one of `bf:MovingImage`, `bf:Audio`, `bf:NotatedMusic`, `bf:Text`, `bf:Multimedia`, `bf:Arrangement`, `bf:NotatedMovement`.
- **LCCN**: `(Hub)-[:ns0__identifiedBy]->(Identifier)-[:rdf__type]->(Lccn)` where Identifier has `rdf__value` property.

### Useful Cypher Queries

```cypher
-- Find a Hub by title (mainTitle is stored as property on Title nodes)
MATCH (h:ns0__Hub)-[:ns0__title]->(t)
WHERE any(mt IN t.ns0__mainTitle WHERE toLower(mt) CONTAINS "pride and prejudice")
RETURN DISTINCT h.uri

-- Get all typed relationships for a Hub
MATCH (h:ns0__Hub)-[:ns1__relationship]->(rel:ns1__Relationship)-[:ns1__relation]->(rt)
WHERE h.uri = $hubUri AND NOT rt.uri STARTS WITH 'bnode'
MATCH (rel)-[:ns0__relatedTo]->(target:ns0__Hub)
OPTIONAL MATCH (target)-[:ns0__title]->(tt)
RETURN replace(rt.uri, 'http://id.loc.gov/entities/relationships/', '') AS relType,
       target.uri, tt.ns0__mainTitle[0] AS targetTitle

-- Get direct Hub→Hub edges
MATCH (h:ns0__Hub)-[r]->(target:ns0__Hub)
WHERE h.uri = $hubUri
RETURN type(r) AS edgeType, target.uri

-- Check medium type for a Hub
MATCH (h:ns0__Hub)-[:rdf__type]->(t)
WHERE h.uri = $hubUri
  AND t.uri <> 'http://id.loc.gov/ontologies/bibframe/Hub'
  AND t.uri <> 'http://id.loc.gov/ontologies/bibframe/Work'
RETURN t.uri

-- Compare authors (agent URIs) between two Hubs
MATCH (h:ns0__Hub)-[:ns0__contribution]->(c)-[:ns0__agent]->(a)
WHERE h.uri = $hubUri
RETURN DISTINCT a.uri
```

## LC suggest2 API

**Base URL**: `https://id.loc.gov/resources/hubs/suggest2`

**Status**: Intermittently available. Was down (503) during development; the Neo4j graph is the primary data source because of this.

**Parameters**: `q` (query), `searchtype` (`leftanchored` | `keyword`), `count`, `offset`

**Response**: JSON with `hits[]` containing `aLabel`, `uri`, `token`, `vLabel`, `more{marcKeys, varianttitles, rdftypes, contributors, identifiers, subjects}`.

**Label endpoint**: `https://id.loc.gov/resources/hubs/label/{encoded_AAP}` returns HTTP 302 redirect to exact Hub URI for known AAPs.

Used as fallback when a Hub can't be found in Neo4j (e.g., newly cataloged works not yet in bulk download).

## Project Structure

```
vufind-plugin/
├── README.md                          ← this file
├── composer.json                      ← PSR-4 autoloading
├── config/
│   └── BibframeHub.ini               ← plugin configuration
├── module/BibframeHub/
│   ├── Module.php                     ← Laminas module bootstrap
│   ├── config/module.config.php       ← service/plugin registration
│   └── src/BibframeHub/
│       ├── Connection/
│       │   ├── HubClient.php          ← LC suggest2 API client
│       │   └── HubClientFactory.php
│       ├── Graph/
│       │   ├── Neo4jService.php       ← Neo4j HTTP API client (needs rework)
│       │   └── Neo4jServiceFactory.php
│       ├── Related/
│       │   ├── BibframeHub.php        ← VuFind Related plugin entry point
│       │   └── BibframeHubFactory.php
│       └── Relationship/
│           └── RelationshipInferrer.php ← AAP string parsing (obsoleted by graph)
├── themes/bibframehub/
│   ├── templates/Related/BibframeHub.phtml  ← sidebar panel template
│   └── theme.config.php
├── tests/
│   ├── bootstrap.php                  ← standalone test shims
│   ├── test_poc.php                   ← API-based test script
│   └── test_surprise_scoring.py       ← surprise model prototype
└── data/
    ├── hubs.bibframe.ttl.gz           ← 570MB compressed bulk download
    └── hubs.bibframe.ttl              ← 4.3GB decompressed (105M lines)
```

## Current Status

### Done
- Full BIBFRAME Hubs dataset imported into Neo4j (2.6M Hubs, 117.5M triples)
- Graph structure explored and documented
- Surprise scoring model designed and prototyped in Python
- Validated scoring against P&P (61 connections, all interesting ones scored 74–100, translations scored 15–23)
- VuFind plugin module structure complete (first iteration)

### Needs Rework
- **Neo4jService.php**: Currently a simple cache. Must become the primary query engine using the graph's native schema (`ns0__Hub`, `ns1__Relationship`, etc.) and surprise scoring.
- **RelationshipInferrer.php**: AAP string parsing approach is obsoleted by the graph's explicit typed relationships. Replace with graph-based classification + surprise scoring.
- **BibframeHub.php** (Related plugin): Rewire to use Neo4j-first flow with LC API as fallback.
- **Hub URI resolution**: Need robust MARC → Hub URI matching via LCCN lookup, title+contributor matching in graph, or suggest2 API fallback.

### Not Started
- Port Python surprise scoring model to PHP
- Build Neo4j Cypher queries for surprise-scored results
- Integration testing with live VuFind
- Multi-hop traversal (2-hop connections like P&P → Zombies → Abraham Lincoln Vampire Hunter)

## VuFind Integration

Activate the module:
```bash
export VUFIND_LOCAL_MODULES=BibframeHub
```

In VuFind's `config.ini`:
```ini
[Record]
related[] = BibframeHub
```
