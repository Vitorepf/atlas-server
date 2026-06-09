"""Symbol->symbol edge resolution — a faithful python mirror of the PHP
CodeGraphSymbolResolver (AP-815 C5, python_ai_data runtime).

This is the FLAG-GATED python alternative to
``app/Services/Engineering/CodeGraph/CodeGraphSymbolResolver.php``. It resolves the
SAME symbol->symbol (FQN) edges with the SAME existence/single-candidate gate, the
SAME import-evidence promotion (EXTRACTED vs INFERRED), the SAME dedup
(strongest-evidence-wins + occurrence counting) and the SAME deterministic edge
ordering. Given identical ``{symbols, relations}`` input it MUST emit an edge set
identical to the PHP resolver — that equivalence is what makes it a safe opt-in.

Why this exists: the operator rule is "heavy data work belongs in Python". This
module makes the symbol-edge join available as a MEASURABLE option in the heavy
runtime without disturbing the proven PHP default. Per
atlas-ai-runtime-language-boundaries.md the python runtime is a *muscle*: it
computes edges over the symbols/relations it is handed and returns them; it never
chooses provider/model/domain/policy and never writes Memory/Context/Policy.

The PHP resolver stays the DEFAULT (see CodeGraphSymbolBuilder): it is the proven
99.5%-precision path, and shipping ~100k symbols across the PHP->python boundary
carries IPC/serialization overhead that may negate any compute win — so this op is
an opt-in to MEASURE, not an assumed improvement.

Pure stdlib, fully deterministic (same input -> identical output), and fail-safe:
malformed entries are skipped and a well-formed safe-default dict is always
returned — ``resolve_edges`` NEVER raises. NOT promoted to production until human
review (runtime_promotion_policy.v1).

Input ``payload``: ``{"symbols": [{"name", "type", "file_path"}, ...],
"relations": [{"file_path", "symbol", "kind"}, ...]}``. Output mirrors the PHP
``resolve()`` return shape::

    {"schema_version", "symbol_node_ids": [...], "edges": [{...}], "stats": {...}}
"""

from __future__ import annotations

import hashlib
from typing import Any, Dict, List, Optional, Tuple

# Mirror of CodeGraphSymbolResolver::SCHEMA — kept byte-identical so persisted
# edge metadata ("resolver" tag) is indistinguishable from the PHP path.
SCHEMA = "atlas.code_graph.symbol_edges.v1"

CONFIDENCE_EXTRACTED = "EXTRACTED"
CONFIDENCE_INFERRED = "INFERRED"

_EDGE_DEPENDS_ON = "depends_on"
_EDGE_TESTS = "tests"

_SCORE_EXTRACTED = 1.0
_SCORE_REFERENCE_INFERRED = 0.85
_SCORE_TEST_INFERRED = 0.8

# Symbol kinds that are real graph nodes (the code backbone) — mirrors
# CodeGraphSymbolResolver::NODE_KINDS exactly (order is irrelevant, membership is).
_NODE_KINDS = ("class", "interface", "trait", "enum", "method")

# Node-id length ceiling (world-model node_id varchar(160)); mirrors the PHP guard.
_NODE_ID_MAX = 160
_NODE_ID_TAIL = 130
_NODE_ID_HASH_LEN = 12


def _str(value: Any) -> Optional[str]:
    """Trimmed non-empty string, or ``None`` — mirror of PHP ``str()``.

    PHP's ``str()`` returns null for anything that is not a string and for an
    empty/whitespace-only string. We reproduce that exactly (note: ``bool`` and
    numbers are NOT strings here, matching PHP's strict ``is_string``).
    """
    if not isinstance(value, str):
        return None
    trimmed = value.strip()
    return trimmed or None


def _fqn(value: Any) -> Optional[str]:
    """Normalize a fully-qualified name — mirror of PHP ``fqn()``.

    Drops a leading backslash (``ltrim($v, '\\')`` strips ALL leading
    backslashes, so we mirror that with ``lstrip``), keeping namespace +
    ``Class[::method]``. Returns ``None`` for non-strings / empties.
    """
    v = _str(value)
    if v is None:
        return None
    return v.lstrip("\\")


def _node_id(fqn: str) -> str:
    """Deterministic node id for a symbol FQN — mirror of PHP ``nodeId()``.

    ``sym:<fqn>`` when within the 160-char ceiling; otherwise the readable tail
    (last 130 chars of the FQN) plus the first 12 hex of ``sha1(fqn)`` so long
    FQNs stay unique and collision-safe. ``strlen``/``substr`` operate on bytes
    in PHP; for the ASCII FQNs the code graph emits this matches python slicing.
    """
    candidate = "sym:" + fqn
    if len(candidate) <= _NODE_ID_MAX:
        return candidate
    digest = hashlib.sha1(fqn.encode("utf-8")).hexdigest()[:_NODE_ID_HASH_LEN]
    return "sym:" + fqn[-_NODE_ID_TAIL:] + "#" + digest


