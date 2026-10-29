# bib-hub-mapping: LC bibliographic records → BIBFRAME Hubs

Builds `bib_to_hub.parquet`, published in
[jimfhahn/lc-bibframe-hubs](https://huggingface.co/datasets/jimfhahn/lc-bibframe-hubs#hubs-collocate-records-across-classification):
493K of the 12.2M LC bibliographic records in
[jimfhahn/lc-bib-embeddings](https://huggingface.co/datasets/jimfhahn/lc-bib-embeddings)
matched to the Hub for their work.

**Why it matters: Hubs collocate records across classification.** A
record sits on one shelf; its Hub's typed relationships reach records on
other shelves. With this table, Sacks's *The man who mistook his wife for
a hat* (6 records, class R) is one hop from Nyman's opera of it (class M),
Chernow's *Alexander Hamilton* (E) from Miranda's *Hamilton* (M), and
Landon's *Anna and the King of Siam* (D) from *The King and I* (M).
Subject headings rarely bridge such pairs: across the 40 cross-shelf
pairs, the two sides share an identical LCSH heading in 3 and a main
heading (usually a person or character both works are about) in 14. In
28 pairs a record on one side already names the other work in a
name/title added entry, but only one-way and only on that record
(`lcsh_overlap.py`). The
[collocation page](https://huggingface.co/spaces/jimfhahn/bibframe-hub-collocation)
(`notebooks/hub-collocation.html`) charts all of them.

## Run

```bash
../hf-dataset/.venv/bin/python build_bib_to_hub.py            # → out/bib_to_hub.parquet (~2 min)
../hf-dataset/.venv/bin/python build_bib_to_hub.py --stats    # + coverage and collocation numbers
../hf-dataset/.venv/bin/python build_bib_to_hub.py --collocation out/collocation   # + notebook files
../hf-dataset/.venv/bin/python lcsh_overlap.py --verbose      # do LCSH / added entries already link the cross-shelf pairs? (fetches MARC from id.loc.gov)
```

Reads `hubs.parquet` and `relations.parquet` from `../hf-dataset/out/` if
present, otherwise from Hugging Face, and only the `lc_001`, `lccn`,
`lcc_class`, `title` and `creator` columns of the embeddings (not the
47 GB of vectors). Needs `duckdb` (any recent version; the `hf-dataset`
venv has it).

`--collocation DIR` also writes four small files (~0.5 MB, high
confidence only) for the notebook: `colloc_hub_class` (records per Hub
per LC class, with Hub labels and `hub_links`, the Hub's relation count;
0 = no family in the explorer), `colloc_edges` (creative Hub→Hub links
with records on both ends; "creative" = surprise tiers 1–3 of the
plugin's `RelationshipInferrer.php`), `colloc_records` (those Hubs'
records) and `colloc_coverage` (records and matches per class).

Publish:

```bash
HF=../hf-dataset/.venv/bin/hf
$HF upload jimfhahn/lc-bibframe-hubs out/bib_to_hub.parquet bib_to_hub.parquet --repo-type dataset
$HF upload jimfhahn/lc-bibframe-hubs out/collocation collocation --repo-type dataset
$HF upload jimfhahn/lc-bibframe-hubs bib_to_hub_eval.csv bib_to_hub_eval.csv --repo-type dataset
$HF upload jimfhahn/lc-bibframe-hubs ../hf-dataset/dataset_card.md README.md --repo-type dataset
(cd ../../notebooks && ./deploy-space.sh collocation)   # refresh the Space's copy
```

## Method

Both sides are normalized (lower case, accents and punctuation stripped,
leading article dropped; record subtitle after ` : `, ` / `, ` = ` dropped;
dates and fuller forms of names dropped) and joined in three tiers:

| tier | `match_method` | record | Hub |
|---|---|---|---|
| 1 | `aap` | first creator + title | `marc_key` `$a` + `$t` |
| 2 | `variant_title` | first creator + title | `$a` + any of `variant_titles` (translations) |
| 3 | `uniform_title_130` | title, records with no creator, ≥ 2 words | `130` Hub `$a` |

Each record keeps its best tier. Within it, name-dominated matches rank
below others, unqualified Hubs rank above qualified ones, and the
most-connected Hub wins (ties by `hub_id`, so runs are reproducible).

`confidence`: `high` = tier 1–2 with one candidate; `medium` = several
candidates, or a series-title Hub; `low` = tier 3 or name-dominated.

- **Name-dominated** (`name_dominated`): after removing words that match
  the creator's name (4-letter prefix), nothing is left ("Picasso" by
  Picasso, "Paulo Orosio" by Orosius), or, for variant titles, one word is
  left and the Hub is a collective title ("Bach-Festival" → *Works*).
- **Series title** (`series_title`): a Hub whose high-confidence records
  have a different subtitle on most records (≥ 4, ≥ half) **and** sit in
  3+ LC classes. That is the signature of different books sharing a main
  title (*Chicken soup for the soul : …*); editions of one work vary
  their subtitles but stay in one or two classes, and classics that do
  span classes (Constitution, *Federalist*, *Leviathan*) keep one
  subtitle on most records. 13 Hubs, 106 records.

## Precision

`bib_to_hub_eval.csv` holds 300 stratified records. Each was checked
against its LC BIBFRAME Work (`lc_work_check`: `CONFIRMED` same Hub,
`SAME_AAP` same name/title Hub, `OTHER_HUB`, `NO_HUB`), and everything
the Work could not settle was judged by hand (`verdict`: `correct`,
`near` = right work but a different Hub of it, `wrong`). The
`confidence` column is the stratum the record was drawn from.

| confidence | records | exactly the right Hub | right work |
|---|---|---|---|
| high | 437,836 | 96.5% (95% CI 93–98%), 199 rows still high | 99% |
| medium | 30,425 | 66% (52–78%) | 78% |
| low | 25,070 | 66% (52–78%) | 72% |

Things learned on the way, kept as rules:

- A minimum-title-length rule would have cut a quarter of the variant
  matches, most of them correct (*Silent night*, *Kama sutra*); it only
  helps in tier 3, where one-word titles were right 2 times in 12, so
  tier 3 requires two words.
- Name-only titles were checked separately against LC's Works: variant
  tier about half right, access-point tier 5 of 40 confirmed (most are
  different catalogues gathered on one `Artist. Artist` Hub). Both are
  labelled `low`, not dropped. This rule moved one sampled high row (a
  de Chirico catalogue, judged wrong) to low.
- Subtitle spread alone is not a usable error signal: 414 Hubs have ≥ 5
  mostly distinct subtitles, and nearly all are law editions, Talmud
  commentary volumes and scores. Only combined with class spread does it
  isolate series titles.
- Back-links from Hubs to Works are not an alternative: the whole Hubs
  dump holds 56 `resources/works|instances` URIs (stray 670 citations).

The eval was drawn from an earlier build that broke ties among equally
connected candidates arbitrarily and had neither label-down rule; for
`medium` rows the `hub_id` judged in the CSV can differ from the one in
the published table, and the `low` figure predates the name-only
access-point rows that now sit there.
