#!/usr/bin/env python3
"""Map LC bibliographic records (lc-bib-embeddings) to BIBFRAME Hubs (lc-bibframe-hubs).

Offline join on the authorized access point: a Hub's marcKey is a name/title
heading ($a name, $t title); a record has `creator` and `title`. Both sides are
normalized and joined in three tiers:

  1. aap                name + title  = Hub $a + $t
  2. variant_title      name + title  = Hub $a + one of its variant titles (catches translations)
  3. uniform_title_130  title only    = 130 Hub, for records with no creator, >= 2 title words

See README.md for the measured precision of each tier and the confidence labels.

    python build_bib_to_hub.py                    # writes out/bib_to_hub.parquet
    python build_bib_to_hub.py --stats            # also prints collocation numbers for the card
    python build_bib_to_hub.py --collocation out/collocation   # + small files for notebooks/hub-collocation.html
"""
import argparse
import json
import sys
import time
import urllib.request
from pathlib import Path

import duckdb

HERE = Path(__file__).resolve().parent
HUBS_REPO = "jimfhahn/lc-bibframe-hubs"
EMB_REPO = "jimfhahn/lc-bib-embeddings"
HF = "https://huggingface.co/datasets"

# Collective uniform titles ("Works", "Selections", "Paintings"…). Hubs with these
# titles carry variant titles that are often just the creator's name.
COLLECTIVE = (r"^(works|selections|correspondence|letters|poems|novels|essays|plays|speeches|"
              r"short stories|prose works|sermons|stories|writings|songs|piano music|vocal music|"
              r"instrumental music|chamber music|orchestra music|choral music|organ music|"
              r"paintings|sculptures|drawings|photographs)\b")
# Creative relationships = surprise tiers 1–3 of the VuFind plugin's RelationshipInferrer.php
# (also copied into notebooks/hub-family-explorer.html). Revisions, supplements, continuations,
# translations and series links (tiers 4–5) are not counted as collocation evidence.
CREATIVE = (
    "inspirationfor", "inspiredby", "parodyof", "imitationof", "derivative", "graphicnovelizationof",
    "novelizationof", "critiqueof", "commentaryon", "analysisof",
    "adaptedasmotionpicture", "motionpictureadaptationof", "adaptedastelevisionprogram", "televisionadaptationof",
    "operaadaptationof", "musicaltheatreadaptationof", "musicaltheatreadaptionof", "adaptedasmusicaltheatre",
    "variationsbasedon", "musicalvariationsbasedon", "dramatizationof", "radioadaptationof", "adaptedaslibretto",
    "verseadaptationof", "musicalsettingof", "settomusicas", "oratorioadaptationof", "incidentalmusicfor",
    "cadenzacomposedfor", "musicalvariations", "motionpicturescreenplaybasedon", "screenplayformotionpicture",
    "librettobasedon",
    "sequel", "sequelto", "prequel", "prequelto", "basedon", "adaptedas", "adaptationof", "librettofor", "libretto",
    "remakeof",
)


def embedding_shards() -> list[str]:
    urls = []
    with urllib.request.urlopen(f"https://huggingface.co/api/datasets/{EMB_REPO}/tree/main/data") as r:
        classes = [e["path"] for e in json.load(r) if e["type"] == "directory"]
    for c in classes:
        with urllib.request.urlopen(f"https://huggingface.co/api/datasets/{EMB_REPO}/tree/main/{c}") as r:
            urls += [f"{HF}/{EMB_REPO}/resolve/main/{e['path']}" for e in json.load(r) if e["path"].endswith(".parquet")]
    return sorted(urls)


def step(con, label, sql):
    t = time.time()
    con.execute(sql)
    print(f"  {label:<38} {time.time() - t:6.1f}s", file=sys.stderr)


