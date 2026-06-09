"""Equivalence proof: the networkx engine == an independent naive PHP-port oracle.

The whole point of the runtime is to replace the removed hand-rolled PHP node
centrality / edge-weight propagation / incoming-outgoing scoring (scoreNode in
WorldModelGraphRanker) with a real networkx+numpy engine. This test
re-implements that EXACT PHP scoring in plain Python (no networkx, no numpy) as
an independent reference oracle, then asserts the engine produces a bit-identical
result — crucially the NODE RANKING ORDER (the task's equivalence target) and
every per-node score/confidence/graph_score — on:

  - the four golden fixtures lifted verbatim from the PHP unit test
    (WorldModelGraphRankerTest), and
  - a 400-case randomised battery + adversarial edge/anchor fixtures.

If the engine ever diverged from the PHP weights / caps / 4-dp rounding / the
DESC-score, ASC-node_id ordering, this fails.
"""

from __future__ import annotations

import math
import random
from typing import Any, Dict, List, Optional, Sequence

from atlas_graph_rank import rank_nodes

# ─── PHP scoring constants (mirror WorldModelGraphRanker) ──────────────────────
EDGE_WEIGHTS = {
    "tests": 0.45,
    "documents": 0.50,
    "documented_by": 0.50,
    "defines": 0.30,
    "depends_on": 0.25,
    "contains_symbol": 0.20,
    "invokes": 0.30,
}
EDGE_DEFAULT = 0.12
SCORE_CAP = 2.5


# --------------------------------------------------------------------------- #
# Independent naive reference (plain Python, mirrors scoreNode line-by-line)
# --------------------------------------------------------------------------- #
def _round4(v: float) -> float:
    f = 10**4
    s = v * f
    r = math.floor(s + 0.5) if s >= 0 else math.ceil(s - 0.5)
    return r / f


def _s(v: Any) -> str:
    return v if isinstance(v, str) else ("" if v is None else str(v))


def _strlist(v: Any) -> List[str]:
    return [x for x in v if isinstance(x, str)] if isinstance(v, list) else []


def _text_score(node: Dict[str, Any], seeds: Sequence[str]) -> float:
    if not seeds:
        return 0.0
    parts = [
        _s(node.get("path")),
        _s(node.get("flow_id")),
        " ".join(_strlist(node.get("capabilities"))),
        " ".join(_strlist(node.get("risks"))),
        _s(node.get("node_id")),
    ]
    hay = " ".join(p for p in parts if p != "").lower()
    if hay == "":
        return 0.0
    hits = sum(1 for sd in seeds if sd != "" and sd in hay)
    if hits == 0:
        return 0.0
    return _round4(min(1.0, hits / max(1, len(seeds))))


def _intersection(a: Any, b: Sequence[str]) -> List[str]:
    if not a or not b:
        return []
    left, seen = [], set()
    for v in a:
        if not isinstance(v, str):
            continue
        s = v.strip().lower()
        if s == "" or s in seen:
            continue
        seen.add(s)
        left.append(s)
    return sorted(s for s in left if s in set(b))


def _path_match(node, target_files) -> bool:
    if not target_files:
        return False
    p = _s(node.get("path")).lower()
    return p != "" and any(t != "" and t in p for t in target_files)


def _flow_match(node, target_flows) -> bool:
    f = node.get("flow_id")
    return f is not None and _s(f).lower() in set(target_flows)


def _anchored(node: Optional[Dict[str, Any]], q) -> bool:
    if not isinstance(node, dict):
        return False
    if _path_match(node, q["target_files"]):
        return True
    if _flow_match(node, q["target_flows"]):
        return True
    if _intersection(node.get("capabilities") or [], q["target_capabilities"]):
        return True
    return _text_score(node, q["textual_seeds"]) >= 0.5


