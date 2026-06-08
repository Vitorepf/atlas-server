"""Surprising-connections + suggested-questions over the Atlas code graph
(AP-811/AP-812 P-8, python_ai_data runtime).

These are *advisory* read-model signals: they surface edges and questions a
human reviewer might not expect, to direct attention — they NEVER decide
provider/model/domain/policy and are not promoted to production until human
review (runtime_promotion_policy.v1). Pure stdlib, fully deterministic.

Inputs match the existing runtime contract:
  - edges: [{"from_node_id": str, "to_node_id": str, "edge_type"?: str,
             "confidence"?: str, "confidence_score"?: float,
             "metadata"?: {...}}, ...]
    Edge shape is exactly what CodeGraphEdgeResolver.resolve(...) emits.
  - assignments (optional): {node_id: community_id} — node -> community label,
    the shape a future Leiden/community op would return. When absent, the
    cross-community term is simply skipped (degrades gracefully).
  - god_nodes (optional): [{node_id: str, degree: int, ...}] — exactly the
    CodeGraphAnalytics.godNodes(...) "god_nodes" list shape.

Confidence labels mirror CodeGraphEdgeResolver: EXTRACTED / INFERRED / AMBIGUOUS.
"""

from __future__ import annotations

from collections import defaultdict

SCHEMA = "atlas.code_graph.insights.v1"

# Scoring weights for surprising_connections. Kept explicit and additive so the
# ranking is auditable: each contributing term is reported in `why`.
_W_CROSS_COMMUNITY = 3.0
_W_PERIPHERY_TO_HUB = 2.0
_W_INFERRED = 1.0
_W_AMBIGUOUS = 1.5

# A target is "hub-like" if its in+out degree is at least this multiple of the
# source's degree (and the source is genuinely low-degree). Deterministic,
# threshold-based — no ML.
_HUB_RATIO = 3.0
_PERIPHERY_MAX_DEGREE = 2

# Labels treated as low-confidence / inferred for the surprise score.
_INFERRED_LABELS = {"INFERRED", "AMBIGUOUS"}
_AMBIGUOUS_LABELS = {"AMBIGUOUS"}


def _node_id(value):
    if not isinstance(value, str):
        return None
    trimmed = value.strip()
    return trimmed or None


def _confidence_label(edge):
    """Best-effort confidence label from an edge, checking top-level then
    metadata (CodeGraphEdgeResolver writes it in both places)."""
    if not isinstance(edge, dict):
        return None
    raw = edge.get("confidence")
    if not isinstance(raw, str) or not raw.strip():
        meta = edge.get("metadata")
        if isinstance(meta, dict):
            raw = meta.get("confidence")
    if isinstance(raw, str) and raw.strip():
        return raw.strip().upper()
    return None


def _is_inferred(edge):
    """True when the edge is non-EXTRACTED evidence. Honors the explicit
    metadata.inferred flag if present, else falls back to the label."""
    if isinstance(edge, dict):
        meta = edge.get("metadata")
        if isinstance(meta, dict) and isinstance(meta.get("inferred"), bool):
            return meta["inferred"]
    label = _confidence_label(edge)
    return label in _INFERRED_LABELS if label is not None else False


def _degrees(edges):
    """Undirected degree (in+out) per node, plus the clean (from,to,...) edge
    list we actually scored. Mirrors CodeGraphAnalytics degree counting."""
    degree = defaultdict(int)
    clean = []
    for edge in edges:
        if not isinstance(edge, dict):
            continue
        a = _node_id(edge.get("from_node_id"))
        b = _node_id(edge.get("to_node_id"))
        if a is None or b is None or a == b:
            continue
        degree[a] += 1
        degree[b] += 1
        clean.append((a, b, edge))
    return degree, clean


def surprising_connections(edges, assignments=None):
    """Rank edges by how *surprising* the connection is.

    Additive score per edge:
      +_W_CROSS_COMMUNITY  if endpoints live in different communities
                           (only when `assignments` is provided),
      +_W_PERIPHERY_TO_HUB if a low-degree node connects to a much-higher-degree
                           hub (periphery -> hub),
      +_W_AMBIGUOUS        if the edge confidence is AMBIGUOUS,
      +_W_INFERRED         else if the edge confidence is INFERRED.

    Returns a deterministic, ranked list:
      [{from_node_id, to_node_id, score, why:[reasons...]}]
    Only edges with score > 0 are returned (an expected EXTRACTED intra-community
    edge is not "surprising"). Ties broken by (from_node_id, to_node_id).
    """
    assignment_map = assignments if isinstance(assignments, dict) else {}
    degree, clean = _degrees(edges)

    scored = {}
    for a, b, edge in clean:
        score = 0.0
        why = []

        # Cross-community: an edge that jumps between detected clusters.
        if assignment_map:
            ca = assignment_map.get(a)
            cb = assignment_map.get(b)
            if ca is not None and cb is not None and ca != cb:
                score += _W_CROSS_COMMUNITY
                why.append(f"cross-community ({ca} -> {cb})")

        # Periphery -> hub: a barely-connected node reaching an architectural hub
        # (either direction; we report it from the low-degree side).
        da, db = degree.get(a, 0), degree.get(b, 0)
        low, high, low_deg, high_deg = (
            (a, b, da, db) if da <= db else (b, a, db, da)
        )
        if (
            low_deg <= _PERIPHERY_MAX_DEGREE
            and high_deg >= max(1, low_deg) * _HUB_RATIO
            and high_deg > low_deg
        ):
            score += _W_PERIPHERY_TO_HUB
            why.append(
                f"periphery->hub (deg {low_deg}={low} -> deg {high_deg}={high})"
            )

        # Low-confidence evidence is inherently more surprising/worth a look.
        label = _confidence_label(edge)
        if label in _AMBIGUOUS_LABELS:
            score += _W_AMBIGUOUS
            why.append("ambiguous-confidence edge")
        elif label in _INFERRED_LABELS or _is_inferred(edge):
            score += _W_INFERRED
            why.append("inferred (non-extracted) edge")

        if score <= 0.0:
            continue

        # Dedupe on the directed pair, keeping the strongest score (matches the
        # resolver's "keep strongest evidence" dedupe behavior).
        key = (a, b)
        existing = scored.get(key)
        if existing is None or score > existing["score"]:
            scored[key] = {
                "from_node_id": a,
                "to_node_id": b,
                "score": round(score, 6),
                "why": why,
            }

    ranked = list(scored.values())
    ranked.sort(
        key=lambda r: (-r["score"], r["from_node_id"], r["to_node_id"])
    )
    return ranked