def build(con, hubs, rels, shards):
    con.execute(r"""
        CREATE MACRO norm(s) AS trim(regexp_replace(regexp_replace(lower(strip_accents(coalesce(s, ''))),
            '[^a-z0-9 ]', ' ', 'g'), '\s+', ' ', 'g'));
        CREATE MACRO noart(s) AS regexp_replace(s,
            '^(the|a|an|le|la|les|l|der|die|das|el|los|las|il|lo|gli|un|une|ein|eine) ', '');
        CREATE MACRO nodate(s) AS trim(regexp_replace(regexp_replace(s,
            '\b[0-9]+\b|\b(active|approximately|ca|b|d|fl|century|or)\b', '', 'g'), '\s+', ' ', 'g'));
    """)
    files = ", ".join(f"'{u}'" for u in shards)
    step(con, f"records ({len(shards)} shards, text cols)", f"""
        CREATE TABLE recs AS SELECT lc_001, lccn, lcc_class, title, creator FROM read_parquet([{files}])""")
    step(con, "record keys", r"""
        CREATE TABLE rk AS SELECT lc_001,
            nodate(norm(regexp_replace(split_part(creator, ';', 1), '\([^)]*\)', '', 'g'))) AS name_k,
            noart(norm(regexp_replace(title, '\s+[:/=;].*$', ''))) AS title_k
        FROM recs""")
    step(con, "hub keys", rf"""
        CREATE TABLE hk AS
        WITH h AS (
            SELECT hub_id, marc_key, variant_titles, substr(marc_key, 1, 3) AS tag,
                   regexp_extract(marc_key, '\$a([^$]*)', 1) AS a,
                   regexp_extract(marc_key, '\$t([^$]*)', 1) AS t,
                   regexp_matches(marc_key, '\$[lskfmnopr]') AS qualified
            FROM read_parquet('{hubs}'))
        SELECT hub_id, marc_key, variant_titles, tag, qualified,
               CASE WHEN tag LIKE '1%' AND tag <> '130' THEN nodate(norm(a)) END AS name_k,
               noart(norm(CASE WHEN tag = '130' THEN a ELSE t END)) AS title_k,
               regexp_matches(norm(t), '{COLLECTIVE}') OR marc_key LIKE '%$kSelections%' AS collective
        FROM h""")
    step(con, "hub variant-title keys", """
        CREATE TABLE hv AS
        SELECT hub_id, name_k, noart(norm(v)) AS title_k, qualified, collective
        FROM (SELECT *, unnest(variant_titles) AS v FROM hk WHERE coalesce(name_k, '') <> '')""")
    step(con, "hub degree", f"""
        CREATE TABLE deg AS SELECT id, count(*) AS n FROM (
            SELECT source_id AS id FROM read_parquet('{rels}') UNION ALL
            SELECT target_id FROM read_parquet('{rels}')) GROUP BY 1""")
    # A match is "name-dominated" when the record title is mostly the creator's name
    # (4-letter prefix match per word): nothing else left ("Picasso" by Picasso, matched to
    # a generic `Picasso, Pablo. Picasso` Hub that gathers every such catalogue), or, for
    # variant titles, one word left and a collective Hub ("Bach-Festival" → Works). Checked
    # against LC's own Work links these are right well under half the time, so they stay
    # but are labelled low.
    step(con, "record title vs. name", r"""
        CREATE TABLE rn AS
        SELECT lc_001,
               len(list_filter(tt, x -> NOT list_bool_or(list_transform(nt, y -> left(x, 4) = left(y, 4))))) AS remaining,
               len(tt) AS ntok
        FROM (SELECT lc_001, list_filter(string_split(title_k, ' '), x -> len(x) > 1) AS tt,
                     string_split(name_k, ' ') AS nt
              FROM rk WHERE name_k <> '' AND title_k <> '')""")
    step(con, "candidates (3 tiers)", r"""
        CREATE TABLE cand AS
        SELECT r.lc_001, 1 AS tier, 'aap' AS how, h.hub_id, h.qualified,
               coalesce(n.ntok > 0 AND n.remaining = 0, false) AS name_dominated
        FROM rk r JOIN hk h ON h.name_k = r.name_k AND h.title_k = r.title_k
        LEFT JOIN rn n USING (lc_001)
        WHERE r.name_k <> '' AND r.title_k <> ''
        UNION ALL
        SELECT r.lc_001, 2, 'variant_title', h.hub_id, h.qualified,
               coalesce(n.ntok > n.remaining AND (n.remaining = 0 OR (n.remaining = 1 AND h.collective)), false)
        FROM rk r JOIN hv h ON h.name_k = r.name_k AND h.title_k = r.title_k
        LEFT JOIN rn n USING (lc_001)
        WHERE r.name_k <> '' AND r.title_k <> ''
        UNION ALL
        SELECT r.lc_001, 3, 'uniform_title_130', h.hub_id, h.qualified, false
        FROM rk r JOIN hk h ON h.tag = '130' AND h.title_k = r.title_k
        WHERE r.name_k = '' AND len(r.title_k) > 3 AND len(string_split(r.title_k, ' ')) >= 2""")
    # Per record keep the best tier, then non-name-dominated, then unqualified Hubs; among
    # what is left prefer the most-connected Hub (ties broken by hub_id).
    step(con, "pick best per record", """
        CREATE TABLE best AS
        WITH c AS (
            SELECT * FROM cand
            QUALIFY tier * 10 + name_dominated::int * 2 + qualified::int
                  = min(tier * 10 + name_dominated::int * 2 + qualified::int) OVER (PARTITION BY lc_001)),
        u AS (SELECT DISTINCT c.lc_001, c.how, c.hub_id, c.qualified, c.name_dominated, coalesce(d.n, 0) AS degree
              FROM c LEFT JOIN deg d ON d.id = c.hub_id)
        SELECT lc_001, any_value(how) AS how, any_value(qualified) AS hub_qualified,
               any_value(name_dominated) AS name_dominated, count(*) AS n_candidates,
               list(hub_id ORDER BY degree DESC, hub_id) AS candidates
        FROM u GROUP BY lc_001""")
    step(con, "mapping", """
        CREATE TABLE mapping0 AS
        SELECT b.lc_001, r.lccn, r.lcc_class, r.title, r.creator,
               b.candidates[1] AS hub_id, b.how AS match_method,
               CASE WHEN b.how = 'uniform_title_130' OR b.name_dominated THEN 'low'
                    WHEN b.n_candidates > 1 THEN 'medium' ELSE 'high' END AS confidence,
               b.name_dominated, b.hub_qualified, b.n_candidates, b.candidates
        FROM best b JOIN recs r USING (lc_001)""")
    # Dropping the subtitle collapses series whose volumes share a main title
    # ("Chicken soup for the soul : …", "Worst-case scenario survival handbook : …") onto one
    # Hub. Those Hubs show a pattern editions of one work do not: mostly different
    # subtitles AND records in 3+ LC classes (each book classed by its own subject).
    # Editions of a law or a classic (Constitution, Federalist, Leviathan) stay unflagged.
    step(con, "series-title check", r"""
        CREATE TABLE mapping AS
        WITH s AS (
            SELECT hub_id FROM (
                SELECT hub_id, count(*) AS n, count(DISTINCT lcc_class) AS ncls,
                       count(DISTINCT norm(nullif(trim(regexp_extract(split_part(title, ' / ', 1), '\s:\s(.*)$', 1)), ''))) AS nsub
                FROM mapping0 WHERE confidence = 'high' GROUP BY 1)
            WHERE ncls >= 3 AND nsub >= 4 AND nsub >= 0.5 * n)
        SELECT m.* REPLACE (CASE WHEN s.hub_id IS NOT NULL AND m.confidence = 'high' THEN 'medium' ELSE m.confidence END AS confidence),
               s.hub_id IS NOT NULL AS series_title
        FROM mapping0 m LEFT JOIN s USING (hub_id)""")


