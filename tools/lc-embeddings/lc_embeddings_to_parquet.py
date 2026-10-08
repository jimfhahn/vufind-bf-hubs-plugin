# /// script
# requires-python = ">=3.10"
# dependencies = ["duckdb>=1.1", "huggingface_hub>=1.0"]
#
# [tool.hf-jobs]
# name    = "lc-bib-embeddings"
# flavor  = "cpu-upgrade"
# timeout = "8h"
# secrets = ["HF_TOKEN"]
# ///
"""
Convert the Library of Congress experimental bibliographic embeddings
(https://id.loc.gov/download/ → "Experimental Embeddings Data by LCC") from
gzipped JSON Lines to Parquet and publish them to a Hugging Face dataset repo.

Source records look like
    {"lc_001": "10002353", "embedding": [1024 floats],
     "meta": {"Title": ..., "Creator": ..., "LCCCode": "VA454", "LCCN": "80802403"}}
(Amazon Titan Text Embeddings V2, unit-normalized, float32-exact).

Each LCC class file is streamed straight from LC's S3 bucket into DuckDB and
written as ~1 GB Parquet shards under data/<class>/, then uploaded as one
commit per class. Classes already present in the repo are skipped, so a
failed run can simply be restarted.

    # local smoke test, no upload
    python lc_embeddings_to_parquet.py --classes V --no-upload --out /tmp/lcemb
    # full run on Hugging Face infrastructure
    hf jobs uv run lc_embeddings_to_parquet.py --repo jimfhahn/lc-bib-embeddings
"""

import argparse
import os
import shutil
import sys
import time
import urllib.error
import urllib.request
from concurrent.futures import ThreadPoolExecutor, as_completed

import duckdb

SOURCE = "https://lds-downloads.s3.amazonaws.com/embeddings/{cls}_embeddings_2025.jsonl.gz"
# Published sizes (GB, gzipped); used only to schedule the biggest classes first.
CLASSES = {
    "P": 32, "no_LCC": 31, "H": 20, "D": 14, "B": 12, "K": 11, "Q": 9, "T": 9,
    "G": 8, "M": 7, "N": 6, "R": 5, "F": 4, "J": 4, "L": 4, "S": 3, "Z": 3,
    "A": 2, "C": 2, "E": 2, "U": 2, "V": 0.5,
}
DIM = 1024


def log(*a):
    print(time.strftime("%H:%M:%S"), *a, file=sys.stderr, flush=True)


def source_status(cls: str) -> int:
    # As of 2026-10-08 LC's bucket returns 403 for P, T and no_LCC although the download page links them.
    req = urllib.request.Request(SOURCE.format(cls=cls), method="HEAD")
    try:
        with urllib.request.urlopen(req, timeout=30) as r:
            return r.status
    except urllib.error.HTTPError as e:
        return e.code


def convert(cls: str, out_root: str, threads: int, mem_gb: float, limit: int | None) -> tuple[str, int, float]:
    url = SOURCE.format(cls=cls)
    out = os.path.join(out_root, cls)
    shutil.rmtree(out, ignore_errors=True)
    os.makedirs(out_root, exist_ok=True)
    t0 = time.time()
    con = duckdb.connect()
    con.execute(f"SET threads={threads}; SET memory_limit='{mem_gb:.1f}GB'; SET preserve_insertion_order=false; "
                f"SET temp_directory='{out_root}/.duck_{cls}'")
    con.execute("INSTALL httpfs; LOAD httpfs")
    lim = f"LIMIT {int(limit)}" if limit else ""
    # Strict cast: a vector that is not exactly 1024-dim fails the class (rerun resumes).
    con.execute(f"""
        COPY (
            SELECT lc_001,
                   meta.LCCN    AS lccn,
                   meta.Title   AS title,
                   meta.Creator AS creator,
                   meta.LCCCode AS lcc_code,
                   '{cls}'      AS lcc_class,
                   CAST(embedding AS FLOAT[{DIM}]) AS embedding
            FROM read_json('{url}',
                           format = 'newline_delimited',
                           compression = 'gzip',
                           maximum_object_size = 4194304,
                           columns = {{
                               lc_001: 'VARCHAR',
                               embedding: 'FLOAT[]',
                               meta: 'STRUCT(Title VARCHAR, Creator VARCHAR, LCCCode VARCHAR, LCCN VARCHAR)'
                           }})
            {lim}
        ) TO '{out}' (FORMAT PARQUET, COMPRESSION ZSTD, ROW_GROUP_SIZE 20000,
                      FILE_SIZE_BYTES '1GB', FILENAME_PATTERN '{cls}-{{i}}')
    """)
    good = con.execute(f"SELECT count(*) FROM read_parquet('{out}/*.parquet')").fetchone()[0]
    con.close()
    shutil.rmtree(f"{out_root}/.duck_{cls}", ignore_errors=True)
    return cls, good, time.time() - t0


