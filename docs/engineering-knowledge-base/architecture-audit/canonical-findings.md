---
id: atlas-ai-architecture-audit-canonical-findings
type: engineering_knowledge
title: Atlas AI Architecture Audit Canonical Findings
status: active
category: architecture
priority: 87
summary: Consolidated architecture audit findings about existing truths, duplicate flows and required consolidation rules.
tags:
  - atlas-ai
  - architecture
  - findings
capabilities:
  - architecture_findings
decisions:
  - Atlas value comes from orchestration, memory, gates, evidence and learning above providers.
  - Surface-specific business logic is the root cause of duplicated behavior.
maintenance:
  - Update when a finding is closed by architecture validation or implementation.
related_paths:
  - docs/engineering-knowledge-base/atlas-ai-architecture-audit.md
  - docs/engineering-knowledge-base/atlas-ai-operating-system.md
  - docs/engineering-knowledge-base/atlas-ai-pipeline.md
doc_schema: atlas_canonical_module_doc.v1

graph_id: atlas-ai-architecture-audit-canonical-findings

graph_title: Atlas AI Architecture Audit Canonical Findings

graph_world: atlas

graph_layer: system

graph_kind: module

graph_parent: atlas-ai-canonical-architecture-index

graph_status: active

graph_source: repo
human_name: Atlas AI Architecture Audit Canonical Findings
canonical_name: Atlas AI Architecture Audit Canonical Findings
technical_name: atlas-ai-architecture-audit-canonical-findings
cartography_type: module
canonical_source: docs/engineering-knowledge-base/architecture-audit/canonical-findings.md

owner: architecture-audit

repo_paths:
  - docs/engineering-knowledge-base/architecture-audit/canonical-findings.md

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
  - architecture-audit

evidence:
  - docs/engineering-knowledge-base/architecture-audit/canonical-findings.md
evidence_refs:
  - symbol: AtlasCanonicalFindingsService
  - command: atlas:aaeos:canonical-findings
  - test: AtlasCanonicalFindingsTest

required_tests:
  - "php artisan atlas:engineering:knowledge docs-health --json"

requires_evidence: true

risk_level: medium

visual_tags:
  - system
  - module
  - architecture-audit

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
# Atlas AI Architecture Audit Canonical Findings

## Canonical Truths

| Truth | Implication |
|---|---|
| Atlas is the surface; providers are replaceable engines. | Provider adapters cannot own flow. |
| Memory belongs to Atlas, not providers. | Important flows must request context through Atlas. |
| Context Pack is an artifact, not improvised prompt text. | Context must be small, traceable, provider-safe and hashable. |
| Engineering needs contract before code. | Medium/hard programming tasks need task contracts and gates. |
| Tools must be governed and evidenced. | Tool Runtime owns registry, policy, executor, normalizer and evidence. |
| 5x over direct Claude Code comes from harness, not model worship. | Context, gates, repair, replay and final packets are the multiplier. |
| Profile is not a model preset. | Domain/flow profile resolves before provider/model. |
| Decide emits receipt; it does not execute. | Domain orchestrator and runtime execute under the receipt. |

## Disorder Observed

| Disorder | Correction |
|---|---|
| Surface became flow. | Surface adapters collect input and call `atlas.run`. |
| Multiple context concepts compete. | Base context, Open Brain and Engineering Context need formal boundaries. |
| Gates exist at several levels. | Domain quality matrix chooses gates by task type and risk. |
| Repair has multiple semantics. | Use one failure taxonomy and repair capsule per domain. |
| Decide can drift into execution. | Decision Receipt is output; runtime executes. |
| Rich docs lacked consolidation map. | Canonical index and Documentation OS govern authority. |

## Current Closure Mechanisms

- Surface Adapter contracts.
- Decision Receipt propagation.
- Architecture validation static scans.
- Documentation health and split plan.
- Domain profile registry.
- Capability registry and surface coverage tests.
- Evidence Ledger and read models.

## Remaining Audit Discipline

When a new duplicated flow appears, do not patch the duplicate in place. Identify
the shared owner and promote the capability to Core, Domain, Runtime or Tool
Runtime.

## Resumo

Consolidated architecture audit findings about existing truths, duplicate flows and required consolidation rules.

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
