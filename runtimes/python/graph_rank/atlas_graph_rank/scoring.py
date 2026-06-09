"""World-model node ranking math, computed in networkx + numpy.

This is the REAL engine the runtime_language_boundary canon mandates: the PHP
kernel (WorldModelGraphRanker) must NOT hand-roll the node-centrality /
edge-weight propagation / incoming-outgoing scoring; it invokes this runtime.
Each scoring rule is a faithful port of the (now-removed) hand-rolled PHP
reference in
app/Services/Ai/AutonomousEngineering/WorldModel/WorldModelGraphRanker.php
(scoreNode + textScore + edgeAnchored + intersection + textOnlyTop) — same
weights, same caps, same 4-dp rounding, same DESC-score / ASC-node_id ranking
order — so the swap is behaviour-preserving (identical node ranking ORDER).

The graph itself is materialised as a networkx.DiGraph so the incoming/outgoing
edge traversal and the (auditable) node centrality are real graph operations,
not ad-hoc loops; numpy backs the deterministic rounding/normalisation. networkx
and numpy ONLY — no scipy/igraph/leiden (those are gated behind dependency
approval); this module imports nothing else.

Boundary input (already lower-cased + cleaned by the PHP kernel, mirroring
WorldModelRankingQuery::cleanList):

  nodes: [{
      "node_id": str, "node_type": str, "path": str|None, "flow_id": str|None,
      "capabilities": [str, ...], "risks": [str, ...]
  }, ...]
  edges: [{"from_node_id": str, "to_node_id": str, "edge_type": str}, ...]
  query: {
      "textual_seeds": [str], "target_files": [str], "target_flows": [str],
      "target_capabilities": [str], "target_risks": [str],
      "risk_elevated": bool, "boost_docs": bool, "boost_tests": bool
  }

Output (PHP composes the human reason strings + relation_path labels from the
structured boost decisions returned here):

  {
    "schema_version": SCHEMA,
    "scored": [ {per-node math + boost decisions}, ... ]  # in final ranked order
    "ranked_order": [node_id, ...],                        # the equivalence target
    "text_only_top_node": str|None,
    "graph_top_node": str|None,
  }
"""

from __future__ import annotations

from typing import Any, Dict, List, Optional, Sequence

import networkx as nx
import numpy as np

SCHEMA = "atlas.ai.codebase_world_model.graph_rank.v1"

# Edge type -> boost weight (mirrors WorldModelGraphRanker::EDGE_WEIGHTS exactly).
EDGE_WEIGHTS: Dict[str, float] = {
    "tests": 0.45,
    "documents": 0.50,
    "documented_by": 0.50,
    "defines": 0.30,
    "depends_on": 0.25,
    "contains_symbol": 0.20,
    "invokes": 0.30,
}
EDGE_DEFAULT_WEIGHT = 0.12

SCORE_CAP = 2.5

# Combination weights (mirror scoreNode's $combined / $confidence formulas).
TEXT_SCORE_WEIGHT = 0.55
GRAPH_SCORE_WEIGHT = 0.85
CONFIDENCE_TEXT_WEIGHT = 0.30
CONFIDENCE_GRAPH_WEIGHT = 0.70

# Per-rule boosts (mirror scoreNode exactly).
FILE_MATCH_BOOST = 0.60
FLOW_MATCH_BOOST = 0.45
CAPABILITY_BOOST_PER = 0.20
CAPABILITY_BOOST_CAP = 0.40
RISK_BOOST_ELEVATED = 0.50
RISK_BOOST_NORMAL = 0.22
TEST_BOOST = 0.12
DOC_BOOST = 0.18

# An edge endpoint counts as "anchored" by the query if its text score meets
# this floor (mirrors edgeAnchored's `>= 0.5`).
EDGE_ANCHOR_TEXT_FLOOR = 0.5


