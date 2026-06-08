"""Call-graph extraction: function/method -> callee name (AP-811/812, deepest granularity).

Companion to treesitter_extract.py (which captures def/import structure). This module
walks every call expression, finds its ENCLOSING function/method, and records the
called name -> a (caller, callee) pair. It is the heavy-dep, full-precision half of
the method->method call edge; the PHP CodeGraphCallResolver is the pure read-model
that turns these heuristic pairs into single-candidate method->method edges.

Same binding-agnostic pattern as treesitter_extract.py:
  - lazy ``get_parser`` (venv-only tree-sitter-language-pack);
  - every Node member access through ``_get()`` (property OR method binding);
  - children via ``.child(i)`` / ``.child_count``, kind via ``.kind``;
  - text recovered by byte-slicing the source (no Node.text).

Heavy-dep capability, so it lives in the python_ai_data runtime per
atlas-ai-runtime-language-boundaries.md, behind the promotion gate (human review)
until wired into the Kernel adapter.

Read-model only: extracts call structure, never decides provider/model/domain/policy.
Calls are inherently heuristic (no type resolution here), so the resolver downstream
marks the resulting edges INFERRED and only keeps single-candidate targets.
"""

from __future__ import annotations

SCHEMA = "atlas.code_graph.callgraph.v1"

# Node kinds that count as an enclosing function/method per language. The walk up the
# parent chain stops at the nearest of these and uses its name as the caller.
_FUNC_KINDS = {
    "python": {"function_definition"},
    "javascript": {"function_declaration", "method_definition", "function_expression", "arrow_function", "generator_function_declaration"},
    "typescript": {"function_declaration", "method_definition", "function_expression", "arrow_function", "generator_function_declaration"},
    "go": {"function_declaration", "method_declaration", "func_literal"},
    "php": {"function_definition", "method_declaration"},
}

# Node kinds that are a call expression per language.
_CALL_KINDS = {
    "python": {"call"},
    "javascript": {"call_expression"},
    "typescript": {"call_expression"},
    "go": {"call_expression"},
    "php": {"function_call_expression", "member_call_expression", "scoped_call_expression", "nullsafe_member_call_expression"},
}

# Identifier-like leaf kinds whose text is a usable simple name. The callee's name is
# the LAST such leaf inside the callee sub-expression (the method/property part of
# ``a.b.c()`` is ``c``); the caller's name is the first such leaf under a func node.
_NAME_KINDS = {
    "identifier",
    "name",
    "property_identifier",
    "field_identifier",
    "type_identifier",
    "constant",
}


def _get(obj, name):
    value = getattr(obj, name)
    return value() if callable(value) else value


def _children(node):
    count = _get(node, "child_count")
    return [node.child(i) for i in range(count)]


def _text(node, src: bytes) -> str:
    return src[_get(node, "start_byte"):_get(node, "end_byte")].decode("utf-8", "replace")


def _func_name(node, src: bytes):
    """Name of an enclosing function/method node (field 'name', else first name leaf)."""
    field = node.child_by_field_name("name")
    if field is not None:
        text = _text(field, src).strip()
        if text:
            return text
    # go method_declaration / js method_definition expose the name without a 'name'
    # field; the first identifier-like child at the top level is the simple name.
    for child in _children(node):
        if _get(child, "kind") in _NAME_KINDS:
            text = _text(child, src).strip()
            if text:
                return text
    return None


def _last_name_leaf(node, src: bytes):
    """Deepest-last simple name under ``node`` — the trailing method/property name.

    For ``a.b.c()`` the callee sub-tree is ``a.b.c``; its last identifier leaf is
    ``c``, which is the called name we want (not the receiver ``a``). Argument lists
    are excluded so a call passed as an argument never leaks its name up.
    """
    result = None

    def visit(n) -> None:
        nonlocal result
        kind = _get(n, "kind")
        if kind in ("argument_list", "arguments"):
            return
        if kind in _NAME_KINDS:
            text = _text(n, src).strip()
            if text:
                result = text
        for child in _children(n):
            visit(child)

    visit(node)
    return result


def _callee_node(call_node, lang: str):
    """The sub-expression naming what is being called, for the given language.

    PHP's member/scoped/nullsafe call nodes carry the method ``name`` as a direct
    child (sibling of the receiver and the arguments), so the whole call node minus
    its argument list is scanned. For the other grammars the callee is the call
    node's ``function`` field when present, else its first named child.
    """
    if lang == "php":
        return call_node  # _last_name_leaf skips argument_list -> trailing method name
    field = call_node.child_by_field_name("function")
    if field is not None:
        return field
    for child in _children(call_node):
        kind = _get(child, "kind")
        if kind in ("argument_list", "arguments", "type_arguments", "(", ")"):
            continue
        return child
    return None


def _enclosing_func_name(call_node, func_kinds, src: bytes):
    """Walk parents from a call node to the nearest function/method; return its name."""
    parent = _get(call_node, "parent")
    while parent is not None:
        if _get(parent, "kind") in func_kinds:
            return _func_name(parent, src)
        parent = _get(parent, "parent")
    return None


def extract_calls(files):
    """files = [{path, language, content}] -> {schema_version, calls:[{caller, callee, path, language}]}.

    For every call expression, ``caller`` is the enclosing function/method name (or the
    file path when the call is at module top level / inside an unnamed scope) and
    ``callee`` is the trailing simple name being called. Deterministic: calls are
    de-duplicated on (caller, callee, path, language) and sorted. Unknown languages,
    non-string content and parse failures degrade gracefully to no calls.
    """
    calls: dict[tuple[str, str, str, str], dict[str, str]] = {}

    for entry in files:
        if not isinstance(entry, dict):
            continue
        lang = entry.get("language")
        path = entry.get("path")
        content = entry.get("content")
        if lang not in _CALL_KINDS or not isinstance(content, str) or not isinstance(path, str):
            continue

        try:
            from tree_sitter_language_pack import get_parser  # venv-only

            tree = get_parser(lang).parse(content)
            root = _get(tree, "root_node")
        except Exception:
            continue

        src = content.encode("utf-8")
        call_kinds = _CALL_KINDS[lang]
        func_kinds = _FUNC_KINDS.get(lang, set())

        stack = [root]
        while stack:
            node = stack.pop()
            if _get(node, "kind") in call_kinds:
                callee_sub = _callee_node(node, lang)
                callee = _last_name_leaf(callee_sub, src) if callee_sub is not None else None
                if callee:
                    caller = _enclosing_func_name(node, func_kinds, src) or path
                    key = (caller, callee, path, lang)
                    if key not in calls:
                        calls[key] = {"caller": caller, "callee": callee, "path": path, "language": lang}
            stack.extend(_children(node))

    call_list = sorted(
        calls.values(),
        key=lambda c: (c["path"], c["language"], c["caller"], c["callee"]),
    )
    return {"schema_version": SCHEMA, "calls": call_list}
