---
license: cc0-1.0
pretty_name: Library of Congress BIBFRAME Hubs (Parquet)
language:
  - en
tags:
  - bibframe
  - library-of-congress
  - linked-data
  - knowledge-graph
  - bibliographic
  - duckdb
size_categories:
  - 1M<n<10M
configs:
  - config_name: hubs
    data_files: hubs.parquet
  - config_name: hubs_connected
    data_files: hubs_connected.parquet
  - config_name: relations
    data_files: relations.parquet
  - config_name: rel_type_freq
    data_files: rel_type_freq.parquet
---

# Library of Congress BIBFRAME Hubs — Parquet edition

A columnar, query-ready conversion of the Library of Congress
[BIBFRAME Hubs](https://id.loc.gov/resources/hubs/) bulk export
(`hubs.bibframe.ttl.gz`, 2026-05-05 publish). A *Hub* is the BIBFRAME
equivalent of a MARC Title or Name/Title authority heading — an
authorized access point such as `Austen, Jane. Pride and prejudice`
or `Homer. Odyssey. English` given an HTTP URI so it can be linked to
rather than string-matched. Hubs are lightweight aggregation nodes
for collocating related resources; a single literary work may have
several (the base work, a language expression, a spoken-word
version…), and Hubs carry typed relationships to one another
(translations, adaptations, sequels, parodies, film versions…). See
[Appendix A: More about Hubs](https://bibframe.org/docs/view/documentation-bf-primer/appendix-a-more-about-hubs.md)
in the BIBFRAME Primer. For how Hubs actually come into existence from
MARC (240+100 AAPs, bare 130s, 758 Work links, series 7XX/8XX fields,
and Modern MARC 240/130 `$1` URIs), see the plugin's
[Modern MARC Hub Discovery](https://github.com/jimfhahn/vufind-bf-hubs-plugin/blob/main/docs/modern-marc-hub-discovery.md#hub-creation-paths-observed-taxonomy)
notes — that taxonomy explains why a single title shows up here as
several Hubs and why some `marc_key` values are name/title strings while
others are bare titles.

The point of this dataset is to make the graph usable **without a
graph database**: the whole thing is ~325 MB of Parquet that DuckDB
(CLI, Python, or WASM in a browser) can query directly over HTTP
range requests.

## Files

| File | Rows | Size | One row per |
|---|---|---|---|
| `hubs.parquet` | 2,907,948 | 227 MB | Hub (all of them), sorted by `hub_id` |
| `hubs_connected.parquet` | 917,826 | 75 MB | Hub that appears in at least one relation — the browsable graph |
| `relations.parquet` | 544,005 | 22 MB | Hub → Hub relation, sorted by `(source_id, target_id)` |
| `rel_type_freq.parquet` | 146 | tiny | relationship type, with count |

### `hubs*.parquet` columns

| column | type | notes |
|---|---|---|
| `hub_id` | string | LC UUID. Full URI is `http://id.loc.gov/resources/hubs/{hub_id}` |
| `title` | string | `bf:mainTitle` of the `bf:Title` (not variant) node; 100% populated |
| `variant_titles` | list\<string\> | `bf:mainTitle` of any `bf:VariantTitle` nodes (~1.05M Hubs have some) |
| `marc_key` | string | `bflc:marcKey` — the MARC 1XX/130/240-style authorized access point, e.g. `1001 $aAusten, Jane,$d1775-1817.$tPride and prejudice` |
| `lccn` | string | `bf:Lccn` value if present (~1.58M Hubs) |
| `agents` | list\<string\> | contributor RWO URIs (`http://id.loc.gov/rwo/agents/{naf_id}`); ~1.89M Hubs have ≥1 |
| `media` | list\<string\> | `rdf:type` local names other than `Hub`/`Work`: `Series`, `Audio`, `NotatedMusic`, `MovingImage`, `Arrangement`, `NotatedMovement`, `NonMusicAudio`, `Multimedia`, `Text`, `MusicAudio` |

### `relations.parquet` columns

| column | type | notes |
|---|---|---|
| `source_id` | string | Hub that carries the `bf:relation` |
| `target_id` | string | Hub referenced by `bf:associatedResource`. **11,833 targets are not present in `hubs.parquet`** (dangling in the LC dump) |
| `rel_type` | string | lower-cased local name of the relationship URI, e.g. `translationof`, `adaptedasmotionpicture`, `parodyof`. `related` when untyped |
| `rel_type_uri` | string | the raw relationship URI. Two vocabularies appear: `http://id.loc.gov/vocabulary/relationship/…` (most) and `http://id.loc.gov/entities/relationships/…` |
| `typed` | bool | false only when the source had no IRI-valued `bf:relationship` |

Relations are recorded **only on the source side** in the LC data. To
get a Hub's full neighborhood you must look in both directions
(`source_id = X OR target_id = X`).

### Top relationship types

```
translationof              436,332
related                     36,011
relatedwork                 25,011
arrangementof               23,673
containerof                  3,124
containedin                  3,104
basedon                      2,095
continuedby                  1,625
continuationof               1,589
musicformotionpicture        1,445
motionpictureadaptationof      951
inseries                       848
```

The long tail (`adaptedasmotionpicture`, `operaadaptationof`,
`parodyof`, `inspirationfor`, `derivative`, `sequel`, …) is where the
interesting cross-medium and creative-transformation links live.

## Querying

### DuckDB, straight from the Hub (no download)

```sql
-- everything one hop from Pride and Prejudice
WITH me AS (SELECT '013dc21c-e732-e589-24fe-bcc876264d3a' AS id)
SELECT r.rel_type,
       CASE WHEN r.source_id = me.id THEN 'out' ELSE 'in' END AS dir,
       h.title, h.media
FROM 'hf://datasets/jimfhahn/lc-bibframe-hubs/relations.parquet' r, me
JOIN 'hf://datasets/jimfhahn/lc-bibframe-hubs/hubs_connected.parquet' h
  ON h.hub_id = CASE WHEN r.source_id = me.id THEN r.target_id ELSE r.source_id END
WHERE r.source_id = me.id OR r.target_id = me.id
ORDER BY r.rel_type;
```

```sql
-- find a Hub by title + author
SELECT hub_id, title, marc_key
FROM 'hf://datasets/jimfhahn/lc-bibframe-hubs/hubs.parquet'
WHERE title ILIKE 'pride and prejudice' AND marc_key ILIKE '%Austen%';
```

### Python

```python
import duckdb
con = duckdb.connect()
con.sql("SELECT rel_type, n FROM 'hf://datasets/jimfhahn/lc-bibframe-hubs/rel_type_freq.parquet' LIMIT 20").show()
```

### Browser (DuckDB-WASM / Observable)

The files are served with HTTP range-request support, so
`DuckDBClient.of({rels: FileAttachment(...)})`-style loading or a
plain `read_parquet('https://huggingface.co/datasets/jimfhahn/lc-bibframe-hubs/resolve/main/relations.parquet')`
works client-side. `relations.parquet` + `hubs_connected.parquet`
(~97 MB) is enough for a full interactive neighborhood explorer.

## Provenance & method

- Source: <https://id.loc.gov/download/resources/hubs.bibframe.ttl.gz>
  (published 2026-05-05; ~735 MB gzipped, ~5.5 GB Turtle, ~152.6M triples).
- Conversion: [`ttl_to_parquet.py`](https://github.com/jimfhahn/vufind-bf-hubs-plugin/tree/main/tools/hf-dataset)
  from the *VuFind BIBFRAME Hub plugin* repo. The dump is organised as
  self-contained `# BEGIN … # END` blocks per Hub, so each block is
  parsed independently with [pyoxigraph](https://github.com/oxigraph/oxigraph)
  and fanned out across processes; the full run takes about a minute.
- Only the predicates listed above are extracted. Admin metadata,
  notes, subjects, genre/form, identifiers other than LCCN, and
  non-Hub `bf:associatedResource` targets are dropped.
- Counts cross-check against an n10s/Neo4j import of the same dump
  (2,907,948 `bf:Hub` nodes; 532,172 Hub→Hub paths = 544,005 relations
  minus 11,833 dangling targets).

## Caveats

- Point-in-time snapshot. LC republishes periodically; Hub URIs can
  drift. Verify a URI is live with a HEAD on
  `https://id.loc.gov/resources/hubs/{hub_id}.rdf` before linking.
- `agents` are bare RWO URIs — no names. Resolve via
  `https://id.loc.gov/rwo/agents/{id}.json` or the LC Names bulk files.
- Language tags on titles are not preserved.

## License

The underlying data is published by the Library of Congress as a U.S.
Government work and is in the public domain. This conversion is
released under CC0 1.0.
