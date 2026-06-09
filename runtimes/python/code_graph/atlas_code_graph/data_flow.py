"""Intra-file data-flow def-use edges (AP-815 P-3, python_ai_data runtime).

Reveals HOW DATA MOVES inside a file that neither AST-symbol nor import/co-change
analysis exposes: which assignment of a variable actually feeds which later use.
Two functions that touch the same variable name are not coupled — but the
*specific* assign that a use reads from is a real data-flow dependency. This is
the kind of per-file event aggregation that, per
atlas-ai-runtime-language-boundaries.md, belongs in the python_ai_data runtime
(the muscle): a language-aware *extractor* (a separate concern, not this module)
emits the assign/use events and we compute the def-use chains over them.

The model here is a classic *reaching-definitions* def-use chain restricted to a
single straight-line walk in source order (no branch/loop control-flow merging —
that is a heavier analysis gated behind dependency/promotion review). For each
file independently we walk its events in line order; every ``use`` of a variable
links to the MOST RECENT prior ``assign`` of that same variable in that file. A
use that has no prior assign is *unresolved* (counted, no edge emitted).

Pure stdlib, fully deterministic (same input -> identical output: edges are
sorted by ``(file, var, from, to)``), and fail-safe: malformed events are
skipped and a well-formed safe-default dict is always returned — this function
NEVER raises. NOT promoted to production until human review
(runtime_promotion_policy.v1).

Input ``events``: a list of dicts, each with ``file`` (str), ``var`` (str),
``kind`` (``"assign"`` or ``"use"``), ``line`` (int), and optional ``symbol``
(str). Output: deterministic def-use edges, each
``{"from", "to", "var", "file", "edge_type": "data_flow"}`` where ``from`` is the
reaching assign formatted ``def:<file>:<var>@<line>`` and ``to`` is the use
formatted ``use:<file>:<var>@<line>``.
"""

from __future__ import annotations

from typing import Any, Dict, List, Optional, Tuple

SCHEMA = "atlas.code_graph.data_flow.v1"

EDGE_TYPE = "data_flow"

KIND_ASSIGN = "assign"
KIND_USE = "use"


def _clean_str(value: Any) -> Optional[str]:
    """Return a trimmed non-empty string, or ``None`` for anything else."""
    if not isinstance(value, str):
        return None
    trimmed = value.strip()
    return trimmed or None


def _clean_line(value: Any) -> Optional[int]:
    """Coerce a line number to an ``int``, or ``None`` if it cannot be.

    Booleans are rejected explicitly (``bool`` is an ``int`` subclass and a
    ``True``/``False`` line number is always malformed). Float/str line numbers
    are accepted only when they represent an exact integer value.
    """
    if isinstance(value, bool):
        return None
    if isinstance(value, int):
        return value
    if isinstance(value, float):
        if value != value or value in (float("inf"), float("-inf")):
            return None
        if value.is_integer():
            return int(value)
        return None
    if isinstance(value, str):
        token = value.strip()
        if not token:
            return None
        try:
            return int(token)
        except (TypeError, ValueError):
            return None
    return None


def _clean_event(event: Any) -> Optional[Tuple[str, str, str, int]]:
    """Coerce one raw event into ``(file, var, kind, line)`` or ``None``.

    An event is valid only when ``file`` and ``var`` are non-empty strings,
    ``kind`` is exactly ``"assign"`` or ``"use"`` (case-insensitive, trimmed),
    and ``line`` is integer-coercible. Anything else is dropped so a malformed
    or hostile event stream cannot fabricate or corrupt an edge.
    """
    if not isinstance(event, dict):
        return None
    file = _clean_str(event.get("file"))
    if file is None:
        return None
    var = _clean_str(event.get("var"))
    if var is None:
        return None
    kind = _clean_str(event.get("kind"))
    if kind is None:
        return None
    kind = kind.lower()
    if kind not in (KIND_ASSIGN, KIND_USE):
        return None
    line = _clean_line(event.get("line"))
    if line is None:
        return None
    return (file, var, kind, line)


def _def_id(file: str, var: str, line: int) -> str:
    """Format the canonical reaching-definition (assign) node id."""
    return f"def:{file}:{var}@{line}"


def _use_id(file: str, var: str, line: int) -> str:
    """Format the canonical use node id."""
    return f"use:{file}:{var}@{line}"


def def_use_edges(events: Any) -> Dict[str, Any]:
    """Compute intra-file data-flow def-use edges from assign/use events.

    For each file independently, events are walked in line order (ties broken
    deterministically so assigns are visited before uses on the same line and
    the original order is otherwise preserved). Each ``use`` of a variable links
    to the most recent prior ``assign`` of that same variable in the same file
    (a def-use / reaching-definition chain). A use with no prior assign in its
    file is *unresolved*: it is counted but emits no edge.

    Args:
        events: list of event dicts, each with ``file`` (str), ``var`` (str),
            ``kind`` (``"assign"``/``"use"``), ``line`` (int), optional
            ``symbol`` (str). Malformed entries are skipped.

    Returns:
        ``{"schema_version", "edges": [{"from", "to", "var", "file",
        "edge_type"}], "files": int, "resolved": int, "unresolved": int}``.
        ``edges`` are sorted deterministically by ``(file, var, from, to)``.
        Always a well-formed dict; never raises on malformed input.
    """
    # Group cleaned events per file, preserving arrival order for stable
    # tie-breaking. Each record is ``(line, kind_rank, order, kind, var)`` where
    # ``order`` is the original arrival index so equal-line events keep a
    # deterministic relative order across runs.
    per_file: "Dict[str, List[Tuple[int, int, int, str, str]]]" = {}
    for order, raw in _iter_events(events):
        cleaned = _clean_event(raw)
        if cleaned is None:
            continue
        file, var, kind, line = cleaned
        # kind-rank 0 for assign, 1 for use: on the same line an assign reaches
        # a same-line use (the use reads the just-made definition).
        kind_rank = 0 if kind == KIND_ASSIGN else 1
        per_file.setdefault(file, []).append((line, kind_rank, order, kind, var))

    edges: List[Dict[str, Any]] = []
    resolved = 0
    unresolved = 0

    for file in per_file:
        records = per_file[file]
        # Deterministic walk order: by line, then assign-before-use on a tie,
        # then original arrival index for total stability.
        records.sort(key=lambda rec: (rec[0], rec[1], rec[2]))
        last_assign_line: Dict[str, int] = {}
        for line, _kind_rank, _order, kind, var in records:
            if kind == KIND_ASSIGN:
                last_assign_line[var] = line
                continue
            # kind == use
            assign_line = last_assign_line.get(var)
            if assign_line is None:
                unresolved += 1
                continue
            resolved += 1
            edges.append(
                {
                    "from": _def_id(file, var, assign_line),
                    "to": _use_id(file, var, line),
                    "var": var,
                    "file": file,
                    "edge_type": EDGE_TYPE,
                }
            )

    edges.sort(key=lambda e: (e["file"], e["var"], e["from"], e["to"]))

    return {
        "schema_version": SCHEMA,
        "edges": edges,
        "files": len(per_file),
        "resolved": resolved,
        "unresolved": unresolved,
    }


def _iter_events(events: Any):
    """Yield ``(index, event)`` pairs for a list/tuple input, else nothing.

    A non-iterable (or string) top-level input degrades to an empty stream so
    the caller still receives the safe-default result.
    """
    if not isinstance(events, (list, tuple)):
        return
    for index, event in enumerate(events):
        yield index, event
