#!/usr/bin/env python3
"""Do subject headings or record-level links already connect the collocation page's cross-shelf pairs?

For every creative Hub pair whose records sit in different LC classes (the "crosses shelves" pairs
on notebooks/hub-collocation.html), fetch up to N MARC records per side from id.loc.gov and report:

  shared_lcsh   an identical LCSH heading (600/610/630/650/651, 2nd indicator 0) on both sides
  shared_main   the same main heading ($a), e.g. a person or character both works are about
  record_link   a record on one side names the other work (600/630/700/730/740 with the other
                work's author and title), i.e. the one-record, usually untyped form of the Hub link

    python lcsh_overlap.py                          # reads out/collocation/ if present, else Hugging Face
    python lcsh_overlap.py --per-side 5 --verbose
"""
import argparse
import concurrent.futures as cf
import re
import unicodedata
import urllib.request
from pathlib import Path

import duckdb

HERE = Path(__file__).resolve().parent
REMOTE = "https://huggingface.co/datasets/jimfhahn/lc-bibframe-hubs/resolve/main/collocation/"
SUBJECT_TAGS = {"600", "610", "630", "650", "651"}
LINK_TAGS = {"600", "630", "700", "730", "740"}


def norm(s):
    s = unicodedata.normalize("NFKD", s or "").encode("ascii", "ignore").decode().lower()
    return re.sub(r"\s+", " ", re.sub(r"[^a-z0-9 ]", " ", s)).strip()


def fetch_marc(lc_001):
    url = f"https://id.loc.gov/resources/instances/{lc_001}.marcxml.xml"
    try:
        xml = urllib.request.urlopen(url, timeout=30).read().decode()
    except Exception:
        return None
    fields = []
    for m in re.finditer(r'<(?:marcxml:)?datafield tag="(\d{3})" ind1="(.)" ind2="(.)">(.*?)</(?:marcxml:)?datafield>', xml, re.S):
        fields.append((m.group(1), m.group(3), re.findall(r'code="(.)">([^<]*)<', m.group(4))))
    return fields


def headings(fields):
    full, main = set(), set()
    for tag, ind2, subs in fields:
        if tag in SUBJECT_TAGS and ind2 == "0" and subs:
            full.add(" -- ".join(norm(v) for c, v in subs if c in "abcdtvxyz"))
            main.add(norm(subs[0][1]))
    return full, main


def side_headings(records):
    full, main = set(), set()
    for fields in records:
        f, m = headings(fields)
        full |= f
        main |= m
    return full, main


def names_work(fields, name, title):
    surname, t = norm(name).split(" ")[0] if name else "", norm(title)[:25]
    for tag, _, subs in fields:
        if tag in LINK_TAGS and (any(c == "t" for c, _ in subs) or tag in ("630", "730")):
            entry = norm(" ".join(v for c, v in subs if c in "at"))
            if t and t in entry and (not surname or entry.startswith(surname)):
                return True
    return False


def main():
    ap = argparse.ArgumentParser(description=__doc__, formatter_class=argparse.RawDescriptionHelpFormatter)
    ap.add_argument("--dir", default=HERE / "out" / "collocation", type=Path)
    ap.add_argument("--per-side", type=int, default=5, help="max records fetched per Hub (default 5)")
    ap.add_argument("--verbose", action="store_true", help="print one line per pair")
    a = ap.parse_args()
    src = lambda f: f"'{a.dir / f}'" if (a.dir / f).exists() else f"'{REMOTE}{f}'"

    pairs = duckdb.sql(f"""
        WITH cls AS (SELECT hub_id, string_agg(lcc_class, '' ORDER BY lcc_class) AS c,
                            any_value(hub_name) AS nm, any_value(hub_title) AS tt
                     FROM read_parquet({src('colloc_hub_class.parquet')}) GROUP BY 1),
             recs AS (SELECT hub_id, list(lc_001 ORDER BY lc_001)[1:{a.per_side}] AS ids
                      FROM read_parquet({src('colloc_records.parquet')}) GROUP BY 1),
             e AS (SELECT least(source_id, target_id) AS x, greatest(source_id, target_id) AS y, min(rel_type) AS rel
                   FROM read_parquet({src('colloc_edges.parquet')}) GROUP BY 1, 2)
        SELECT e.rel, a.nm, a.tt, ra.ids, b.nm, b.tt, rb.ids
        FROM e JOIN cls a ON a.hub_id = e.x JOIN cls b ON b.hub_id = e.y
        JOIN recs ra ON ra.hub_id = e.x JOIN recs rb ON rb.hub_id = e.y
        WHERE a.c <> b.c ORDER BY a.nm, a.tt""").fetchall()

    ids = {i for p in pairs for i in p[3] + p[6]}
    with cf.ThreadPoolExecutor(6) as ex:
        marc = dict(zip(ids, ex.map(fetch_marc, ids)))
    missing = sum(v is None for v in marc.values())

    counts = {"shared_lcsh": 0, "shared_main": 0, "record_link": 0, "neither": 0}
    for rel, a_nm, a_tt, a_ids, b_nm, b_tt, b_ids in pairs:
        A = [marc[i] for i in a_ids if marc[i]]
        B = [marc[i] for i in b_ids if marc[i]]
        fa, ma = side_headings(A)
        fb, mb = side_headings(B)
        shared, shared_main = fa & fb, (ma & mb) - {""}
        link = any(names_work(f, b_nm, b_tt) for f in A) or any(names_work(f, a_nm, a_tt) for f in B)
        counts["shared_lcsh"] += bool(shared)
        counts["shared_main"] += bool(shared_main)
        counts["record_link"] += link
        counts["neither"] += not shared and not link
        if a.verbose:
            flag = ("L" if link else "-") + ("S" if shared else "s" if shared_main else "-")
            print(f"{flag}  {a_nm}, {a_tt}  --{rel}--  {b_nm}, {b_tt}   {sorted(shared or shared_main)[:2]}")

    print(f"cross-shelf pairs: {len(pairs)}  (records fetched: {len(ids) - missing}/{len(ids)})")
    for k, v in counts.items():
        print(f"  {k:12} {v}")


if __name__ == "__main__":
    main()
