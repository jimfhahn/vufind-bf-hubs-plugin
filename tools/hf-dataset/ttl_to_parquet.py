#!/usr/bin/env python3
"""
Convert the LC BIBFRAME Hubs bulk dump (hubs.bibframe.ttl.gz) straight to
Parquet — no Neo4j, no decompression to disk.

The dump is organized as self-contained blocks:

    # BEGIN /resources/hubs/<uuid>
    <hub> a bf:Hub, bf:Work ; ... .
    # END /resources/hubs/<uuid>

All blank nodes are block-local, so each block can be parsed independently
(pyoxigraph, lenient mode) and fanned out to worker processes.

Outputs (in --out, default ./out):
  hubs.parquet             hub_id, title, variant_titles[], marc_key, lccn, agents[], media[]   sorted by hub_id
  hubs_connected.parquet   subset of hubs.parquet that participate in a relation
  relations.parquet        source_id, target_id, rel_type, rel_type_uri, typed
  rel_type_freq.parquet    rel_type, n

hub_id is the LC UUID (prefix "http://id.loc.gov/resources/hubs/" stripped).
Slug/prefix handling mirrors Neo4jService::findRelatedHubs in the plugin.
"""

import argparse
import gzip
import os
import sys
import time
from multiprocessing import Pool

import duckdb
import pyarrow as pa
import pyarrow.parquet as pq
import pyoxigraph as ox

HUB_PREFIX = "http://id.loc.gov/resources/hubs/"
BF = "http://id.loc.gov/ontologies/bibframe/"
BFLC = "http://id.loc.gov/ontologies/bflc/"
RDF_TYPE = "http://www.w3.org/1999/02/22-rdf-syntax-ns#type"
RDF_VALUE = "http://www.w3.org/1999/02/22-rdf-syntax-ns#value"
GENERIC_TYPES = {BF + "Hub", BF + "Work"}
REL_PREFIXES = (
    "http://id.loc.gov/vocabulary/relationship/",
    "http://id.loc.gov/entities/relationships/",
)

P_TITLE, P_MAINTITLE, T_VARIANT = BF + "title", BF + "mainTitle", BF + "VariantTitle"
P_CONTRIB, P_AGENT = BF + "contribution", BF + "agent"
P_RELATION, P_ASSOC, P_RELSHIP = BF + "relation", BF + "associatedResource", BF + "relationship"
P_IDBY, T_LCCN = BF + "identifiedBy", BF + "Lccn"
P_MARCKEY = BFLC + "marcKey"

HUB_SCHEMA = pa.schema([
    ("hub_id", pa.string()), ("title", pa.string()), ("variant_titles", pa.list_(pa.string())),
    ("marc_key", pa.string()), ("lccn", pa.string()),
    ("agents", pa.list_(pa.string())), ("media", pa.list_(pa.string())),
])
REL_SCHEMA = pa.schema([
    ("source_id", pa.string()), ("target_id", pa.string()), ("rel_type", pa.string()),
    ("rel_type_uri", pa.string()), ("typed", pa.bool_()),
])

_PREFIXES = b""  # set in each worker via initializer


def _init_worker(prefixes: bytes):
    global _PREFIXES
    _PREFIXES = prefixes


def strip_hub(uri: str) -> str:
    return uri[len(HUB_PREFIX):] if uri.startswith(HUB_PREFIX) else uri


def rel_slug(uri):
    if uri is None:
        return "related", False
    for p in REL_PREFIXES:
        if uri.startswith(p):
            return uri[len(p):].lower(), True
    return uri, True


