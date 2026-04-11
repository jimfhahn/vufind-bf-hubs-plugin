#!/usr/bin/env python3
"""
Surprise Scoring Model for BIBFRAME Hub Relationships
=====================================================
Prototype scoring that prioritizes non-obvious, interesting connections.

Surprise = f(relationship_type_tier, rarity_bonus, author_distance, medium_crossing)
"""

import json
import math
import sys
from urllib.request import Request, urlopen
from urllib.parse import quote

NEO4J_URL = "http://localhost:7474/db/neo4j/tx/commit"
NEO4J_AUTH = ("neo4j", "bibframe123")

# ─── Relationship Type Tiers ───────────────────────────────────────
# Tier 1: Creative transformations (most surprising — different lens on same material)
TIER_1 = {
    "inspirationfor", "inspiredby", "parodyof", "imitationof",
    "derivative", "graphicnovelizationof", "novelizationof",
    "critiqueof", "commentaryon", "analysisof",
}

# Tier 2: Cross-medium adaptations (surprising — jumps to different art form)
TIER_2 = {
    "adaptedasmotionpicture", "motionpictureadaptationof",
    "adaptedastelevisionprogram", "televisionadaptationof",
    "operaadaptationof", "musicaltheatreadaptationof",
    "adaptedasmusicaltheatre", "variationsbasedon", "musicalvariationsbasedon",
    "dramatizationof", "radioadaptationof", "adaptedaslibretto",
    "verseadaptationof", "musicalsettingof", "settomusicas",
    "oratorioadaptationof", "incidentalmusicfor",
    "cadenzacomposedfor", "musicalvariations",
    "motionpicturescreenplaybasedon", "screenplayformotionpicture",
    "librettobasedon",
}

# Tier 3: Narrative continuations & direct adaptations (moderately surprising)
TIER_3 = {
    "sequel", "sequelto", "prequel", "prequelto",
    "basedon", "adaptedas", "adaptationof",
    "librettofor", "libretto", "remakeof",
}

# Tier 4: Serial/structural relationships (less surprising)
TIER_4 = {
    "continuedby", "continuationof", "continues", "precededby",
    "succeededby", "expandedas", "expandedversionof",
    "abridgedas", "abridgementof", "revisionof", "revisedas",
    "augmentationof", "augmentedby", "complementedby",
    "supplementto", "supplement", "mergerof", "mergedtoform",
    "replacementof", "replacedby", "paraphraseof",
    "freetranslationof", "absorbedby", "absorptionof",
    "separatedfrom", "continuedinpartby",
}

# Tier 5: Predictable/common (least surprising)
TIER_5 = {
    "translator", "translationof", "translatedas",
    "editor", "compiler", "editorofcompilation",
    "inseries", "containedin", "containerof", "contains",
    "subseriesof", "seriescontainerof", "issuedas",
    "author", "creator", "contributor", "publisher",
    "issuingbody", "sponsoringbody", "founder",
    "filmdirector", "performer", "actor", "singer",
    "producer", "lyricist", "composer", "artist",
    "illustrator", "photographer", "host",
    "dedicatee", "honouree", "addressee", "formerowner",
    "writerofaddedcommentary", "writerofintroduction",
    "writerofsupplementarytextualcontent", "writerofaddedtext",
    "writerofforeword", "attributedname",
    "related", "descriptionof", "setting",
    "musicformotionpicture", "motionpicturemusic",
    "musicfortelevisionprogram", "incidentalmusic", "musicfor",
}

# Direct edge types (not via bflc:relationship)
DIRECT_EDGE_TIERS = {
    "ns0__translationOf": 5,
    "ns0__relatedTo": 3,       # generic, could be anything
    "ns0__arrangementOf": 4,
}

# Base scores per tier
TIER_SCORES = {1: 90, 2: 75, 3: 55, 4: 30, 5: 10}

# Medium types that indicate non-text works
INTERESTING_MEDIA = {
    "http://id.loc.gov/ontologies/bibframe/MovingImage": "film/video",
    "http://id.loc.gov/ontologies/bibframe/Audio": "audio",
    "http://id.loc.gov/ontologies/bibframe/NotatedMusic": "music",
    "http://id.loc.gov/ontologies/bibframe/NotatedMovement": "dance",
    "http://id.loc.gov/ontologies/bibframe/Multimedia": "multimedia",
    "http://id.loc.gov/ontologies/bibframe/Arrangement": "arrangement",
}


