#!/usr/bin/env python3
"""
Export the LC BIBFRAME Hubs graph from the n10s Neo4j container to Parquet.

Produces (in --out, default ./out):
  raw/hub_titles.parquet   hub_id, title
  raw/hub_agents.parquet   hub_id, agent_uri
  raw/hub_types.parquet    hub_id, type_uri      (bf:Hub / bf:Work excluded)
  relations.parquet        source_id, target_id, rel_type, rel_type_uri, typed
  hubs.parquet             hub_id, title, agents[], media[]   (all ~2.9M Hubs, sorted by hub_id)
  hubs_connected.parquet   same columns, only Hubs that appear in relations.parquet
  rel_type_freq.parquet    rel_type, n

hub_id is the LC UUID with the "http://id.loc.gov/resources/hubs/" prefix stripped.
Cypher patterns mirror module/BibframeHub/src/BibframeHub/Graph/Neo4jService.php
(reified bf:Relation pattern, dual relationship-type URI prefixes, mainTitle
STRING/LIST normalization via valueType()).

Streaming design: each raw table is one non-aggregating Cypher stream, so
Neo4j never has to hold 2.9M grouping keys. Grouping happens in DuckDB.
"""

import argparse
import os
import sys
import time

import duckdb
import pyarrow as pa
import pyarrow.parquet as pq
from neo4j import GraphDatabase

HUB_PREFIX = "http://id.loc.gov/resources/hubs/"
BF = "http://id.loc.gov/ontologies/bibframe/"
GENERIC_TYPES = {BF + "Hub", BF + "Work"}
REL_PREFIXES = (
    "http://id.loc.gov/vocabulary/relationship/",
    "http://id.loc.gov/entities/relationships/",
)

Q_TITLES = """
MATCH (h:ns0__Hub)-[:ns0__title]->(t)
WITH h.uri AS uri,
     CASE WHEN valueType(t.ns0__mainTitle) STARTS WITH "LIST"
          THEN t.ns0__mainTitle[0] ELSE t.ns0__mainTitle END AS title
WHERE title IS NOT NULL
RETURN uri, title
"""

Q_AGENTS = """
MATCH (h:ns0__Hub)-[:ns0__contribution]->(c)-[:ns0__agent]->(a)
WHERE a.uri IS NOT NULL
RETURN h.uri AS uri, a.uri AS agent
"""

Q_TYPES = """
MATCH (h:ns0__Hub)-[:rdf__type]->(t)
WHERE t.uri IS NOT NULL
RETURN h.uri AS uri, t.uri AS type
"""

Q_RELATIONS = """
MATCH (s:ns0__Hub)-[:ns0__relation]->(rel:ns0__Relation)-[:ns0__associatedResource]->(t:ns0__Hub)
OPTIONAL MATCH (rel)-[:ns0__relationship]->(rt)
RETURN s.uri AS source, t.uri AS target, rt.uri AS relTypeUri
"""


def strip_hub(uri: str) -> str:
    return uri[len(HUB_PREFIX):] if uri.startswith(HUB_PREFIX) else uri


def rel_slug(uri):
    if uri is None or uri.startswith("bnode"):
        return "related", False
    for p in REL_PREFIXES:
        if uri.startswith(p):
            return uri[len(p):].lower(), True
    return uri, True


def stream_to_parquet(session, query, path, schema, transform, batch_size=100_000):
    """Run a streaming read and write rows to Parquet in fixed-size row groups."""
    t0 = time.time()
    n = 0
    cols = {f.name: [] for f in schema}
    writer = pq.ParquetWriter(path, schema, compression="zstd")

    def flush():
        nonlocal cols
        if not cols[schema[0].name]:
            return
        writer.write_table(pa.table(cols, schema=schema))
        cols = {f.name: [] for f in schema}

    result = session.run(query)
    for rec in result:
        row = transform(rec)
        if row is None:
            continue
        for k, v in row.items():
            cols[k].append(v)
        n += 1
        if n % batch_size == 0:
            flush()
            print(f"  {os.path.basename(path)}: {n:,} rows  ({time.time() - t0:.0f}s)", file=sys.stderr)
    flush()
    writer.close()
    print(f"  {os.path.basename(path)}: {n:,} rows total ({time.time() - t0:.0f}s)", file=sys.stderr)
    return n


