# Notebooks — Observable Notebooks 2.0 experiments

Serverless exploration of the LC BIBFRAME Hubs graph, reading Parquet
straight from the Hugging Face dataset
[jimfhahn/lc-bibframe-hubs](https://huggingface.co/datasets/jimfhahn/lc-bibframe-hubs)
with DuckDB-WASM in the browser. Companion to the VuFind plugin in this
repo; the plan is for the plugin sidebar to deep-link into these pages.

## `hub-family-explorer.html`

Bibliographic-family view of any Hub:

1. **Search** by title / access point (ranked by connectivity).
2. **Bottom-up climb** — follows outgoing `translationof` / `arrangementof`
   to the family root (the LRM *Work*). Entering through a translation is
   supported and the entry point is marked.
3. **Top-down layout** of the one-hop family, grouped into *Derivative
   works* (surprise tiers 1–3, coloured), *Expressions* (collapsed by
   default), *Series & parts*, *Other related*. Three layouts:
   **indented tree** (default), tidy tree, force graph.
4. **Flat table** of the same rows with `id.loc.gov` links.

Click a title to re-root; shift-click opens `id.loc.gov`. Surprise tiers
are copied verbatim from
[`RelationshipInferrer.php`](../module/BibframeHub/src/BibframeHub/Relationship/RelationshipInferrer.php).

**Deep link:** `?hub=<uuid>` opens directly on that Hub, e.g.
`hub-family-explorer?hub=013dc21c-e732-e589-24fe-bcc876264d3a`
(*Pride and prejudice*). The URL is kept in sync as you re-root.

Design draws on Arastoopoor (2022, *LHT* 40:1) — top-down vs. bottom-up
navigation of bibliographic families; Pauman Budanović & Žumer (2021,
*CCQ* 59:7) — LRM family as the organizing unit; Merčun, Žumer & Aalberg's
FrbrVis (*JASIST* 2017, *J. Doc.* 2016) — indented tree best of four
hierarchical layouts.

## Running locally

```bash
cd notebooks
npm install
node_modules/.bin/notebooks preview --root .
# → http://localhost:5173/hub-family-explorer
```

First load pulls ~97 MB (`relations.parquet` + `hubs_connected.parquet`)
into DuckDB-WASM; ~5 s on a fast connection, then everything is local.

## Building a static site

```bash
node_modules/.bin/notebooks build --root . -- *.html
# → .observable/dist/
```

The output is plain HTML/JS and can be hosted anywhere static (GitHub
Pages, a Hugging Face Space with the `static` SDK, …).

## Editing

Notebooks are single HTML files (`<notebook>` root, one `<script>` per
cell, four-space indented) — edit in any text editor, or open in
[Observable Desktop](https://observablehq.github.io/notebook-kit/desktop)
/ the new Observable web editor, whose AI agent can extend them. The
explorer exposes stable handles for that: `db` (DuckDB client with
`rels`, `hubs`, `deg`), `family` (one-hop rows with `tier`, `group`,
`dir`, `sameAuthor`), `climb` (root + bottom-up path), `setFocus(id)`,
and the helpers `tierOf`, `authorOf`, `hubUrl`, `TIER_COLOR`.