def reference_rank(nodes: List[Dict[str, Any]], edges: List[Dict[str, Any]], query: Dict[str, Any]) -> Dict[str, Any]:
    q = {
        "textual_seeds": _strlist(query.get("textual_seeds")),
        "target_files": _strlist(query.get("target_files")),
        "target_flows": _strlist(query.get("target_flows")),
        "target_capabilities": _strlist(query.get("target_capabilities")),
        "target_risks": _strlist(query.get("target_risks")),
        "risk_elevated": bool(query.get("risk_elevated", False)),
        "boost_docs": bool(query.get("boost_docs", False)),
        "boost_tests": bool(query.get("boost_tests", False)),
    }
    by_id = {_s(n.get("node_id")): n for n in nodes if _s(n.get("node_id")) != ""}
    # PHP groups edges by from / by to, preserving the candidateEdges sort order
    # (from,to,edge_type). The engine preserves arrival order, so the reference
    # iterates edges in arrival order too.
    out_edges: Dict[str, List[Dict[str, Any]]] = {}
    in_edges: Dict[str, List[Dict[str, Any]]] = {}
    for e in edges:
        a, b = _s(e.get("from_node_id")), _s(e.get("to_node_id"))
        if a == "" or b == "":
            continue
        out_edges.setdefault(a, []).append(e)
        in_edges.setdefault(b, []).append(e)

    scored = []
    for node in nodes:
        nid = _s(node.get("node_id"))
        if nid == "":
            continue
        ts = _text_score(node, q["textual_seeds"])
        gs = 0.0

        if _path_match(node, q["target_files"]):
            gs += 0.60
        if _flow_match(node, q["target_flows"]):
            gs += 0.45
        cap = _intersection(node.get("capabilities") or [], q["target_capabilities"])
        if cap:
            gs += min(0.40, 0.20 * len(cap))
        risk = _intersection(node.get("risks") or [], q["target_risks"])
        if risk:
            gs += 0.50 if q["risk_elevated"] else 0.22

        for e in out_edges.get(nid, []):
            other = by_id.get(_s(e.get("to_node_id")))
            if not _anchored(other, q):
                continue
            gs += EDGE_WEIGHTS.get(_s(e.get("edge_type")), EDGE_DEFAULT)
        for e in in_edges.get(nid, []):
            other = by_id.get(_s(e.get("from_node_id")))
            if not _anchored(other, q):
                continue
            gs += EDGE_WEIGHTS.get(_s(e.get("edge_type")), EDGE_DEFAULT)

        nt = _s(node.get("node_type"))
        if q["boost_tests"] and nt == "test":
            gs += 0.12
        if q["boost_docs"] and nt == "doc":
            gs += 0.18

        combined = _round4(min(SCORE_CAP, max(0.0, ts * 0.55 + gs * 0.85)))
        confidence = _round4(min(1.0, ts * 0.30 + min(1.0, gs) * 0.70))
        scored.append(
            {
                "node_id": nid,
                "text_score": _round4(ts),
                "graph_score": _round4(gs),
                "score": combined,
                "confidence": confidence,
            }
        )

    scored.sort(key=lambda r: (-r["score"], r["node_id"]))
    return {
        "ranked_order": [r["node_id"] for r in scored],
        "scored": scored,
    }


def _project(engine_out: Dict[str, Any]) -> Dict[str, Any]:
    """Reduce the engine output to the math the reference oracle computes
    (scores + order), dropping the PHP-composed reason labels / relation_path /
    centrality which the oracle deliberately does not reproduce."""
    return {
        "ranked_order": engine_out["ranked_order"],
        "scored": [
            {
                "node_id": s["node_id"],
                "text_score": s["text_score"],
                "graph_score": s["graph_score"],
                "score": s["score"],
                "confidence": s["confidence"],
            }
            for s in engine_out["scored"]
        ],
    }


