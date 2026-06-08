"""Stdlib-only "lite" ingesters that turn three text/JSON artifact kinds into
code-graph nodes + edges (AP-811/AP-812 P-11, python_ai_data runtime).

This is a *read-model feeder*: it parses already-on-disk artifacts (MCP config,
SCIP index JSON, Markdown) into the same {nodes, edges} shape the rest of the
code_graph runtime consumes. It NEVER decides provider/model/domain/policy and
is NOT promoted to production until human review (runtime_promotion_policy.v1).

Scope (deliberately the stdlib-only subset of P-11):
  - ingest_mcp_config(obj)  : .mcpServers -> server/command/package/env_var nodes
                              + requires_env edges. Reads env *names* only — the
                              VALUES of env vars are never read or emitted.
  - ingest_scip_json(obj)   : documents[].symbols -> symbol nodes + scip_ref /
                              scip_def / scip_impl edges, emitted ONLY when the
                              relationship marker is a real boolean ``True``
                              (truthy strings like "true"/"yes"/1 are rejected,
                              so a non-boolean SCIP export cannot fabricate a
                              relationship).
  - ingest_markdown(text, p): ATX headings (# .. ######) -> doc/heading nodes +
                              contains edges modelling the heading hierarchy.

PDF / image / video ingestion is intentionally NOT implemented here: those
require third-party dependencies (pypdf, pillow/ocr, ffmpeg/whisper) that are
gated behind dependency approval in AP-812's promotion review. Calling the
helpers below for those formats is out of scope — the upstream caller routes
them elsewhere.

Edge shape matches CodeGraphEdgeResolver.resolve(...) exactly:
  {from_node_id, to_node_id, edge_type, confidence, confidence_score, metadata}
Confidence labels mirror the resolver: EXTRACTED / INFERRED / AMBIGUOUS.

Everything here is pure stdlib and fully deterministic: same input -> identical
output (nodes/edges are de-duplicated by id and returned in sorted order).
"""

from __future__ import annotations

import re
from collections import OrderedDict

SCHEMA = "atlas.code_graph.ingest_lite.v1"

# Confidence labels — identical strings to CodeGraphEdgeResolver so downstream
# consumers (insights.py etc.) treat these edges consistently.
CONFIDENCE_EXTRACTED = "EXTRACTED"
CONFIDENCE_INFERRED = "INFERRED"

_SCORE_EXTRACTED = 1.0
_SCORE_INFERRED = 0.85

# Node id namespaces. Kept distinct so ids never collide across artifact kinds.
_NS_SERVER = "mcp:server"
_NS_COMMAND = "mcp:command"
_NS_PACKAGE = "mcp:package"
_NS_ENV = "mcp:env"
_NS_SCIP_SYMBOL = "scip:symbol"
_NS_SCIP_DOC = "scip:doc"
_NS_DOC = "doc"
_NS_HEADING = "doc:heading"

# SCIP relationship flag -> edge_type. Only these three are recognised, and only
# when the flag is a real boolean True (see _is_true_flag).
_SCIP_REL_EDGE = OrderedDict(
    [
        ("is_reference", "scip_ref"),
        ("is_definition", "scip_def"),
        ("is_implementation", "scip_impl"),
    ]
)

# ATX heading: 1-6 '#', a required space, then the text. Closing '#'s stripped.
_HEADING_RE = re.compile(r"^(#{1,6})\s+(.*?)\s*#*\s*$")


def _clean(value):
    """Return a trimmed non-empty string, or None for anything else."""
    if not isinstance(value, str):
        return None
    trimmed = value.strip()
    return trimmed or None


def _node(node_id, node_type, label, metadata=None):
    node = {
        "node_id": node_id,
        "node_type": node_type,
        "label": label,
    }
    if metadata:
        node["metadata"] = metadata
    return node


def _edge(from_id, to_id, edge_type, confidence, score, metadata=None):
    meta = {"ingester": SCHEMA}
    if metadata:
        meta.update(metadata)
    return {
        "from_node_id": from_id,
        "to_node_id": to_id,
        "edge_type": edge_type,
        "confidence": confidence,
        "confidence_score": score,
        "metadata": meta,
    }


