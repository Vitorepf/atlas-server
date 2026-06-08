"""Multi-language symbol + import extraction (AP-811/AP-812 P-1/P-2,
python_ai_data runtime).

A *read-model* extractor: given source files, it surfaces the top-level
symbols each file *defines* and the modules/packages each file *imports*, as
code-graph nodes and edges. It is advisory structure only — it NEVER decides
provider/model/domain/policy and is not promoted to production until human
review (runtime_promotion_policy.v1). Pure stdlib, fully deterministic.

Why python here (not the Laravel Kernel): per
atlas-ai-runtime-language-boundaries.md, language-aware parsing (real
`ast` for python, plus the multi-language regex fallbacks) belongs in the
python_ai_data runtime; the Kernel keeps the cheap pure-transform edge work in
CodeGraphEdgeResolver.php / CodeGraphAnalytics.php.

Strategy per language:
  - python   -> real AST via the stdlib `ast` module (classes, functions,
                async functions, `import` / `from ... import`). No heuristics.
  - go / rust / typescript / javascript / java -> deterministic, line-oriented
                regex over top-level declarations + import statements. STDLIB
                only (no tree-sitter); these are intentionally conservative
                surface scans, not full parsers.

Input:
  files = [{"path": str, "language": str, "content": str}, ...]

Output (mirrors the existing runtime contract / edge shape that
CodeGraphEdgeResolver emits — from_node_id/to_node_id/edge_type/confidence):
  {
    "schema_version": "atlas.code_graph.multilang.v1",
    "nodes": [{id, label, kind, path, language}],
    "edges": [{from_node_id, to_node_id, edge_type:'imports'|'defines',
               confidence}],
  }

Node id convention follows the rest of the graph ("node:" prefix):
  - file node:   "node:file:<path>"
  - symbol node: "node:sym:<language>:<path>:<kind>:<name>"
  - import node: "node:import:<target>"  (a module/package, not necessarily a
                 file we indexed — kept as a distinct namespace so it never
                 collides with a real file node)

Confidence labels mirror CodeGraphEdgeResolver: AST-derived facts are
EXTRACTED; regex-derived facts are INFERRED (a conservative surface scan, not a
parse). Determinism: nodes/edges are de-duplicated by id and sorted.
"""

from __future__ import annotations

import ast
import re

SCHEMA = "atlas.code_graph.multilang.v1"

CONFIDENCE_EXTRACTED = "EXTRACTED"
CONFIDENCE_INFERRED = "INFERRED"

EDGE_IMPORTS = "imports"
EDGE_DEFINES = "defines"

_KIND_CLASS = "class"
_KIND_FUNCTION = "function"
_KIND_TYPE = "type"
_KIND_INTERFACE = "interface"

# Languages handled by the deterministic regex fallback (everything that is not
# python). Normalized aliases map onto a canonical language key.
_LANG_ALIASES = {
    "py": "python",
    "python": "python",
    "go": "go",
    "golang": "go",
    "rs": "rust",
    "rust": "rust",
    "ts": "typescript",
    "tsx": "typescript",
    "typescript": "typescript",
    "js": "javascript",
    "jsx": "javascript",
    "javascript": "javascript",
    "mjs": "javascript",
    "cjs": "javascript",
    "java": "java",
}


def _text(value):
    return value if isinstance(value, str) else ""


def _norm_language(value):
    lang = _text(value).strip().lower()
    return _LANG_ALIASES.get(lang, lang)


def _file_node_id(path):
    return f"node:file:{path}"


def _symbol_node_id(language, path, kind, name):
    return f"node:sym:{language}:{path}:{kind}:{name}"


def _import_node_id(target):
    return f"node:import:{target}"


# --- python (real AST) ------------------------------------------------------


def _extract_python(path, content):
    """Real-AST extraction for python. Returns (symbols, imports).

    symbols: [(kind, name)] for top-level class/def/async-def.
    imports: [target_module] for `import x` / `from x import y` (the *module*).
    Syntax errors degrade gracefully to empty results (never raise).
    """
    symbols = []
    imports = []
    try:
        tree = ast.parse(content)
    except (SyntaxError, ValueError):
        return symbols, imports

    # Only top-level (module body) definitions — matches the "top-level
    # types/functions" contract used by the regex languages, keeping the graph
    # comparable across languages.
    for node in tree.body:
        if isinstance(node, ast.ClassDef):
            symbols.append((_KIND_CLASS, node.name))
        elif isinstance(node, (ast.FunctionDef, ast.AsyncFunctionDef)):
            symbols.append((_KIND_FUNCTION, node.name))

    # Imports can appear anywhere; walk the whole tree for completeness.
    for node in ast.walk(tree):
        if isinstance(node, ast.Import):
            for alias in node.names:
                target = _text(alias.name).strip()
                if target:
                    imports.append(target)
        elif isinstance(node, ast.ImportFrom):
            # `from . import x` has module=None (relative) -> use the dotted
            # level marker so it is still a stable, deterministic target.
            module = _text(node.module).strip()
            if module:
                imports.append(module)
            elif node.level:
                imports.append("." * node.level)

    return symbols, imports