def stats(con, rels):
    q = lambda s: con.sql(s).show(max_width=200)
    print("\n# Coverage")
    q("""SELECT confidence, count(*) AS records, round(100.0 * count(*) / (SELECT count(*) FROM recs), 2) AS pct_of_all
         FROM mapping GROUP BY 1 ORDER BY 1""")
    q("""SELECT r.lcc_class, count(*) AS records,
                round(100.0 * count(m.lc_001) FILTER (WHERE m.confidence = 'high') / count(*), 1) AS pct_high
         FROM recs r LEFT JOIN mapping m USING (lc_001) GROUP BY 1 ORDER BY 3 DESC""")
    con.execute("CREATE TEMP TABLE m AS SELECT * FROM mapping WHERE confidence = 'high'")
    print("\n# Collocation (high confidence)")
    q("""SELECT count(*) AS hubs_with_records, count(*) FILTER (WHERE n >= 2) AS hubs_2plus_records,
                max(n) AS max_records_on_one_hub, count(*) FILTER (WHERE ncls >= 2) AS hubs_spanning_2plus_classes
         FROM (SELECT hub_id, count(*) AS n, count(DISTINCT lcc_class) AS ncls FROM m GROUP BY 1)""")
    creative = ", ".join(f"'{s}'" for s in CREATIVE)
    con.execute(f"""
        CREATE TEMP TABLE und AS
        SELECT source_id AS a, target_id AS b, rel_type FROM read_parquet('{rels}') UNION ALL
        SELECT target_id, source_id, rel_type FROM read_parquet('{rels}')""")
    con.execute("""
        CREATE TEMP TABLE hubcls AS
        SELECT hub_id, count(*) AS n, string_agg(DISTINCT lcc_class, '' ORDER BY lcc_class) AS cls FROM m GROUP BY 1""")
    q(f"""SELECT count(DISTINCT (u.a, u.b)) AS creative_hub_pairs_with_records_on_both_ends,
                 count(DISTINCT (u.a, u.b)) FILTER (WHERE x.cls <> y.cls) AS of_which_cross_class
          FROM und u JOIN hubcls x ON x.hub_id = u.a JOIN hubcls y ON y.hub_id = u.b
          WHERE u.rel_type IN ({creative}) AND u.a < u.b""")
    print("\n# Cross-class creative links (largest first)")
    q(f"""SELECT u.rel_type, left(ha.marc_key, 70) AS source, x.cls, x.n, left(hb.marc_key, 70) AS target, y.cls, y.n
          FROM und u JOIN hubcls x ON x.hub_id = u.a JOIN hubcls y ON y.hub_id = u.b
          JOIN hk ha ON ha.hub_id = u.a JOIN hk hb ON hb.hub_id = u.b
          WHERE u.rel_type IN ({creative}) AND x.cls <> y.cls AND u.a < u.b
          ORDER BY x.n + y.n DESC LIMIT 20""")