def parse_block(block: bytes):
    """Parse one hub block → (hub_row | None, [relation_rows]). Never raises."""
    # subject -> predicate -> [objects]; objects are (kind, value): kind ∈ {"iri","bnode","lit"}
    spo = {}
    try:
        for t in ox.parse(input=_PREFIXES + block, format=ox.RdfFormat.TURTLE, lenient=True):
            s, p, o = t.subject, t.predicate, t.object
            if isinstance(o, ox.NamedNode):
                ov = ("iri", o.value)
            elif isinstance(o, ox.BlankNode):
                ov = ("bnode", o.value)
            else:
                ov = ("lit", o.value)
            sk = s.value
            spo.setdefault(sk, {}).setdefault(p.value, []).append(ov)
    except Exception as e:  # noqa: BLE001 — one bad block must not kill the run
        return None, [], f"parse error: {e}"

    hub = None
    for sk, preds in spo.items():
        if sk.startswith(HUB_PREFIX) and any(v == BF + "Hub" for k, v in preds.get(RDF_TYPE, [])):
            hub = sk
            break
    if hub is None:
        return None, [], "no bf:Hub subject"

    preds = spo[hub]

    def objs(subject, pred):
        return spo.get(subject, {}).get(pred, [])

    # A Hub may carry several bf:title bnodes; only the one typed bf:Title (not
    # bf:VariantTitle) is the authoritative main title.
    title, variants = None, []
    for kind, tnode in objs(hub, P_TITLE):
        if kind != "bnode":
            continue
        main = next((v for k, v in objs(tnode, P_MAINTITLE) if k == "lit"), None)
        if main is None:
            continue
        if any(v == T_VARIANT for k, v in objs(tnode, RDF_TYPE)):
            variants.append(main)
        elif title is None:
            title = main
    if title is None and variants:
        title = variants.pop(0)

    marc_key = next((v for k, v in preds.get(P_MARCKEY, []) if k == "lit"), None)

    lccn = None
    for kind, idn in objs(hub, P_IDBY):
        if kind == "bnode" and any(v == T_LCCN for k, v in objs(idn, RDF_TYPE)):
            lccn = next((v for k, v in objs(idn, RDF_VALUE) if k == "lit"), None)
            if lccn:
                break

    agents = sorted({v for k, c in objs(hub, P_CONTRIB) if k == "bnode"
                     for k2, v in objs(c, P_AGENT) if k2 == "iri"})
    media = sorted({v[len(BF):] if v.startswith(BF) else v
                    for k, v in preds.get(RDF_TYPE, []) if k == "iri" and v not in GENERIC_TYPES})

    hub_id = strip_hub(hub)
    rels = []
    for kind, rnode in objs(hub, P_RELATION):
        if kind != "bnode":
            continue
        rt_uri = next((v for k, v in objs(rnode, P_RELSHIP) if k == "iri"), None)
        slug, typed = rel_slug(rt_uri)
        for k2, target in objs(rnode, P_ASSOC):
            if k2 == "iri" and target.startswith(HUB_PREFIX):
                rels.append((hub_id, strip_hub(target), slug, rt_uri, typed))

    return (hub_id, title, variants, marc_key, lccn, agents, media), rels, None


def parse_batch(blocks):
    hubs, rels, errors = [], [], []
    for b in blocks:
        h, r, err = parse_block(b)
        if h:
            hubs.append(h)
        rels.extend(r)
        if err:
            errors.append(err)
    return hubs, rels, errors


def iter_blocks(path):
    """Yield (prefix_header_bytes once as first item, then each hub block as bytes)."""
    prefixes, block, in_block = [], [], False
    with gzip.open(path, "rb") as f:
        for line in f:
            if not in_block:
                if line.startswith(b"@prefix"):
                    prefixes.append(line)
                elif line.startswith(b"# BEGIN"):
                    if prefixes:
                        yield b"".join(prefixes)
                        prefixes = None
                    in_block, block = True, []
            else:
                if line.startswith(b"# END"):
                    in_block = False
                    yield b"".join(block)
                else:
                    block.append(line)


def batched(it, n):
    buf = []
    for x in it:
        buf.append(x)
        if len(buf) == n:
            yield buf
            buf = []
    if buf:
        yield buf


class Writer:
    def __init__(self, path, schema, flush_at=200_000):
        self.schema, self.rows, self.flush_at, self.n = schema, [], flush_at, 0
        self.w = pq.ParquetWriter(path, schema, compression="zstd")

    def extend(self, rows):
        self.rows.extend(rows)
        if len(self.rows) >= self.flush_at:
            self.flush()

    def flush(self):
        if self.rows:
            cols = list(zip(*self.rows))
            self.w.write_table(pa.table({f.name: list(c) for f, c in zip(self.schema, cols)}, schema=self.schema))
            self.n += len(self.rows)
            self.rows = []

    def close(self):
        self.flush()
        self.w.close()