def _kind_family(kind: Any) -> str:
    """Classify a relation kind — mirror of PHP ``kindFamily()``.

    Lowercases the kind (non-strings become ``""``); a kind containing
    ``use``/``import``/``depend`` is a ``dependency``, one containing ``test`` is
    a ``test``, otherwise ``reference``. The substring checks run in this exact
    order (dependency wins over test when both substrings are present), matching
    the PHP ``if`` ladder.
    """
    k = kind.lower() if isinstance(kind, str) else ""
    if "use" in k or "import" in k or "depend" in k:
        return "dependency"
    if "test" in k:
        return "test"
    return "reference"


def _grade(
    family: str,
    file: str,
    target_fqn: str,
    imported_by_file: Dict[str, Dict[str, bool]],
) -> Tuple[str, str, float]:
    """Return ``(edge_type, confidence, score)`` — mirror of PHP ``grade()``.

    * ``dependency`` -> ``depends_on`` / EXTRACTED / 1.0 (an explicit import is
      always trusted, no promotion needed);
    * ``test`` -> ``tests``; EXTRACTED/1.0 when the file ALSO imports the target
      (import-evidence promotion), else INFERRED/0.8;
    * ``reference`` -> ``depends_on``; EXTRACTED/1.0 when promoted, else
      INFERRED/0.85.
    """
    if family == "dependency":
        return (_EDGE_DEPENDS_ON, CONFIDENCE_EXTRACTED, _SCORE_EXTRACTED)
    if family == "test":
        promoted = target_fqn in imported_by_file.get(file, {})
        return (
            _EDGE_TESTS,
            CONFIDENCE_EXTRACTED if promoted else CONFIDENCE_INFERRED,
            _SCORE_EXTRACTED if promoted else _SCORE_TEST_INFERRED,
        )
    # reference: import-evidence promotion.
    promoted = target_fqn in imported_by_file.get(file, {})
    return (
        _EDGE_DEPENDS_ON,
        CONFIDENCE_EXTRACTED if promoted else CONFIDENCE_INFERRED,
        _SCORE_EXTRACTED if promoted else _SCORE_REFERENCE_INFERRED,
    )


def _add_edge(
    edges: Dict[str, Dict[str, Any]],
    used_nodes: "Dict[str, bool]",
    stats: Dict[str, int],
    from_id: str,
    to_id: str,
    edge_type: str,
    confidence: str,
    score: float,
    kind: str,
    source_ref: str,
) -> None:
    """Insert or dedup-merge one edge — mirror of PHP ``addEdge()``.

    Key is ``from|to|type``. On a repeat: bump ``deduped`` + ``occurrences`` and,
    if the new score strictly beats the stored one, upgrade the stored
    confidence/score/relation (strongest-evidence-wins). On first sight: count it
    as extracted/inferred, mark both nodes used, and build the full edge record
    with the same metadata keys the PHP resolver writes (so persisted rows are
    indistinguishable between the two paths).
    """
    key = from_id + "|" + to_id + "|" + edge_type
    existing = edges.get(key)
    if existing is not None:
        stats["deduped"] += 1
        existing["metadata"]["occurrences"] += 1
        if score > existing["confidence_score"]:
            existing["confidence"] = confidence
            existing["confidence_score"] = score
            existing["metadata"]["relation"] = kind
        return

    if confidence == CONFIDENCE_EXTRACTED:
        stats["extracted"] += 1
    else:
        stats["inferred"] += 1
    used_nodes[from_id] = True
    used_nodes[to_id] = True

    edges[key] = {
        "from_node_id": from_id,
        "to_node_id": to_id,
        "edge_type": edge_type,
        "confidence": confidence,
        "confidence_score": score,
        "metadata": {
            "resolver": SCHEMA,
            "relation": kind,
            "confidence": confidence,
            "confidence_score": score,
            "source_ref": source_ref,
            "occurrences": 1,
            "inferred": confidence != CONFIDENCE_EXTRACTED,
        },
    }


def _iter_list(value: Any):
    """Yield items of a list/tuple, else nothing (degrade non-iterables safely)."""
    if not isinstance(value, (list, tuple)):
        return
    for item in value:
        yield item


