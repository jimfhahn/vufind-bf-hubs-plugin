# tools/hf-dataset — LC Hubs bulk dump → Parquet → Hugging Face

Converts `hubs.bibframe.ttl.gz` (the LC BIBFRAME Hubs bulk export) into
query-ready Parquet and publishes it to
[jimfhahn/lc-bibframe-hubs](https://huggingface.co/datasets/jimfhahn/lc-bibframe-hubs).
No Neo4j required: the dump is organised as self-contained
`# BEGIN … # END` blocks per Hub, so each block is parsed independently
with [pyoxigraph](https://github.com/oxigraph/oxigraph) across worker
processes. Full run ≈ 1 minute on an M-series laptop (vs. ~37 min for the
n10s import).

## Files

| File | Purpose |
|---|---|
| `ttl_to_parquet.py` | The converter. Writes `hubs.parquet`, `hubs_connected.parquet`, `relations.parquet`, `rel_type_freq.parquet`. |
| `export_parquet.py` | Alternative exporter that streams from a running n10s Neo4j instance instead of the TTL. Same output schema; kept for cross-checking. |
| `dataset_card.md` | The Hugging Face dataset card (`README.md` in the repo). Column reference, provenance, example DuckDB queries. |
| `requirements.txt` | `neo4j`, `pyarrow`, `duckdb`, `huggingface_hub` (+ `pyoxigraph` for the TTL path). |

Schema, counts and caveats are documented in `dataset_card.md`.

## Usage

```bash
cd tools/hf-dataset
python3 -m venv .venv && .venv/bin/pip install -r requirements.txt pyoxigraph

# smoke test on the first 20K Hubs
.venv/bin/python ttl_to_parquet.py ../../data/hubs.bibframe.ttl.gz --out out-smoke --limit 20000

# full conversion
.venv/bin/python ttl_to_parquet.py ../../data/hubs.bibframe.ttl.gz --out out

# publish (uses the venv's hf ≥ 1.32; the Homebrew `hf` may be older)
cp dataset_card.md out/README.md
.venv/bin/hf upload jimfhahn/lc-bibframe-hubs out . --repo-type dataset \
  --commit-message "Refresh from LC dump YYYY-MM-DD"
```

## Refreshing after an LC re-publish

1. Download the new `hubs.bibframe.ttl.gz` into `data/`.
2. Re-run the full conversion.
3. Update the date/counts in `dataset_card.md`, then upload. Xet
   deduplication means only changed row groups are transferred.

## Gotchas

- A Hub may carry several `bf:title` bnodes; only the one typed `bf:Title`
  (not `bf:VariantTitle`) is the main title. The converter handles this
  (`variant_titles` column) — an earlier version picked the first bnode
  and labelled *Pride and prejudice* as *First impressions*.
- `relations.parquet` keeps edges whose target is not in the dump
  (11,833 of 544,005). Join to `hubs.parquet` if you need resolvable
  targets only; 532,172 remain, matching the Neo4j path count.
