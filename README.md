# VuFind BIBFRAME Hub Plugin

A VuFind plugin that surfaces **surprising, non-obvious work relationships** using Library of Congress BIBFRAME Hubs. Instead of showing the predictable (translations, series membership), the plugin prioritizes creative transformations, cross-medium adaptations, and unexpected connections between works.

## Quick Start (Docker)

The fastest way to see the plugin in action. Requires Docker and a running Neo4j instance with the BIBFRAME Hubs dataset (see [Neo4j Setup](#neo4j-setup) below).

```bash
git clone https://github.com/jimfhahn/vufind-bf-hubs-plugin.git
cd vufind-bf-hubs-plugin
docker compose up --build
```

VuFind will be available at **http://localhost:4567/vufind/**. Four test records are loaded automatically:
- [Pride and Prejudice](http://localhost:4567/vufind/Record/test-pandp-001) — 5 relationship groups
- [Hamlet](http://localhost:4567/vufind/Record/test-hamlet-001) — 2 relationship groups
- [The Great Gatsby](http://localhost:4567/vufind/Record/test-gatsby-001) — 4 relationship groups
- [Palinuro of Mexico](http://localhost:4567/vufind/Record/test-palinuro-001) — Modern MARC fast lane (Hub URI from 240 `$1`)

The Docker setup includes VuFind (PHP 8.3 + Apache), MariaDB, and embedded Solr. Neo4j runs on the host and is accessed via `host.docker.internal`.

## Installing into an Existing VuFind

### Prerequisites

- VuFind 10.x or 11.x
- Neo4j 5.x with the [n10s](https://neo4j.com/labs/neosemantics/) plugin and the BIBFRAME Hubs dataset loaded (see [Neo4j Setup](#neo4j-setup))
- PHP 8.1+

### 1. Clone the plugin

```bash
cd /path/to/your/
git clone https://github.com/jimfhahn/vufind-bf-hubs-plugin.git
```

### 2. Symlink into VuFind

```bash
ln -s /path/to/your/vufind-bf-hubs-plugin/module/BibframeHub /path/to/vufind/module/BibframeHub
ln -s /path/to/your/vufind-bf-hubs-plugin/themes/bibframehub /path/to/vufind/themes/bibframehub
```

### 3. Register the autoloader

Add to your VuFind's `composer.local.json` (create it if it doesn't exist):

```json
{
    "autoload": {
        "psr-4": {
            "BibframeHub\\": "module/BibframeHub/src/BibframeHub/"
        },
        "classmap": [
            "module/BibframeHub/Module.php"
        ]
    }
}
```

Then regenerate the autoloader:

```bash
cd /path/to/vufind
composer dump-autoload
```

### 4. Copy the plugin config

```bash
cp /path/to/your/vufind-bf-hubs-plugin/config/BibframeHub.ini /path/to/vufind/local/config/vufind/BibframeHub.ini
```

Edit `BibframeHub.ini` and set your Neo4j connection details:

```ini
[Neo4j]
enabled = true
uri = "bolt://localhost:7687"
username = "neo4j"
password = "your_neo4j_password"
database = "neo4j"
```

### 5. Activate the module and theme

Set the environment variable when starting VuFind:

```bash
export VUFIND_LOCAL_MODULES=BibframeHub
```

In your VuFind `local/config/vufind/config.ini`, set the theme and activate the related plugin:

```ini
[Site]
theme = bibframehub

[Record]
related[] = BibframeHub
```

> **Note:** The `bibframehub` theme extends `bootstrap5`. If you use a custom theme, you can either extend `bibframehub` instead, or copy `themes/bibframehub/templates/Related/BibframeHub.phtml` into your theme's `templates/Related/` directory and use your existing theme name.

### 6. Start VuFind

```bash
# If using the built-in PHP server:
VUFIND_HOME=/path/to/vufind VUFIND_LOCAL_DIR=/path/to/vufind/local \
  VUFIND_LOCAL_MODULES=BibframeHub php -S localhost:8080 -t public/

# Or with Apache, set VUFIND_LOCAL_MODULES in your Apache config or .env
```

The "Related Works" panel will appear in the sidebar of any record page where a matching BIBFRAME Hub is found.

## Neo4j Setup

The plugin requires a Neo4j graph database loaded with the LC BIBFRAME Hubs dataset.

### 1. Start Neo4j with n10s

```bash
docker run -d --name neo4j-hubs \
  -p 7474:7474 -p 7687:7687 \
  -e NEO4J_AUTH=neo4j/your_password \
  -e NEO4J_PLUGINS='["n10s"]' \
  -v $(pwd)/data:/import \
  neo4j:5.26
```

### 2. Download the BIBFRAME Hubs dataset

```bash
mkdir -p data
curl -L -o data/hubs.bibframe.ttl.gz \
  "https://id.loc.gov/download/resources/hubs.bibframe.ttl.gz"
gunzip data/hubs.bibframe.ttl.gz
```

This is ~570MB compressed, ~4.3GB decompressed.

### 3. Configure n10s and import

Open the Neo4j browser at http://localhost:7474 and run:

```cypher
CALL n10s.graphconfig.init({handleVocabUris: "SHORTEN"});
CREATE CONSTRAINT n10s_unique_uri FOR (r:Resource) REQUIRE r.uri IS UNIQUE;
```

Import the dataset (~20 minutes):

```cypher
CALL n10s.rdf.import.fetch("file:///import/hubs.bibframe.ttl", "Turtle", {commitSize: 25000});
```

### 4. Create indexes

```cypher
CREATE INDEX hub_uri FOR (h:ns0__Hub) ON (h.uri);
CREATE FULLTEXT INDEX hub_title_ft FOR (t:ns0__Title) ON EACH [t.ns0__mainTitle];
```

The loaded graph contains **2.39M Hub nodes**, 28.8M total nodes, and 117.5M triples.

## Design Principle

> Surface surprising, non-obvious connections rather than predictable ones like translations or series membership.

A patron viewing *Pride and Prejudice* should see *Death Comes to Pemberley* (P.D. James derivative), the 2005 Keira Knightley film, *Pride and Prejudice and Zombies* (parody), and *Bride & Prejudice* (Bollywood adaptation) — rather than drowning in 30+ translations and a series container.

## How It Works

### Data Flow

1. **MARC → Hub URI**: Resolve the record to a BIBFRAME Hub URI using a priority cascade:
   - **Modern MARC fast lane**: Direct Hub URI from 240/130 `$1` subfield (instant, no external lookups)
   - **758 self-hub**: Hub URI from 758 fields with "Expression of" relationship
   - **Legacy**: LCCN → Neo4j lookup → title search → LC suggest2 API
2. **RDF-first**: Fetch live RDF/XML from `id.loc.gov/{hubUri}.rdf` → parse typed relationships.
3. **Base-work recovery**: If the resolved Hub has no relationships (e.g. suggest2 returned a collected-works edition), strip AAP qualifiers and look up the canonical base work via the label endpoint.
4. **Neo4j fallback**: If RDF is unavailable for all candidate URIs, query the graph for all Hub-to-Hub relationships (direct edges + typed `bflc:relationship` links).
5. **Surprise scoring**: Score each related Hub on a 0–100 scale using four signals.
6. **Display**: Render grouped results in a collapsible tree in the record sidebar.

### Modern MARC Support

As of LC's **Modern MARC** initiative (March 2026), BIBFRAME identifiers are embedded directly in MARC records. The plugin exploits these for faster, more reliable Hub resolution:

| MARC Field | Subfield | Content | Plugin Use |
|------------|----------|---------|-----------|
| 240/130 | `$1` | Hub URI (`id.loc.gov/resources/hubs/...`) | **Primary fast lane** — direct Hub resolution, no API calls |
| 758 | `$1` | Work/Instance URI (`id.loc.gov/resources/works/...`) | Self-hub detection; work→hub traversal planned |
| 758 | `$4` | Relationship type URI | Relationship type identification |
| 100/700 | `$0`/`$1` | Name Authority / RWO URI | Future: precise author-distance scoring |

> **Key finding**: LC uses `$1` (Real World Object URI) rather than `$0` for Hub URIs in 240/130. The plugin checks both subfields.

See [docs/modern-marc-hub-discovery.md](docs/modern-marc-hub-discovery.md) for detailed findings and implementation notes.

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

**Parameters**: `q` (query), `searchtype` (`leftanchored` | `keyword`), `count`, `offset`

**Response**: JSON with `hits[]` containing `aLabel`, `uri`, `token`, `vLabel`, `more{marcKeys, varianttitles, rdftypes, contributors, identifiers, subjects}`.

**Label endpoint**: `https://id.loc.gov/resources/hubs/label/{encoded_AAP}` returns HTTP 302 redirect to exact Hub URI for known AAPs.

Used as fallback when a Hub can't be found in Neo4j (e.g., newly cataloged works not yet in bulk download).

## Project Structure

```
vufind-bf-hubs-plugin/
├── config/
│   └── BibframeHub.ini               ← plugin configuration (copy to VuFind local/)
├── module/BibframeHub/
│   ├── Module.php                     ← Laminas module bootstrap
│   ├── config/module.config.php       ← service/plugin registration
│   └── src/BibframeHub/
│       ├── Connection/
│       │   ├── HubClient.php          ← LC suggest2/label API client + base-work recovery
│       │   └── HubClientFactory.php
│       ├── Graph/
│       │   ├── HubRdfParser.php       ← id.loc.gov RDF/XML fetcher + parser
│       │   ├── Neo4jService.php       ← Neo4j HTTP API client (graph queries)
│       │   └── Neo4jServiceFactory.php
│       ├── Related/
│       │   ├── BibframeHub.php        ← VuFind Related plugin (orchestrator)
│       │   └── BibframeHubFactory.php
│       └── Relationship/
│           └── RelationshipInferrer.php ← 5-tier surprise scoring engine
├── themes/bibframehub/
│   ├── templates/Related/BibframeHub.phtml  ← sidebar panel template
│   └── theme.config.php
├── docs/
│   └── modern-marc-hub-discovery.md   ← Modern MARC field analysis + findings
├── docker-compose.yml                 ← Docker dev environment
├── docker/                            ← Dockerfile + entrypoint + config overrides
├── tools/
│   └── reconcile/                     ← Hub URI reconciliation against id.loc.gov
└── tests/                             ← scoring tests
```

## Hub Reconciliation

The bulk-loaded BIBFRAME TTL snapshot drifts from `id.loc.gov` over time:
URIs get re-minted, hubs get merged, edges go stale. Records that resolve to
those legacy URIs would render zero results without intervention.

[`tools/reconcile/`](tools/reconcile/README.md) provides a Python script that:

1. HEAD-checks `(:ns0__Hub)` URIs against `id.loc.gov` (live → redirect → gone).
2. For `gone` Hubs, calls the LC label endpoint with `"{agent}. {title}"`
   candidates derived from the graph itself.
3. Verifies any recovered canonical URI actually carries `bf:relation` data
   before accepting it.
4. Writes `upstream_status` / `canonical_uri` / `last_verified` back to each Hub.

The plugin reads those properties at query time and substitutes the
canonical URI in display links. Records whose original Hub URI is
confirmed dead with no recovery are dropped silently rather than rendered
as un-clickable text.

Empirical recovery rate on the demo records: **~75 %** of stale Hub URIs
resolve to a current canonical with verified relationships.

## Configuration Reference

`BibframeHub.ini`:

```ini
[Connection]
baseUrl = "https://id.loc.gov"     ; LC Linked Data Service
userAgent = "VuFind-BibframeHub/1.0"
timeout = 10

[Neo4j]
enabled = true                      ; Set to false to disable graph queries
uri = "bolt://localhost:7687"       ; Neo4j connection (HTTP 7474 or Bolt 7687)
username = "neo4j"
password = "your_password"
database = "neo4j"

[Display]
validateUris = true                 ; HEAD-check URIs before displaying links
validationCacheTtl = 86400          ; Cache validation results for 24 hours
maxDisplayResults = 15              ; Max related works to show
```

## Roadmap

- **758 Work→Hub traversal**: Use 758 Work URIs to discover Hubs via HEAD request (`x-preflabel` header) → label endpoint (302 redirect). This would provide a second fast lane for records that have 758 but not 240 `$1`.
- **Authority-based author scoring**: Use 100/700 `$0`/`$1` Name Authority URIs for precise author-distance calculations without Neo4j agent lookups.
- **Refresh Neo4j with current Hubs data**: Automate periodic re-import of the LC bulk Hubs dataset to keep URIs current and reduce reliance on the RDF fallback path.
- **Broader identifier-based Hub lookup**: Use ISBNs and other identifiers (not just title/LCCN) for more reliable MARC-to-Hub resolution.
- **Collapsible tree UI refinement**: Verify and improve the indented tree display — ensure expand/collapse, caret rotation, and tier-based default states work correctly across browsers.

## License

This project is open source. BIBFRAME Hub data is from the Library of Congress Linked Data Service.
