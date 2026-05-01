# VuFind BIBFRAME Hub Plugin

> **Status: Working end-to-end (May 2026).** This plugin runs against the
> 2026-04-30 LC BIBFRAME Hubs bulk dump (~2.89M Hub nodes, ~99% of LC's
> live Hub population). Earlier reconciliation tooling (graph-coverage
> sweeps, label-recovery, activity-stream crawler) has been retired now
> that the upstream snapshot is current.

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

> **Note on the graph back-end:** at runtime the plugin's *primary* data path is **live RDF/XML from `id.loc.gov`** for whichever Hub the record resolves to. Neo4j is used as a fallback (when the live RDF is empty or unreachable) and as a metadata cache for related-Hub titles, agents, and media types during scoring. The full bulk-imported graph is **not strictly required** to run the plugin against modern MARC records that carry a Hub URI in 240/130 `$1`; it becomes important for legacy records that depend on title/LCCN lookup, and for fully-populated scoring of related Hubs.

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

This is ~700MB compressed, ~5.4GB decompressed.

### 3. Configure n10s and import

Open the Neo4j browser at http://localhost:7474 and run:

```cypher
CALL n10s.graphconfig.init({handleVocabUris: "SHORTEN"});
CREATE CONSTRAINT n10s_unique_uri FOR (r:Resource) REQUIRE r.uri IS UNIQUE;
```

Import the dataset (~30–60 minutes; pass `verifyUriSyntax: false` because
the bulk file contains a handful of non-conformant URIs that would otherwise
abort the load):

```cypher
CALL n10s.rdf.import.fetch(
  "file:///import/hubs.bibframe.ttl",
  "Turtle",
  {commitSize: 25000, verifyUriSyntax: false}
);
```

### 4. Create indexes

```cypher
CREATE INDEX hub_uri FOR (h:ns0__Hub) ON (h.uri);
CREATE FULLTEXT INDEX hub_title_ft FOR (t:ns0__Title) ON EACH [t.ns0__mainTitle];
```

The loaded graph from the 2026-04-30 LC bulk export contains
**~2.89M Hub nodes**, ~37M total nodes, ~69M relationships,
and ~152M triples — close to LC's reported live Hub population
(~2.93M, ~99% coverage).

## Graph Back-End

The Neo4j graph is a *cache* layered on top of `id.loc.gov`, not a source of
truth. At runtime the plugin's primary data path is live RDF from
`id.loc.gov/{hubUri}.rdf`; Neo4j is consulted for:

- Legacy MARC title/LCCN → Hub URI resolution
- Related-Hub metadata enrichment (titles, agents, media types) during scoring
- Relationship-type frequency statistics for the rarity bonus (24h cached)
- Fallback relationship traversal when live RDF is empty/unreachable

