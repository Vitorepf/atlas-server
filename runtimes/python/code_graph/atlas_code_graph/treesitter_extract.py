"""Tree-sitter multi-language extraction (AP-812 P-1, full precision).

Requires the code_graph venv (tree-sitter + tree-sitter-language-pack). Real AST
parsing across grammars — captures nested defs/methods/imports that the stdlib
`ast`(top-level)+regex fallback in multilang.py cannot. Heavy-dep capability, so
it lives in the python_ai_data runtime per atlas-ai-runtime-language-boundaries.md;
behind the promotion gate (human review) until wired into the Kernel adapter.

Binding-agnostic: the installed tree-sitter exposes Node members as either
properties or methods (kind/child_count/child/start_byte), so every access goes
through _get(). Text is recovered by byte-slicing the source (no Node.text here).

Read-model only: extracts structure, never decides provider/model/domain/policy.
"""

from __future__ import annotations

SCHEMA = "atlas.code_graph.treesitter.v1"

_DEFS = {
    "python": {"class_definition": "class", "function_definition": "function"},
    "javascript": {"class_declaration": "class", "function_declaration": "function", "method_definition": "method"},
    "typescript": {"class_declaration": "class", "function_declaration": "function", "method_definition": "method", "interface_declaration": "interface"},
    "go": {"function_declaration": "function", "method_declaration": "method", "type_spec": "type"},
    "rust": {"function_item": "function", "struct_item": "struct", "trait_item": "trait"},
    "java": {"class_declaration": "class", "method_declaration": "method", "interface_declaration": "interface"},
}
_IMPORTS = {
    "python": {"import_statement", "import_from_statement"},
    "javascript": {"import_statement"},
    "typescript": {"import_statement"},
    "go": {"import_spec"},
    "rust": {"use_declaration"},
    "java": {"import_declaration"},
}


def _get(obj, name):
    value = getattr(obj, name)
    return value() if callable(value) else value


def _children(node):
    count = _get(node, "child_count")
    return [node.child(i) for i in range(count)]


def _text(node, src: bytes) -> str:
    return src[_get(node, "start_byte"):_get(node, "end_byte")].decode("utf-8", "replace")


def _name_of(node, src: bytes):
    field = node.child_by_field_name("name")
    if field is not None:
        return _text(field, src)
    for child in _children(node):
        if _get(child, "kind") in ("identifier", "type_identifier", "constant", "field_identifier"):
            return _text(child, src)
    return None


def extract(files):
    """files = [{path, language, content}] -> {schema_version, node_count, edge_count, nodes, edges}."""
    from tree_sitter_language_pack import get_parser  # venv-only

    nodes = {}
    edges = {}
    for entry in files:
        if not isinstance(entry, dict):
            continue
        lang = entry.get("language")
        path = entry.get("path")
        content = entry.get("content")
        if lang not in _DEFS or not isinstance(content, str) or not isinstance(path, str):
            continue
        try:
            tree = get_parser(lang).parse(content)
            root = _get(tree, "root_node")
        except Exception:
            continue
        src = content.encode("utf-8")
        defmap = _DEFS[lang]
        impset = _IMPORTS.get(lang, set())

        stack = [root]
        while stack:
            node = stack.pop()
            kind = _get(node, "kind")
            if kind in defmap:
                name = _name_of(node, src)
                if name:
                    nid = f"sym:{lang}:{path}:{defmap[kind]}:{name}"
                    line = src[: _get(node, "start_byte")].count(b"\n") + 1
                    nodes[nid] = {"id": nid, "label": name, "kind": defmap[kind], "path": path, "language": lang, "line": line}
            elif kind in impset:
                text = _text(node, src).strip()
                if text:
                    tid = f"import:{text[:80]}"
                    nodes[tid] = {"id": tid, "label": text[:80], "kind": "import", "path": path, "language": ""}
                    edges[f"file:{path}|{tid}"] = {"from_node_id": f"file:{path}", "to_node_id": tid, "edge_type": "imports", "confidence": "EXTRACTED"}
            stack.extend(_children(node))

    node_list = sorted(nodes.values(), key=lambda n: n["id"])
    edge_list = sorted(edges.values(), key=lambda e: (e["edge_type"], e["from_node_id"], e["to_node_id"]))
    return {"schema_version": SCHEMA, "node_count": len(node_list), "edge_count": len(edge_list), "nodes": node_list, "edges": edge_list}
