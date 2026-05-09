---
id: graphify-module-inventory
type: engineering_knowledge
title: "Graphify Module Inventory"
description: "Inventario dos modulos upstream do Graphify e decisao de colheita para Atlas."
category: "code-intelligence"
status: "source_material"
owner: "architecture"
last_verified: "2026-05-09"
related_paths:
  - "docs/engineering-knowledge-base/archive/source-material/code-intelligence/graphify/README.md"
  - "docs/engineering-knowledge-base/archive/source-material/code-intelligence/graphify/atlas-harvest-map.md"
---

# Graphify Module Inventory

This inventory summarizes what each upstream module does and what Atlas should
do with it.

The line counts are from the audited upstream copy at commit
`adf96da28379b17daf6481294ef7f6e0359481b2`.

## Scale Snapshot

Graphify has about `15,706` Python lines in the audited package. The largest
modules are:

- `extract.py`: `5,047` lines.
- `__main__.py`: `2,500` lines.
- `export.py`: `1,263` lines.
- `detect.py`: `877` lines.
- `llm.py`: `853` lines.
- `serve.py`: `581` lines.
- `tree_html.py`: `580` lines.
- `analyze.py`: `573` lines.

Atlas implication: Graphify is useful, but it is not a small drop-in library.
The professional path is selective harvest, not wholesale import.

## Core Modules

| Module | Role | Atlas Harvest Decision |
| --- | --- | --- |
| `detect.py` | Classifies files, filters ignored/sensitive paths, converts office/PDF-like material, maintains manifests. | Adapt the classification and manifest ideas. Reimplement with Atlas source policy and privacy gates. |
| `extract.py` | Main static extraction engine across many languages using Tree-sitter and custom parsing. | Highest value source. Harvest language coverage, AST patterns, import/call extraction, and cross-file resolver ideas. Do not import wholesale without tests. |
| `build.py` | Builds NetworkX graph from JSON-like extraction objects, merges duplicate nodes, prunes/prefixes for global graphs. | Harvest graph-building contracts and merge semantics. Map to Atlas graph schema, not raw Graphify schema. |
| `cluster.py` | Runs Leiden/fallback clustering and community scoring. | Harvest clustering/gating ideas. Atlas should treat clusters as analysis, not memory truth. |
| `analyze.py` | Computes god nodes, surprising connections, suggested questions, graph diffs. | Very high value for Curator, Constelacao, and Code Intelligence proposals. Reimplement as Atlas analysis services. |
| `report.py` | Creates markdown reports from graph analysis. | Harvest report shape. Atlas docs/reports need Evidence refs and AP links. |
| `export.py` | Exports graph to HTML, Obsidian, Wiki, Neo4j, GraphML, SVG. | Use as reference. Only adopt exports behind explicit user command and privacy checks. |

## Agent And LLM Modules

| Module | Role | Atlas Harvest Decision |
| --- | --- | --- |
| `llm.py` | Provider abstraction for semantic extraction, including direct Claude, Bedrock, OpenAI-compatible, Ollama, chunking, retries, token estimates. | Strong design material. Atlas must route every provider call through Atlas Decide/AP-99/provider policy. No direct reuse in production path. |
| `serve.py` | MCP server exposing graph query tools. | Good pattern for agent-facing graph tools. Atlas should create native MCP/API tools with auth, receipts, and source refs. |
| `skills.py` | Skill/integration helpers for coding agents. | Reference only. Atlas should not auto-install agent hooks from upstream. |

## Corpus And Media Modules

| Module | Role | Atlas Harvest Decision |
| --- | --- | --- |
| `ingest.py` | Fetches external web/arxiv/tweet/binary-like sources into local corpus. | Reference only until Provider Release Intelligence/source allowlist governance is complete. Must avoid SSRF/private leakage. |
| `transcribe.py` | Uses faster-whisper/yt-dlp style flow for audio/video transcription and graph insertion. | Useful future pattern for Continuous Multimodal Context. Keep out of P0 unless privacy and media consent gates exist. |
| `google_workspace.py` | Google Workspace ingestion helpers. | Reference only. Atlas needs explicit connector auth and Human Knowledge Surface rules. |
| `wiki.py` | Wiki-style export/navigation. | Low-risk reference for rendering. Not core. |
| `tree_html.py` | HTML tree visualization. | Useful UI inspiration. Not core Kernel material. |

