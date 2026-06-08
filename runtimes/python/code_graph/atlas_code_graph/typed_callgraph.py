"""PHP type-aware call extractor (AP-811/812, P-1).

Read-model only: turns PHP source into a deterministic set of call records that
*feed* the type-aware call graph. It decides nothing (no provider/model/domain/
policy) — it observes what the AST literally says and leaves resolution of
unknown receivers to a later slice. Heavy-dep capability (tree-sitter), so it
lives in the python_ai_data runtime per the language-boundaries doc, behind the
same promotion gate as treesitter_extract.py until wired into the Kernel adapter.

Binding-agnostic, matching treesitter_extract.py: the installed tree-sitter
exposes Node members as either properties or methods, so every access goes
through _get(); Node uses .kind / .child(i) / .child_count / .parent (no
.type/.children/.text); text is recovered by byte-slicing the source;
tree.root_node is a method; parser.parse() takes a str.

What it emits per PHP file:
  - namespace (from namespace_definition / namespace_name), "" if none.
  - imports: alias -> FQN map. alias = explicit "as" alias, else the last
    segment of the imported name. FQN has any leading "\\" stripped.
  - one call record for every method call that occurs INSIDE a class method:
      {path, caller_class (FQN = namespace + enclosing class name),
       caller_method (enclosing method/function name),
       callee_name (called method short name),
       receiver}
    where receiver is:
      "this"                       for $this->m()
      "self" / "static" / "parent" for self::m() / static::m() / parent::m()
      {"static_class": "<Name>"}   for Name::m() (short last segment as written)
      "unknown"                    for $var->m() / dynamic (no type guess in v1)

The grammar in tree-sitter-language-pack uses these node kinds/fields (verified
against the installed grammar):
  namespace_definition      -> child_by_field_name("name") = namespace_name
  namespace_use_declaration -> contains namespace_use_clause nodes
  namespace_use_clause      -> child_by_field_name("alias") for `as`; the name is
                               a qualified_name / name child
  class_declaration         -> child_by_field_name("name")
  method_declaration /
  function_definition       -> child_by_field_name("name")
  member_call_expression    -> fields object (variable_name) + name (callee)
  scoped_call_expression    -> fields scope (relative_scope self/static/parent,
                               or name / qualified_name) + name (callee)
"""

from __future__ import annotations

SCHEMA = "atlas.code_graph.typed_callgraph.v1"

# Node kinds whose `name` field is the enclosing callable's name. function_definition
# is included so a method-style call written inside a plain function is still
# attributed to a caller_method (caller_class stays None when not in a class).
_CALLABLE_KINDS = {"method_declaration", "function_definition"}
_CLASS_KINDS = {"class_declaration"}
# relative_scope text -> receiver token for scoped calls.
_RELATIVE_SCOPE = {"self", "static", "parent"}


def _get(obj, name):
    """Read a Node member that may be a property or a zero-arg method."""
    value = getattr(obj, name)
    return value() if callable(value) else value


def _children(node):
    count = _get(node, "child_count")
    return [node.child(i) for i in range(count)]


def _text(node, src: bytes) -> str:
    return src[_get(node, "start_byte"):_get(node, "end_byte")].decode("utf-8", "replace")


def _field(node, name):
    """child_by_field_name guarded against binding differences (returns None)."""
    try:
        return node.child_by_field_name(name)
    except Exception:
        return None


def _line_of(node, src: bytes) -> int:
    return src[: _get(node, "start_byte")].count(b"\n") + 1


def _last_segment(fqn: str) -> str:
    cleaned = fqn.strip().lstrip("\\")
    return cleaned.split("\\")[-1] if cleaned else cleaned


def _join_ns(namespace: str, name: str) -> str:
    name = name.strip().lstrip("\\")
    return f"{namespace}\\{name}" if namespace else name


def _namespace_of_root(root, src: bytes) -> str:
    """First namespace_definition wins; file-level namespace, "" if none."""
    for node in _children(root):
        if _get(node, "kind") == "namespace_definition":
            name_node = _field(node, "name")
            if name_node is not None:
                return _text(name_node, src).strip().lstrip("\\")
            # Fall back to the namespace_name child if the field is absent.
            for child in _children(node):
                if _get(child, "kind") == "namespace_name":
                    return _text(child, src).strip().lstrip("\\")
    return ""


