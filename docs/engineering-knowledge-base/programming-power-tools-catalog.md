---
id: atlas-programming-power-tools-catalog
type: engineering_knowledge
title: Atlas Programming Power Tools Catalog
status: active
category: tool_runtime
priority: 97
summary: Compact product index for programming power tools, authority groups, tiers, external agent boundaries and implementation backlog.
tags:
  - atlas
  - tools
  - programming
  - quality
  - security
capabilities:
  - programming_power_tools_catalog
  - tool_authority_matrix
  - t0_t3_execution_policy
  - anti_duplication_policy
  - external_agent_boundary
  - local_open_source_first
decisions:
  - Atlas is the control plane; external tools are sensors, validators or governed executors.
  - Paid/cloud tools are never mandatory core dependencies.
  - Tool overlap is allowed only with declared authority groups.
  - Detailed family catalog and backlog live in focused child docs.
maintenance:
  - Run `atlas tools authority --json` after changing tiers or authority.
  - Run docs sync and code index after changing this doc.
related_paths:
  - docs/engineering-knowledge-base/tool-runtime/programming-tool-families.md
  - docs/engineering-knowledge-base/tool-runtime/programming-tool-backlog.md
  - docs/engineering-knowledge-base/archive/source-material/tool-runtime/programming-power-tools-catalog-full-2026-05-08.md
  - docs/engineering-knowledge-base/super-tool-runtime-core.md
  - docs/engineering-knowledge-base/tool-runtime/README.md
  - docs/engineering-knowledge-base/tool-runtime/contracts.md
  - app/Services/Tools/AtlasToolDefinitionCatalog.php
  - app/Services/Tools/AtlasToolAuthorityMatrixService.php
doc_schema: atlas_canonical_module_doc.v1

graph_id: atlas-programming-power-tools-catalog

graph_title: Atlas Programming Power Tools Catalog

graph_world: atlas

graph_layer: module

graph_kind: module

graph_parent: atlas-ai-canonical-architecture-index

graph_status: active

graph_source: repo
human_name: Atlas Programming Power Tools Catalog
canonical_name: Atlas Programming Power Tools Catalog
technical_name: atlas-programming-power-tools-catalog
cartography_type: module
canonical_source: docs/engineering-knowledge-base/programming-power-tools-catalog.md

owner: tool-runtime

repo_paths:
  - docs/engineering-knowledge-base/programming-power-tools-catalog.md

allowed_changes:
  - Atualizar este doc quando codigo, arquitetura, fluxo, evidencia ou decisao canonica mudar.

forbidden_changes:
  - Declarar runtime, maturidade ou prontidao sem evidencia verificavel e gates verdes.

depends_on:
  - atlas-ai-documentation-operating-system

flows_to:
  - atlas-cartography
  - atlas-code

unlocks:
  - ai-safe-implementation-context

governs:
  - tool-runtime

evidence:
  - docs/engineering-knowledge-base/programming-power-tools-catalog.md

evidence_refs:
  - symbol: AtlasToolDefinitionCatalog
required_tests:
  - "php artisan atlas:engineering:knowledge docs-health --json"

requires_evidence: true

risk_level: high

visual_tags:
  - module
  - module
  - tool-runtime

ai_entrypoints:
  - Leia Resumo, Contratos, Regras para IA, Evidencias e Riscos antes de implementar.

ai_usage_notes:
  - Use repo_paths, allowed_changes, forbidden_changes e required_tests como limites operacionais.

quality_gates:
  - "php artisan atlas:engineering:knowledge docs-health --json"

failure_modes:
  - Contexto desatualizado entre doc, codigo, teste e evidencia.

observability_signals:
  - docs-health status ok

next_actions:
  - Manter este doc sincronizado com codigo, testes, evidencias e Cartografia.
---
# Atlas Programming Power Tools Catalog

This is the active compact index for programming power tools. The full original
catalog is preserved at
`archive/source-material/tool-runtime/programming-power-tools-catalog-full-2026-05-08.md`.

## Current State

The Super Tool Runtime already provides registry, policy engine, executor,
generic and specific normalizers, Evidence Store, approvals, waivers, evidence
gates, release gates, command recipes, filters, authority matrix and Engineering
app panel.

The first P0 package includes Gitleaks, Semgrep, OSV-Scanner, Syft, Trivy,
PHPStan, TypeScript, ESLint, Laravel Pint, Biome, Hadolint and Checkov.

## Tiers

| Tier | Intent | Examples | Rule |
|---|---|---|---|
| T0 | Instant context/edit feedback | ripgrep, Code Intelligence, Tree-sitter, ast-grep | Interactive, local, no automatic write. |
| T1 | Fast local feedback | typecheck, lint, PHPStan, Gitleaks, Knip | Save/pre-commit/light quality. |
| T2 | Medium review | CodeQL, Semgrep, Schemathesis, visual smoke, axe | PR/task gate with timeout and evidence. |
| T3 | Heavy release/audit | Infection, Stryker, Lighthouse full, SBOM/release scans | Nightly/release/deep only. |

## Authority Rule

No gate runs tools directly. Gates evaluate persisted evidence filtered by
workspace, surface, context, tool, recipe and tier.

## Anti-Duplication Examples

| Group | Primary | Complement |
|---|---|---|
| `semantic_sast` | CodeQL | Semgrep |
| `ts_js_architecture` | dependency-cruiser | Madge |
| `php_static_analysis` | PHPStan | Psalm |
| `vulnerability_scan` | Trivy | Grype |
| `sbom` | Syft | n/a |
| `accessibility` | axe-core | Pa11y |

Full family map: `tool-runtime/programming-tool-families.md`.

## External Agents

Aider, Continue, OpenHands, Claude Code, Codex CLI, Cursor and future agents may
execute under Atlas policy, approval, evidence and gates. They do not own
context, memory, authority or quality.

## Implementation Backlog

See `tool-runtime/programming-tool-backlog.md`.

## Product Final Criteria

- The tool is registered.
- The recipe is safe and dry-run capable where relevant.
- Results are normalized.
- Evidence is persisted.
- Gates consume evidence by authority group.
- App/CLI exposes findings and repair hints.
- Self-Improvement can detect stale or missing tooling.

## Resumo

Compact product index for programming power tools, authority groups, tiers, external agent boundaries and implementation backlog.

## Papel no Atlas

Define a responsabilidade desta peca dentro da arquitetura Atlas.

## Onde Se Encaixa

Relaciona esta peca com seu sistema, camada, fluxo ou modulo pai.

## Contratos

Declara invariantes, entradas, saidas, limites e obrigacoes relevantes.

## Fluxo

Descreve o caminho operacional ou a sequencia de uso quando aplicavel.

## Regras para IA

Agentes devem respeitar escopo, evidencias, testes e proibicoes antes de alterar codigo.

## Escopo de Implementacao

Mudancas devem permanecer nos caminhos e limites declarados no frontmatter.

## Dependencias

Dependencias canonicas vivem em frontmatter e no corpo deste documento.

## Evidencias

Evidencias aceitas incluem docs, comandos, testes, receipts, reports e paths verificaveis.

## Riscos

Riscos principais devem ser tratados antes de promover status, runtime ou claims de prontidao.

## Exemplos

Exemplos concretos devem ser adicionados quando reduzirem ambiguidade para humanos ou IAs.

## Proximas Acoes

Proximas acoes devem ser concretas, verificaveis e ligadas a gates de qualidade.