def cypher(statement, params=None):
    """Execute a Cypher query and return rows as dicts."""
    import base64
    body = {"statements": [{"statement": statement}]}
    if params:
        body["statements"][0]["parameters"] = params
    data = json.dumps(body).encode()
    auth = base64.b64encode(f"{NEO4J_AUTH[0]}:{NEO4J_AUTH[1]}".encode()).decode()
    req = Request(NEO4J_URL, data=data, headers={
        "Content-Type": "application/json",
        "Authorization": f"Basic {auth}",
    })
    resp = urlopen(req)
    result = json.loads(resp.read())
    if result.get("errors"):
        for e in result["errors"]:
            print(f"ERROR: {e['message'][:500]}", file=sys.stderr)
        return []
    rows = []
    for r in result["results"]:
        cols = r["columns"]
        for row in r["data"]:
            rows.append(dict(zip(cols, row["row"])))
    return rows


def get_relationship_type_frequencies():
    """Build a frequency map for all bflc:relationship types."""
    rows = cypher("""
        MATCH (:ns0__Hub)-[:ns1__relationship]->(rel:ns1__Relationship)-[:ns1__relation]->(rt)
        WHERE NOT rt.uri STARTS WITH 'bnode'
        RETURN replace(rt.uri, 'http://id.loc.gov/entities/relationships/', '') AS relType,
               count(*) AS freq
    """)
    return {r["relType"]: r["freq"] for r in rows}


def get_tier(rel_type):
    """Return the tier (1-5) for a relationship type string."""
    rt = rel_type.lower()
    if rt in TIER_1: return 1
    if rt in TIER_2: return 2
    if rt in TIER_3: return 3
    if rt in TIER_4: return 4
    if rt in TIER_5: return 5
    return 3  # unknown types default to mid-tier


def compute_surprise(rel_type, rel_freq, total_freq,
                     source_agents, target_agents,
                     source_media, target_media,
                     is_direct_edge=False, direct_edge_type=None):
    """
    Compute a surprise score (0-100) for a related Hub.

    Components:
    1. Base tier score (10-90)
    2. Rarity bonus within tier (0-10): rarer types get more
    3. Author distance bonus (0-15): different author = more surprising
    4. Medium crossing bonus (0-10): different medium = more surprising
    """
    # 1. Base tier score
    if is_direct_edge and direct_edge_type in DIRECT_EDGE_TIERS:
        tier = DIRECT_EDGE_TIERS[direct_edge_type]
    else:
        tier = get_tier(rel_type)
    base = TIER_SCORES.get(tier, 40)

    # 2. Rarity bonus: log-inverse of frequency relative to total
    if rel_freq and rel_freq > 0 and total_freq > 0:
        # Normalize: translation (21K) → ~0.0, inspirationfor (33) → ~1.0
        rarity = 1.0 - (math.log10(rel_freq + 1) / math.log10(total_freq + 1))
        rarity_bonus = rarity * 10
    else:
        rarity_bonus = 5  # unknown frequency, give mid bonus

    # 3. Author distance
    if source_agents and target_agents:
        if not source_agents.intersection(target_agents):
            author_bonus = 15  # completely different author(s)
        elif source_agents != target_agents:
            author_bonus = 8   # some overlap (e.g., co-author added)
        else:
            author_bonus = 0   # same author
    else:
        author_bonus = 5  # can't determine, give partial

    # 4. Medium crossing
    source_interesting = source_media - {"http://id.loc.gov/ontologies/bibframe/Hub",
                                          "http://id.loc.gov/ontologies/bibframe/Work"}
    target_interesting = target_media - {"http://id.loc.gov/ontologies/bibframe/Hub",
                                          "http://id.loc.gov/ontologies/bibframe/Work"}
    if source_interesting and target_interesting and source_interesting != target_interesting:
        medium_bonus = 10  # different medium
    elif not source_interesting and target_interesting:
        medium_bonus = 8   # source has no specific medium, target does
    elif source_interesting and not target_interesting:
        medium_bonus = 3   # less interesting direction
    else:
        medium_bonus = 0

    total = base + rarity_bonus + author_bonus + medium_bonus
    return min(100, max(0, total)), {
        "tier": tier,
        "base": base,
        "rarity_bonus": round(rarity_bonus, 1),
        "author_bonus": author_bonus,
        "medium_bonus": medium_bonus,
    }


