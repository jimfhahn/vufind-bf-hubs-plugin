#!/usr/bin/env python3
"""
Export the Hugging Face Parquet dataset to TSV files for the VuFind loader
(`php public/index.php bibframehub/load-hubs --dir <out>`).

Writes hubs.tsv, agents.tsv, relations.tsv — tab-separated, no header,
RFC 4180 quoting (quotes doubled), which PHP's fgetcsv reads natively.

Source defaults to the published dataset (hf://…); pass --source out to
use the local conversion output instead.
"""

import argparse
import os
import sys
import time

import duckdb

HF = "hf://datasets/jimfhahn/lc-bibframe-hubs/"


def main():
    ap = argparse.ArgumentParser(description=__doc__, formatter_class=argparse.RawDescriptionHelpFormatter)
    ap.add_argument("--source", default=HF, help="directory or hf:// prefix holding hubs.parquet + relations.parquet")
    ap.add_argument("--out", default="sql-out")
    args = ap.parse_args()

    src = args.source if args.source.endswith("/") else args.source + "/"
    os.makedirs(args.out, exist_ok=True)
    con = duckdb.connect()
    if src.startswith("hf://") or src.startswith("http"):
        con.execute("INSTALL httpfs; LOAD httpfs")

    t0 = time.time()
    con.execute(f"CREATE VIEW hubs AS SELECT * FROM read_parquet('{src}hubs.parquet')")
    con.execute(f"CREATE VIEW rels AS SELECT * FROM read_parquet('{src}relations.parquet')")
    con.execute("""
        CREATE TABLE deg AS
        SELECT id, count(*) AS n FROM (
            SELECT source_id AS id FROM rels UNION ALL SELECT target_id FROM rels
        ) GROUP BY id
    """)
    print(f"views ready ({time.time() - t0:.0f}s)", file=sys.stderr)

    csv_opts = "FORMAT CSV, DELIMITER '\t', HEADER false, QUOTE '\"', ESCAPE '\"'"

    con.execute(f"""
        COPY (
            SELECT h.hub_id,
                   h.title,
                   h.marc_key,
                   regexp_replace(h.lccn, '\\s+', '', 'g') AS lccn,
                   array_to_string(h.media, ',') AS media,
                   coalesce(d.n, 0) AS degree
            FROM hubs h LEFT JOIN deg d ON d.id = h.hub_id
            ORDER BY h.hub_id
        ) TO '{args.out}/hubs.tsv' ({csv_opts})
    """)
    print(f"hubs.tsv ({time.time() - t0:.0f}s)", file=sys.stderr)

    con.execute(f"""
        COPY (
            SELECT hub_id, unnest(agents) AS agent_uri FROM hubs WHERE len(agents) > 0
        ) TO '{args.out}/agents.tsv' ({csv_opts})
    """)
    print(f"agents.tsv ({time.time() - t0:.0f}s)", file=sys.stderr)

    con.execute(f"""
        COPY (
            SELECT source_id, target_id, rel_type FROM rels
        ) TO '{args.out}/relations.tsv' ({csv_opts})
    """)
    print(f"relations.tsv ({time.time() - t0:.0f}s)", file=sys.stderr)

    for f in ("hubs", "agents", "relations"):
        p = f"{args.out}/{f}.tsv"
        print(f"  {f}.tsv: {os.path.getsize(p) / 1e6:.1f} MB", file=sys.stderr)


if __name__ == "__main__":
    main()
