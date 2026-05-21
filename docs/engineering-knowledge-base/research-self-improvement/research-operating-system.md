---
id: atlas-ai-research-operating-system
type: engineering_knowledge
title: Atlas AI Research Operating System
status: active
category: architecture
priority: 100
summary: Enterprise architecture for automatic high-reliability research as an evidence-first operating system.
tags:
  - atlas-ai
  - research-os
  - evidence-first
  - enterprise-research
capabilities:
  - research_operating_system
  - evidence_first_research
  - deep_research_architecture
decisions:
  - Atlas research must produce evidence dossiers before knowledge claims.
  - Research OS is a governed system, not a single chat response or search call.
  - Reports publish verified claims, not unsupported generated knowledge.
maintenance:
  - Update when Source Registry, Evidence Lake, scheduler, agents or eval harness become executable.
related_paths:
  - docs/engineering-knowledge-base/atlas-ai-research-self-improvement-runtime.md
  - docs/engineering-knowledge-base/research-self-improvement/evidence-lake-and-citation-health.md
  - docs/engineering-knowledge-base/research-self-improvement/multi-agent-research-roles.md
  - docs/engineering-knowledge-base/research-self-improvement/scheduled-research-and-triggers.md
  - docs/engineering-knowledge-base/research-self-improvement/reporting-and-publication-contract.md
  - docs/engineering-knowledge-base/research-self-improvement/private-connectors-security-and-stack.md
  - docs/engineering-knowledge-base/research-self-improvement/source-connectors-and-capture.md
doc_schema: atlas_canonical_module_doc.v1

graph_id: atlas-ai-research-operating-system

graph_title: Atlas AI Research Operating System

graph_world: atlas

graph_layer: system

graph_kind: module

graph_parent: atlas-ai-canonical-architecture-index

graph_status: active

graph_source: repo
human_name: Atlas AI Research Operating System
canonical_name: Atlas AI Research Operating System
technical_name: atlas-ai-research-operating-system
cartography_type: module
canonical_source: docs/engineering-knowledge-base/research-self-improvement/research-operating-system.md

owner: research-self-improvement

repo_paths:
  - docs/engineering-knowledge-base/research-self-improvement/research-operating-system.md

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
  - research-self-improvement

evidence:
  - docs/engineering-knowledge-base/research-self-improvement/research-operating-system.md

required_tests:
  - "php artisan atlas:engineering:knowledge docs-health --json"

requires_evidence: true

risk_level: medium

visual_tags:
  - system
  - module
  - research-self-improvement

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
# Atlas AI Research Operating System

Research OS is the target architecture for automatic Atlas research at maximum
reliability.

It is not "web search plus summary". It is a factory for evidence-backed
knowledge where every important conclusion has source, timestamp, provenance,
confidence, contradiction search and review state.

## Architecture

```text
Research objective
-> Scheduler / trigger
-> Source Registry
-> Collectors
-> Evidence Lake
-> Hybrid Index
-> Multi-Agent Research
-> Claim Verification
-> Citation Health
-> Contradiction Search
-> Synthesis Report
-> Eval Harness
-> Memory / Docs / AP promotion
-> Self-Improvement proposal
```

## Components

| Component | Responsibility |
|---|---|
| Scheduler | Time/event based research jobs. |
| Source Registry | Allowed sources, trust tier, permissions, rate limits. |
| Collectors | API, RSS, GitHub, browser, PDF, transcript, dataset and repo capture. |
| Evidence Lake | Raw immutable evidence, hashes, snapshots, extracted text and screenshots. |
| Hybrid Index | BM25, embeddings, graph edges, temporal metadata and authority scoring. |
| Research Agents | Plan, scout, inspect, verify, red-team and synthesize. |
| Claim Store | Atomic claims and evidence links. |
| Citation Health | URL liveness, archive, quote support and source drift. |
| Eval Harness | Quality, factuality, citation, cost, latency and utility metrics. |
| Promotion Gate | Decides docs/AP/code/memory/report action. |

## Core Rule

Atlas must not publish "knowledge". Atlas publishes verified claims with
evidence.

## Implementation Phases

1. Read-only Source Registry and source scoring.
2. Manual Evidence Lake packet import.
3. Claim extraction and citation health checks.
4. Research report compiler.
5. Self-Improvement proposal integration.
6. Scheduled read-only research jobs.
7. Multi-agent parallel research.
8. Approved low-risk docs promotion.

No phase may skip evidence, citation health or promotion gates.

## Resumo

Enterprise architecture for automatic high-reliability research as an evidence-first operating system.

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