def find_hub_by_title_author(title, author=None):
    """Find a Hub URI by title. Pick the hub with most outbound relationships."""
    rows = cypher("""
        MATCH (h:ns0__Hub)-[:ns0__title]->(t)
        WHERE any(mt IN t.ns0__mainTitle WHERE toLower(mt) CONTAINS toLower($title))
        WITH DISTINCT h
        OPTIONAL MATCH (h)-[r]->(other:ns0__Hub)
        WITH h, count(r) AS relCount
        RETURN h.uri, relCount
        ORDER BY relCount DESC
        LIMIT 10
    """, {"title": title})
    if not rows:
        return []
    # Print candidates so we can see what was found
    for r in rows:
        t = get_hub_title(r["h.uri"])
        print(f"  Candidate: {t} ({r['relCount']} rels) — {r['h.uri']}")
    return [r["h.uri"] for r in rows]


def get_hub_agents(hub_uri):
    """Get the set of agent URIs for a Hub."""
    rows = cypher("""
        MATCH (h:ns0__Hub)-[:ns0__contribution]->(c)-[:ns0__agent]->(a)
        WHERE h.uri = $uri
        RETURN DISTINCT a.uri
    """, {"uri": hub_uri})
    return {r["a.uri"] for r in rows}


def get_hub_media_types(hub_uri):
    """Get the set of rdf:type URIs for a Hub."""
    rows = cypher("""
        MATCH (h:ns0__Hub)-[:rdf__type]->(t)
        WHERE h.uri = $uri
        RETURN t.uri
    """, {"uri": hub_uri})
    return {r["t.uri"] for r in rows}


def get_hub_title(hub_uri):
    """Get the main title for a Hub."""
    rows = cypher("""
        MATCH (h:ns0__Hub)-[:ns0__title]->(t)
        WHERE h.uri = $uri
        RETURN t.ns0__mainTitle[0] AS title
        LIMIT 1
    """, {"uri": hub_uri})
    return rows[0]["title"] if rows else "Unknown"


def get_all_related_hubs(hub_uri):
    """
    Get all related Hubs with their relationship types.
    Returns both direct edges and bflc:relationship typed edges.
    """
    results = []

    # 1. Direct Hub-to-Hub edges (translationOf, relatedTo, arrangementOf)
    direct = cypher("""
        MATCH (h:ns0__Hub)-[r]->(target:ns0__Hub)
        WHERE h.uri = $uri
        RETURN type(r) AS edgeType, target.uri AS targetUri
    """, {"uri": hub_uri})
    for row in direct:
        results.append({
            "targetUri": row["targetUri"],
            "relType": row["edgeType"],
            "is_direct": True,
        })

    # 2. Also check inbound direct edges
    inbound = cypher("""
        MATCH (source:ns0__Hub)-[r]->(h:ns0__Hub)
        WHERE h.uri = $uri AND type(r) <> 'ns0__relatedTo'
        RETURN type(r) AS edgeType, source.uri AS sourceUri
    """, {"uri": hub_uri})
    for row in inbound:
        results.append({
            "targetUri": row["sourceUri"],
            "relType": row["edgeType"] + "_INBOUND",
            "is_direct": True,
        })

    # 3. Typed relationships via bflc:relationship
    typed = cypher("""
        MATCH (h:ns0__Hub)-[:ns1__relationship]->(rel:ns1__Relationship)-[:ns1__relation]->(rt)
        WHERE h.uri = $uri AND NOT rt.uri STARTS WITH 'bnode'
        MATCH (rel)-[:ns0__relatedTo]->(target:ns0__Hub)
        RETURN replace(rt.uri, 'http://id.loc.gov/entities/relationships/', '') AS relType,
               target.uri AS targetUri
    """, {"uri": hub_uri})
    for row in typed:
        results.append({
            "targetUri": row["targetUri"],
            "relType": row["relType"],
            "is_direct": False,
        })

    # 4. Also check inbound typed relationships
    inbound_typed = cypher("""
        MATCH (target:ns0__Hub)-[:ns1__relationship]->(rel:ns1__Relationship)-[:ns1__relation]->(rt)
        WHERE NOT rt.uri STARTS WITH 'bnode'
        MATCH (rel)-[:ns0__relatedTo]->(h:ns0__Hub)
        WHERE h.uri = $uri
        RETURN replace(rt.uri, 'http://id.loc.gov/entities/relationships/', '') AS relType,
               target.uri AS targetUri
    """, {"uri": hub_uri})
    for row in inbound_typed:
        results.append({
            "targetUri": row["targetUri"],
            "relType": row["relType"] + "_INBOUND",
            "is_direct": False,
        })

    # Deduplicate by target URI, keeping the most informative relationship
    seen = {}
    for r in results:
        key = r["targetUri"]
        if key not in seen or (not r["is_direct"] and seen[key]["is_direct"]):
            seen[key] = r
    return list(seen.values())