def resolve_edges(payload: Any) -> Dict[str, Any]:
    """Resolve symbol->symbol edges from ``{symbols, relations}``.

    Faithful python mirror of ``CodeGraphSymbolResolver::resolve()``:

    1. Index symbols: only NODE_KINDS count. ``FQN -> defining node id`` is
       single-candidate — a FQN defined in a SECOND, different file is marked
       AMBIGUOUS and is never used as an edge target (the first-seen definition
       is kept, matching the PHP "else" guard). Class-like (non-method) symbols
       are tracked per file as the edge SOURCES.
    2. Build per-file imported FQNs from ``dependency``-family relations
       (import-evidence for promotion).
    3. For each relation: resolve the target FQN to its single defining node;
       skip unresolved/ambiguous/source-less ones; grade it; and emit one edge
       per class-like source in the relation's file (skipping self-edges),
       deduping with strongest-evidence-wins.
    4. Sort edges deterministically by ``(from, to, edge_type)``.

    Args:
        payload: ``{"symbols": [...], "relations": [...]}``. Any other shape, or
            malformed entries, degrade safely.

    Returns:
        ``{"schema_version", "symbol_node_ids", "edges", "stats"}`` — the same
        shape the PHP ``resolve()`` returns. Always well-formed; never raises.
    """
    symbols = payload.get("symbols") if isinstance(payload, dict) else None
    relations = payload.get("relations") if isinstance(payload, dict) else None

    # --- Pass 1: index defining symbols + per-file class-like sources. ---
    def_by_fqn: Dict[str, str] = {}
    def_file_by_fqn: Dict[str, str] = {}
    collisions: Dict[str, bool] = {}
    classes_by_file: Dict[str, List[str]] = {}

    for sym in _iter_list(symbols):
        if not isinstance(sym, dict):
            continue
        name = _fqn(sym.get("name"))
        raw_type = sym.get("type")
        sym_type = raw_type.lower() if isinstance(raw_type, str) else ""
        file = _str(sym.get("file_path"))
        if name is None or sym_type not in _NODE_KINDS:
            continue
        # PHP uses ($file ?? '') for the def-file: a missing file_path is "".
        def_file = file if file is not None else ""
        if name in def_file_by_fqn and def_file_by_fqn[name] != def_file:
            collisions[name] = True  # same FQN in a different file -> ambiguous
        else:
            def_file_by_fqn[name] = def_file
            def_by_fqn[name] = _node_id(name)
        # Track the class-like symbols defined per file (the edge sources).
        if file is not None and sym_type != "method":
            classes_by_file.setdefault(file, []).append(name)

    edges: Dict[str, Dict[str, Any]] = {}
    used_nodes: "Dict[str, bool]" = {}
    stats: Dict[str, int] = {
        "relations": 0,
        "extracted": 0,
        "inferred": 0,
        "skipped_unresolved": 0,
        "skipped_self": 0,
        "deduped": 0,
        "ambiguous_target": 0,
    }

    # --- Pass 2: per-file imported FQNs (for import-evidence promotion). ---
    imported_by_file: Dict[str, Dict[str, bool]] = {}
    for rel in _iter_list(relations):
        if not isinstance(rel, dict):
            continue
        if _kind_family(rel.get("kind", "")) != "dependency":
            continue
        f = _str(rel.get("file_path"))
        s = _fqn(rel.get("symbol"))
        if f is not None and s is not None:
            imported_by_file.setdefault(f, {})[s] = True

    # --- Pass 3: resolve each relation into graded, deduped edges. ---
    for rel in _iter_list(relations):
        if not isinstance(rel, dict):
            continue
        stats["relations"] += 1
        file = _str(rel.get("file_path"))
        target_fqn = _fqn(rel.get("symbol"))
        family = _kind_family(rel.get("kind", ""))
        if file is None or target_fqn is None:
            stats["skipped_unresolved"] += 1
            continue
        if target_fqn in collisions:
            stats["ambiguous_target"] += 1
            continue  # not single-candidate
        target_node = def_by_fqn.get(target_fqn)
        sources = classes_by_file.get(file, [])
        if target_node is None or not sources:
            stats["skipped_unresolved"] += 1
            continue

        edge_type, confidence, score = _grade(
            family, file, target_fqn, imported_by_file
        )
        # PHP casts the relation kind to string for the metadata "relation" field.
        kind_str = rel.get("kind")
        kind_str = kind_str if isinstance(kind_str, str) else str(kind_str)

        for source_fqn in sources:
            source_node = _node_id(source_fqn)
            if source_node == target_node:
                stats["skipped_self"] += 1
                continue
            _add_edge(
                edges,
                used_nodes,
                stats,
                source_node,
                target_node,
                edge_type,
                confidence,
                score,
                kind_str,
                file,
            )

    # Deterministic order: by (from_node_id, to_node_id, edge_type), mirroring the
    # PHP usort spaceship comparison over the same 3-tuple.
    edge_list = sorted(
        edges.values(),
        key=lambda e: (e["from_node_id"], e["to_node_id"], e["edge_type"]),
    )

    return {
        "schema_version": SCHEMA,
        # Insertion order of node keys, exactly like PHP's array_keys(usedNodes).
        "symbol_node_ids": list(used_nodes.keys()),
        "edges": edge_list,
        "stats": stats,
    }