def _round4(value: float) -> float:
    """Round half away from zero to 4 dp, matching PHP round($v, 4).

    numpy's rint is round-half-to-even, so we replicate PHP's half-away-from-zero
    explicitly. Done element-wise via numpy for the float64 semantics PHP uses.
    """
    x = np.float64(value) * np.float64(10000.0)
    # floor(x + 0.5) for x>=0, ceil(x - 0.5) for x<0  ==  half away from zero
    rounded = np.where(x >= 0.0, np.floor(x + 0.5), np.ceil(x - 0.5))
    return float(rounded / np.float64(10000.0))


def _as_str(value: Any) -> str:
    return value if isinstance(value, str) else ("" if value is None else str(value))


def _str_list(value: Any) -> List[str]:
    if not isinstance(value, list):
        return []
    return [v for v in (x for x in value) if isinstance(v, str)]


def _intersection(node_values: Sequence[Any], query_values: Sequence[str]) -> List[str]:
    """Faithful port of WorldModelGraphRanker::intersection.

    node_values are lower-cased+trimmed+deduped; intersect with query_values
    (already lower-cased by the kernel), then SORT ascending.
    """
    if not node_values or not query_values:
        return []
    left: List[str] = []
    seen = set()
    for v in node_values:
        if not isinstance(v, str):
            continue
        s = v.strip().lower()
        if s == "" or s in seen:
            continue
        seen.add(s)
        left.append(s)
    q = set(query_values)
    inter = sorted(s for s in left if s in q)
    return inter


def _text_score(node: Dict[str, Any], seeds: Sequence[str]) -> float:
    """Faithful port of WorldModelGraphRanker::textScore.

    haystack = lower(path + flow_id + capabilities + risks + node_id); score =
    hits / max(1, len(seeds)), clamped to 1.0, rounded 4dp. (Inputs are already
    lower-cased by the kernel, but we lower() again to match the PHP exactly.)
    """
    if not seeds:
        return 0.0
    parts = [
        _as_str(node.get("path")),
        _as_str(node.get("flow_id")),
        " ".join(_str_list(node.get("capabilities"))),
        " ".join(_str_list(node.get("risks"))),
        _as_str(node.get("node_id")),
    ]
    haystack = " ".join(p for p in parts if p != "").lower()
    if haystack == "":
        return 0.0
    hits = 0
    for seed in seeds:
        if seed == "":
            continue
        if seed in haystack:
            hits += 1
    if hits == 0:
        return 0.0
    return _round4(min(1.0, hits / max(1, len(seeds))))


def _path_matches_target_files(node: Dict[str, Any], target_files: Sequence[str]) -> bool:
    if not target_files:
        return False
    path = _as_str(node.get("path")).lower()
    if path == "":
        return False
    for target in target_files:
        if target != "" and target in path:
            return True
    return False


def _flow_matches(node: Dict[str, Any], target_flows: Sequence[str]) -> bool:
    flow = node.get("flow_id")
    if flow is None:
        return False
    return _as_str(flow).lower() in set(target_flows)


def _edge_anchored(
    node: Optional[Dict[str, Any]],
    query: Dict[str, Any],
) -> bool:
    """Faithful port of WorldModelGraphRanker::edgeAnchored."""
    if not isinstance(node, dict):
        return False
    if _path_matches_target_files(node, query["target_files"]):
        return True
    if _flow_matches(node, query["target_flows"]):
        return True
    if _intersection(node.get("capabilities") or [], query["target_capabilities"]):
        return True
    return _text_score(node, query["textual_seeds"]) >= EDGE_ANCHOR_TEXT_FLOOR


def _normalise_query(query: Dict[str, Any]) -> Dict[str, Any]:
    return {
        "textual_seeds": _str_list(query.get("textual_seeds")),
        "target_files": _str_list(query.get("target_files")),
        "target_flows": _str_list(query.get("target_flows")),
        "target_capabilities": _str_list(query.get("target_capabilities")),
        "target_risks": _str_list(query.get("target_risks")),
        "risk_elevated": bool(query.get("risk_elevated", False)),
        "boost_docs": bool(query.get("boost_docs", False)),
        "boost_tests": bool(query.get("boost_tests", False)),
    }


