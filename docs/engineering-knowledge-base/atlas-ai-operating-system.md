---
id: atlas-ai-operating-system
type: engineering_knowledge
title: Atlas AI Operating System
status: active
category: architecture
priority: 100
summary: Compact macro contract for Atlas AI as the central orchestration intelligence across surfaces, domains, context, policy, tools, gates, evidence and learning.
tags:
  - atlas-ai
  - orchestration
  - architecture
  - programming
  - personal-development
  - finance
  - self-improvement
capabilities:
  - atlas_ai_operating_system
  - operating_system_overview
  - domain_pipeline_overview
decisions:
  - Atlas AI is the central orchestration intelligence.
  - Commands, app screens, workers, mobile, voice and MCP are surfaces.
  - Horizontal capabilities live in Core/shared runtimes, not isolated surfaces.
  - Domain Profile and Flow Profile are canonical representations of operational work.
  - Atlas Decide compiles the operational decision and emits Decision Receipt.
  - Atlas Dev and Atlas Forge are intensities of Programming, not competing products.
maintenance:
  - Keep this parent compact; edit focused specs under operating-system/.
  - Full historical source is archived in archive/source-material/atlas-ai-operating-system-full-2026-05-08.md.
related_paths:
  - docs/engineering-knowledge-base/operating-system/README.md
  - docs/engineering-knowledge-base/operating-system/surfaces-and-profiles.md
  - docs/engineering-knowledge-base/operating-system/domain-pipelines.md
  - docs/engineering-knowledge-base/operating-system/governance-and-dod.md
  - docs/engineering-knowledge-base/atlas-ai-pipeline.md
  - docs/engineering-knowledge-base/atlas-ai-core-vs-domain.md
  - docs/engineering-knowledge-base/archive/source-material/atlas-ai-operating-system-full-2026-05-08.md
  - app/Services/Ai/AtlasDecideService.php
  - app/Services/Ai/Programming/AtlasProgrammingOrchestrator.php
  - app/Services/Ai/AiGatewayService.php
  - app/Services/Ai/AiPromptBuilder.php
  - docs/engineering-knowledge-base/atlas-canonical-glossary-and-naming.md
doc_schema: atlas_canonical_module_doc.v1

graph_id: atlas-ai-operating-system

graph_title: Atlas AI Operating System

graph_world: atlas

graph_layer: system

graph_kind: module

graph_parent: atlas-ai-canonical-architecture-index

graph_status: active

graph_source: repo
human_name: Atlas AI Operating System
canonical_name: Atlas AI Operating System
technical_name: atlas-ai-operating-system
cartography_type: module
canonical_source: docs/engineering-knowledge-base/atlas-ai-operating-system.md

owner: architecture

repo_paths:
  - docs/engineering-knowledge-base/atlas-ai-operating-system.md

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
  - architecture

evidence:
  - docs/engineering-knowledge-base/atlas-ai-operating-system.md

required_tests:
  - "php artisan atlas:engineering:knowledge docs-health --json"

requires_evidence: true

risk_level: medium

visual_tags:
  - system
  - module
  - architecture

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
# Atlas AI Operating System

Atlas AI is the orchestration intelligence of Atlas. It understands intent,
selects domain/flow, assembles context, resolves policy, decides provider/model,
executes through governed runtimes, validates, repairs, records evidence and
learns.

## Problem

Atlas contains many strong capabilities: Decide, Dev, Forge, Open Brain, Memory,
Code Intelligence, Tool Runtime, Quality Gates, Repair, Engineering Blueprint,
telemetry, multimodal input, app, CLI, workers and APIs.

The risk is capabilities appearing in only one surface. The rule is simple:
horizontal capability belongs to Atlas AI, not to a command.

## Read Order

| Need | Read |
|---|---|
| Surfaces, aliases, Domain/Flow Profiles and Decide authority | `operating-system/surfaces-and-profiles.md` |
| Domain pipeline shapes | `operating-system/domain-pipelines.md` |
| Horizontal layers, anti-duplication and DoD | `operating-system/governance-and-dod.md` |
| Historical implementation narrative | `archive/source-material/atlas-ai-operating-system-full-2026-05-08.md` |

## Core Law

```txt
Surface -> Input -> Domain/Intent -> Context -> Policy -> Decide
-> Runtime/Executor -> Gates -> Repair/Escalation -> Evidence -> Learning -> Output
```

No mature operational flow skips policy, gates, evidence or learning when the
task changes real state or produces important decisions.

## Domain Shape

| Domain | Role |
|---|---|
| Programming | dev, forge, fix, review, refactor, QA, tests, security, release |
| Personal Development | private non-clinical plans, reflection, focus, recovery |
| Finance | review-only analysis, risk, compliance, research and thesis review |
| Self-Improvement / Curator | docs drift, gaps, duplication, proposals and quality evolution |

Domains may build specialist harnesses. They do not bypass the Kernel pipeline.

## Surface Shape

`atlas dev`, `atlas forge`, `atlas fix`, `atlas continue`, chat, app, mobile,
voice and MCP are entry points. They may preserve UX origin for audit, but they
must resolve to canonical surface/domain/flow contracts.

## Anti-Duplication

If a capability is useful in more than one place, move it to Core/shared runtime
or document why it is intentionally local. Alias commands must not carry their
own business logic once a canonical flow exists.

## Final Decision

Atlas AI should be treated as an operating system of orchestration. The goal is
not many commands. The goal is one intelligence that uses the right capabilities
from any surface with evidence, memory, repair and continuous evolution.

## Resumo

Compact macro contract for Atlas AI as the central orchestration intelligence across surfaces, domains, context, policy, tools, gates, evidence and learning.

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