def export_raw(driver, database, raw_dir):
    os.makedirs(raw_dir, exist_ok=True)
    str_ = pa.string()

    with driver.session(database=database, fetch_size=10_000) as s:
        print("relations", file=sys.stderr)

        def rel_row(r):
            slug, typed = rel_slug(r["relTypeUri"])
            return {
                "source_id": strip_hub(r["source"]),
                "target_id": strip_hub(r["target"]),
                "rel_type": slug,
                "rel_type_uri": r["relTypeUri"],
                "typed": typed,
            }

        stream_to_parquet(
            s, Q_RELATIONS, os.path.join(raw_dir, "..", "relations.parquet"),
            pa.schema([
                ("source_id", str_), ("target_id", str_), ("rel_type", str_),
                ("rel_type_uri", str_), ("typed", pa.bool_()),
            ]),
            rel_row,
        )

        print("titles", file=sys.stderr)
        stream_to_parquet(
            s, Q_TITLES, os.path.join(raw_dir, "hub_titles.parquet"),
            pa.schema([("hub_id", str_), ("title", str_)]),
            lambda r: {"hub_id": strip_hub(r["uri"]), "title": r["title"]},
        )

        print("agents", file=sys.stderr)
        stream_to_parquet(
            s, Q_AGENTS, os.path.join(raw_dir, "hub_agents.parquet"),
            pa.schema([("hub_id", str_), ("agent_uri", str_)]),
            lambda r: {"hub_id": strip_hub(r["uri"]), "agent_uri": r["agent"]},
        )

        print("types", file=sys.stderr)
        stream_to_parquet(
            s, Q_TYPES, os.path.join(raw_dir, "hub_types.parquet"),
            pa.schema([("hub_id", str_), ("type_uri", str_)]),
            lambda r: None if r["type"] in GENERIC_TYPES
            else {"hub_id": strip_hub(r["uri"]), "type_uri": r["type"]},
        )


def build_final(out_dir):
    """Group raw pair tables into one row per Hub; derive connected subset + frequencies."""
    con = duckdb.connect()
    raw = os.path.join(out_dir, "raw")
    con.execute(f"""
        CREATE OR REPLACE VIEW titles AS SELECT * FROM read_parquet('{raw}/hub_titles.parquet');
        CREATE OR REPLACE VIEW agents AS SELECT * FROM read_parquet('{raw}/hub_agents.parquet');
        CREATE OR REPLACE VIEW types  AS SELECT * FROM read_parquet('{raw}/hub_types.parquet');
        CREATE OR REPLACE VIEW rels   AS SELECT * FROM read_parquet('{out_dir}/relations.parquet');

        CREATE OR REPLACE TABLE hubs AS
        WITH ids AS (
            SELECT hub_id FROM titles
            UNION SELECT hub_id FROM agents
            UNION SELECT hub_id FROM types
            UNION SELECT source_id FROM rels
            UNION SELECT target_id FROM rels
        ),
        t AS (SELECT hub_id, any_value(title) AS title FROM titles GROUP BY hub_id),
        a AS (SELECT hub_id, list(DISTINCT agent_uri ORDER BY agent_uri) AS agents FROM agents GROUP BY hub_id),
        m AS (SELECT hub_id, list(DISTINCT replace(type_uri, '{BF}', '') ORDER BY 1) AS media FROM types GROUP BY hub_id)
        SELECT i.hub_id, t.title,
               coalesce(a.agents, []) AS agents,
               coalesce(m.media, []) AS media
        FROM ids i
        LEFT JOIN t USING (hub_id)
        LEFT JOIN a USING (hub_id)
        LEFT JOIN m USING (hub_id)
        ORDER BY i.hub_id;
    """)
    con.execute(f"COPY hubs TO '{out_dir}/hubs.parquet' (FORMAT PARQUET, COMPRESSION ZSTD, ROW_GROUP_SIZE 100000)")

    con.execute(f"""
        COPY (
            SELECT h.* FROM hubs h
            WHERE h.hub_id IN (SELECT source_id FROM rels UNION SELECT target_id FROM rels)
            ORDER BY h.hub_id
        ) TO '{out_dir}/hubs_connected.parquet' (FORMAT PARQUET, COMPRESSION ZSTD, ROW_GROUP_SIZE 100000)
    """)
    con.execute(f"""
        COPY (
            SELECT rel_type, count(*) AS n FROM rels GROUP BY rel_type ORDER BY n DESC
        ) TO '{out_dir}/rel_type_freq.parquet' (FORMAT PARQUET)
    """)

    for tbl in ("hubs", "hubs_connected", "relations", "rel_type_freq"):
        n = con.execute(f"SELECT count(*) FROM read_parquet('{out_dir}/{tbl}.parquet')").fetchone()[0]
        size = os.path.getsize(f"{out_dir}/{tbl}.parquet") / 1e6
        print(f"  {tbl}.parquet: {n:,} rows, {size:.1f} MB", file=sys.stderr)


def main():
    ap = argparse.ArgumentParser(description=__doc__, formatter_class=argparse.RawDescriptionHelpFormatter)
    ap.add_argument("--uri", default=os.environ.get("NEO4J_URI", "neo4j://localhost:7689"))
    ap.add_argument("--user", default=os.environ.get("NEO4J_USER", "neo4j"))
    ap.add_argument("--password", default=os.environ.get("NEO4J_PASSWORD", "bibframe123"))
    ap.add_argument("--database", default="neo4j")
    ap.add_argument("--out", default="out")
    ap.add_argument("--skip-export", action="store_true", help="Reuse existing raw/ and relations.parquet; only rebuild final tables")
    args = ap.parse_args()

    os.makedirs(args.out, exist_ok=True)
    if not args.skip_export:
        driver = GraphDatabase.driver(args.uri, auth=(args.user, args.password))
        try:
            driver.verify_connectivity()
            export_raw(driver, args.database, os.path.join(args.out, "raw"))
        finally:
            driver.close()

    print("building final tables", file=sys.stderr)
    build_final(args.out)


if __name__ == "__main__":
    main()