def collocation(con, rels, outdir):
    """Small files for the collocation notebook (high-confidence matches only)."""
    out = Path(outdir)
    out.mkdir(parents=True, exist_ok=True)
    creative = ", ".join(f"'{s}'" for s in CREATIVE)
    con.execute(rf"""
        CREATE OR REPLACE TEMP TABLE hi AS SELECT * FROM mapping WHERE confidence = 'high';
        CREATE OR REPLACE TEMP TABLE hubcls AS
            SELECT hub_id, lcc_class, count(*) AS n FROM hi GROUP BY ALL;
        CREATE OR REPLACE TEMP TABLE edges AS
            SELECT DISTINCT r.source_id, r.target_id, r.rel_type FROM read_parquet('{rels}') r
            WHERE r.rel_type IN ({creative})
              AND r.source_id IN (SELECT hub_id FROM hubcls) AND r.target_id IN (SELECT hub_id FROM hubcls)
              AND r.source_id <> r.target_id;
        -- Hubs worth charting: records on 2+ shelves, or one end of a creative link.
        CREATE OR REPLACE TEMP TABLE keep AS
            SELECT hub_id FROM hubcls GROUP BY 1 HAVING count(*) >= 2
            UNION SELECT source_id FROM edges UNION SELECT target_id FROM edges;
        CREATE OR REPLACE TEMP TABLE hublabel AS
            SELECT hub_id,
                   trim(regexp_replace(regexp_replace(substr(marc_key, 6), '\$[0-9]\S*', '', 'g'),
                        '\s*\$[a-z]', ' ', 'g')) AS label,
                   CASE WHEN tag <> '130' THEN trim(regexp_extract(marc_key, '\$a([^$]*)', 1), ' ,.') END AS hub_name,
                   trim(regexp_replace(regexp_extract(marc_key, CASE WHEN tag = '130' THEN '\$a(.*)$' ELSE '\$t(.*)$' END, 1),
                        '\s*\$[a-z]', ' ', 'g'), ' ,.;') AS hub_title
            FROM hk WHERE hub_id IN (SELECT hub_id FROM keep)""")
    con.execute(f"""COPY (
        SELECT c.hub_id, l.label, l.hub_name, l.hub_title, c.lcc_class, c.n,
               sum(c.n) OVER (PARTITION BY c.hub_id)::INTEGER AS hub_total,
               count(*) OVER (PARTITION BY c.hub_id) AS hub_classes
        FROM hubcls c JOIN hublabel l USING (hub_id) ORDER BY hub_id, lcc_class
        ) TO '{out / "colloc_hub_class.parquet"}' (FORMAT parquet, COMPRESSION zstd)""")
    con.execute(f"COPY (SELECT * FROM edges ORDER BY 1, 2) TO '{out / 'colloc_edges.parquet'}' (FORMAT parquet, COMPRESSION zstd)")
    con.execute(f"""COPY (
        SELECT lc_001, hub_id, lcc_class, title, creator FROM hi WHERE hub_id IN (SELECT hub_id FROM keep)
        ORDER BY hub_id, lcc_class, lc_001
        ) TO '{out / "colloc_records.parquet"}' (FORMAT parquet, COMPRESSION zstd)""")
    con.execute(f"""COPY (
        SELECT r.lcc_class, count(*) AS records,
               count(m.lc_001) FILTER (WHERE m.confidence = 'high') AS high,
               count(m.lc_001) FILTER (WHERE m.confidence = 'medium') AS medium,
               count(m.lc_001) FILTER (WHERE m.confidence = 'low') AS low
        FROM recs r LEFT JOIN mapping m USING (lc_001) GROUP BY 1 ORDER BY 1
        ) TO '{out / "colloc_coverage.parquet"}' (FORMAT parquet, COMPRESSION zstd)""")
    for f in sorted(out.glob("colloc_*.parquet")):
        n = con.sql(f"SELECT count(*) FROM read_parquet('{f}')").fetchone()[0]
        print(f"wrote {n:>8,} rows  {f.stat().st_size / 1e6:5.1f} MB  {f}", file=sys.stderr)