## Operations Modules

| Module | Role | Atlas Harvest Decision |
| --- | --- | --- |
| `__main__.py` | Large CLI command router for extract, query, update, hooks, exports, integrations, benchmark, global graph. | Do not copy the CLI. Harvest command taxonomy and split into Atlas AP-owned commands. |
| `cache.py` | Content hash, AST cache, semantic cache. | High value. Adapt to Atlas fingerprints and Evidence Ledger provenance. |
| `security.py` | URL validation, SSRF guard, path validation, sanitization. | High value as a checklist. Atlas should maintain stricter policy because Atlas has private workspace context. |
| `benchmark.py` | Compares raw token load versus graph-based context. | High value for AP-99 style evaluation. Needs Atlas benchmark harness. |
| `global_graph.py` | Local global graph add/remove/list/path. | Avoid direct adoption. Atlas global graph must be governed memory, not arbitrary local merge. |
| `hooks.py` | Installs/uninstalls git hooks. | Do not adopt by default. Hooks are too invasive for Atlas Kernel unless explicitly AP-owned. |
| `watch.py` | Watches repo and updates graph on file changes. | Good future idea. Must be governed and throttled. No uncontrolled background watcher in P0. |
| `validate.py` | Extraction schema validation. | Useful pattern. Atlas should create strict validators for any graph evidence schema. |
| `dedup.py` | Deduplication helpers. | Useful pattern, but Atlas needs deterministic source-aware dedupe. |
| `manifest.py` | Very small manifest helper. | Low value as code; useful as concept. |

## Extraction Coverage

The audited `extract.py` contains extractors or extractor helpers for:

- Python.
- JavaScript and TypeScript.
- Svelte.
- Java.
- Groovy.
- C and C++.
- Ruby.
- C#.
- Kotlin.
- Scala.
- PHP.
- Blade.
- Dart.
- Verilog.
- SQL.
- Lua.
- Swift.
- Julia.
- Fortran.
- Go.
- Rust.
- Zig.
- PowerShell.
- Objective-C.
- Elixir.
- Markdown.

Atlas value:

- wide language coverage for Programming domain.
- immediate reference for Code Intelligence.
- potential cross-language architecture maps.

Atlas caution:

- breadth is not the same as correctness.
- each extractor adopted into Atlas needs fixtures.
- Laravel/PHP/Blade should be prioritized because Atlas server is Laravel/PHP.
- Swift should be prioritized only for mobile app context when the mobile work is
  active.

## LLM Extraction Details

The audited `llm.py` includes:

- backend resolution.
- OpenAI-compatible calls.
- direct Claude calls.
- Bedrock calls.
- Ollama URL validation.
- token estimation.
- chunking by token budget and parent directory.
- bounded concurrency.
- adaptive split/retry when a chunk is truncated.
- extraction system prompt for structured graph facts.

Atlas interpretation:

- the chunking/retry idea is excellent.
- the direct-provider behavior must not be reused directly.
- provider choice, budget, and source scope belong to Atlas Decide.
- output must become evidence with provider/model/source metadata.

## Analyze Details

The audited `analyze.py` provides the most Atlas-like behaviors:

- god node detection.
- surprising connection discovery.
- cross-community bridge detection.
- suggested questions for ambiguous or weak edges.
- graph diff.

Atlas interpretation:

- these map naturally to Curator proposals.
- they can help the Constelacao surface find meaningful bridges.
- they can help docs hygiene discover orphaned architecture fragments.
- every finding needs source refs and confidence labels.

## MCP Details

The audited `serve.py` exposes graph tools that let agents ask:

- what matches a query.
- what a node is.
- what neighbors a node has.
- what community a node belongs to.
- what the important nodes are.
- what graph stats look like.
- what path connects two nodes.

Atlas interpretation:

- this is almost exactly the agent-facing interface Atlas needs.
- but it must be recreated under Atlas auth/policy/evidence.
- a tool result should never silently become memory.

## Enterprise Conclusion

The best parts of Graphify for Atlas are:

- extractor breadth.
- graph compression.
- cluster/bridge/question analysis.
- cache/fingerprint discipline.
- MCP query model.
- token benchmark framing.
- security checklist.

The riskiest parts are:

- direct LLM/provider calls.
- uncontrolled git hooks/watchers.
- raw graph commits.
- global graph merge without Atlas memory governance.
- false semantic inference treated as truth.