def _hub_node_ids(god_nodes):
    """Extract ordered hub node_ids from a CodeGraphAnalytics god_nodes list."""
    hubs = []
    if isinstance(god_nodes, list):
        for entry in god_nodes:
            if isinstance(entry, dict):
                nid = _node_id(entry.get("node_id"))
                if nid is not None:
                    hubs.append(nid)
    return hubs


def suggested_questions(edges, god_nodes=None, assignments=None):
    """Template-fill 4-6 reviewer questions from graph signals.

    Signals used:
      - high-degree hubs that are reached through inferred (non-extracted) edges
        ("is this dependency real, or a name-match guess?");
      - bridge nodes / edges that span two communities (when assignments given)
        ("should these two clusters be coupled at all?");
      - isolated / leaf nodes (degree <= 1) ("is this dead, or missing edges?");
      - the single most-connected hub (blast-radius framing).

    Returns a deterministic list [{question, basis}] of 4-6 entries (capped),
    padded with a stable general question if the graph is too sparse to fill 4.
    """
    degree, clean = _degrees(edges)
    assignment_map = assignments if isinstance(assignments, dict) else {}
    hubs = _hub_node_ids(god_nodes)
    # Derive a degree-ranked hub fallback when god_nodes wasn't supplied.
    if not hubs and degree:
        hubs = sorted(degree, key=lambda n: (-degree[n], n))
    hub_set = set(hubs)

    questions = []
    seen = set()

    def _add(question, basis):
        if question not in seen:
            seen.add(question)
            questions.append({"question": question, "basis": basis})

    # 1) Hubs reached via inferred edges — highest-leverage doubt.
    inferred_into_hub = []
    for a, b, edge in clean:
        if not _is_inferred(edge):
            continue
        # Treat the higher-degree endpoint as the hub side.
        hub = b if degree.get(b, 0) >= degree.get(a, 0) else a
        other = a if hub == b else b
        if hub in hub_set:
            inferred_into_hub.append((degree.get(hub, 0), hub, other))
    inferred_into_hub.sort(key=lambda t: (-t[0], t[1], t[2]))
    for _deg, hub, other in inferred_into_hub[:2]:
        _add(
            f"Hub '{hub}' is reached from '{other}' through an inferred "
            "(non-extracted) edge — is that dependency real, or a name-match "
            "guess that should be dropped?",
            f"high-degree hub (degree {_deg}) with inferred inbound edge",
        )

    # 2) Cross-community bridges — coupling that may be accidental.
    if assignment_map:
        bridges = []
        for a, b, _edge in clean:
            ca, cb = assignment_map.get(a), assignment_map.get(b)
            if ca is not None and cb is not None and ca != cb:
                bridges.append((a, b, ca, cb))
        bridges.sort(key=lambda t: (t[0], t[1]))
        for a, b, ca, cb in bridges[:2]:
            _add(
                f"Edge '{a}' -> '{b}' bridges communities {ca} and {cb} — "
                "should these two clusters be coupled at all, or is this a "
                "leak across a boundary?",
                f"bridge edge between communities {ca} and {cb}",
            )

    # 3) Isolated / leaf nodes — possibly dead or under-indexed.
    isolated = sorted(n for n, d in degree.items() if d <= 1)
    for node in isolated[:2]:
        _add(
            f"Node '{node}' has degree {degree.get(node, 0)} (near-isolated) — "
            "is it dead code, or are its real relations missing from the graph?",
            f"isolated node (degree {degree.get(node, 0)})",
        )

    # 4) Top hub blast-radius framing — always relevant when a hub exists.
    if hubs:
        top = hubs[0]
        _add(
            f"'{top}' is the most-connected node (degree {degree.get(top, 0)}) — "
            "is its blast radius understood before any change lands there?",
            f"top god-node by degree ({degree.get(top, 0)})",
        )

    # Pad to a minimum of 4 with a stable, graph-agnostic question so the op
    # always returns a useful, deterministic set (never fewer than 4 unless the
    # graph is empty).
    if degree:
        _add(
            "Which of these connections are EXTRACTED from real imports versus "
            "INFERRED by heuristic — and is the inferred share acceptable for "
            "this part of the codebase?",
            "confidence-mix sanity check over the resolved edge set",
        )
        _add(
            "Are there modules expected to depend on these hubs that show NO "
            "edge here — i.e. coverage gaps in the index rather than the code?",
            "missing-edge / index-coverage check",
        )

    return questions[:6]
