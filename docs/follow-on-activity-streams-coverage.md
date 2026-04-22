# Follow-on: Comprehensive Hub Coverage via Activity Streams

## Background

After Phase 1 reconciliation (label recovery on ~2.6M `gone` Hubs) completes,
our local graph still won't contain Hubs that exist upstream but were never in
the bulk TTL snapshot. There is no fresh bulk download available; the only way
to enumerate every live Hub is via id.loc.gov's Activity Streams feed.

Estimated post-recovery state (from current run):
- ~92 currently-live Hubs (verified `redirect` to self)
- ~495K Hubs with `canonical_uri` pointing to a live merge target
- ~2.1M genuinely gone (no recoverable canonical)
- Unknown additional ~330K+ Hubs that exist upstream but never appeared in our snapshot

## Data Source

**BIBFRAME Hubs Activity Stream**
- Feed root: `https://id.loc.gov/resources/hubs/activitystreams/feed/`
- Page 1: `https://id.loc.gov/resources/hubs/activitystreams/feed/1`
- Last page (as of 2026-04-22): **29,347**
- Format: ActivityStreams 2.0 JSON-LD, paginated `OrderedCollectionPage`
- Each page lists ~100 events; total ~2.93M items (matches upstream Hub count)

### Event shape

```json
{
  "type": "Add" | "Update",          // (also expect "Remove"/"Delete")
  "published": "2026-04-18",
  "actor": "http://id.loc.gov/vocabulary/organizations/dlc",
  "object": {
    "id": "http://id.loc.gov/resources/hubs/{uuid}",
    "updated": "2026-04-18",
    "type": ["bf:Work", "bf:Hub", "bf:Series"?],
    "url": [{ "href": "...rdf" }, { "href": "...json" }, { "href": "...nt" }]
  },
  "target": [{ "id": "http://id.loc.gov/resources/hubs", "type": [...] }]
}
```

Page metadata includes `next` and `partOf` for pagination, and `summary`
reports total page count.

## Plan

### Step 1 — Crawler (`tools/coverage/activity_stream_crawler.py`)

- Walk pages 1 → N (parameterized; default = parse `summary` for total).
- Polite rate (~1 req/s, single worker; the feed is small per page).
- Output: JSONL line per event with `{uri, type, published, actor, bf_types}`.
- Resume support: write progress checkpoint after each page; skip pages already
  covered if restarted.
- Cost estimate: ~8 hours single-worker, ~1 hour with 5 workers.

### Step 2 — Reconcile against graph

- `MATCH (h:ns0__Hub) RETURN h.uri` → set `local_uris`
- Build `upstream_uris` from crawl JSONL
- Compute three sets:
  - `missing_local`: in upstream, not in graph → candidates to MERGE
  - `extra_local`: in graph, not in upstream → confirmed dead (or merged); cross-check with our existing `upstream_status`
  - `present_both`: in graph and upstream → potential refresh candidates if `Update` event newer than our import

### Step 3 — Ingest missing Hubs

For each URI in `missing_local`:
1. HEAD-check (should be 200/302 since the feed is current).
2. Fetch `{uri}.nt` (n-triples; smallest payload, easiest n10s ingest).
3. `n10s.rdf.import.fetch(uri, "N-Triples")` — appends to graph using existing
   namespace shortening rules.
4. Set `upstream_status = 'live'`, `last_verified = now`.

Throttle to ~5 req/s; total ingest = ~330K + delta = ~18 hours at that rate.

### Step 4 — Refresh updated Hubs (optional, lower priority)

For Hubs in `present_both` where event `published > h.last_verified`:
- Re-fetch RDF, replace triples (DELETE + n10s import, or selective update).
- Useful for keeping relationship graph fresh as catalogers add new
  `bf:relation` edges.

### Step 5 — Re-run reconciler sweep

After ingest, run `reconcile_hubs.py` (sweep mode, no `--label-recovery`) on the
new nodes (filter `WHERE h.upstream_status IS NULL`). They should all come back
`live`.

## Plugin Implications

`Neo4jService::getHubsBulk()` already returns `canonical_uri` and
`upstream_status`. After ingest, the previously-missing canonical targets will
exist as full nodes, so `BibframeHub::fetchAndScoreViaNeo4j()`'s canonical
substitution will resolve to nodes with full title/agents/media instead of
phantom URIs. No plugin code changes required.

## Why Not Now

The current label-recovery run (~2.5 days remaining) is independent and
worth completing first because:
- Label recovery handles old-UUID → new-UUID merges that the activity feed
  doesn't expose (merge logic is in LC's redirect layer, not the event stream).
- ~495K recovered canonicals will already point to many of the upstream Hubs we
  don't yet have, giving us a head-start list of priority targets to ingest.

## Ontological Cleanup: Relabeling Legacy Hub-Works

The ~2.1M `gone` Hubs that fail label recovery are not simply "deleted" —
they are artifacts of LC's **previous BIBFRAME modeling decision** in which
`bf:Hub` was treated as a subclass of `bf:Work`. LC subsequently reprocessed
the Hub corpus under a revised model (separate class, different generation
pipeline, new identifier minting), and the old work-as-Hub URIs were retired
wholesale — not because each individual work was deleted, but because the
class definition shifted underneath them.

This makes our local `:ns0__Hub` label on those nodes **categorically wrong
under the current ontology**, not merely stale:

- The old URIs were "Works tagged as Hubs under a now-superseded model"
- They likely still correspond to legitimate `bf:Work` resources upstream,
  just not as Hubs