class _Accumulator:
    """Collects nodes (deduped by id, first label wins) and edges (deduped by
    from|to|type) and emits them in deterministic sorted order."""

    def __init__(self):
        self._nodes = OrderedDict()
        self._edges = OrderedDict()

    def add_node(self, node):
        nid = node["node_id"]
        if nid not in self._nodes:
            self._nodes[nid] = node

    def add_edge(self, edge):
        key = (edge["from_node_id"], edge["to_node_id"], edge["edge_type"])
        if key not in self._edges:
            self._edges[key] = edge

    def result(self):
        nodes = sorted(
            self._nodes.values(),
            key=lambda n: (n["node_type"], n["node_id"]),
        )
        edges = sorted(
            self._edges.values(),
            key=lambda e: (e["from_node_id"], e["to_node_id"], e["edge_type"]),
        )
        return {"schema_version": SCHEMA, "nodes": nodes, "edges": edges}


# --------------------------------------------------------------------------- #
# MCP config
# --------------------------------------------------------------------------- #
def ingest_mcp_config(obj):
    """Ingest a parsed MCP config object (``{"mcpServers": {...}}``).

    For each server entry we emit:
      - a server node,
      - a command node (the launch binary, e.g. "npx"/"python3") + a runs edge,
      - a package node when ``args`` references an obvious package spec + a
        launches edge,
      - one env_var node per declared environment variable + a requires_env edge.

    SECURITY: only env-var *names* (the keys of the ``env`` map) are ever read or
    emitted. The values are never accessed, never stored, never put in metadata —
    secrets in an MCP config cannot leak through this graph.

    Returns {schema_version, nodes, edges}. Unknown / malformed shapes degrade
    to an empty result rather than raising.
    """
    acc = _Accumulator()
    if not isinstance(obj, dict):
        return acc.result()

    servers = obj.get("mcpServers")
    if not isinstance(servers, dict):
        return acc.result()

    for raw_name, raw_spec in servers.items():
        name = _clean(raw_name)
        if name is None or not isinstance(raw_spec, dict):
            continue

        server_id = f"{_NS_SERVER}:{name}"
        acc.add_node(_node(server_id, "mcp_server", name))

        command = _clean(raw_spec.get("command"))
        if command is not None:
            command_id = f"{_NS_COMMAND}:{command}"
            acc.add_node(_node(command_id, "mcp_command", command))
            acc.add_edge(
                _edge(
                    server_id,
                    command_id,
                    "runs",
                    CONFIDENCE_EXTRACTED,
                    _SCORE_EXTRACTED,
                    {"relation": "command"},
                )
            )

        package = _package_from_args(raw_spec.get("args"))
        if package is not None:
            package_id = f"{_NS_PACKAGE}:{package}"
            acc.add_node(_node(package_id, "mcp_package", package))
            acc.add_edge(
                _edge(
                    server_id,
                    package_id,
                    "launches",
                    CONFIDENCE_INFERRED,
                    _SCORE_INFERRED,
                    {"relation": "package_arg"},
                )
            )

        env = raw_spec.get("env")
        if isinstance(env, dict):
            # Sort the names so output order is stable regardless of dict order.
            for raw_var in sorted(env.keys(), key=lambda k: str(k)):
                var = _clean(raw_var)
                if var is None:
                    continue
                env_id = f"{_NS_ENV}:{var}"
                # NOTE: we pass ONLY the name as the label. env[raw_var] (the
                # value) is deliberately never touched.
                acc.add_node(_node(env_id, "env_var", var))
                acc.add_edge(
                    _edge(
                        server_id,
                        env_id,
                        "requires_env",
                        CONFIDENCE_EXTRACTED,
                        _SCORE_EXTRACTED,
                        {"relation": "env_var", "var_name": var},
                    )
                )

    return acc.result()