def _build_graph(
    nodes: List[Dict[str, Any]],
    edges: List[Dict[str, Any]],
) -> nx.MultiDiGraph:
    """Materialise the world-model slice as a real networkx directed multigraph.

    A MultiDiGraph (not a simple DiGraph) because two nodes may be linked by more
    than one edge_type and EACH contributes its own boost in the PHP reference
    (the PHP iterates every edge bucket, it does not collapse parallel edges).
    Nodes that appear only as an edge endpoint (not in the node list) are still
    added so traversal sees them; their attrs default to a thin record.
    """
    g: nx.MultiDiGraph = nx.MultiDiGraph()
    for node in nodes:
        nid = _as_str(node.get("node_id"))
        if nid == "":
            continue
        g.add_node(nid, record=node)
    for edge in edges:
        a = _as_str(edge.get("from_node_id"))
        b = _as_str(edge.get("to_node_id"))
        etype = _as_str(edge.get("edge_type"))
        if a == "" or b == "":
            continue
        if a not in g:
            g.add_node(a, record=None)
        if b not in g:
            g.add_node(b, record=None)
        g.add_edge(a, b, edge_type=etype)
    return g


def _node_record(g: nx.MultiDiGraph, node_id: str) -> Optional[Dict[str, Any]]:
    if node_id not in g:
        return None
    rec = g.nodes[node_id].get("record")
    return rec if isinstance(rec, dict) else None