def _imports_of_root(root, src: bytes) -> dict:
    """alias -> FQN for every namespace_use_clause anywhere in the file."""
    imports: dict[str, str] = {}
    stack = list(_children(root))
    while stack:
        node = stack.pop()
        kind = _get(node, "kind")
        if kind == "namespace_use_clause":
            fqn = _use_clause_fqn(node, src)
            if fqn:
                alias_node = _field(node, "alias")
                alias = _text(alias_node, src).strip() if alias_node is not None else _last_segment(fqn)
                if alias:
                    imports[alias] = fqn
            # No need to descend further into a use clause.
            continue
        stack.extend(_children(node))
    return imports


def _use_clause_fqn(node, src: bytes) -> str:
    """The imported name inside a namespace_use_clause, leading '\\' stripped."""
    for child in _children(node):
        if _get(child, "kind") in ("qualified_name", "namespace_name", "name"):
            return _text(child, src).strip().lstrip("\\")
    return ""


def _enclosing(node):
    """Walk up via .parent collecting the nearest class + callable names host.

    Returns (class_node_or_None, callable_node_or_None). The callable is the
    closest enclosing method/function; the class is the closest enclosing class.
    """
    class_node = None
    callable_node = None
    current = _get(node, "parent")
    while current is not None:
        kind = _get(current, "kind")
        if callable_node is None and kind in _CALLABLE_KINDS:
            callable_node = current
        if class_node is None and kind in _CLASS_KINDS:
            class_node = current
        current = _get(current, "parent")
    return class_node, callable_node


# Node kinds that denote a type annotation on a parameter.
_TYPE_KINDS = {"named_type", "qualified_name", "name", "primitive_type", "optional_type"}


def _object_creation_class(node, src: bytes):
    """The class short-name in `new ClassName(...)`, or None."""
    for child in _children(node):
        if _get(child, "kind") in ("qualified_name", "name"):
            return _last_segment(_text(child, src))
    return None


def _param_type_and_name(param_node, src: bytes):
    """(varName, typeShort) for a simple_parameter, either may be None.

    Union/intersection types are skipped (ambiguous receiver) by returning None.
    """
    name = None
    typ = None
    for child in _children(param_node):
        kind = _get(child, "kind")
        if kind == "variable_name":
            name = _text(child, src).strip().lstrip("$")
        elif typ is None and kind in _TYPE_KINDS:
            raw = _text(child, src).strip()
            if "|" in raw or "&" in raw:  # union/intersection -> ambiguous, skip
                continue
            typ = _last_segment(raw)
    return name, typ


def _method_var_types(callable_node, src: bytes) -> dict:
    """varName -> type short-name within a method, from the two deterministic,
    no-flow-analysis sources: parameter type hints and `$v = new ClassName()`.
    First definition wins. This is what lets a `$var->m()` receiver carry a type.
    """
    types: dict[str, str] = {}
    stack = _children(callable_node)
    while stack:
        node = stack.pop()
        kind = _get(node, "kind")
        if kind == "simple_parameter":
            var, typ = _param_type_and_name(node, src)
            if var and typ and var not in types:
                types[var] = typ
        elif kind == "assignment_expression":
            left = _field(node, "left")
            right = _field(node, "right")
            if (left is not None and right is not None
                    and _get(left, "kind") == "variable_name"
                    and _get(right, "kind") == "object_creation_expression"):
                var = _text(left, src).strip().lstrip("$")
                cls = _object_creation_class(right, src)
                if var and cls and var not in types:
                    types[var] = cls
        stack.extend(_children(node))
    return types


def _member_receiver(call_node, src: bytes, var_types: dict):
    """member_call_expression receiver: 'this' for $this->; {'var_type': T} when
    the variable's type is known (param hint / new); else 'unknown'.
    """
    obj = _field(call_node, "object")
    if obj is None:
        return "unknown"
    if _get(obj, "kind") == "variable_name":
        var = _text(obj, src).strip()
        if var == "$this":
            return "this"
        typ = var_types.get(var.lstrip("$"))
        if typ:
            return {"var_type": typ}
    return "unknown"