The LC bulk dump is published periodically; refresh by re-running the import
above and clearing the plugin's caches
(`bibframehub_rel_frequencies.json`, `bibframehub_empty_rdf.json`,
`bibframehub_uri_validation.json` in the VuFind `local/cache/` directory).

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
4. **Neo4j fallback**: If RDF is unavailable for all candidate URIs, query the graph using the reified relation pattern `(:Hub)-[:relation]->(:Relation)-[:associatedResource]->(:Hub)` (traversed both directions; relationship type read from the `bf:Relation`'s `bf:relationship` URI).
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

See [Neo4j Setup](#neo4j-setup) above for the full bootstrap. Quick recap:

```bash
docker run -d --name neo4j-hubs \
  -p 7474:7474 -p 7687:7687 \
  -e NEO4J_AUTH=neo4j/bibframe123 \
  -e NEO4J_PLUGINS='["n10s"]' \
  -v $(pwd)/data:/import \
  neo4j:5.26
```

```cypher
CALL n10s.graphconfig.init({handleVocabUris: "SHORTEN"});
CREATE CONSTRAINT n10s_unique_uri FOR (r:Resource) REQUIRE r.uri IS UNIQUE;
CALL n10s.rdf.import.fetch(
  "file:///import/hubs.bibframe.ttl", "Turtle",
  {commitSize: 25000, verifyUriSyntax: false}
);
CREATE INDEX hub_uri FOR (h:ns0__Hub) ON (h.uri);
CREATE FULLTEXT INDEX hub_title_ft FOR (t:ns0__Title) ON EACH [t.ns0__mainTitle];
```

### Graph Statistics (2026-04-30 LC bulk dump)

| Metric | Value |
|--------|-------|
| Total triples | ~152M |
| Total nodes | ~37.3M |
| Total relationships | ~69.1M |
| Hub nodes (`ns0__Hub`) | ~2.89M |
| `bf:Relation` reification nodes | ~548K |
| Hub→Hub relations (typed via `bf:Relation`) | ~533K (`associatedResource` targeting Hubs) |

### Schema (n10s namespace mapping)

| Prefix | Full URI |
|--------|----------|
| `ns0` | `http://id.loc.gov/ontologies/bibframe/` |
| `ns1` | `http://id.loc.gov/ontologies/bflc/` |
| `ns3` | `http://purl.org/dc/terms/` |

### How relationships are modeled

The 2026-04-30 bulk dump uses a uniform reified pattern — there are
**no direct `bf:translationOf`/`bf:relatedTo`/`bf:arrangementOf` edges
between Hubs**, and the older `bflc:Relationship` chain is gone too.
Every relationship is a `bf:Relation` blank node:

```
(source:Hub)-[:ns0__relation]->(:ns0__Relation)-[:ns0__associatedResource]->(target:Hub)
                                          \-[:ns0__relationship]->(rt:Resource)
```

The relation is recorded only on the source side, so the plugin
traverses both outbound and inbound to discover all neighbors.

Relationship-type URIs come in two prefix flavors which the plugin
strips uniformly:

- `http://id.loc.gov/vocabulary/relationship/{type}` (~548K, the bulk of the dump)
- `http://id.loc.gov/entities/relationships/{type}` (~60K, the older typed set)

A small population of bnode-typed relations (~22K) is skipped during
scoring.

### Top relationship types

Sampled from the live aggregation cached in
`bibframehub_rel_frequencies.json` after first run.
Distribution shifts somewhat from the previous snapshot — `relatedwork`
and `translationof` dominate, with cross-medium adaptations
(`operaadaptationof`, `motionpictureadaptationof`, `televisionadaptationof`)
and creative transformations (`inspirationfor`, `parodyof`, `derivative`)
in the long tail that drives the surprise score.

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

-- Get all Hub→Hub relations (outbound) for a Hub, with type
MATCH (h:ns0__Hub {uri: $hubUri})-[:ns0__relation]->(rel:ns0__Relation)
  -[:ns0__associatedResource]->(target:ns0__Hub)
OPTIONAL MATCH (rel)-[:ns0__relationship]->(rt)
OPTIONAL MATCH (target)-[:ns0__title]->(tt)
RETURN replace(replace(coalesce(rt.uri, "related"),
         "http://id.loc.gov/vocabulary/relationship/", ""),
         "http://id.loc.gov/entities/relationships/", "") AS relType,
       target.uri, tt.ns0__mainTitle[0] AS targetTitle

-- Inbound relations (the same Hub seen from neighbors that point to it)
MATCH (h:ns0__Hub {uri: $hubUri})<-[:ns0__associatedResource]-(rel:ns0__Relation)
  <-[:ns0__relation]-(source:ns0__Hub)
OPTIONAL MATCH (rel)-[:ns0__relationship]->(rt)
RETURN replace(replace(coalesce(rt.uri, "related"),
         "http://id.loc.gov/vocabulary/relationship/", ""),
         "http://id.loc.gov/entities/relationships/", "") AS relType,
       source.uri
```

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
└── tests/                             ← scoring tests
```

## Refreshing the Graph

The LC bulk dump is published periodically. To refresh:

1. Re-download `hubs.bibframe.ttl.gz` and decompress.
2. Stop the existing `neo4j-hubs` container, delete its volume, re-create it.
3. Re-run the n10s init + import + index commands from [Neo4j Setup](#neo4j-setup).
4. Clear the plugin caches inside the VuFind container:
   `rm /vufind-local/cache/bibframehub_*.json`.

No separate reconciliation step is required — LC's published snapshot now
tracks live `id.loc.gov` closely (~99% coverage as of the 2026-04-30 dump),
so stale-URI handling is no longer a runtime concern.

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

- **758 Work→Hub traversal**: Use 758 Work URIs to discover Hubs via HEAD request (`x-preflabel` header) → label endpoint (302 redirect). A second fast lane for records that have 758 but not 240 `$1`.
- **Authority-based author scoring**: Use 100/700 `$0`/`$1` Name Authority URIs for precise author-distance calculations without Neo4j agent lookups.
- **Broader identifier-based Hub lookup**: ISBNs and other identifiers (not just title/LCCN) for more reliable MARC-to-Hub resolution.
- **Collapsible tree UI refinement**: Verify and improve the indented tree display — ensure expand/collapse, caret rotation, and tier-based default states work correctly across browsers.

## Acknowledgments

- **Library of Congress** for the BIBFRAME ontology, the Hubs dataset, and the `id.loc.gov` Linked Data Service that this plugin depends on end-to-end.
- **Heng, Kudeki, Lampron, and Han (2026)** for the [openly published Hamlet/Concerto/Local reconciliation corpus](https://doi.org/10.13012/B2IDB-1613787_V1) and the empirical baseline in *[Managing BIBFRAME Work and Hub Entities at Scale](https://doi.org/10.1080/01639374.2026.2655113)*. See [docs/related-work-heng-et-al.md](docs/related-work-heng-et-al.md) for how this plugin's architecture relates to and builds on that work.
- **VuFind community** for the `\VuFind\Related\RelatedInterface` extension point and the broader plugin architecture that makes this kind of sidebar enrichment a small, well-bounded change.
- **GitHub Copilot** (Claude Sonnet / Opus models) was used extensively as a pair-programming and exploratory-research assistant throughout this project — for codebase navigation, RDF/Cypher experimentation, surprise-scoring iteration, reconciliation-tool design, and documentation drafting. All architectural decisions, data-model choices, and final code are the author's responsibility, but the velocity and scope of the work would not have been the same without it.

## License

This project is open source. BIBFRAME Hub data is from the Library of Congress Linked Data Service.
