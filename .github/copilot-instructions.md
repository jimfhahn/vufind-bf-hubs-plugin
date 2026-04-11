---
applyTo: "**"
---

# BIBFRAME Hub VuFind Plugin — Copilot Context

## What This Project Is

A VuFind plugin module (`BibframeHub`) that shows **related works** in the record sidebar by querying BIBFRAME Hub relationships. The guiding design principle: **surface surprising, non-obvious connections** rather than predictable ones like translations or series membership.

## Current Status (Working End-to-End)

The plugin is **fully operational** in Docker. VuFind at `http://localhost:4567/vufind/` with test records (P&P, Hamlet, Gatsby, Palinuro) displaying scored related works in the record sidebar.

### Data Flow
1. MARC record → extract title/author/LCCN + Modern MARC identifiers (240/130 `$1`, 758)
2. Resolve Hub URI via priority cascade:
   - **Fast lane**: Direct Hub URI from 240/130 `$0`/`$1` subfield (Modern MARC)
   - **Fast lane**: Self-hub URI from 758 fields
   - **Legacy**: LCCN → Neo4j → title → Neo4j → title → LC suggest2 API
3. **RDF-first**: Fetch live RDF/XML from `id.loc.gov/{hubUri}.rdf` → parse relationships
4. **Neo4j fallback**: Query graph if RDF unavailable
5. Score all relationships via 5-tier surprise model
6. Validate displayed URIs via cached HEAD checks
7. Render grouped results in sidebar template

## Key Technical Facts

### VuFind Plugin Architecture
- Laminas MVC framework. Modules in `module/{Name}/`.
- Related record plugins implement `\VuFind\Related\RelatedInterface` with `init($settings, $driver)` and `getResults()`.
- Registered via `module.config.php` under `vufind` → `plugin_managers` → `related`.
- Templates in `themes/{name}/templates/Related/`.

### id.loc.gov RDF (Primary Data Source)
- Fetch hub RDF/XML: `https://id.loc.gov/resources/hubs/{uuid}.rdf`
- **Much richer than bulk TTL**: P&P hub → 137 `bf:relation` elements → 47 unique related hubs
- Relationship types: URI-based (`entities/relationships/adaptedas`) and inline labels (`"Dramatized as (work)"`)
- Inline labels mapped to scoring slugs via `INLINE_LABEL_MAP` constant in `HubRdfParser.php`
- Agents/media extracted from direct children only (`./bf:contribution`, `./rdf:type`) to avoid nested hub contamination
- Hub URIs in bulk TTL dataset can be stale (404s) — live RDF always has current URIs

### Neo4j Graph (Fallback Data Source)
- **Neo4j 5.26** with **n10s 5.26.0** plugin at `localhost:7474` (HTTP) / `localhost:7687` (Bolt).
- Auth: `neo4j/bibframe123`.
- **2.39M Hub nodes**, 28.8M total nodes, 117.5M triples from LC bulk download.
- n10s SHORTEN mode: `ns0` = `bf:` (bibframe), `ns1` = `bflc:`, `ns3` = `dcterms:`.
- Hub nodes have only `uri` property (UUID-based LC URI).
- Titles: `(Hub)-[:ns0__title]->(bnode)` where bnode has `ns0__mainTitle` as string array.
- Agents: `(Hub)-[:ns0__contribution]->(bnode)-[:ns0__agent]->(Resource{uri})` — no name labels, just URIs.
- Media types: `(Hub)-[:rdf__type]->(Resource)` with URIs like `bf:MovingImage`, `bf:Audio`, `bf:NotatedMusic`.
- **Direct edges**: `ns0__translationOf` (470K), `ns0__relatedTo` (80K), `ns0__arrangementOf` (23K).
- **Typed relationships**: `(Hub)-[:ns1__relationship]->(ns1__Relationship)-[:ns1__relation]->(Resource)` → typed URI from `http://id.loc.gov/entities/relationships/`. 138K+ edges, ~100 types.
- Indexes:
  - `CREATE INDEX hub_uri FOR (h:ns0__Hub) ON (h.uri)` — fast URI lookups.
  - `CREATE FULLTEXT INDEX hub_title_ft FOR (t:ns0__Title) ON EACH [t.ns0__mainTitle]` — title search.
- Hub disambiguation: count bidirectional relationships `(h)-[r]-(other)` — canonical hubs are primarily *targets*.

### Surprise Scoring Model
Score = base_tier + rarity_bonus + author_distance + medium_crossing (0–100 scale).

- **Tier 1** (base 90): Creative transformations — `inspirationfor`, `parodyof`, `derivative`, `graphicnovelizationof`, `critiqueof`.
- **Tier 2** (base 75): Cross-medium adaptations — `adaptedasmotionpicture`, `operaadaptationof`, `variationsbasedon`, `dramatizationof`.
- **Tier 3** (base 55): Narrative continuations — `sequel`, `prequel`, `basedon`, `adaptedas`, `remakeof`.
- **Tier 4** (base 30): Serial/structural — `continuedby`, `expandedversionof`, `revisionof`, `supplementto`.
- **Tier 5** (base 10): Predictable — `translator`, `translationof`, `editor`, `inseries`, `containerof`.
- **Rarity**: `1 - log10(freq)/log10(max_freq)` scaled to 0–10 points.
- **Author distance**: Different author = +15, partial overlap = +8, same = +0.
- **Medium crossing**: Different media types = +10, source generic but target specific = +8.