# --------------------------------------------------------------------------- #
# Golden fixtures lifted verbatim from WorldModelGraphRankerTest.php
# --------------------------------------------------------------------------- #
def test_golden_graph_relations_override_pure_textual_top_pick():
    nodes = [
        {"node_id": "service:router", "node_type": "service", "path": "app/Services/Ai/Router/RouterService.php", "flow_id": "atlas_router", "capabilities": [], "risks": []},
        {"node_id": "service:router_decoy", "node_type": "service", "path": "app/Services/Notes/RouterNoteService.php", "flow_id": "atlas_dev", "capabilities": [], "risks": []},
        {"node_id": "doc:router-canon", "node_type": "doc", "path": "docs/engineering-knowledge-base/atlas-router.md", "flow_id": "atlas_research", "capabilities": [], "risks": []},
        {"node_id": "test:router-cover", "node_type": "test", "path": "tests/Feature/Ai/RouterCoverageTest.php", "flow_id": "atlas_router", "capabilities": [], "risks": []},
    ]
    edges = [
        {"from_node_id": "doc:router-canon", "to_node_id": "service:router", "edge_type": "documents"},
        {"from_node_id": "test:router-cover", "to_node_id": "service:router", "edge_type": "tests"},
        {"from_node_id": "service:router", "to_node_id": "service:router_decoy", "edge_type": "depends_on"},
    ]
    query = {"textual_seeds": ["router"], "target_files": ["app/services/ai/router/routerservice.php"], "target_flows": ["atlas_router"]}
    out = rank_nodes(nodes, edges, query)
    assert out["ranked_order"][0] == "service:router"
    assert out["graph_top_node"] == "service:router"
    assert out["text_only_top_node"] is not None
    assert _project(out) == reference_rank(nodes, edges, query)


def test_golden_test_linked_to_seed_file_gets_boost():
    nodes = [
        {"node_id": "service:auth", "node_type": "service", "path": "app/Services/Auth/AuthService.php", "flow_id": "atlas_security", "capabilities": [], "risks": []},
        {"node_id": "test:auth-covering", "node_type": "test", "path": "tests/Unit/Auth/AuthServiceTest.php", "flow_id": "atlas_security", "capabilities": [], "risks": []},
        {"node_id": "test:unrelated", "node_type": "test", "path": "tests/Unit/Other/UnrelatedTest.php", "flow_id": "atlas_dev", "capabilities": [], "risks": []},
    ]
    edges = [{"from_node_id": "test:auth-covering", "to_node_id": "service:auth", "edge_type": "tests"}]
    query = {"textual_seeds": ["auth"], "target_files": ["app/services/auth/authservice.php"], "target_flows": ["atlas_security"]}
    out = rank_nodes(nodes, edges, query)
    by_id = {s["node_id"]: s for s in out["scored"]}
    assert by_id["test:auth-covering"]["score"] > by_id["test:unrelated"]["score"]
    assert _project(out) == reference_rank(nodes, edges, query)


def test_golden_governing_doc_outranks_unrelated_doc():
    nodes = [
        {"node_id": "service:payments", "node_type": "service", "path": "app/Services/Payments/PaymentsService.php", "flow_id": "atlas_dev", "capabilities": [], "risks": []},
        {"node_id": "doc:payments-canon", "node_type": "doc", "path": "docs/engineering-knowledge-base/atlas-payments.md", "flow_id": "atlas_research", "capabilities": [], "risks": []},
        {"node_id": "doc:unrelated-canon", "node_type": "doc", "path": "docs/engineering-knowledge-base/atlas-other-topic.md", "flow_id": "atlas_research", "capabilities": [], "risks": []},
    ]
    edges = [{"from_node_id": "doc:payments-canon", "to_node_id": "service:payments", "edge_type": "documents"}]
    query = {"textual_seeds": ["payments"], "target_files": ["app/services/payments/paymentsservice.php"], "boost_docs": True}
    out = rank_nodes(nodes, edges, query)
    by_id = {s["node_id"]: s for s in out["scored"]}
    assert by_id["doc:payments-canon"]["score"] > by_id["doc:unrelated-canon"]["score"]
    assert _project(out) == reference_rank(nodes, edges, query)