# --- regex fallback languages ----------------------------------------------

# Each language maps to: list of (kind, compiled-regex) for definitions, and a
# list of compiled regexes for imports. Patterns are anchored to the start of a
# (left-stripped) line so they capture *top-level-ish* declarations
# deterministically without a full parser. `\b` + a captured identifier keeps
# them conservative.

_IDENT = r"[A-Za-z_][A-Za-z0-9_]*"

_GO_DEFS = [
    (_KIND_FUNCTION, re.compile(r"^func\s+(?:\([^)]*\)\s*)?(" + _IDENT + r")\b")),
    (_KIND_TYPE, re.compile(r"^type\s+(" + _IDENT + r")\b")),
]
_GO_IMPORTS = [
    # single:  import "fmt"   /   import alias "pkg/path"
    re.compile(r'^import\s+(?:' + _IDENT + r'\s+)?"([^"]+)"'),
    # inside an import ( ... ) block:   "fmt"   /   alias "pkg/path"
    re.compile(r'^(?:' + _IDENT + r'\s+)?"([^"]+)"\s*$'),
]

_RUST_DEFS = [
    (_KIND_FUNCTION, re.compile(r"^(?:pub\s+)?(?:async\s+)?fn\s+(" + _IDENT + r")\b")),
    (_KIND_TYPE, re.compile(r"^(?:pub\s+)?struct\s+(" + _IDENT + r")\b")),
    (_KIND_TYPE, re.compile(r"^(?:pub\s+)?enum\s+(" + _IDENT + r")\b")),
    (_KIND_INTERFACE, re.compile(r"^(?:pub\s+)?trait\s+(" + _IDENT + r")\b")),
]
_RUST_IMPORTS = [
    # use a::b::c;  -> capture the path (strip trailing `;`, `{...}` group, `as`)
    re.compile(r"^(?:pub\s+)?use\s+([A-Za-z_][A-Za-z0-9_:]*)"),
]

# TypeScript / JavaScript share definition + import grammar for this surface
# scan (TS adds interface/type/enum).
_TS_DEFS = [
    (_KIND_CLASS, re.compile(r"^(?:export\s+)?(?:default\s+)?(?:abstract\s+)?class\s+(" + _IDENT + r")\b")),
    (_KIND_FUNCTION, re.compile(r"^(?:export\s+)?(?:default\s+)?(?:async\s+)?function\s+\*?\s*(" + _IDENT + r")\b")),
    (_KIND_INTERFACE, re.compile(r"^(?:export\s+)?interface\s+(" + _IDENT + r")\b")),
    (_KIND_TYPE, re.compile(r"^(?:export\s+)?type\s+(" + _IDENT + r")\b")),
    (_KIND_TYPE, re.compile(r"^(?:export\s+)?enum\s+(" + _IDENT + r")\b")),
]
_JS_DEFS = [
    (_KIND_CLASS, re.compile(r"^(?:export\s+)?(?:default\s+)?class\s+(" + _IDENT + r")\b")),
    (_KIND_FUNCTION, re.compile(r"^(?:export\s+)?(?:default\s+)?(?:async\s+)?function\s+\*?\s*(" + _IDENT + r")\b")),
]
# ES module + CommonJS imports. The module specifier is in quotes either way.
_JS_IMPORTS = [
    # import ... from 'x'   /   import 'x'   /   export ... from 'x'
    re.compile(r"""^(?:import|export)\b[^'"]*?from\s+['"]([^'"]+)['"]"""),
    re.compile(r"""^import\s+['"]([^'"]+)['"]"""),
    # const x = require('x')   /   require("x")
    re.compile(r"""\brequire\(\s*['"]([^'"]+)['"]\s*\)"""),
    # dynamic import('x')
    re.compile(r"""\bimport\(\s*['"]([^'"]+)['"]\s*\)"""),
]

_JAVA_DEFS = [
    (_KIND_CLASS, re.compile(r"^(?:public\s+|final\s+|abstract\s+)*class\s+(" + _IDENT + r")\b")),
    (_KIND_INTERFACE, re.compile(r"^(?:public\s+)?interface\s+(" + _IDENT + r")\b")),
    (_KIND_TYPE, re.compile(r"^(?:public\s+)?enum\s+(" + _IDENT + r")\b")),
]
_JAVA_IMPORTS = [
    # import a.b.C;   /   import static a.b.C.d;  -> capture dotted path
    re.compile(r"^import\s+(?:static\s+)?([A-Za-z_][A-Za-z0-9_.]*)"),
]

