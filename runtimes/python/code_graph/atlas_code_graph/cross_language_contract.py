"""Cross-language contract edges — the polyglot boundary (AP-815, block P-10).

Reveals the coupling that lives in a *shared API contract* rather than in any
one language's source: a TypeScript front end calls back into a PHP service over
an OpenAPI / GraphQL / proto interface, and neither codebase's AST or import
graph can see the other side. The contract IS the edge. This is the kind of
language-agnostic boundary aggregation that, per
atlas-ai-runtime-language-boundaries.md, belongs in the python_ai_data runtime
(the muscle) rather than the Laravel Kernel — the Kernel hands us the raw spec
documents (already extracted per workspace) and we fold them into one contract
graph that the code-graph can stitch onto both the caller and callee symbols.

Pure stdlib (``json`` for OpenAPI, ``re`` for GraphQL/proto SDL), deterministic
(sorted nodes + edges), fail-safe (never raises on malformed input — a spec that
fails to parse is skipped and a safe default is returned). NOT promoted to
production until human review (runtime_promotion_policy.v1).

Input ``specs``: a list of contract specs, each
``{"kind": "openapi"|"graphql"|"proto", "content": str, "workspace"?: str}``.

Per kind:
  ``openapi`` — ``json.loads`` the content (JSON only; no YAML), walk the
    ``paths`` object. For each path + HTTP method emit a node
    ``api:<METHOD> <path>`` and, when an ``operationId`` is present, an edge
    ``op:<operationId> -> api:<METHOD> <path>`` (edge_type ``operation_binds``).
  ``graphql`` — regex-parse SDL ``type Name { field: ReturnType }`` blocks. Emit
    a node ``gql:type:Name`` per object type and an edge
    ``gql:type:Name -> gql:type:ReturnType`` per field (edge_type
    ``graphql_field``), stripping ``[]`` and ``!`` list/non-null wrappers off
    the return type.
  ``proto`` — regex-parse ``service Service { rpc Method (...) returns (...) }``.
    Emit a node ``svc:<Service>`` per service and an edge
    ``svc:<Service> -> rpc:<Service>.<Method>`` per rpc (edge_type ``proto_rpc``).

Output:
  ``schema_version``: the module SCHEMA.
  ``nodes``: distinct ``{"id", "kind"}`` (kind = api / op / gql_type / svc /
    rpc), sorted by (kind, id).
  ``edges``: distinct ``{"from", "to", "edge_type"}``, sorted by
    (edge_type, from, to).
  ``by_kind``: per-input-kind counts of accepted specs
    ``{"openapi", "graphql", "proto"}``.
"""

from __future__ import annotations

import json
import re
from typing import Any, Dict, List, Set, Tuple

SCHEMA = "atlas.code_graph.cross_language_contract.v1"

# The HTTP methods we recognise inside an OpenAPI path item. Anything else under
# a path (``parameters``, ``summary``, ``$ref``, vendor extensions, …) is not a
# method and is ignored.
_HTTP_METHODS = (
    "get",
    "put",
    "post",
    "delete",
    "options",
    "head",
    "patch",
    "trace",
)

# Node kinds (stable identifiers used in the ``nodes`` output).
_KIND_API = "api"
_KIND_OP = "op"
_KIND_GQL_TYPE = "gql_type"
_KIND_SVC = "svc"
_KIND_RPC = "rpc"

# Edge types.
_EDGE_OPERATION_BINDS = "operation_binds"
_EDGE_GRAPHQL_FIELD = "graphql_field"
_EDGE_PROTO_RPC = "proto_rpc"

_GRAPHQL_KINDS = ("type",)
_IDENT = r"[A-Za-z_][A-Za-z0-9_]*"

# GraphQL: ``type Name { ... }`` / ``type Name implements X & Y { ... }``. We
# capture the type name and its brace body (no nested braces inside an object
# type body, so a non-greedy ``[^{}]*`` is sufficient and cannot run away).
_GRAPHQL_TYPE_RE = re.compile(
    r"\btype\s+(" + _IDENT + r")\b[^{}]*\{([^{}]*)\}",
    re.DOTALL,
)