def existing_classes(api, repo: str) -> set[str]:
    try:
        files = api.list_repo_files(repo, repo_type="dataset")
    except Exception:
        return set()
    return {f.split("/")[1] for f in files if f.startswith("data/") and f.endswith(".parquet")}


def main():
    ap = argparse.ArgumentParser(description=__doc__, formatter_class=argparse.RawDescriptionHelpFormatter)
    ap.add_argument("--repo", default="jimfhahn/lc-bib-embeddings")
    ap.add_argument("--classes", default=",".join(CLASSES), help="comma-separated LCC classes (default: all)")
    ap.add_argument("--out", default="/tmp/lcemb")
    ap.add_argument("--parallel", type=int, default=2, help="classes converted concurrently (disk-bound: ~13 GB out for P)")
    ap.add_argument("--limit", type=int, default=0, help="only the first N records per class (smoke test)")
    ap.add_argument("--no-upload", action="store_true")
    args = ap.parse_args()

    wanted = [c for c in args.classes.split(",") if c]
    unknown = set(wanted) - set(CLASSES)
    if unknown:
        sys.exit(f"unknown classes: {sorted(unknown)}")
    wanted.sort(key=lambda c: -CLASSES[c])

    api = None
    if not args.no_upload:
        from huggingface_hub import HfApi
        api = HfApi()
        api.create_repo(args.repo, repo_type="dataset", exist_ok=True)
        done = existing_classes(api, args.repo)
        if done:
            log(f"already in {args.repo}: {sorted(done)} (skipping)")
        wanted = [c for c in wanted if c not in done]

    unreadable = {c: s for c in wanted if (s := source_status(c)) != 200}
    if unreadable:
        log(f"source not publicly readable, skipping: {unreadable}")
        wanted = [c for c in wanted if c not in unreadable]

    # Inside HF Jobs os.cpu_count() reports the host; CPU_CORES / MEMORY (e.g. "32Gi") are the real limits.
    cores = int(os.environ.get("CPU_CORES") or os.cpu_count() or 2)
    mem = os.environ.get("MEMORY", "")
    total_gb = float(mem[:-2]) if mem.endswith("Gi") else 8.0
    threads = max(1, cores // max(1, args.parallel))
    mem_gb = total_gb * 0.6 / max(1, args.parallel)
    log(f"converting {wanted} with parallel={args.parallel}, threads/class={threads}, memory/class={mem_gb:.1f}GB")
    total = 0
    with ThreadPoolExecutor(max_workers=args.parallel) as pool:
        futs = {pool.submit(convert, c, args.out, threads, mem_gb, args.limit or None): c for c in wanted}
        for fut in as_completed(futs):
            cls = futs[fut]
            try:
                cls, good, secs = fut.result()
            except Exception as e:
                log(f"{cls}: FAILED {type(e).__name__}: {str(e)[:500]}")
                continue
            total += good
            size = sum(os.path.getsize(os.path.join(args.out, cls, f)) for f in os.listdir(os.path.join(args.out, cls)))
            log(f"{cls}: {good:,} rows, {size / 1e9:.2f} GB parquet, {secs / 60:.1f} min")
            if api:
                api.upload_folder(
                    repo_id=args.repo, repo_type="dataset",
                    folder_path=os.path.join(args.out, cls), path_in_repo=f"data/{cls}",
                    commit_message=f"Add LCC class {cls} ({good:,} records)",
                )
                log(f"{cls}: uploaded")
                shutil.rmtree(os.path.join(args.out, cls), ignore_errors=True)
    log(f"done: {total:,} rows this run")


if __name__ == "__main__":
    main()