### LC suggest2 API (Hub Resolution)
- URL: `https://id.loc.gov/resources/hubs/suggest2?q=...&searchtype=keyword&count=20`.
- Intermittently available (503s common).
- Label endpoint: `https://id.loc.gov/resources/hubs/label/{encoded_AAP}` → 302 redirect to Hub URI.

## Source Files

### Plugin Core
- **`BibframeHub.php`** (Related plugin): Orchestrates hub resolution → RDF fetch → Neo4j fallback → scoring → URI validation. Constructor takes `HubClient`, `Neo4jService`, `HubRdfParser`, `RelationshipInferrer`, `config[]`. Modern MARC methods: `getFirstHubUriFromField()` (240/130 `$0`/`$1`), `extract758Relations()`, `isHubResourceUri()`.
- **`BibframeHubFactory.php`**: Laminas factory, reads `BibframeHub.ini`, creates all dependencies.
- **`HubRdfParser.php`**: Fetches `{hubUri}.rdf` from id.loc.gov, parses with DOMXPath. Extracts typed relationships, agents, media types. `INLINE_LABEL_MAP` maps ~35 inline labels to scoring slugs.
- **`Neo4jService.php`**: Read-only Cypher queries against n10s graph via HTTP API. Methods: `findHubByTitle`, `findHubByLccn`, `getHubTitle`, `getHubAgents`, `getHubMediaTypes`, `findRelatedHubs`, `getRelationshipTypeFrequencies`.
- **`RelationshipInferrer.php`**: 5-tier surprise scoring with `computeSurprise()`, `scoreRelatedHubs()`, `getTier()`, `humanLabel()`.
- **`HubClient.php`**: LC suggest2 API client for hub lookup. Fallback for newly cataloged works.

### Config
- **`config/BibframeHub.ini`**: `[Connection]` (LC API), `[Neo4j]` (graph DB), `[Display]` (URI validation, max results).
- **`docker/local/config/vufind/config.ini`**: Full VuFind config with MariaDB, bibframehub theme, `related[] = "BibframeHub"`.

### Template
- **`themes/bibframehub/templates/Related/BibframeHub.phtml`**: Renders scored results as collapsible tree grouped by relationship type (via `getGroupedResults()`), with badges for media and different author. Tier 1–2 groups expanded by default. Theme extends `bootstrap5`.

### Tests
- `tests/test_surprise_scoring.py` — Python prototype of scoring model.
- `tests/test_neo4j_scoring.php` — PHP end-to-end test against live Neo4j.

## Docker Setup

```bash
cd vufind-plugin && docker compose up --build
# VuFind: http://localhost:4567/vufind/
# Solr:   http://localhost:8983
# Test records: test-pandp-001, test-hamlet-001, test-gatsby-001, test-palinuro-001
```

- **2 services**: `vufind` (PHP 8.3-apache + embedded Solr) and `db` (MariaDB 11)
- Ports: 4567→80 (web), 8983→8983 (Solr)
- Plugin module + theme volume-mounted for live code editing
- Entrypoint auto-creates DB tables, loads test MARC records into Solr, registers BibframeHub autoloader
- Neo4j accessed via `host.docker.internal` (runs on host, not in compose)

### Modern MARC Support
- 240/130 `$1` (Real World Object URI) carries direct Hub URIs — primary fast lane
- 758 `$1` carries Work/Instance URIs (not Hub URIs) — used for self-hub detection
- LC uses `$1` not `$0` for Hub URIs; plugin checks both subfields
- Fast lane skips Neo4j for hub resolution but still uses it for scoring (agent/media lookups on target hubs)
- See `docs/modern-marc-hub-discovery.md` for detailed findings

## Coding Conventions
- PHP 8.1+, PSR-4 autoloading via Composer.
- Laminas service factories for dependency injection.
- Neo4j HTTP API (not Bolt) to minimize PHP dependencies. cURL timeout 30s.
- Cypher queries parameterized via `$params` (never interpolate user input).
- Lucene special characters escaped in full-text queries via `escapeLucene()`.
- Prefer lean implementations — no over-abstraction or premature optimization.

## VuFind Integration
- Module loaded via `VUFIND_LOCAL_MODULES=BibframeHub` env var.
- In Docker: volume-mounted into `/usr/local/vufind/module/BibframeHub`.
- Locally: symlinked into VuFind's `module/` and `themes/` directories.
- Config `BibframeHub.ini` goes in VuFind's `local/config/vufind/`.
- Activated in config.ini: `[Record]` → `related[] = "BibframeHub"`.
- Template resolved as `Related/BibframeHub.phtml` via `ClassBasedTemplateRendererTrait`.
