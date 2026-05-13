---
id: atlas-tool-runtime-catalog-roadmap
type: engineering_knowledge
title: Atlas Tool Runtime Catalog Roadmap
status: active
category: tool_runtime
priority: 97
summary: Tool families Atlas should govern for heavy programming, security, frontend, API, architecture, testing and external agents.
tags:
  - atlas
  - tools
  - programming
  - roadmap
capabilities:
  - programming_power_tools_catalog
  - tool_registry
decisions:
  - Tool backlog is optional/local/open-source first when possible.
  - Tool families enter as governed capabilities, not parallel flows.
maintenance:
  - Promote a tool only with recipe, policy and evidence semantics.
related_paths:
  - docs/engineering-knowledge-base/super-tool-runtime-core.md
  - docs/engineering-knowledge-base/programming-power-tools-catalog.md
  - docs/engineering-knowledge-base/tool-runtime/contracts.md
doc_schema: atlas_canonical_module_doc.v1

graph_id: atlas-tool-runtime-catalog-roadmap

graph_title: Atlas Tool Runtime Catalog Roadmap

graph_world: atlas

graph_layer: module

graph_kind: module

graph_parent: atlas-ai-canonical-architecture-index

graph_status: active

graph_source: repo

owner: tool-runtime

repo_paths:
  - docs/engineering-knowledge-base/tool-runtime/catalog-roadmap.md

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
  - docs/engineering-knowledge-base/tool-runtime/catalog-roadmap.md

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
# Atlas Tool Runtime Catalog Roadmap

## P0 Code Semantics

- Serena/LSP/MCP for symbols, references and semantic edits;
- Tree-sitter graph for local AST/code graph;
- ast-grep for structural search/refactor;
- universal-ctags for lightweight symbol index;
- IDE bridges for VS Code/JetBrains when useful.

Reads are cheap. Writes require sandbox/approval and evidence.

## P0 Quality And Static Analysis

- PHPStan/Psalm/Psalm taint;
- Pint/Biome/Prettier;
- ESLint/TypeScript;
- Rector;
- PHPMD/PHPCPD;
- Composer Require Checker/Unused;
- Knip/ts-prune.

## P0 Security And Supply Chain

- Gitleaks;
- Semgrep;
- CodeQL;
- OSV-Scanner;
- Trivy/Grype;
- Syft;
- Checkov/Terrascan;
- kube-linter/kube-score;
- Dockle;
- ScanCode/ORT/licensee.

## P1 API, Testing, Frontend And Architecture

- Schemathesis, Pact, Prism, WireMock, Bruno;
- Infection/Stryker, fast-check/Hypothesis, coverage tools;
- axe-core, Pa11y, Lighthouse CI, bundle analyzers, pixelmatch;
- Deptrac, dependency-cruiser, Madge;
- OpenRewrite, comby, jscodeshift, ts-morph.

## P2 External Agents

- Aider;
- Continue;
- OpenHands;
- Serena/MCP write operations.

Agents must run behind Atlas context, policy, worktree/sandbox, tests, gates,
Evidence Store and operator approval where needed.

## Promotion Rule

A cataloged tool becomes operational only after:

- registry definition;
- doctor detection state;
- safe recipe when executable;
- normalizer or explicit generic fallback;
- authority group role;
- gate behavior;
- tests with fake binary or fixture output.

## Resumo

Tool families Atlas should govern for heavy programming, security, frontend, API, architecture, testing and external agents.

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
