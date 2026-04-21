"""Smoke-test hardened recover_one against the 121 demo URIs.

Compares results to demo-snapshot.json (captured from the first-match
recovery run that achieved 90/121). Writes nothing to Neo4j.
"""
import json
import sys
import time

sys.path.insert(0, ".")
from reconcile_hubs import cypher, recover_one  # noqa: E402
import requests  # noqa: E402
from concurrent.futures import ThreadPoolExecutor, as_completed  # noqa: E402

with open("demo-snapshot.json") as f:
    snapshot = json.load(f)

uris = list(snapshot.keys())
print(f"Loading context for {len(uris)} demo URIs...")

rows = cypher(
    """
    UNWIND $uris AS u
    MATCH (h:ns0__Hub {uri: u})
    OPTIONAL MATCH (h)-[:ns0__title]->(t)
    WITH h, collect(DISTINCT t.ns0__mainTitle) AS title_arrays
    OPTIONAL MATCH (h)-[:ns0__contribution]->(c)-[:ns0__agent]->(a)
    WITH h, title_arrays, collect(DISTINCT a.uri) AS agent_uris
    RETURN h.uri AS uri, title_arrays, agent_uris
    """,
    {"uris": uris},
)

hubs = []
for r in rows:
    titles = []
    for arr in r.get("title_arrays") or []:
        if isinstance(arr, list):
            titles.extend(s for s in arr if isinstance(s, str))
        elif isinstance(arr, str):
            titles.append(arr)
    hubs.append(
        {
            "uri": r["uri"],
            "titles": list(dict.fromkeys(titles)),
            "agent_uris": r.get("agent_uris") or [],
        }
    )

print(f"Got {len(hubs)} Hubs. Running hardened recovery (strict, agent-verified, consensus)...\n")

session = requests.Session()
results = []
t0 = time.time()
with ThreadPoolExecutor(max_workers=8) as pool:
    futs = {pool.submit(recover_one, h, session, False): h for h in hubs}
    done = 0
    for fut in as_completed(futs):
        r = fut.result()
        results.append(r)
        done += 1
        if done % 20 == 0:
            print(f"  ... {done}/{len(hubs)}")
        time.sleep(1.6 / 8)

elapsed = time.time() - t0

recovered_now = {r["uri"]: r["canonical_uri"] for r in results if r["canonical_uri"]}
recovered_before = {u: s["canonical"] for u, s in snapshot.items() if s.get("canonical")}
disagreements = [r for r in results if (r.get("note") or "").startswith("disagreement")]

new_set = set(recovered_now.keys())
old_set = set(recovered_before.keys())

print(f"\n--- HARDENED RECOVERY RESULTS ({elapsed:.1f}s) ---")
print(f"Recovered now:    {len(new_set):3}/121 ({100 * len(new_set) / 121:.1f}%)")
print(f"Recovered before: {len(old_set):3}/121 ({100 * len(old_set) / 121:.1f}%)")
print(f"Disagreements (rejected): {len(disagreements)}")
print()

both = new_set & old_set
mismatched = [u for u in both if recovered_now[u] != recovered_before[u]]
print(f"Recovered both runs: {len(both)}")
print(f"  canonicals match: {len(both) - len(mismatched)}")
print(f"  canonicals DIFFER: {len(mismatched)}")
for u in mismatched[:5]:
    print(f"    {u}")
    print(f"      before: {recovered_before[u]}")
    print(f"      now:    {recovered_now[u]}")

dropped = old_set - new_set
print(f"\nDropped (was recovered, now gone): {len(dropped)}")
drop_reasons = {"disagreement": 0, "validation_failed": 0}
for u in dropped:
    match = next((r for r in results if r["uri"] == u), None)
    if match and (match.get("note") or "").startswith("disagreement"):
        drop_reasons["disagreement"] += 1
    else:
        drop_reasons["validation_failed"] += 1
print(f"  of which disagreement: {drop_reasons['disagreement']}")
print(f"  of which validation failed (no agent match / empty / 404): "
      f"{drop_reasons['validation_failed']}")

new_finds = new_set - old_set
print(f"\nNewly recovered (were gone before): {len(new_finds)}")

with open("demo-smoketest-results.json", "w") as f:
    json.dump(results, f, indent=2, default=str)
print("\nDetailed results -> demo-smoketest-results.json")
print("(NOTE: no writes to Neo4j — demo URIs remain in bare 'gone' state)")