# A GraphQL field line: ``name(args): ReturnType`` — we only need name + the raw
# return-type token (wrappers stripped later). Args (if any) are skipped via the
# optional ``\([^()]*\)``.
_GRAPHQL_FIELD_RE = re.compile(
    r"^\s*" + _IDENT + r"\s*(?:\([^()]*\))?\s*:\s*([^\n,]+)",
)

# proto: ``service Name { ... }`` — capture name + brace body.
_PROTO_SERVICE_RE = re.compile(
    r"\bservice\s+(" + _IDENT + r")\s*\{([^{}]*)\}",
    re.DOTALL,
)

# proto: ``rpc Method (Req) returns (Resp);`` — we only need the method name.
_PROTO_RPC_RE = re.compile(
    r"\brpc\s+(" + _IDENT + r")\s*\(",
)


def _clean_str(value: Any) -> str:
    """Coerce a value to a stripped string; non-strings / blanks -> ''."""
    if not isinstance(value, str):
        return ""
    return value.strip()


def _strip_gql_wrappers(type_token: str) -> str:
    """Reduce a GraphQL field return type to its bare named type.

    Strips list (``[]``) and non-null (``!``) wrappers plus any default/comment
    trailing noise, e.g. ``[Post!]!`` -> ``Post``. Returns ``''`` when nothing
    resembling a type name remains.
    """
    token = type_token.strip()
    # Drop a trailing inline comment / default-value tail if present.
    for sep in ("#", "@"):
        idx = token.find(sep)
        if idx != -1:
            token = token[:idx].strip()
    # Strip the wrapper characters entirely; the inner name is what we link to.
    token = token.replace("[", "").replace("]", "").replace("!", "").strip()
    match = re.match(r"^(" + _IDENT + r")", token)
    return match.group(1) if match else ""


def _parse_openapi(
    content: str,
    nodes: Dict[str, str],
    edges: Set[Tuple[str, str, str]],
) -> bool:
    """Parse an OpenAPI document (JSON only) into api nodes + op-bind edges.

    Walks ``paths`` -> ``<path>`` -> ``<method>``; emits an ``api:<METHOD>
    <path>`` node per operation and an ``op:<operationId> -> api:...`` edge when
    an ``operationId`` is present. Returns ``True`` if the document parsed as a
    JSON object (so it counts as an accepted openapi spec), ``False`` otherwise.
    Never raises.
    """
    try:
        doc = json.loads(content)
    except (ValueError, TypeError):
        return False
    if not isinstance(doc, dict):
        return False

    paths = doc.get("paths")
    if isinstance(paths, dict):
        for raw_path, path_item in paths.items():
            path = _clean_str(raw_path)
            if not path or not isinstance(path_item, dict):
                continue
            for raw_method, operation in path_item.items():
                method = _clean_str(raw_method).lower()
                if method not in _HTTP_METHODS:
                    continue
                api_id = "api:" + method.upper() + " " + path
                nodes[api_id] = _KIND_API
                if isinstance(operation, dict):
                    op_id = _clean_str(operation.get("operationId"))
                    if op_id:
                        op_node = "op:" + op_id
                        nodes[op_node] = _KIND_OP
                        edges.add((op_node, api_id, _EDGE_OPERATION_BINDS))
    # A well-formed OpenAPI object counts even if it declared no paths.
    return True


def _parse_graphql(
    content: str,
    nodes: Dict[str, str],
    edges: Set[Tuple[str, str, str]],
) -> bool:
    """Parse GraphQL SDL ``type`` blocks into gql_type nodes + field edges.

    For each ``type Name { field: ReturnType }`` block, emits ``gql:type:Name``
    and one ``gql:type:Name -> gql:type:ReturnType`` edge per field (wrappers
    stripped). Returns ``True`` if at least one object type was found. Never
    raises.
    """
    found = False
    for type_match in _GRAPHQL_TYPE_RE.finditer(content):
        type_name = type_match.group(1)
        if not type_name:
            continue
        found = True
        from_node = "gql:type:" + type_name
        nodes[from_node] = _KIND_GQL_TYPE
        body = type_match.group(2) or ""
        for line in body.splitlines():
            field_match = _GRAPHQL_FIELD_RE.match(line)
            if not field_match:
                continue
            return_type = _strip_gql_wrappers(field_match.group(1))
            if not return_type:
                continue
            to_node = "gql:type:" + return_type
            nodes[to_node] = _KIND_GQL_TYPE
            edges.add((from_node, to_node, _EDGE_GRAPHQL_FIELD))
    return found