def _package_from_args(args):
    """Best-effort extraction of a single package spec from a command's args.

    Heuristic, deterministic: the first arg that looks like a package (not a
    flag, not a path) wins. ``-y``/``--yes`` and similar flags are skipped. This
    is INFERRED, never EXTRACTED, precisely because it is a guess.
    """
    if not isinstance(args, list):
        return None
    for raw in args:
        token = _clean(raw)
        if token is None:
            continue
        if token.startswith("-"):
            continue
        # Skip obvious filesystem paths / scripts — those aren't a package spec.
        if token.startswith((".", "/", "~")) or token.endswith((".js", ".py", ".mjs")):
            continue
        return token
    return None


# --------------------------------------------------------------------------- #
# SCIP JSON
# --------------------------------------------------------------------------- #
def _is_true_flag(value):
    """True ONLY for a real boolean ``True``.

    The whole point of P-11's SCIP rule: a relationship edge is emitted only
    when the marker is an actual boolean true. Truthy strings ("true", "yes"),
    ints (1), or any other type must NOT be enough to fabricate a relationship,
    because a sloppy/hostile SCIP export could otherwise inject edges.
    """
    return value is True


def ingest_scip_json(obj):
    """Ingest a parsed SCIP index object (``{"documents": [{...}]}``).

    Each ``documents[].symbols[]`` entry becomes a symbol node. Relationship
    edges (scip_ref / scip_def / scip_impl) are emitted from the document's
    "anchor" symbol (the first definition in the doc, else the first symbol) to
    each related symbol — but ONLY when the relationship marker is a boolean
    ``True``. The document itself becomes a doc node with a defines edge to each
    symbol it declares.

    A symbol entry shape (stdlib subset):
      {"symbol": "scip-... Foo#", "relationships": [
          {"symbol": "scip-... Bar#", "is_reference": true},
          {"symbol": "scip-... Baz#", "is_definition": true},
          {"symbol": "scip-... Qux#", "is_implementation": true}]}

    Returns {schema_version, nodes, edges}; degrades to empty on bad shapes.
    """
    acc = _Accumulator()
    if not isinstance(obj, dict):
        return acc.result()

    documents = obj.get("documents")
    if not isinstance(documents, list):
        return acc.result()

    for document in documents:
        if not isinstance(document, dict):
            continue
        _ingest_scip_document(acc, document)

    return acc.result()


def _ingest_scip_document(acc, document):
    symbols = document.get("symbols")
    if not isinstance(symbols, list):
        return

    doc_path = _clean(document.get("relative_path")) or _clean(document.get("path"))
    doc_id = f"{_NS_SCIP_DOC}:{doc_path}" if doc_path else None
    if doc_id is not None:
        acc.add_node(_node(doc_id, "scip_document", doc_path))

    # First pass: register every symbol node and remember the document anchor.
    anchor_id = None
    for entry in symbols:
        if not isinstance(entry, dict):
            continue
        sym = _clean(entry.get("symbol"))
        if sym is None:
            continue
        sym_id = f"{_NS_SCIP_SYMBOL}:{sym}"
        acc.add_node(_node(sym_id, "scip_symbol", sym, {"symbol": sym}))
        if doc_id is not None:
            acc.add_edge(
                _edge(
                    doc_id,
                    sym_id,
                    "defines",
                    CONFIDENCE_EXTRACTED,
                    _SCORE_EXTRACTED,
                    {"relation": "scip_document_symbol"},
                )
            )
        # Anchor = first symbol flagged as a definition, else first symbol seen.
        if anchor_id is None:
            anchor_id = sym_id
        if _symbol_is_definition(entry) and _anchor_pref(anchor_id, sym_id):
            anchor_id = sym_id

    # Second pass: relationship edges, boolean-true gated.
    for entry in symbols:
        if not isinstance(entry, dict):
            continue
        sym = _clean(entry.get("symbol"))
        if sym is None:
            continue
        from_id = f"{_NS_SCIP_SYMBOL}:{sym}"
        relationships = entry.get("relationships")
        if not isinstance(relationships, list):
            continue
        for rel in relationships:
            if not isinstance(rel, dict):
                continue
            target = _clean(rel.get("symbol"))
            if target is None:
                continue
            to_id = f"{_NS_SCIP_SYMBOL}:{target}"
            # Ensure the related symbol exists as a node even if it wasn't in
            # this doc's symbol list.
            acc.add_node(_node(to_id, "scip_symbol", target, {"symbol": target}))
            for flag, edge_type in _SCIP_REL_EDGE.items():
                if _is_true_flag(rel.get(flag)):
                    acc.add_edge(
                        _edge(
                            from_id,
                            to_id,
                            edge_type,
                            CONFIDENCE_EXTRACTED,
                            _SCORE_EXTRACTED,
                            {"relation": flag},
                        )
                    )


