---
id: atlas-ai-master-architecture
type: engineering_knowledge
title: Atlas AI Master Architecture
status: active
category: architecture
priority: 100
summary: Compact enterprise architecture index for Atlas AI as the operational intelligence above providers, surfaces, domains, runtimes, evidence and learning.
tags:
  - atlas-ai
  - master-architecture
  - enterprise-architecture
  - orchestration
capabilities:
  - atlas_ai_master_architecture
  - enterprise_orchestration
  - operational_intelligence
  - domain_profile_architecture
decisions:
  - Atlas AI is the operational intelligence of Atlas, not a chat, provider wrapper or single harness.
  - Atlas does not compete with Claude, ChatGPT, Gemini or Codex; it replaces direct dependence on them through an upper orchestration layer.
  - New domains are incorporated through onboarding contracts, not ad hoc commands or prompts.
  - AtlasVault is the human knowledge surface, not raw operational truth.
  - This file is an index; child docs own the details.
maintenance:
  - Keep this file compact and link detailed product architecture to master-architecture/* child docs.
  - Update START_HERE.md, README.md and canonical index when authority changes.
  - Use docs-health before and after expanding master architecture.
related_paths:
  - docs/engineering-knowledge-base/master-architecture/planes-and-authority.md
  - docs/engineering-knowledge-base/master-architecture/domain-onboarding.md
  - docs/engineering-knowledge-base/master-architecture/runtime-evidence-learning.md
  - docs/engineering-knowledge-base/master-architecture/competitive-strategy.md
  - docs/engineering-knowledge-base/archive/source-material/master-architecture/atlas-ai-master-architecture-full-2026-05-08.md
  - docs/engineering-knowledge-base/atlas-ai-thesis-multiplier-channel.md
  - docs/engineering-knowledge-base/atlas-ai-kernel-architecture.md
  - docs/engineering-knowledge-base/atlas-ai-pipeline.md
  - docs/engineering-knowledge-base/obras/shared-workspace-and-forge.md
doc_schema: atlas_canonical_module_doc.v1

graph_id: atlas-ai-master-architecture

graph_title: Atlas AI Master Architecture

graph_world: atlas

graph_layer: system

graph_kind: module

graph_parent: atlas-ai-canonical-architecture-index

graph_status: active

graph_source: repo
human_name: Atlas AI Master Architecture
canonical_name: Atlas AI Master Architecture
technical_name: atlas-ai-master-architecture
cartography_type: module
canonical_source: docs/engineering-knowledge-base/atlas-ai-master-architecture.md

owner: architecture

repo_paths:
  - docs/engineering-knowledge-base/atlas-ai-master-architecture.md

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
  - docs/engineering-knowledge-base/atlas-ai-master-architecture.md

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
# Atlas AI Master Architecture

Atlas AI is an evidence-driven operational intelligence. It uses providers as
engines, memory as continuity, tools as actuators, policy as law, domains as
specialization, gates as truth tests, evidence as audit and learning as
evolution.

## Product Thesis

Claude Code, ChatGPT, Gemini and future provider verticals are not the product
center. Atlas is the channel above them:

```text
Provider capability
* Atlas memory/context/tools/gates/evidence/learning
= Atlas output
```

If a provider improves, Atlas should improve by ingesting that provider as a
driver, benchmark, skill, connector or recipe.

## Seven Planes

| Plane | Purpose | Detail |
|---|---|---|
| Control | input, intent, profile, context, policy, decide | `master-architecture/planes-and-authority.md` |
| Domain | Programming, Finance, Marketing, Cognitive, Personal, Curator | `master-architecture/domain-onboarding.md` |
| Runtime | Laravel, Python, Go, Swift, providers, harnesses, tools | `master-architecture/runtime-evidence-learning.md` |
| Evidence | Ledger, projections, packets, replay, audit | `master-architecture/runtime-evidence-learning.md` |
| Learning | memory signals, quality score, Curator proposals | `master-architecture/runtime-evidence-learning.md` |
| Production Workspace | Obras Shared Workspace, Forge Workspace, packets, artifacts, integration queue | `obras/shared-workspace-and-forge.md` |
| Agent Control Plane | provider sessions, agent runs, heartbeat runs, checkout locks, wakeup queue, execution workspaces, liveness | `self-construction/agent-control-plane-contract.md`, `self-construction/paperclip-control-plane-benchmark.md` |
| Human Knowledge | AtlasVault/Obsidian, review, identity, synthesis | knowledge governance docs |
| Surface | CLI, App, Mobile, API, MCP, Voice | pipeline and surface docs |

## Canonical Flow

Every surface must enter the same Atlas AI pipeline:

```text
Surface -> Input -> Envelope -> Intent -> Business Context -> Domain/Profile/Flow
-> Context -> Policy -> Decide -> Receipt -> Runtime -> Gates -> Repair
-> Evidence -> Learning -> Output
```

## Non-Negotiable Rules

1. Surface does not decide.
2. Provider does not decide.
3. Tool does not decide.
4. Domain does not bypass Policy.
5. Runtime does not execute without Decision Receipt.
6. AtlasVault is curated human knowledge, not raw operational truth.
7. Obras Shared Workspace coordinates provider collaboration; it does not decide.
8. Business/project context is not a cognitive domain.
9. Everything repeated becomes Core.
10. Everything important becomes Evidence.
11. Curator does not auto-apply critical behavior without review.

## Read Next

| Need | Read |
|---|---|
| Plane authority and Control Plane | `master-architecture/planes-and-authority.md` |
| Adding a new domain | `master-architecture/domain-onboarding.md` |
| Runtime, Evidence and Learning | `master-architecture/runtime-evidence-learning.md` |
| Shared workspace for multi-provider production | `obras/shared-workspace-and-forge.md` |
| How Atlas wins provider launches | `master-architecture/competitive-strategy.md` |

## Resumo

Compact enterprise architecture index for Atlas AI as the operational intelligence above providers, surfaces, domains, runtimes, evidence and learning.

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