def test_golden_risk_boost_on_high_risk_task():
    nodes = [
        {"node_id": "service:safe-utility", "node_type": "service", "path": "app/Services/Util/SafeUtility.php", "flow_id": "atlas_dev", "capabilities": [], "risks": []},
        {"node_id": "migration:risky", "node_type": "migration", "path": "database/migrations/2026_05_18_risky_migration.php", "flow_id": "atlas_dev", "capabilities": [], "risks": ["persistence_change", "large_scope_promotion"]},
    ]
    low = rank_nodes(nodes, [], {"textual_seeds": ["migration"], "target_risks": ["persistence_change"], "risk_elevated": False})
    high = rank_nodes(nodes, [], {"textual_seeds": ["migration"], "target_risks": ["persistence_change"], "risk_elevated": True})
    low_m = {s["node_id"]: s for s in low["scored"]}["migration:risky"]
    high_m = {s["node_id"]: s for s in high["scored"]}["migration:risky"]
    assert high_m["score"] > low_m["score"]
    assert _project(low) == reference_rank(nodes, [], {"textual_seeds": ["migration"], "target_risks": ["persistence_change"], "risk_elevated": False})
    assert _project(high) == reference_rank(nodes, [], {"textual_seeds": ["migration"], "target_risks": ["persistence_change"], "risk_elevated": True})


# --------------------------------------------------------------------------- #
# Randomised battery
# --------------------------------------------------------------------------- #
_NODE_TYPES = ["service", "doc", "test", "migration", "module", "command", "file"]
_FLOWS = ["atlas_router", "atlas_dev", "atlas_security", "atlas_research"]
_CAPS = ["router", "compounding", "frontend", "payments"]
_RISKS = ["persistence_change", "large_scope_promotion", "secret_leak"]
_EDGE_TYPES = list(EDGE_WEIGHTS.keys()) + ["unknown_edge"]
_PATH_TOKENS = ["router", "auth", "payments", "util", "notes", "other", "ai"]


def _rand_nodes(rng: random.Random) -> List[Dict[str, Any]]:
    n = rng.randint(0, 9)
    nodes = []
    for k in range(n):
        toks = "/".join(rng.choice(_PATH_TOKENS) for _ in range(rng.randint(1, 3)))
        nodes.append(
            {
                "node_id": f"{rng.choice(_NODE_TYPES)}:{k}:{rng.choice(_PATH_TOKENS)}",
                "node_type": rng.choice(_NODE_TYPES),
                "path": f"app/{toks}/file{k}.php" if rng.random() < 0.85 else None,
                "flow_id": rng.choice(_FLOWS) if rng.random() < 0.8 else None,
                "capabilities": rng.sample(_CAPS, rng.randint(0, len(_CAPS))),
                "risks": rng.sample(_RISKS, rng.randint(0, len(_RISKS))),
            }
        )
    return nodes


def _rand_edges(rng: random.Random, nodes: List[Dict[str, Any]]) -> List[Dict[str, Any]]:
    if len(nodes) < 2:
        return []
    ids = [n["node_id"] for n in nodes]
    edges = []
    for _ in range(rng.randint(0, 12)):
        a, b = rng.choice(ids), rng.choice(ids)
        edges.append({"from_node_id": a, "to_node_id": b, "edge_type": rng.choice(_EDGE_TYPES)})
    # candidateEdges hands edges sorted by (from, to, edge_type); honour that.
    edges.sort(key=lambda e: (e["from_node_id"], e["to_node_id"], e["edge_type"]))
    return edges


def _rand_query(rng: random.Random) -> Dict[str, Any]:
    return {
        "textual_seeds": rng.sample(_PATH_TOKENS, rng.randint(0, 3)),
        "target_files": [f"app/{rng.choice(_PATH_TOKENS)}" for _ in range(rng.randint(0, 2))],
        "target_flows": rng.sample(_FLOWS, rng.randint(0, 2)),
        "target_capabilities": rng.sample(_CAPS, rng.randint(0, 2)),
        "target_risks": rng.sample(_RISKS, rng.randint(0, 2)),
        "risk_elevated": rng.random() < 0.5,
        "boost_docs": rng.random() < 0.5,
        "boost_tests": rng.random() < 0.5,
    }


