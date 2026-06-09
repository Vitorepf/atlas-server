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
    "rust": {"function_item": "function", "struct_item": "struct", "trait_item": "trait", "enum_item": "enum", "mod_item": "module"},
    "java": {"class_declaration": "class", "method_declaration": "method", "interface_declaration": "interface"},
    # P-5a breadth (tree-sitter-language-pack): more grammars, same node shape.
    "ruby": {"class": "class", "method": "method", "singleton_method": "method", "module": "module"},
    "kotlin": {"class_declaration": "class", "function_declaration": "function", "object_declaration": "object"},
    "scala": {"class_definition": "class", "function_definition": "function", "object_definition": "object", "trait_definition": "trait"},
    "swift": {"class_declaration": "class", "function_declaration": "function", "protocol_declaration": "protocol"},
    "php": {"class_declaration": "class", "function_definition": "function", "method_declaration": "method", "interface_declaration": "interface", "trait_declaration": "trait"},
    "c": {"function_definition": "function", "struct_specifier": "struct"},
    "cpp": {"function_definition": "function", "class_specifier": "class", "struct_specifier": "struct"},
    "lua": {"function_declaration": "function"},
    "bash": {"function_definition": "function"},
}
_IMPORTS = {
    "python": {"import_statement", "import_from_statement"},
    "javascript": {"import_statement"},
    "typescript": {"import_statement"},
    "go": {"import_spec"},
    "rust": {"use_declaration"},
    "java": {"import_declaration"},
    "kotlin": {"import_header"},
    "scala": {"import_declaration"},
    "swift": {"import_declaration"},
    "php": {"namespace_use_declaration"},
    "c": {"preproc_include"},
    "cpp": {"preproc_include"},
}

# P-5a: normalize an incoming `language` (file extension or alt name) to the
# tree_sitter_language_pack grammar id. Identity for ids already in _DEFS; this
# only ADDS reach (e.g. "rs"->"rust", "rb"->"ruby") and never changes the
# behavior of a language already passed by its canonical id.
_LANG_ALIASES = {
    # python
    "py": "python", "pyi": "python", "python3": "python",
    # javascript / typescript
    "js": "javascript", "jsx": "javascript", "mjs": "javascript", "cjs": "javascript", "node": "javascript",
    "ts": "typescript", "tsx": "typescript",
    # go
    "golang": "go",
    # rust
    "rs": "rust",
    # java / jvm
    "kt": "kotlin", "kts": "kotlin",
    "sc": "scala",
    # ruby
    "rb": "ruby", "ruby2": "ruby", "ruby3": "ruby",
    # php
    "php3": "php", "php4": "php", "php5": "php", "phtml": "php",
    # swift
    "swift5": "swift",
    # c / c++
    "h": "c",
    "cc": "cpp", "cxx": "cpp", "hpp": "cpp", "hxx": "cpp", "c++": "cpp", "cplusplus": "cpp",
    # shell / lua
    "sh": "bash", "shell": "bash", "zsh": "bash",
    "luau": "lua",
}


def _resolve_language(lang):
    """Map a file extension / language alias to a tree_sitter_language_pack id.

    Case-insensitive; strips a leading dot. Returns the canonical id when known,
    otherwise the lowercased input unchanged so the pack still gets a shot at it.
    """
    if not isinstance(lang, str):
        return None
    key = lang.strip().lstrip(".").lower()
    if not key:
        return None
    return _LANG_ALIASES.get(key, key)


def _get(obj, name):
    value = getattr(obj, name)
    return value() if callable(value) else value


def _children(node):
    count = _get(node, "child_count")
    return [node.child(i) for i in range(count)]


def _text(node, src: bytes) -> str:
    return src[_get(node, "start_byte"):_get(node, "end_byte")].decode("utf-8", "replace")


def _row_of(point):
    """1-based row from a tree-sitter point (Point.row or a (row, col) tuple); None if absent."""
    if point is None:
        return None
    row = getattr(point, "row", None)
    if row is None:
        try:
            row = point[0]
        except (TypeError, IndexError, KeyError):
            return None
    try:
        return int(row) + 1
    except (TypeError, ValueError):
        return None


def _point(node, *names):
    """First available position point on the node; binding-agnostic over member name + property/method."""
    for name in names:
        try:
            return _get(node, name)
        except Exception:
            continue
    return None


def _line_span(node):
    """(line_start, line_end) 1-based from the node's start/end point rows; (None, None) on failure.

    The installed binding names them start_position/end_position; other tree-sitter
    builds use start_point/end_point — try both, and tolerate missing position info.
    """
    start = _point(node, "start_position", "start_point")
    end = _point(node, "end_position", "end_point")
    return _row_of(start), _row_of(end)


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
        path = entry.get("path")
        content = entry.get("content")
        # P-5a: resolve extension/alias -> grammar id, then gate. Languages handled
        # by the original path (python/js/ts/go/rust/java) resolve to themselves,
        # so their behavior is unchanged; aliases and added grammars now also pass.
        lang = _resolve_language(entry.get("language"))
        if lang not in _DEFS or not isinstance(content, str) or not isinstance(path, str):
            continue
        # FAIL-SAFE: a missing/broken grammar (e.g. c_sharp not in the bundle) must
        # never raise — skip the file and keep every other language working.
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
                    line_start, line_end = _line_span(node)
                    nodes[nid] = {"id": nid, "label": name, "kind": defmap[kind], "path": path, "language": lang, "line": line, "line_start": line_start, "line_end": line_end}
            elif lang in ("javascript", "typescript") and kind == "variable_declarator":
                # const X = () => ... / const X = function ... — the React component &
                # arrow-export idiom that a `function`-keyword-only walk misses. The whole
                # point of A1 for React is to capture these, so emit them as functions.
                value = node.child_by_field_name("value")
                vkind = _get(value, "kind") if value is not None else ""
                if vkind in ("arrow_function", "function", "function_expression"):
                    name = _name_of(node, src)
                    if name:
                        nid = f"sym:{lang}:{path}:function:{name}"
                        line = src[: _get(node, "start_byte")].count(b"\n") + 1
                        line_start, line_end = _line_span(node)
                        nodes[nid] = {"id": nid, "label": name, "kind": "function", "path": path, "language": lang, "line": line, "line_start": line_start, "line_end": line_end}
            elif kind in impset:
                text = _text(node, src).strip()
                if text:
                    tid = f"import:{text[:80]}"
                    line_start, line_end = _line_span(node)
                    nodes[tid] = {"id": tid, "label": text[:80], "kind": "import", "path": path, "language": "", "line_start": line_start, "line_end": line_end}
                    edges[f"file:{path}|{tid}"] = {"from_node_id": f"file:{path}", "to_node_id": tid, "edge_type": "imports", "confidence": "EXTRACTED"}
            stack.extend(_children(node))

    node_list = sorted(nodes.values(), key=lambda n: n["id"])
    edge_list = sorted(edges.values(), key=lambda e: (e["edge_type"], e["from_node_id"], e["to_node_id"]))
    return {"schema_version": SCHEMA, "node_count": len(node_list), "edge_count": len(edge_list), "nodes": node_list, "edges": edge_list}
