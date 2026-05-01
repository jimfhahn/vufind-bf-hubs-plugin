---
applyTo: "**"
---

# BIBFRAME Hub VuFind Plugin — Copilot Context

## What This Project Is

A VuFind plugin module (`BibframeHub`) that shows **related works** in the record sidebar by querying BIBFRAME Hub relationships. The guiding design principle: **surface surprising, non-obvious connections** rather than predictable ones like translations or series membership.

## Current Status (Working End-to-End)

The plugin is **fully operational** in Docker. VuFind at `http://localhost:4567/vufind/` with test records (P&P, Hamlet, Gatsby, Palinuro) displaying scored related works in the record sidebar.

Graph back-end is the 2026-04-30 LC BIBFRAME Hubs bulk dump loaded into the `neo4j-hubs-new` container (port 7475/7688). Earlier reconciliation tooling (`tools/reconcile/`, `tools/coverage/`, label-recovery, activity-stream crawler, `upstream_status`/`canonical_uri` runtime substitution) has been retired — the upstream snapshot now covers ~99% of live Hubs and the schema changed to a uniform reified pattern that obsoleted the old direct-edge handling.

### Data Flow
1. MARC record → extract title/author/LCCN + Modern MARC identifiers (240/130 `$1`, 758)
2. Resolve Hub URI via priority cascade:
   - **Fast lane**: Direct Hub URI from 240/130 `$0`/`$1` subfield (Modern MARC)
   - **Fast lane**: Self-hub URI from 758 fields
   - **Legacy**: LCCN → Neo4j → title → Neo4j → title → LC suggest2 API
3. **RDF-first**: Fetch live RDF/XML from `id.loc.gov/{hubUri}.rdf` → parse relationships
4. **Base-work recovery**: If the resolved Hub has no relationships (e.g. suggest2 returned a collected-works edition), strip AAP qualifiers → label endpoint → canonical base work hub
5. **Neo4j fallback**: Query graph if RDF unavailable for all candidate URIs
6. Score all relationships via 5-tier surprise model
7. Validate displayed URIs via cached HEAD checks
8. Render grouped results in sidebar template

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
- **Neo4j 5.26** with **n10s 5.26.0** plugin at `localhost:7475` (HTTP) / `localhost:7688` (Bolt).
- Runs as standalone Docker container `neo4j-hubs-new` (NOT part of `docker-compose.yml`). Check `docker ps` for it before assuming Neo4j is down.
- Auth: `neo4j/bibframe123`.
- **~2.89M Hub nodes**, ~37.3M total nodes, ~152M triples from the 2026-04-30 LC bulk export. id.loc.gov reports ~2.93M live Hubs (~99% coverage); reconciliation tooling has been retired as no longer necessary.
- n10s SHORTEN mode: `ns0` = `bf:` (bibframe), `ns1` = `bflc:`, `ns3` = `dcterms:`.
- Hub nodes have only `uri` property (UUID-based LC URI).
- Titles: `(Hub)-[:ns0__title]->(bnode)` where bnode has `ns0__mainTitle` as string array.
- Agents: `(Hub)-[:ns0__contribution]->(bnode)-[:ns0__agent]->(Resource{uri})` — no name labels, just URIs.
- Media types: `(Hub)-[:rdf__type]->(Resource)` with URIs like `bf:MovingImage`, `bf:Audio`, `bf:NotatedMusic`.
- **Hub→Hub relations are uniformly reified** via `bf:Relation` blank nodes. There are NO direct `bf:translationOf`/`bf:relatedTo`/`bf:arrangementOf` edges and NO `bflc:Relationship` chain. Pattern:
  ```
  (:ns0__Hub)-[:ns0__relation]->(:ns0__Relation)-[:ns0__associatedResource]->(:ns0__Hub)
  (:ns0__Relation)-[:ns0__relationship]->(rt:Resource)   // typed via rt.uri
  ```
  The relation is stored only on the source side, so `findRelatedHubs` traverses both outbound and inbound.
- Relationship-type URI prefixes are mixed: `http://id.loc.gov/vocabulary/relationship/{type}` (~548K) and `http://id.loc.gov/entities/relationships/{type}` (~60K). The plugin strips both with chained `replace()`. ~22K bnode-typed relations are skipped.
- Indexes:
  - `CREATE INDEX hub_uri FOR (h:ns0__Hub) ON (h.uri)` — fast URI lookups.
  - `CREATE FULLTEXT INDEX hub_title_ft FOR (t:ns0__Title) ON EACH [t.ns0__mainTitle]` — title search.
