"""SCIP reference/occurrence edges — compiler-grade who-references-whom (AP-815
P-5b, python_ai_data runtime).

The existing ``ingest_scip`` op (see ingest_lite.ingest_scip_json) walks a SCIP
index's ``documents[].symbols[]`` relationship markers. This module is the
*occurrence* complement: it reads the per-document ``occurrences`` list — the
actual locations where a symbol appears — and turns them into edges that answer
"which file references this symbol?". Definitions (occurrences whose
``symbol_roles`` bitmask has bit ``0x1`` set) anchor a symbol to its declaring
path; every NON-definition occurrence of a symbol is a reference from the
referencing document to that symbol. This is the kind of compiler-grade,
index-aggregation analytic that — per atlas-ai-runtime-language-boundaries.md —
belongs in the python_ai_data runtime (the muscle), not the Laravel Kernel.

Pure stdlib (dict walking + bit math), fully deterministic (same input ->
identical output; edges deduped + sorted by ``from``/``to``), and fail-safe: it
NEVER raises on malformed input — missing keys, wrong types, garbage entries are
skipped and a well-formed safe default is returned. NOT promoted to production
until human review (runtime_promotion_policy.v1).

Input ``scip``: a SCIP-shaped dict ``{"documents": [{...}]}`` where each document
has a ``relative_path`` (str) and an ``occurrences`` list, each occurrence having
a ``symbol`` (str) and ``symbol_roles`` (int bitmask; bit ``0x1`` == Definition).

Output (always a well-formed dict)::

    {"schema_version", "edges": [{"from", "to", "symbol", "edge_type"}],
     "definitions": int, "references": int, "documents": int}

Edge ids are namespaced so they never collide: the referencing document is
``ref:<relative_path>`` and the symbol is ``sym:<symbol>``. Defined symbols are
returned as nodes ``{"id": "sym:<symbol>", "path": <relative_path>}``.
"""

from __future__ import annotations

from typing import Any, Dict, List

SCHEMA = "atlas.code_graph.scip_references.v1"

# Edge type emitted for every non-definition occurrence.
EDGE_TYPE_REFERENCE = "scip_reference"

# SCIP SymbolRole bitmask: bit 0x1 marks a Definition occurrence.
_ROLE_DEFINITION = 0x1

# Node id namespaces — kept distinct so a path and a symbol can never collide.
_NS_REF = "ref"
_NS_SYM = "sym"


def _clean_str(value: Any) -> str | None:
    """Return a trimmed non-empty string, or ``None`` for anything else."""
    if not isinstance(value, str):
        return None
    trimmed = value.strip()
    return trimmed or None


def _is_definition(symbol_roles: Any) -> bool:
    """True iff the role bitmask is an int with the Definition bit (0x1) set.

    Booleans are rejected even though ``bool`` is an ``int`` subclass: a SCIP
    ``symbol_roles`` field is a numeric bitmask, and accepting ``True`` (== 1)
    would let a non-numeric export accidentally flag every occurrence as a
    definition. Strings like ``"1"`` are likewise rejected — bit math is only
    meaningful on a real int.
    """
    if isinstance(symbol_roles, bool):
        return False
    if not isinstance(symbol_roles, int):
        return False
    return bool(symbol_roles & _ROLE_DEFINITION)


def scip_reference_edges(scip: Any) -> Dict[str, Any]:
    """Compute SCIP reference edges + defined-symbol nodes from occurrences.

    For each document in ``scip["documents"]`` with a usable ``relative_path``:
      * a DEFINITION occurrence (``symbol_roles`` bit ``0x1`` set) registers a
        defined-symbol node ``{"id": "sym:<symbol>", "path": <relative_path>}``
        and contributes to the ``definitions`` count — it emits NO edge;
      * every NON-definition occurrence emits a reference edge
        ``{"from": "ref:<relative_path>", "to": "sym:<symbol>", "symbol",
        "edge_type": "scip_reference"}`` and contributes to ``references``.

    Edges are de-duplicated (a symbol referenced many times from one document
    yields a single edge) and sorted deterministically by (``from``, ``to``).
    Defined-symbol nodes are de-duplicated by ``id`` (first declaring path wins)
    and sorted by ``id``.

    Args:
        scip: a SCIP-shaped dict ``{"documents": [{...}]}``. Any other type, or
            malformed documents/occurrences, degrade safely.

    Returns:
        ``{"schema_version", "edges", "definitions", "references",
        "documents"}``. Always well-formed; never raises on malformed input.
    """
    # Deduped edges keyed by (from_id, to_id); first occurrence's payload wins.
    edges_by_key: Dict[tuple, Dict[str, Any]] = {}
    # Defined-symbol nodes keyed by sym id; first declaring path wins.
    defs_by_id: Dict[str, Dict[str, Any]] = {}
    definitions = 0
    references = 0
    documents = 0

    docs = scip.get("documents") if isinstance(scip, dict) else None
    if isinstance(docs, list):
        for document in docs:
            if not isinstance(document, dict):
                continue
            rel_path = _clean_str(document.get("relative_path"))
            if rel_path is None:
                # A document without a usable path can neither define nor
                # reference in a way we can attribute — skip it entirely.
                continue
            occurrences = document.get("occurrences")
            if not isinstance(occurrences, list):
                # Path was usable but there's nothing to walk; still a document.
                documents += 1
                continue

            documents += 1
            from_id = f"{_NS_REF}:{rel_path}"

            for occ in occurrences:
                if not isinstance(occ, dict):
                    continue
                symbol = _clean_str(occ.get("symbol"))
                if symbol is None:
                    continue
                sym_id = f"{_NS_SYM}:{symbol}"

                if _is_definition(occ.get("symbol_roles")):
                    definitions += 1
                    if sym_id not in defs_by_id:
                        defs_by_id[sym_id] = {"id": sym_id, "path": rel_path}
                    continue

                # Non-definition occurrence -> reference edge.
                references += 1
                key = (from_id, sym_id)
                if key not in edges_by_key:
                    edges_by_key[key] = {
                        "from": from_id,
                        "to": sym_id,
                        "symbol": symbol,
                        "edge_type": EDGE_TYPE_REFERENCE,
                    }

    edges: List[Dict[str, Any]] = sorted(
        edges_by_key.values(), key=lambda e: (e["from"], e["to"])
    )
    defs: List[Dict[str, Any]] = sorted(
        defs_by_id.values(), key=lambda n: n["id"]
    )

    return {
        "schema_version": SCHEMA,
        "edges": edges,
        "definitions": definitions,
        "references": references,
        "documents": documents,
        # Defined-symbol node list (spec: "also a node list of defined symbols").
        "nodes": defs,
    }