def _symbol_is_definition(entry):
    """A symbol counts as a definition anchor when it carries definition
    metadata that is a boolean true (kind/role markers vary by exporter)."""
    for key in ("is_definition", "definition", "has_definition"):
        if _is_true_flag(entry.get(key)):
            return True
    return False


def _anchor_pref(current, candidate):
    """Deterministic anchor preference: only upgrade to the lexicographically
    smaller id so the chosen anchor is stable regardless of symbol order."""
    return candidate < current


# --------------------------------------------------------------------------- #
# Markdown
# --------------------------------------------------------------------------- #
def ingest_markdown(text, path):
    """Ingest a Markdown document's heading structure.

    A doc node is created for the file. Every ATX heading (``#`` .. ``######``)
    becomes a heading node, with a ``contains`` edge modelling the hierarchy:
    each heading is contained by its nearest shallower ancestor (top-level
    headings are contained by the doc node itself). Heading ids are made unique
    per-document by an occurrence index so repeated heading text doesn't collide.

    Fenced code blocks (``` ``` ``` / ``~~~``) are skipped so a ``#`` *inside*
    code is not mistaken for a heading.

    Returns {schema_version, nodes, edges}. Empty text -> just the doc node (or
    nothing if the path is unusable).
    """
    acc = _Accumulator()
    doc_path = _clean(path)
    if doc_path is None:
        return acc.result()

    doc_id = f"{_NS_DOC}:{doc_path}"
    acc.add_node(_node(doc_id, "doc", doc_path, {"path": doc_path}))

    if not isinstance(text, str) or not text:
        return acc.result()

    # Ancestor stack of (level, node_id); doc is the level-0 root.
    stack = [(0, doc_id)]
    seen_slugs = {}
    fence = None  # active code-fence marker, or None

    for raw_line in text.splitlines():
        stripped = raw_line.strip()

        # Toggle fenced code blocks; ignore everything inside them.
        fence_marker = _fence_marker(stripped)
        if fence is not None:
            if fence_marker == fence:
                fence = None
            continue
        if fence_marker is not None:
            fence = fence_marker
            continue

        match = _HEADING_RE.match(raw_line)
        if match is None:
            continue
        level = len(match.group(1))
        title = match.group(1) and match.group(2).strip()
        if not title:
            continue

        slug = _slugify(title)
        index = seen_slugs.get(slug, 0)
        seen_slugs[slug] = index + 1
        suffix = "" if index == 0 else f"-{index}"
        heading_id = f"{_NS_HEADING}:{doc_path}#{slug}{suffix}"

        acc.add_node(
            _node(
                heading_id,
                "doc_heading",
                title,
                {"path": doc_path, "level": level},
            )
        )

        # Pop ancestors that are at the same or deeper level than this heading.
        while len(stack) > 1 and stack[-1][0] >= level:
            stack.pop()
        parent_id = stack[-1][1]
        acc.add_edge(
            _edge(
                parent_id,
                heading_id,
                "contains",
                CONFIDENCE_EXTRACTED,
                _SCORE_EXTRACTED,
                {"relation": "heading", "level": level},
            )
        )
        stack.append((level, heading_id))

    return acc.result()


def _fence_marker(stripped_line):
    """Return the normalised fence marker ('```' or '~~~') if the line opens or
    closes a code fence, else None."""
    for marker in ("```", "~~~"):
        if stripped_line.startswith(marker):
            return marker
    return None


def _slugify(title):
    """Lowercase, hyphenated, ascii-word slug. Deterministic and dependency-free."""
    slug = re.sub(r"[^a-z0-9]+", "-", title.lower()).strip("-")
    return slug or "section"