def _parse_proto(
    content: str,
    nodes: Dict[str, str],
    edges: Set[Tuple[str, str, str]],
) -> bool:
    """Parse proto ``service``/``rpc`` lines into svc nodes + rpc edges.

    For each ``service Name { rpc Method(...) ... }``, emits ``svc:Name`` and one
    ``svc:Name -> rpc:Name.Method`` edge per rpc. Returns ``True`` if at least
    one service was found. Never raises.
    """
    found = False
    for svc_match in _PROTO_SERVICE_RE.finditer(content):
        service = svc_match.group(1)
        if not service:
            continue
        found = True
        svc_node = "svc:" + service
        nodes[svc_node] = _KIND_SVC
        body = svc_match.group(2) or ""
        for rpc_match in _PROTO_RPC_RE.finditer(body):
            method = rpc_match.group(1)
            if not method:
                continue
            rpc_node = "rpc:" + service + "." + method
            nodes[rpc_node] = _KIND_RPC
            edges.add((svc_node, rpc_node, _EDGE_PROTO_RPC))
    return found


_PARSERS = {
    "openapi": _parse_openapi,
    "graphql": _parse_graphql,
    "proto": _parse_proto,
}


def contract_graph(specs: Any) -> Dict[str, Any]:
    """Fold cross-language API-contract specs into one contract graph.

    Each well-formed spec is dispatched by ``kind`` (openapi / graphql / proto)
    to its parser, which contributes boundary nodes (the API surface) and edges
    (operation bindings / field references / rpc declarations). Results are
    deduped and emitted in deterministic sorted order so the same input always
    yields byte-identical output.

    Args:
        specs: list of contract specs, each
            ``{"kind", "content", "workspace"?}``.

    Returns:
        ``{"schema_version", "nodes", "edges", "by_kind"}``. Always a
        well-formed dict; never raises on malformed input (a spec that fails to
        parse is skipped, and ``by_kind`` only counts accepted specs).
    """
    # id -> kind (dedupes nodes; last kind wins but a given id maps to one kind).
    nodes: Dict[str, str] = {}
    # (from, to, edge_type) tuples (dedupes edges).
    edges: Set[Tuple[str, str, str]] = set()
    by_kind: Dict[str, int] = {"openapi": 0, "graphql": 0, "proto": 0}

    if isinstance(specs, (list, tuple)):
        for spec in specs:
            if not isinstance(spec, dict):
                continue
            kind = _clean_str(spec.get("kind")).lower()
            parser = _PARSERS.get(kind)
            if parser is None:
                continue
            content = spec.get("content")
            if not isinstance(content, str) or not content.strip():
                continue
            try:
                accepted = parser(content, nodes, edges)
            except Exception:  # noqa: BLE001 — fail-safe: a bad spec never raises
                accepted = False
            if accepted:
                by_kind[kind] = by_kind.get(kind, 0) + 1

    node_list: List[Dict[str, str]] = [
        {"id": node_id, "kind": kind} for node_id, kind in nodes.items()
    ]
    node_list.sort(key=lambda n: (n["kind"], n["id"]))

    edge_list: List[Dict[str, str]] = [
        {"from": src, "to": dst, "edge_type": edge_type}
        for (src, dst, edge_type) in edges
    ]
    edge_list.sort(key=lambda e: (e["edge_type"], e["from"], e["to"]))

    return {
        "schema_version": SCHEMA,
        "nodes": node_list,
        "edges": edge_list,
        "by_kind": by_kind,
    }