def rank_nodes(
    nodes: Any,
    edges: Any,
    query: Any,
) -> Dict[str, Any]:
    """Score + rank world-model nodes. Faithful, behaviour-preserving port.

    Returns the per-node math (scores, confidence, structured boost decisions)
    in FINAL ranked order plus the ranked node-id order — the equivalence target
    the PHP kernel re-emits. PHP composes the human reason strings + relation
    path labels from the boost decisions here (so the boost LOGIC lives in one
    place, this runtime, never duplicated).
    """
    node_list = [n for n in nodes if isinstance(n, dict)] if isinstance(nodes, list) else []
    edge_list = [e for e in edges if isinstance(e, dict)] if isinstance(edges, list) else []
    q = _normalise_query(query if isinstance(query, dict) else {})

    g = _build_graph(node_list, edge_list)

    # Centrality over the materialised graph. This is the "node centrality"
    # numeric graph math: in-degree / out-degree / total-degree, computed by
    # networkx, surfaced as an auditable signal (it does NOT alter the ranking
    # order — the order is the faithful PHP score — but it makes the centrality
    # a real, inspectable graph metric rather than a hidden loop, per the canon).
    in_deg = dict(g.in_degree())
    out_deg = dict(g.out_degree())

    scored: List[Dict[str, Any]] = []
    for node in node_list:
        nid = _as_str(node.get("node_id"))
        if nid == "":
            continue

        text_score = _text_score(node, q["textual_seeds"])
        graph_score = 0.0
        reasons: List[str] = []
        relation_path: List[Dict[str, str]] = []

        if _path_matches_target_files(node, q["target_files"]):
            graph_score += FILE_MATCH_BOOST
            reasons.append("query_target_file_match")
        if _flow_matches(node, q["target_flows"]):
            graph_score += FLOW_MATCH_BOOST
            reasons.append("query_target_flow_match")

        cap_overlap = _intersection(node.get("capabilities") or [], q["target_capabilities"])
        if cap_overlap:
            graph_score += min(CAPABILITY_BOOST_CAP, CAPABILITY_BOOST_PER * len(cap_overlap))
            reasons.append("capability_overlap:" + ",".join(cap_overlap))

        risk_overlap = _intersection(node.get("risks") or [], q["target_risks"])
        if risk_overlap:
            graph_score += RISK_BOOST_ELEVATED if q["risk_elevated"] else RISK_BOOST_NORMAL
            reasons.append("risk_match:" + ",".join(risk_overlap))

        # Edge-derived boosts. A boost requires the OTHER endpoint of the edge to
        # be anchored by the query. Iterate OUTGOING then INCOMING, in the same
        # deterministic order the PHP grouped+iterated (edges arrive pre-sorted by
        # from,to,edge_type from the kernel's candidateEdges query). We preserve
        # arrival order via networkx's insertion-ordered edge views.
        for _, other_id, data in g.out_edges(nid, data=True):
            other = _node_record(g, other_id)
            if not _edge_anchored(other, q):
                continue
            etype = _as_str(data.get("edge_type"))
            graph_score += EDGE_WEIGHTS.get(etype, EDGE_DEFAULT_WEIGHT)
            relation_path.append(
                {"from": nid, "to": other_id, "edge_type": etype, "direction": "outgoing"}
            )
            # PHP composes the reason label; we hand it the raw decision.
            reasons.append("__edge__:outgoing:" + etype + ":" + _as_str(node.get("node_type")))

        for src_id, _, data in g.in_edges(nid, data=True):
            other = _node_record(g, src_id)
            if not _edge_anchored(other, q):
                continue
            etype = _as_str(data.get("edge_type"))
            graph_score += EDGE_WEIGHTS.get(etype, EDGE_DEFAULT_WEIGHT)
            relation_path.append(
                {"from": src_id, "to": nid, "edge_type": etype, "direction": "incoming"}
            )
            reasons.append("__edge__:incoming:" + etype + ":" + _as_str(node.get("node_type")))

        node_type = _as_str(node.get("node_type"))
        if q["boost_tests"] and node_type == "test":
            graph_score += TEST_BOOST
            reasons.append("task_requests_test_boost")
        if q["boost_docs"] and node_type == "doc":
            graph_score += DOC_BOOST
            reasons.append("task_requests_doc_boost")

        combined = _round4(
            min(SCORE_CAP, max(0.0, text_score * TEXT_SCORE_WEIGHT + graph_score * GRAPH_SCORE_WEIGHT))
        )
        confidence = _round4(
            min(1.0, text_score * CONFIDENCE_TEXT_WEIGHT + min(1.0, graph_score) * CONFIDENCE_GRAPH_WEIGHT)
        )

        if not reasons and text_score > 0.0:
            reasons.append("textual_match_only")

        scored.append(
            {
                "node_id": nid,
                "text_score": _round4(text_score),
                "graph_score": _round4(graph_score),
                "score": combined,
                "confidence": confidence,
                "reasons": reasons,
                "relation_path": relation_path,
                "centrality": {
                    "in_degree": int(in_deg.get(nid, 0)),
                    "out_degree": int(out_deg.get(nid, 0)),
                    "degree": int(in_deg.get(nid, 0)) + int(out_deg.get(nid, 0)),
                },
            }
        )

    # Final ranking: score DESC, then node_id ASC (strcmp) — the equivalence
    # target. usort in PHP is not stable, but the explicit node_id tie-break makes
    # the order total + deterministic; we sort by (-score, node_id).
    scored.sort(key=lambda r: (-r["score"], r["node_id"]))
    ranked_order = [r["node_id"] for r in scored]

    return {
        "schema_version": SCHEMA,
        "scored": scored,
        "ranked_order": ranked_order,
        "text_only_top_node": _text_only_top(node_list, q["textual_seeds"]),
        "graph_top_node": ranked_order[0] if ranked_order else None,
    }


def _text_only_top(nodes: List[Dict[str, Any]], seeds: Sequence[str]) -> Optional[str]:
    """Faithful port of WorldModelGraphRanker::textOnlyTop.

    Highest text score wins; ties broken by the smaller node_id (strcmp < 0).
    Returns None when no node has a positive text score (or no seeds).
    """
    if not seeds:
        return None
    best: Optional[str] = None
    best_score = -1.0
    for node in nodes:
        nid = _as_str(node.get("node_id"))
        if nid == "":
            continue
        score = _text_score(node, seeds)
        if score > best_score or (score == best_score and best is not None and nid < best):
            best = nid
            best_score = score
    return best if best_score > 0.0 else None
