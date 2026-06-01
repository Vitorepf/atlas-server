---
id: atlas-programming-tool-families
type: engineering_knowledge
title: Atlas Programming Tool Families
status: active
category: tool_runtime
priority: 86
summary: Focused family map for programming tools governed by the Atlas Super Tool Runtime.
tags:
  - atlas
  - tools
  - programming
capabilities:
  - programming_power_tools_catalog
  - tool_authority_matrix
decisions:
  - Tool families define authority and complementarity; they do not bypass the registry.
maintenance:
  - Update when a tool family gains or loses primary authority.
related_paths:
  - docs/engineering-knowledge-base/programming-power-tools-catalog.md
  - docs/engineering-knowledge-base/tool-runtime/contracts.md
doc_schema: atlas_canonical_module_doc.v1

graph_id: atlas-programming-tool-families

graph_title: Atlas Programming Tool Families

graph_world: atlas

graph_layer: module

graph_kind: module

graph_parent: atlas-ai-canonical-architecture-index

graph_status: active

graph_source: repo
human_name: Atlas Programming Tool Families
canonical_name: Atlas Programming Tool Families
technical_name: atlas-programming-tool-families
cartography_type: module
canonical_source: docs/engineering-knowledge-base/tool-runtime/programming-tool-families.md

owner: tool-runtime

repo_paths:
  - docs/engineering-knowledge-base/tool-runtime/programming-tool-families.md

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
  - docs/engineering-knowledge-base/tool-runtime/programming-tool-families.md
evidence_refs:
  - symbol: AtlasProgrammingToolFamiliesService
  - command: atlas:aaeos:programming-tool-families
  - test: AtlasProgrammingToolFamiliesTest

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
# Atlas Programming Tool Families

## Families

| Family | Representative tools |
|---|---|
| Code and context | `atlas_code_intelligence`, `ripgrep`, `tree_sitter`, `ast_grep`, `serena`, `universal_ctags` |
| PHP quality/types/refactor | `composer`, `laravel_pint`, `phpstan`, `psalm`, `phpmd`, `phpcpd`, `composer_require_checker`, `composer_unused`, `rector` |
| TypeScript/frontend/dead code | `typescript`, `eslint`, `biome`, `knip`, `ts_prune` |
| Security/supply chain/licenses | `gitleaks`, `semgrep`, `codeql`, `osv_scanner`, `trivy`, `grype`, `syft`, license scanners |
| Containers/IaC/Kubernetes | `hadolint`, `checkov`, `trivy`, Kubernetes scanners |
| APIs/contracts/mocking | OpenAPI validators, Schemathesis, contract test tools |
| Visual/accessibility/performance | visual smoke, Playwright, axe-core, Lighthouse, Pa11y |
| Architecture | dependency-cruiser, Madge, Deptrac |
| External agents | Aider, Continue, OpenHands, Claude Code, Codex CLI, Cursor |

## Authority Pattern

Each family must declare:

- primary tool;
- complementary/fallback tools;
- output normalizer;
- evidence shape;
- gate thresholds;
- duplicate suppression rule.

## Registry Rule

This doc is not the registry. Runtime truth lives in `atlas_tool_definitions` and
the Tool Runtime services.

## Resumo

Focused family map for programming tools governed by the Atlas Super Tool Runtime.

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
