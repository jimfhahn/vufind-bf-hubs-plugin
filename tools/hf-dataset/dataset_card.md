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
  - config_name: bib_to_hub
    data_files: bib_to_hub.parquet
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

## Hubs collocate records across classification

**See it: [Hubs collocate records across classification](https://huggingface.co/spaces/jimfhahn/bibframe-hub-collocation)**,
an in-browser page built on the table below.

`bib_to_hub.parquet` links **493K Library of Congress bibliographic
records** to the Hubs here (437,836 at high confidence, 96.5% exactly
right in a 300-record hand check). It shows what Hubs add to a catalog:
classification puts a record on one shelf, while a Hub and its typed
relationships pull together the novel, the opera, the score and the
case study wherever each one is classed.

| Record shelved in… | Hub relationship | …is now one hop from |
|---|---|---|
| Sacks, *The man who mistook his wife for a hat* — 6 records in **R** (medicine) | opera adaptation | Nyman's chamber opera — 2 records in **M** (music) |
| Chernow, *Alexander Hamilton* — **E** (American history) | inspiration for | Miranda, *Hamilton* — 2 records in **M** |
| Landon, *Anna and the King of Siam* — 4 records in **D** (history) | musical theatre adaptation | Rodgers, *The King and I* — 7 records in **M** |
| Cellini, *Vita* — 4 records in **N** (art) | opera adaptation | Berlioz, *Benvenuto Cellini* — 4 records in **M** |
| Herriman, *Krazy Kat* — 2 records in **N** | based on | Carpenter's *Krazy Kat* ballet — **M** |

No call number, subject heading or shelf browse connects these pairs;
the Hub graph does, and `bib_to_hub` ties both ends to real records
(`https://id.loc.gov/resources/works/{lc_001}`). At high confidence,
304K Hubs gather records, 69K of them gather two or more (up to 176 on
one Hub), 2,303 gather records from two or more LC classes, and 298
creative relationships (adaptation, libretto, musical setting, based
on…) have records on both ends, 40 of them across classes.

The records come from LC's experimental bibliographic embeddings,
repackaged as [jimfhahn/lc-bib-embeddings](https://huggingface.co/datasets/jimfhahn/lc-bib-embeddings)
(12.2M records); `lc_001` joins the two. That source has **no class P
(language and literature)** or T yet (LC's files are not downloadable),
and P is where most adaptations start, so these cross-class counts are
a floor. Method, precision and caveats are
in [`bib_to_hub.parquet` details](#bib_to_hubparquet-details) below.

```sql
-- every LC record on Sacks's Hub and on the Hubs one relation away
WITH me AS (SELECT 'a9402977-4b49-89c3-e703-6f4e0c969790' AS id),  -- Sacks, Man who mistook his wife for a hat
fam AS (
  SELECT id AS hub_id, 'this Hub' AS rel FROM me
  UNION
  SELECT CASE WHEN r.source_id = me.id THEN r.target_id ELSE r.source_id END, r.rel_type
  FROM 'hf://datasets/jimfhahn/lc-bibframe-hubs/relations.parquet' r, me
  WHERE me.id IN (r.source_id, r.target_id))
SELECT fam.rel, b.lcc_class, b.title, b.creator, b.lc_001
FROM fam JOIN 'hf://datasets/jimfhahn/lc-bibframe-hubs/bib_to_hub.parquet' b USING (hub_id)
WHERE b.confidence = 'high'
ORDER BY b.lcc_class;
```

## Files

| File | Rows | Size | One row per |
|---|---|---|---|
| `hubs.parquet` | 2,907,948 | 227 MB | Hub (all of them), sorted by `hub_id` |
| `hubs_connected.parquet` | 917,826 | 75 MB | Hub that appears in at least one relation — the browsable graph |
| `relations.parquet` | 544,005 | 22 MB | Hub → Hub relation, sorted by `(source_id, target_id)` |
| `rel_type_freq.parquet` | 146 | tiny | relationship type, with count |
| `bib_to_hub.parquet` | 493,331 | 35 MB | LC bibliographic record matched to a Hub (see [details](#bib_to_hubparquet-details)) |
| `bib_to_hub_eval.csv` | 300 | 90 KB | hand-checked sample behind the precision figures |
| `collocation/colloc_*.parquet` | 4 files | 0.5 MB | small summaries (high confidence) behind the [collocation page](https://huggingface.co/spaces/jimfhahn/bibframe-hub-collocation): records per Hub per class, creative Hub pairs, the records themselves, coverage by class |

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

### `bib_to_hub.parquet` details

One row per LC bibliographic record that matched a Hub, out of the
12.2M in [lc-bib-embeddings](https://huggingface.co/datasets/jimfhahn/lc-bib-embeddings).

| column | type | notes |
|---|---|---|
| `lc_001` | string | MARC 001 of the LC record; its BIBFRAME Work is `https://id.loc.gov/resources/works/{lc_001}`; joins to `lc-bib-embeddings` |
| `lccn`, `lcc_class`, `title`, `creator` | string | copied from the record so the table is readable on its own |
| `hub_id` | string | best-matching Hub |
| `match_method` | string | `aap`, `variant_title` or `uniform_title_130` (below) |
| `confidence` | string | `high`, `medium` or `low` (below) |
| `name_dominated` | bool | the record title is just the creator's name ("Picasso" by Picasso), or, for variant titles, the name plus one word against a collective Hub |
| `series_title` | bool | the Hub looks like a series title that collected different books (see below) |
| `hub_qualified` | bool | the Hub's access point carries `$l`/`$k`/`$n`/`$p`… (an expression, part or selection) |
| `n_candidates`, `candidates` | int, list | all equally ranked Hubs, most-connected first; `hub_id` is `candidates[1]` |

**How records were matched.** Offline, on the authorized access point.
The record's first creator (dates and fuller forms dropped) and title
(subtitle and leading article dropped) are normalized and compared with
each Hub's `marc_key`:

1. `aap`: creator + title = Hub `$a` + `$t`.
2. `variant_title`: creator + title = Hub `$a` + one of its
   `variant_titles`. This catches translations: *Studies on the
   development of consciousness* → Chesni, *Recherches sur le
   développement de la conscience*.
3. `uniform_title_130`: title only, against `130` Hubs, for records
   with no creator and at least two title words.

Records keep the best tier; among ties, unqualified Hubs win, then the
most-connected one. Two error patterns found while checking are
labelled down rather than dropped:

- **Name-only titles** → `low`. Exhibition catalogues called *Picasso*
  or *Marc Chagall* all match a generic `Picasso, Pablo. Picasso` Hub;
  only 5 of 40 checked were the Hub LC's own Work links.
- **Series titles** → `medium`. Dropping the subtitle collapses
  *Chicken soup for the soul : …* or *The worst-case scenario survival
  handbook : …* volumes onto one Hub. Such Hubs show a pattern editions
  of one work do not: a different subtitle on most records **and**
  records in three or more LC classes. 13 Hubs (106 records); the
  Constitution, the *Federalist* and *Leviathan* are not flagged.

**Precision** (300 records, stratified; each checked against the LC
Work's own Hub links where it has any, otherwise by hand):

| confidence | records | exactly the right Hub | right work |
|---|---|---|---|
| `high`: one candidate, tiers 1–2 | 437,836 | **96.5%** (95% CI 93–98%) | 99% |
| `medium`: several Hubs tie, or series title | 30,425 | 66% (52–78%) | 78% |
| `low`: `130` title-only, or name-dominated | 25,070 | 66% (52–78%) | 72% |

The `medium` and `low` samples were drawn before the two rules above;
the 4,700 name-only matches that moved into `low` are weaker than that
sample suggests (5 of 40 confirmed by LC's Work links).

Use `high` as-is. Treat `medium` as "right work, Hub uncertain": the
tied Hubs usually differ only by a qualifier (a dated vs. undated law,
volume (Yoma) vs. (Ḥagigah)), so show `candidates`. Use `low` for
exploration. Every judgment is in `bib_to_hub_eval.csv`.

**What it complements.** LC's BIBFRAME Work for a record links Hubs
only when the MARC has a uniform title (240/130) or series; in an
80-record spot check, 7 Works had a `bf:expressionOf` Hub link. Most
correct matches here are records whose Work links no Hub at all. Where
LC does link one, it is often the language-expression Hub (*Carmen.
Italian & French*) while `bib_to_hub` gives the base work, or the same
Hub.

**Limits.** 3.6% of records match at high confidence (12% in M,
1.5–5% elsewhere): a Hub exists only where LC made a name/title
authority, and translated titles match only through variant titles.
Built from the 2026-05-05 Hubs dump and the 2025 embeddings release
with [`build_bib_to_hub.py`](https://github.com/jimfhahn/vufind-bf-hubs-plugin/tree/main/tools/bib-hub-mapping)
(about 2 minutes; reads only the text columns of the embeddings).

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