def score_related_hubs(hub_uri):
    """Main scoring function: find all related hubs and score them by surprise."""
    print(f"\n{'='*80}")
    title = get_hub_title(hub_uri)
    print(f"Scoring related works for: {title}")
    print(f"Hub URI: {hub_uri}")

    source_agents = get_hub_agents(hub_uri)
    source_media = get_hub_media_types(hub_uri)
    print(f"Source agents: {source_agents}")
    print(f"Source media: {[m.split('/')[-1] for m in source_media]}")

    related = get_all_related_hubs(hub_uri)
    print(f"\nFound {len(related)} related hubs")

    # Get frequency data
    freq_map = get_relationship_type_frequencies()
    max_freq = max(freq_map.values()) if freq_map else 1

    scored = []
    for rel in related:
        target_uri = rel["targetUri"]
        rel_type = rel["relType"]
        is_direct = rel["is_direct"]

        target_title = get_hub_title(target_uri)
        target_agents = get_hub_agents(target_uri)
        target_media = get_hub_media_types(target_uri)

        # Look up frequency
        clean_type = rel_type.replace("_INBOUND", "").lower()
        freq = freq_map.get(clean_type, 0)

        direct_edge_type = rel_type.replace("_INBOUND", "") if is_direct else None

        score, breakdown = compute_surprise(
            rel_type=clean_type,
            rel_freq=freq,
            total_freq=max_freq,
            source_agents=source_agents,
            target_agents=target_agents,
            source_media=source_media,
            target_media=target_media,
            is_direct_edge=is_direct,
            direct_edge_type=direct_edge_type,
        )

        scored.append({
            "title": target_title,
            "uri": target_uri,
            "relType": rel_type,
            "score": score,
            "breakdown": breakdown,
            "target_media": [m.split("/")[-1] for m in target_media
                           if m not in ("http://id.loc.gov/ontologies/bibframe/Hub",
                                        "http://id.loc.gov/ontologies/bibframe/Work")],
            "different_author": bool(source_agents and target_agents
                                    and not source_agents.intersection(target_agents)),
        })

    # Sort by surprise score descending
    scored.sort(key=lambda x: x["score"], reverse=True)

    print(f"\n{'─'*80}")
    print(f"{'Score':>5} {'Tier':>4} {'RelType':<35} {'Title'}")
    print(f"{'─'*80}")
    for s in scored:
        flags = []
        if s["different_author"]:
            flags.append("DIFF_AUTHOR")
        if s["target_media"]:
            flags.append("+".join(s["target_media"]))
        flag_str = f" [{', '.join(flags)}]" if flags else ""
        print(f"{s['score']:>5.0f} {s['breakdown']['tier']:>4} "
              f"{s['relType']:<35} {s['title']}{flag_str}")

    # Show top 10 "most surprising"
    print(f"\n{'='*80}")
    print("TOP 10 MOST SURPRISING CONNECTIONS:")
    print(f"{'='*80}")
    for i, s in enumerate(scored[:10], 1):
        flags = []
        if s["different_author"]:
            flags.append("different author")
        if s["target_media"]:
            flags.append(" + ".join(s["target_media"]))
        flag_str = f" ({', '.join(flags)})" if flags else ""
        b = s['breakdown']
        print(f"\n  {i}. [{s['score']:.0f}] {s['title']}{flag_str}")
        print(f"     via: {s['relType']}")
        print(f"     scoring: base={b['base']} + rarity={b['rarity_bonus']} "
              f"+ author={b['author_bonus']} + medium={b['medium_bonus']}")

    return scored


if __name__ == "__main__":
    # Test with known works
    test_cases = [
        ("Pride and prejudice", "Austen"),
        ("Hamlet", "Shakespeare"),
    ]

    if len(sys.argv) > 1:
        title = sys.argv[1]
        author = sys.argv[2] if len(sys.argv) > 2 else None
        test_cases = [(title, author)]

    for title, author_hint in test_cases:
        print(f"\n\n{'#'*80}")
        print(f"# SEARCHING: {title}" + (f" by {author_hint}" if author_hint else ""))
        print(f"{'#'*80}")

        if author_hint:
            uris = find_hub_by_title_author(title, author_hint)
        else:
            uris = find_hub_by_title_author(title)

        if not uris:
            print(f"  No hubs found for '{title}'")
            continue

        # Use first match
        hub_uri = uris[0]
        score_related_hubs(hub_uri)