- The ~19% that *do* recover via label endpoint represent cases where the new
  modeling pipeline derived an equivalent Hub from the same source authority
  + title; the other ~80% correspond to works that under the new model don't
  warrant Hub-level aggregation (single instance, no cross-medium ties, etc.)

### Proposed three-bucket relabeling (post-ingest)

After the activity-streams ingest completes (so we have the most generous
possible recovery surface), partition the existing `:ns0__Hub` population:

```cypher
// Bucket 1: verified-current Hubs — keep :ns0__Hub label as-is
// (no action needed; these have upstream_status IN ['live', 'redirect']
//  and either are themselves canonical or canonical_uri points to a real Hub)

// Bucket 2: merged into a current Hub — was a Hub, still has bibliographic
// meaning via redirect, but the URI itself is no longer a Hub
MATCH (h:ns0__Hub)
WHERE h.canonical_uri IS NOT NULL AND h.upstream_status = 'gone'
REMOVE h:ns0__Hub
SET h:MergedHub

// Bucket 3: legacy work-as-Hub artifacts — never a Hub under current model
MATCH (h:ns0__Hub)
WHERE h.upstream_status = 'gone' AND h.canonical_uri IS NULL
REMOVE h:ns0__Hub
SET h:ns0__Work, h:LegacyHubWork
```

Rationale for the labels:

- **`:MergedHub`** — historically Hubs, redirected upstream to a different
  current Hub. Bibliographic relationships from MARC records pointing at
  these URIs remain valid facts; the redirect lets us follow them to the
  current canonical. Useful for audit and for legacy MARC reconciliation.
- **`:ns0__Work` + `:LegacyHubWork`** — keep the `bf:Work` superclass
  assertion (likely still ontologically true), and add a provenance label
  recording that this URI was modeled as a Hub under LC's prior pipeline.
  Removes the false `bf:Hub` assertion while preserving the data and edges.

### Why this matters for downstream consumers

1. **Truth in querying.** Anyone running `MATCH (h:ns0__Hub) RETURN ...`
   should get nodes that are actually Hubs under the current ontology.
   Right now they'd get a mix of current Hubs and legacy work-as-Hub URIs.
2. **n10s namespace integrity.** The `ns0__Hub` label was minted by n10s
   because the source TTL claimed `<uri> a bf:Hub`. If LC has retracted
   that class assertion (by reprocessing the corpus), our local graph
   should retract it too.
3. **Honest provenance.** Our graph is a snapshot from a specific point in
   LC's modeling history. Splitting `:MergedHub` from `:LegacyHubWork`
   preserves *both* the bibliographic value (redirects work) and the
   modeling provenance (these were never Hubs as currently defined).
4. **Plugin queries already filter by `upstream_status`** so this cleanup
   doesn't break anything; it just makes the schema honest about what each
   node represents.

### Sequencing

This must happen **after** the activity-streams ingest (Step 3 above), not
before. Ingest may discover that some Hubs we currently classify as `gone`
have in fact been republished under their original URIs — those should
stay `:ns0__Hub`. Doing the relabel first would force redundant work to
unwind it.

## Open Questions

- Does the feed include `Remove` / `Delete` events? Sample of page 1 shows
  only `Add` and `Update`. May need to scan deeper or check if deletions are
  silent (relying on HEAD-check 410/404 instead).
- Are merge-redirected old UUIDs ever republished as `Update` events on the
  new canonical? If yes, we get merge tracking from the feed. If no, label
  recovery remains the only way to bridge old → new.
- Crawl frequency post-bootstrap: weekly delta crawl from last-seen page should
  keep the graph current with minimal cost.

## Future: Display Mode Toggle (Surprising vs. All Hubs)

The plugin's core design principle is to surface *surprising, non-obvious*
connections — the surprise scoring model exists to push translations,
series membership, and other predictable links to the bottom (Tier 5) so
genuinely interesting cross-medium and creative-transformation links rise
to the top.

That curatorial stance is the right default for a discovery sidebar, but
some users (catalogers verifying coverage, researchers doing
completeness audits, librarians wanting to see "everything LC knows
about this work") will reasonably want the unfiltered view.

### Proposed setting

Add a `[Display]` config key in `BibframeHub.ini`:

```ini
[Display]
; Display mode: "surprising" (default) shows scored, grouped, curated results
; with Tier 5 de-emphasized. "all" shows every related Hub LC knows about,
; sorted by relationship type alphabetically with no scoring filter.
displayMode = "surprising"
```

And a per-record query parameter (`?bibframehub_mode=all`) that overrides the
default for a single page view, so users don't have to edit config to spot-check.

### Implementation sketch

- In `BibframeHub::getResults()`, branch on `$this->config['displayMode']` and
  the request query param.
- `surprising` mode: current behavior (5-tier scoring, `maxDisplayResults` cap,
  grouped template with Tier 1–2 expanded).
- `all` mode: skip scoring entirely, return every HEAD-validated related Hub,
  group by raw relationship type URI / inline label, no result cap (or a much
  higher one — say 200), all groups collapsed by default.
- Template (`Related/BibframeHub.phtml`) gets a small mode-aware header
  ("Curated related works" vs. "All related works") and possibly a toggle
  link to flip between modes.

### Why this is a follow-on, not now

- The current scoring model is the differentiating value proposition; shipping
  an "all" mode before validating the curated mode in production would muddy
  the message.
- "All" mode is much more useful once the graph reconciliation work is done,
  because today many `bf:relation` targets are stale URIs that would inflate
  the unfiltered list with broken links.
- Need to think about how `all` mode interacts with `getHubsBulk()`'s
  performance contract — uncapped result sets could re-introduce the slow-page
  problem the bulk-prefetch was designed to solve.
