---
id: atlas-ai-cyber-recipes-external-mcp
type: engineering_knowledge
title: Atlas AI Cyber External MCP Tooling
status: building
category: knowledge-base
priority: 80
implementation_state: cyber_security_scaffold_not_runtime_promoted
summary: Governance for integrating offensive external MCP tooling through Atlas wrappers instead of raw provider configuration.
tags:
  - atlas-ai
  - cyber-security
  - mcp
capabilities:
  - cyber_recipes_proposal
decisions:
  - External offensive MCP servers are never connected directly to Atlas Decide.
  - A wrapper skill must enforce Decision Receipt, scope proof, refusal matrix, sandbox and evidence.
maintenance:
  - Add a section for each external MCP candidate after security review.
related_paths:
  - docs/engineering-knowledge-base/cyber-security/recipes-catalog.md
  - docs/engineering-knowledge-base/cyber-security/refusal-matrix.md
  - docs/engineering-knowledge-base/archive/source-material/cyber-security/recipes-catalog-full-2026-05-08.md
doc_schema: atlas_canonical_module_doc.v1

graph_id: atlas-ai-cyber-recipes-external-mcp

graph_title: Atlas AI Cyber External MCP Tooling

graph_world: atlas

graph_layer: module

graph_kind: module

graph_parent: atlas-ai-canonical-architecture-index

graph_status: building

graph_source: repo
human_name: Atlas AI Cyber External MCP Tooling
canonical_name: Atlas AI Cyber External MCP Tooling
technical_name: atlas-ai-cyber-recipes-external-mcp
cartography_type: module
canonical_source: docs/engineering-knowledge-base/cyber-security/recipes-external-mcp.md

owner: cyber-security

repo_paths:
  - docs/engineering-knowledge-base/cyber-security/recipes-external-mcp.md

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
  - cyber-security

evidence:
  - docs/engineering-knowledge-base/cyber-security/recipes-external-mcp.md
evidence_refs:
  - symbol: AtlasRecipesExternalMcpService
  - command: atlas:aaeos:recipes-external-mcp
  - test: AtlasRecipesExternalMcpTest

required_tests:
  - "php artisan atlas:engineering:knowledge docs-health --json"

requires_evidence: true

risk_level: high

visual_tags:
  - module
  - module
  - cyber-security

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
# Atlas AI Cyber External MCP Tooling

## Rule

External MCP servers with offensive capability are integrated as wrapped recipes,
not as raw `mcpServers` entries exposed to Atlas Decide or providers.

## Candidate: HexStrike MCP

| Field | Value |
|---|---|
| Tool slug | `hexstrike-mcp` |
| Recipe | `pentest-orchestrate` |
| Integration | MCP server through wrapper skill |
| Required sandbox | Dedicated VM |
| Execution tier | T2 |
| Approval | Extra approval always required |
| Skill wrapper | `cyber-hexstrike-runner` |

Constraints:

- apply Decision Receipt before every call;
- enforce `scope.in`;
- apply refusal matrix;
- write Evidence Ledger events;
- rate-limit MCP calls;
- kill-switch if the tool leaves declared scope;
- no reuse of VM between engagements.

## General External MCP Pattern

1. Create wrapper skill `cyber-<tool>-runner`.
2. Register external recipe with `integration: mcp_server`.
3. Require VM sandbox.
4. Record ADR in cyber extension docs.
5. Enforce receipt, scope proof, refusal matrix and evidence.
6. Limit simultaneous MCP connections.

## Anti-Pattern

Never paste an offensive MCP server directly into a provider config and let it
act as a normal tool. That bypasses Atlas governance.

## Resumo

Governance for integrating offensive external MCP tooling through Atlas wrappers instead of raw provider configuration.

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
