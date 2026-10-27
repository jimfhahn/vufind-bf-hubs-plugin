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
Carson's *Silent spring* (Q) from Stucky's orchestral piece (M), and
Landon's *Anna and the King of Siam* (D) from *The King and I* (M). No
call number, subject heading or shelf browse connects those pairs.

## Run

```bash
../hf-dataset/.venv/bin/python build_bib_to_hub.py            # → out/bib_to_hub.parquet (~2 min)
../hf-dataset/.venv/bin/python build_bib_to_hub.py --stats    # + coverage and collocation numbers
```

Reads `hubs.parquet` and `relations.parquet` from `../hf-dataset/out/` if
present, otherwise from Hugging Face, and only the `lc_001`, `lccn`,
`lcc_class`, `title` and `creator` columns of the embeddings (not the
47 GB of vectors). Needs `duckdb` (any recent version; the `hf-dataset`
venv has it).

Publish:

```bash
HF=../hf-dataset/.venv/bin/hf
$HF upload jimfhahn/lc-bibframe-hubs out/bib_to_hub.parquet bib_to_hub.parquet --repo-type dataset
$HF upload jimfhahn/lc-bibframe-hubs bib_to_hub_eval.csv bib_to_hub_eval.csv --repo-type dataset
$HF upload jimfhahn/lc-bibframe-hubs ../hf-dataset/dataset_card.md README.md --repo-type dataset
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

Each record keeps its best tier. Within it, variant matches whose title is
mostly the creator's name ("Paulo Orosio", "Bach-Festival") rank below
others, unqualified Hubs rank above qualified ones, and the most-connected
Hub wins (ties by `hub_id`, so runs are reproducible).

`confidence`: `high` = tier 1–2 with one candidate; `medium` = several
candidates; `low` = tier 3 or name-dominated.

## Precision

`bib_to_hub_eval.csv` holds 300 stratified records. Each was checked
against its LC BIBFRAME Work (`lc_work_check`: `CONFIRMED` same Hub,
`SAME_AAP` same name/title Hub, `OTHER_HUB`, `NO_HUB`), and everything
the Work could not settle was judged by hand (`verdict`: `correct`,
`near` = right work but a different Hub of it, `wrong`).

| confidence | exactly the right Hub | right work |
|---|---|---|
| high | 96.0% (95% CI 92–98%) | 98.5% |
| medium | 66% (52–78%) | 78% |
| low | 66% (52–78%) | 72% |

Things learned on the way, kept as rules:

- A minimum-title-length rule would have cut a quarter of the variant
  matches, most of them correct (*Silent night*, *Kama sutra*); it only
  helps in tier 3, where one-word titles were right 2 times in 12, so
  tier 3 requires two words.
- Name-dominated variant titles (mostly exhibition catalogues titled with
  the artist's name) were right about half the time against LC's Works,
  so they are labelled `low`, not dropped.
- Back-links from Hubs to Works are not an alternative: the whole Hubs
  dump holds 56 `resources/works|instances` URIs (stray 670 citations).

The eval was drawn from the session build that preceded this script,
which broke ties among equally connected candidates arbitrarily; for
`medium` rows the `hub_id` judged in the CSV can differ from the one in
the published table. `high` rows are identical.