def _scoped_receiver(call_node, src: bytes):
    """scoped_call_expression: self/static/parent, or {'static_class': Name}."""
    scope = _field(call_node, "scope")
    if scope is None:
        return "unknown"
    scope_kind = _get(scope, "kind")
    if scope_kind == "relative_scope":
        token = _text(scope, src).strip()
        if token in _RELATIVE_SCOPE:
            return token
        return "unknown"
    if scope_kind in ("name", "qualified_name"):
        short = _last_segment(_text(scope, src))
        if short:
            return {"static_class": short}
    return "unknown"


def _callee_name(call_node, src: bytes):
    name_node = _field(call_node, "name")
    if name_node is not None:
        return _text(name_node, src).strip()
    return None


def _callable_name(callable_node, src: bytes):
    name_node = _field(callable_node, "name")
    if name_node is not None:
        return _text(name_node, src).strip()
    return None


def _class_name(class_node, src: bytes):
    name_node = _field(class_node, "name")
    if name_node is not None:
        return _text(name_node, src).strip()
    return None


def _collect_calls(root, src: bytes, path: str, namespace: str) -> list:
    calls = []
    var_types_cache: dict = {}  # enclosing-callable start_byte -> {var: type}
    stack = [root]
    while stack:
        node = stack.pop()
        kind = _get(node, "kind")
        if kind in ("member_call_expression", "scoped_call_expression"):
            class_node, callable_node = _enclosing(node)
            # Only emit calls that occur inside a class method (per spec).
            if class_node is not None and callable_node is not None:
                if kind == "member_call_expression":
                    key = _get(callable_node, "start_byte")
                    if key not in var_types_cache:
                        var_types_cache[key] = _method_var_types(callable_node, src)
                    receiver = _member_receiver(node, src, var_types_cache[key])
                else:
                    receiver = _scoped_receiver(node, src)

                callee = _callee_name(node, src)
                caller_method = _callable_name(callable_node, src)
                class_short = _class_name(class_node, src)
                if callee and caller_method and class_short:
                    calls.append({
                        "path": path,
                        "caller_class": _join_ns(namespace, class_short),
                        "caller_method": caller_method,
                        "callee_name": callee,
                        "receiver": receiver,
                        "line": _line_of(node, src),
                    })
        stack.extend(_children(node))
    return calls


def _receiver_sort_key(receiver):
    """Stable ordering for receiver: str, {'static_class': name} or {'var_type': t}."""
    if isinstance(receiver, dict):
        if "static_class" in receiver:
            return (1, "static_class:" + str(receiver["static_class"]))
        if "var_type" in receiver:
            return (2, "var_type:" + str(receiver["var_type"]))
        return (3, str(sorted(receiver.items())))
    return (0, str(receiver))


def extract_typed_calls(files):
    """files = [{path, language, content}] (PHP only; other langs skipped).

    Returns {schema_version, namespace_by_file, imports_by_file, calls}.
    Deterministic: maps are plain dicts keyed by path; calls is sorted.
    """
    from tree_sitter_language_pack import get_parser  # venv-only heavy dep

    namespace_by_file: dict[str, str] = {}
    imports_by_file: dict[str, dict] = {}
    calls: list = []

    for entry in files:
        if not isinstance(entry, dict):
            continue
        if entry.get("language") != "php":
            continue  # skip non-PHP gracefully
        path = entry.get("path")
        content = entry.get("content")
        if not isinstance(path, str) or not isinstance(content, str):
            continue
        try:
            tree = get_parser("php").parse(content)
            root = _get(tree, "root_node")
        except Exception:
            continue
        src = content.encode("utf-8")

        namespace = _namespace_of_root(root, src)
        namespace_by_file[path] = namespace
        imports_by_file[path] = _imports_of_root(root, src)
        calls.extend(_collect_calls(root, src, path, namespace))

    calls.sort(key=lambda c: (
        c["path"],
        c["caller_class"],
        c["caller_method"],
        c["callee_name"],
        _receiver_sort_key(c["receiver"]),
        c["line"],
    ))

    return {
        "schema_version": SCHEMA,
        "namespace_by_file": {k: namespace_by_file[k] for k in sorted(namespace_by_file)},
        "imports_by_file": {k: imports_by_file[k] for k in sorted(imports_by_file)},
        "calls": calls,
    }