def test_engine_equals_naive_reference_on_random_battery():
    rng = random.Random(20260609)
    for _ in range(400):
        nodes = _rand_nodes(rng)
        edges = _rand_edges(rng, nodes)
        query = _rand_query(rng)
        got = _project(rank_nodes(nodes, edges, query))
        want = reference_rank(nodes, edges, query)
        assert got == want, (query, nodes, edges, got, want)


def test_engine_equals_reference_with_parallel_and_unknown_edges():
    # Two nodes linked by MULTIPLE edge types (parallel edges) + an unknown
    # edge type (default 0.12) + a self-anchored seed — each parallel edge must
    # contribute its own boost, exactly like the PHP per-edge loop.
    nodes = [
        {"node_id": "service:a", "node_type": "service", "path": "app/ai/router/a.php", "flow_id": "atlas_router", "capabilities": ["router"], "risks": []},
        {"node_id": "service:b", "node_type": "service", "path": "app/other/b.php", "flow_id": "atlas_dev", "capabilities": [], "risks": []},
    ]
    edges = [
        {"from_node_id": "service:b", "to_node_id": "service:a", "edge_type": "depends_on"},
        {"from_node_id": "service:b", "to_node_id": "service:a", "edge_type": "invokes"},
        {"from_node_id": "service:b", "to_node_id": "service:a", "edge_type": "unknown_edge"},
    ]
    query = {"textual_seeds": ["router"], "target_files": ["app/ai/router"], "target_flows": ["atlas_router"], "target_capabilities": ["router"]}
    assert _project(rank_nodes(nodes, edges, query)) == reference_rank(nodes, edges, query)


def test_engine_equals_reference_with_edge_to_missing_node():
    # Edge endpoint not in the node list -> never anchored, contributes nothing,
    # and must not crash (graph adds the phantom node, record=None).
    nodes = [
        {"node_id": "service:a", "node_type": "service", "path": "app/ai/router/a.php", "flow_id": "atlas_router", "capabilities": [], "risks": []},
    ]
    edges = [{"from_node_id": "service:a", "to_node_id": "ghost:x", "edge_type": "tests"}]
    query = {"textual_seeds": ["router"], "target_files": ["app/ai/router"]}
    assert _project(rank_nodes(nodes, edges, query)) == reference_rank(nodes, edges, query)


def test_score_cap_is_enforced_identically():
    # Pile on every boost to push past the 2.5 cap; both must clamp to 2.5.
    nodes = [
        {
            "node_id": "doc:everything",
            "node_type": "doc",
            "path": "app/ai/router/auth/payments.php",
            "flow_id": "atlas_router",
            "capabilities": ["router", "compounding", "frontend", "payments"],
            "risks": ["persistence_change", "large_scope_promotion"],
        },
        {"node_id": "anchor:a", "node_type": "service", "path": "app/ai/router/auth/payments.php", "flow_id": "atlas_router", "capabilities": [], "risks": []},
    ]
    edges = [
        {"from_node_id": "anchor:a", "to_node_id": "doc:everything", "edge_type": "documents"},
        {"from_node_id": "doc:everything", "to_node_id": "anchor:a", "edge_type": "documents"},
    ]
    query = {
        "textual_seeds": ["router", "auth", "payments"],
        "target_files": ["app/ai/router/auth/payments.php"],
        "target_flows": ["atlas_router"],
        "target_capabilities": ["router", "compounding", "frontend", "payments"],
        "target_risks": ["persistence_change", "large_scope_promotion"],
        "risk_elevated": True,
        "boost_docs": True,
    }
    out = rank_nodes(nodes, edges, query)
    assert max(s["score"] for s in out["scored"]) <= 2.5
    assert _project(out) == reference_rank(nodes, edges, query)