def convert(ttl_gz, out_dir, workers, batch_size, limit):
    t0 = time.time()
    gen = iter_blocks(ttl_gz)
    prefixes = next(gen)
    if limit:
        import itertools
        gen = itertools.islice(gen, limit)

    hub_w = Writer(os.path.join(out_dir, "hubs_unsorted.parquet"), HUB_SCHEMA)
    rel_w = Writer(os.path.join(out_dir, "relations_unsorted.parquet"), REL_SCHEMA)
    n_blocks, n_err, err_samples = 0, 0, []

    with Pool(workers, initializer=_init_worker, initargs=(prefixes,)) as pool:
        for hubs, rels, errors in pool.imap_unordered(parse_batch, batched(gen, batch_size), chunksize=1):
            hub_w.extend(hubs)
            rel_w.extend(rels)
            n_blocks += batch_size
            n_err += len(errors)
            if len(err_samples) < 10:
                err_samples.extend(errors[: 10 - len(err_samples)])
            if n_blocks % (batch_size * 50) == 0:
                el = time.time() - t0
                print(f"  ~{n_blocks:,} blocks  {hub_w.n + len(hub_w.rows):,} hubs  "
                      f"{rel_w.n + len(rel_w.rows):,} relations  {n_err} errors  {el:.0f}s", file=sys.stderr)
    hub_w.close()
    rel_w.close()
    print(f"parsed {hub_w.n:,} hubs, {rel_w.n:,} relations, {n_err} block errors in {time.time() - t0:.0f}s",
          file=sys.stderr)
    for e in err_samples:
        print("  sample error:", e[:200], file=sys.stderr)


def finalize(out_dir):
    con = duckdb.connect()
    hu = os.path.join(out_dir, "hubs_unsorted.parquet")
    ru = os.path.join(out_dir, "relations_unsorted.parquet")
    con.execute(f"""
        COPY (SELECT * FROM read_parquet('{ru}') ORDER BY source_id, target_id)
        TO '{out_dir}/relations.parquet' (FORMAT PARQUET, COMPRESSION ZSTD, ROW_GROUP_SIZE 100000)
    """)
    con.execute(f"""
        COPY (SELECT * FROM read_parquet('{hu}') ORDER BY hub_id)
        TO '{out_dir}/hubs.parquet' (FORMAT PARQUET, COMPRESSION ZSTD, ROW_GROUP_SIZE 100000)
    """)
    con.execute(f"""
        COPY (
            SELECT h.* FROM read_parquet('{out_dir}/hubs.parquet') h
            WHERE h.hub_id IN (SELECT source_id FROM read_parquet('{out_dir}/relations.parquet')
                               UNION SELECT target_id FROM read_parquet('{out_dir}/relations.parquet'))
            ORDER BY h.hub_id
        ) TO '{out_dir}/hubs_connected.parquet' (FORMAT PARQUET, COMPRESSION ZSTD, ROW_GROUP_SIZE 100000)
    """)
    con.execute(f"""
        COPY (SELECT rel_type, count(*) AS n FROM read_parquet('{out_dir}/relations.parquet')
              GROUP BY rel_type ORDER BY n DESC)
        TO '{out_dir}/rel_type_freq.parquet' (FORMAT PARQUET)
    """)
    os.remove(hu)
    os.remove(ru)
    for tbl in ("hubs", "hubs_connected", "relations", "rel_type_freq"):
        n = con.execute(f"SELECT count(*) FROM read_parquet('{out_dir}/{tbl}.parquet')").fetchone()[0]
        size = os.path.getsize(f"{out_dir}/{tbl}.parquet") / 1e6
        print(f"  {tbl}.parquet: {n:,} rows, {size:.1f} MB", file=sys.stderr)


def main():
    ap = argparse.ArgumentParser(description=__doc__, formatter_class=argparse.RawDescriptionHelpFormatter)
    ap.add_argument("ttl_gz", help="path to hubs.bibframe.ttl.gz")
    ap.add_argument("--out", default="out")
    ap.add_argument("--workers", type=int, default=max(1, os.cpu_count() - 2))
    ap.add_argument("--batch-size", type=int, default=500, help="hub blocks per worker task")
    ap.add_argument("--limit", type=int, default=0, help="only process the first N blocks (smoke test)")
    ap.add_argument("--skip-parse", action="store_true", help="reuse *_unsorted.parquet; only run finalize")
    args = ap.parse_args()

    os.makedirs(args.out, exist_ok=True)
    if not args.skip_parse:
        convert(args.ttl_gz, args.out, args.workers, args.batch_size, args.limit)
    print("finalizing", file=sys.stderr)
    finalize(args.out)


if __name__ == "__main__":
    main()