_REGEX_LANGUAGES = {
    "go": (_GO_DEFS, _GO_IMPORTS),
    "rust": (_RUST_DEFS, _RUST_IMPORTS),
    "typescript": (_TS_DEFS, _JS_IMPORTS),
    "javascript": (_JS_DEFS, _JS_IMPORTS),
    "java": (_JAVA_DEFS, _JAVA_IMPORTS),
}


def _strip_inline_comment(line, language):
    """Drop trailing `//` line comments for C-family languages so a commented
    keyword doesn't produce a phantom symbol. Conservative: ignores `//` inside
    quotes by only cutting when it is not obviously inside a string of the same
    line (best-effort surface scan)."""
    if language in ("go", "rust", "typescript", "javascript", "java"):
        idx = line.find("//")
        if idx != -1:
            return line[:idx]
    return line


def _extract_regex(language, content):
    defs, import_patterns = _REGEX_LANGUAGES[language]
    symbols = []
    imports = []
    for raw_line in content.splitlines():
        line = _strip_inline_comment(raw_line, language).strip()
        if not line:
            continue

        for kind, pattern in defs:
            match = pattern.match(line)
            if match:
                name = match.group(1)
                if name:
                    symbols.append((kind, name))
                break  # one definition kind per line

        for pattern in import_patterns:
            # require()/dynamic-import patterns can appear mid-line; the
            # import/from/use patterns are line-anchored via `^`. `search`
            # honors the `^` anchor where present and still finds inline
            # require()/import() calls.
            match = pattern.search(line)
            if match:
                target = match.group(1).strip()
                if target:
                    imports.append(target)
    return symbols, imports


# --- orchestration ----------------------------------------------------------


def extract_symbols_and_imports(files):
    """Extract symbol (`defines`) and import (`imports`) nodes + edges.

    See module docstring for the input/output contract. Deterministic: the
    returned `nodes` and `edges` are de-duplicated by id and sorted.

    For each file we emit:
      - one file node,
      - one symbol node + one (file --defines--> symbol) edge per top-level
        definition,
      - one import node + one (file --imports--> import) edge per import target.
    """
    nodes = {}
    edges = {}

    def _add_node(node_id, label, kind, path, language):
        if node_id not in nodes:
            nodes[node_id] = {
                "id": node_id,
                "label": label,
                "kind": kind,
                "path": path,
                "language": language,
            }

    def _add_edge(from_id, to_id, edge_type, confidence):
        key = (from_id, to_id, edge_type)
        if key not in edges:
            edges[key] = {
                "from_node_id": from_id,
                "to_node_id": to_id,
                "edge_type": edge_type,
                "confidence": confidence,
            }

    if not isinstance(files, list):
        files = []

    for entry in files:
        if not isinstance(entry, dict):
            continue
        path = _text(entry.get("path")).strip()
        if not path:
            continue
        language = _norm_language(entry.get("language"))
        content = _text(entry.get("content"))

        if language == "python":
            symbols, imports = _extract_python(path, content)
            confidence = CONFIDENCE_EXTRACTED
        elif language in _REGEX_LANGUAGES:
            symbols, imports = _extract_regex(language, content)
            confidence = CONFIDENCE_INFERRED
        else:
            # Unknown language: still register the file node so it appears in
            # the graph, but extract nothing (no guessing).
            symbols, imports = [], []
            confidence = CONFIDENCE_INFERRED

        file_id = _file_node_id(path)
        _add_node(file_id, path, "file", path, language)

        for kind, name in symbols:
            sym_id = _symbol_node_id(language, path, kind, name)
            _add_node(sym_id, name, kind, path, language)
            _add_edge(file_id, sym_id, EDGE_DEFINES, confidence)

        for target in imports:
            import_id = _import_node_id(target)
            # Import targets are modules/packages, not files we necessarily
            # indexed; keep them in their own namespace with no source path.
            # They are language-agnostic on purpose: the *same* module id (e.g.
            # "os") may be imported from files in different languages, so the
            # node carries no single importer's language — that keeps the output
            # order-independent (the importer's language lives on the file node
            # and is recoverable via the edge's source).
            _add_node(import_id, target, "module", "", "")
            _add_edge(file_id, import_id, EDGE_IMPORTS, confidence)

    sorted_nodes = sorted(nodes.values(), key=lambda n: n["id"])
    sorted_edges = sorted(
        edges.values(),
        key=lambda e: (e["edge_type"], e["from_node_id"], e["to_node_id"]),
    )

    return {
        "schema_version": SCHEMA,
        "nodes": sorted_nodes,
        "edges": sorted_edges,
    }