def main():
    ap = argparse.ArgumentParser(description=__doc__, formatter_class=argparse.RawDescriptionHelpFormatter)
    local = HERE.parent / "hf-dataset" / "out"
    ap.add_argument("--hubs", default=None, help="hubs.parquet (default: tools/hf-dataset/out or the HF dataset)")
    ap.add_argument("--relations", default=None, help="relations.parquet (same default)")
    ap.add_argument("--out", default=str(HERE / "out" / "bib_to_hub.parquet"))
    ap.add_argument("--db", default=":memory:", help="DuckDB file to keep intermediate tables")
    ap.add_argument("--stats", action="store_true", help="print coverage and collocation numbers")
    ap.add_argument("--collocation", metavar="DIR", help="also write the small colloc_*.parquet files for the notebook")
    a = ap.parse_args()
    pick = lambda arg, name: arg or (str(local / name) if (local / name).exists() else f"{HF}/{HUBS_REPO}/resolve/main/{name}")
    hubs, rels = pick(a.hubs, "hubs.parquet"), pick(a.relations, "relations.parquet")

    con = duckdb.connect(a.db)
    con.execute("INSTALL httpfs; LOAD httpfs;")
    print(f"hubs: {hubs}\nrelations: {rels}", file=sys.stderr)
    build(con, hubs, rels, embedding_shards())
    Path(a.out).parent.mkdir(parents=True, exist_ok=True)
    con.execute(f"COPY (SELECT * FROM mapping ORDER BY lcc_class, lc_001) TO '{a.out}' (FORMAT parquet, COMPRESSION zstd)")
    n = con.sql("SELECT count(*) FROM mapping").fetchone()[0]
    print(f"wrote {n:,} rows → {a.out}", file=sys.stderr)
    if a.stats:
        stats(con, rels)
    if a.collocation:
        collocation(con, rels, a.collocation)


if __name__ == "__main__":
    main()