- Hub disambiguation: count bidirectional relationships `(h)-[r]-(other)` — canonical hubs are primarily *targets*.
- Import gotcha: pass `verifyUriSyntax: false` to `n10s.rdf.import.fetch` — the bulk file contains a handful of non-conformant URIs that abort the load otherwise. See `docs/neo4j-graph-topology.md`.

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
- Label endpoint: `https://id.loc.gov/resources/hubs/label/{encoded_AAP}` → 302 redirect to Hub URI.

## Source Files

### Plugin Core
- **`BibframeHub.php`** (Related plugin): Orchestrates hub resolution → RDF fetch → Neo4j fallback → scoring → URI validation. Constructor takes `HubClient`, `Neo4jService`, `HubRdfParser`, `RelationshipInferrer`, `config[]`. Modern MARC methods: `getFirstHubUriFromField()` (240/130 `$0`/`$1`), `extract758Relations()`, `isHubResourceUri()`.
- **`BibframeHubFactory.php`**: Laminas factory, reads `BibframeHub.ini`, creates all dependencies.
- **`HubRdfParser.php`**: Fetches `{hubUri}.rdf` from id.loc.gov, parses with DOMXPath. Extracts typed relationships, agents, media types. `INLINE_LABEL_MAP` maps ~35 inline labels to scoring slugs.
- **`Neo4jService.php`**: Read-only Cypher queries against n10s graph via HTTP API. Methods: `findHubByTitle`, `findHubByLccn`, `getHubTitle`, `getHubAgents`, `getHubMediaTypes`, `findRelatedHubs` (uses the new `bf:relation`/`bf:Relation`/`bf:associatedResource`/`bf:relationship` reified pattern, traversed bidirectionally, with both `vocabulary/relationship/` and `entities/relationships/` URI prefixes stripped), `getRelationshipTypeFrequencies`, **`getHubsBulk(array $hubUris)`** (single-query batch prefetch of title/agents/media for many Hubs — always use this when scoring >1 related Hub).
- **`RelationshipInferrer.php`**: 5-tier surprise scoring with `computeSurprise()`, `scoreRelatedHubs()`, `getTier()`, `humanLabel()`.
- **`HubClient.php`**: LC suggest2 API client for hub lookup. Fallback for newly cataloged works. `resolveBaseWorkUri()` strips AAP qualifiers and uses the label endpoint to find the canonical base work hub. `lookupByLabel()` preserves the 302 redirect URI (not the suggest2 enrichment URI).

## Performance Contracts (do not regress)

The plugin must stay under ~0.5s warm per record page. Three caches make that possible; removing or weakening any of them re-introduces the 10–30s hangs we measured in April 2026:

1. **Bulk Neo4j enrichment**: both `fetchAndScoreViaRdf` and `fetchAndScoreViaNeo4j` call `Neo4jService::getHubsBulk()` once, then serve the scorer's per-Hub callbacks from the resulting map. Do **not** reintroduce per-URI `getHubTitle` / `getHubAgents` / `getHubMediaTypes` calls inside the scoring closures.
2. **Relationship frequency cache**: `getRelationshipTypeFrequencies()` aggregates over 138K+ edges (~2s). Result is persisted to `/vufind-local/cache/bibframehub_rel_frequencies.json` with 24h TTL, wired via `Neo4jServiceFactory`. Distribution is effectively static, so this TTL is safe.
3. **Negative RDF cache**: some id.loc.gov Hub URIs return HTTP 200 with an empty-relations payload after 10–30s. `BibframeHub::fetchAndScoreViaRdf` records those outcomes in `/vufind-local/cache/bibframehub_empty_rdf.json` (24h TTL) so we skip them and drop straight to Neo4j.
4. **Parallel HEAD validation**: `headCheckUrisParallel()` uses `curl_multi` at concurrency 10 with 5s timeout. Never replace with a sequential loop — 30× 300ms = 9s of avoidable wait.

All cache paths default from `LOCAL_OVERRIDE_DIR`; override via `[Display]` keys `validationCachePath`, `emptyRdfCachePath`, and `[Neo4j]` key `frequencyCachePath`.

## Hard URI Validation Policy

If a Hub URI does not HEAD-resolve on id.loc.gov (2xx/3xx), it is **not shown** — regardless of source (RDF, suggest2, or Neo4j). This applies to both related-work URIs and the primary Hub URI rendered in the sidebar header. Rationale: the bulk TTL snapshot can still drift between LC publishes, and surfacing unlinkable titles as if they were canonical Hubs is misleading.

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
