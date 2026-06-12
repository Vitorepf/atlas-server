---
id: atlas-ai-evolution-roadmap
type: engineering_knowledge
title: Atlas AI Evolution Roadmap
status: active
category: roadmap
priority: 95
summary: Operational index for Atlas AI evolution. Keeps the final-product backlog governed by Kernel, Evidence Ledger, Context Builder, provider performance, self-improvement and documentation law.
tags:
  - atlas-ai
  - roadmap
  - evolution
  - provider-performance
  - context-builder
capabilities:
  - atlas_ai_evolution_roadmap
  - provider_performance_roadmap
  - hybrid_context_roadmap
  - self_improvement_roadmap
decisions:
  - This document is an index, not a parallel architecture.
  - Evolution ideas must extend existing Kernel, Context Builder, Evidence Ledger, Provider Strategy, Policy/Profile and Curator contracts.
  - New large material must be added as child docs under evolution/ or AP specs, never appended here.
maintenance:
  - Read atlas-ai-canonical-architecture-index.md before implementing any phase.
  - Read atlas-ai-documentation-operating-system.md before expanding roadmap docs.
  - Use docs/engineering-knowledge-base/evolution/README.md as the implementation bootstrap.
related_paths:
  - docs/engineering-knowledge-base/atlas-ai-canonical-architecture-index.md
  - docs/engineering-knowledge-base/atlas-ai-documentation-operating-system.md
  - docs/engineering-knowledge-base/atlas-ai-kernel-architecture.md
  - docs/engineering-knowledge-base/atlas-ai-master-architecture.md
  - docs/engineering-knowledge-base/atlas-ai-telemetry-evidence-performance.md
  - docs/engineering-knowledge-base/atlas-ai-evolution-phase-0-audit.md
  - docs/engineering-knowledge-base/atlas-ai-autonomy-power-backlog.md
  - docs/engineering-knowledge-base/evolution/README.md
  - docs/engineering-knowledge-base/archive/source-material/evolution/atlas-ai-evolution-roadmap-full-2026-05-08.md
doc_schema: atlas_canonical_module_doc.v1

graph_id: atlas-ai-evolution-roadmap

graph_title: Atlas AI Evolution Roadmap

graph_world: atlas

graph_layer: flow

graph_kind: module

graph_parent: atlas-ai-pipeline

graph_status: active

graph_source: repo
human_name: Atlas AI Evolution Roadmap
canonical_name: Atlas AI Evolution Roadmap
technical_name: atlas-ai-evolution-roadmap
cartography_type: module
canonical_source: docs/engineering-knowledge-base/atlas-ai-evolution-roadmap.md

owner: roadmap

repo_paths:
  - docs/engineering-knowledge-base/atlas-ai-evolution-roadmap.md

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
  - roadmap

evidence:
  - docs/engineering-knowledge-base/atlas-ai-evolution-roadmap.md
evidence_refs:
  - symbol: AtlasAiEvolutionRoadmapService
  - command: atlas:aaeos:ai-evolution-roadmap
  - test: AtlasAiEvolutionRoadmapTest

required_tests:
  - "php artisan atlas:engineering:knowledge docs-health --json"

requires_evidence: true

risk_level: low

visual_tags:
  - flow
  - module
  - roadmap

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
# Atlas AI Evolution Roadmap

This is the compact operational entry point for Atlas evolution. The original
long source was archived at
`archive/source-material/evolution/atlas-ai-evolution-roadmap-full-2026-05-08.md`
and remains available for audit.

## Non-Negotiable Frame

Atlas is not a wrapper around Claude, Codex, Gemini or ChatGPT. Atlas is the
upper orchestration layer that absorbs provider progress and multiplies it
through local memory, domain context, receipts, evidence, gates and curation.

Therefore:

1. provider launches become inputs to Atlas, not existential threats;
2. capabilities go through Kernel and Domain contracts;
3. repeated patterns move to Core;
4. all important outcomes become Evidence;
5. Curator proposes evolution, but does not self-apply critical behavior.

## Read Order

| Need | Read |
|---|---|
| Evolution bootstrap | `evolution/README.md` |
| Hybrid RAG and context | `evolution/context-builder-roadmap.md` |
| Provider performance and model routing | `evolution/provider-performance-roadmap.md` |
| Advanced frontier backlog | `evolution/advanced-capabilities-backlog.md` |
| Personal longitudinal intelligence | `evolution/personal-longitudinal-roadmap.md` |
| Execution waves and AP handoff | `evolution/implementation-handoff.md` |

## Current Implementation Spine

| Phase | Contract | Status |
|---|---|---|
| Phase 0 | AP-99 Provider Performance Contract | implemented/read-model path |
| Phase 1 | AP-100 Context Pack Manifest Reflection | implemented |
| Phase 1 | AP-101 Retrieval Router | next natural Context Builder increment |
| Phase 2 | Self-RAG and reflection gates | planned |
| Phase 2 | Graph RAG explicit/observed/inferred | planned |
| Phase 3 | Personal longitudinal memory | planned, privacy gated |
| Phase 4 | Proactive Curator and adaptive routing | planned, proposal-only first |

## Authority

| Topic | Owner |
|---|---|
| Kernel invariants | `atlas-ai-kernel-architecture.md` |
| Product planes | `atlas-ai-master-architecture.md` |
| Provider/model choice | `evolution/provider-performance-roadmap.md` + AP-99 |
| Context retrieval | `evolution/context-builder-roadmap.md` |
| Future power backlog | `evolution/advanced-capabilities-backlog.md` |
| Personal memory | `evolution/personal-longitudinal-roadmap.md` |
| Implementation order | `evolution/implementation-handoff.md` |

## Implementation Rule

If a contract already exists, extend it. If a table/event/service already
exists, normalize the payload there. Do not create a new subsystem with a new
name for the same function.

## Resumo

Operational index for Atlas AI evolution. Keeps the final-product backlog governed by Kernel, Evidence Ledger, Context Builder, provider performance, self-improvement and documentation law.

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

## O-2 "Loop decente" — qualidade no ciclo (Campanha Fable, 2026-06-12)

A falha histórica (Opus/GPT nunca deixaram o Loop decente) era qualidade no ciclo, não o
runtime. Quatro contratos estruturais, cada um com teste congelado:

- **(a) Capture quality enforce é o default.** `atlas.ai.capture_quality_gate.mode` passou
  observe→enforce (Marco Zero mediu 94% waste em observe; os fixes do O-1 tornaram seguro).
  Em enforce: ruído não persiste, conteúdo idêntico colapsa (dedup por content_hash
  canonicalizado), e o dedup NÃO cola em proposta rejeitada (gate OU humano).
  Prova: `AtlasLearningProposalQualityGateTest`, `AtlasCaptureQualityGateTest`.
- **(b) Descoberta dirigida por evidência real.** `AtlasLoopEvidenceSignalService` deriva
  um peso por arquivo do corpus REAL de falhas (failure_signatures, janela 30d); o score
  de descoberta passou a `0.72·estrutural + 0.28·evidência`. Fail-open: corpus vazio →
  ranking estrutural intacto. Prova: `AtlasLoopEvidenceSignalServiceTest`.
- **(c) Guard numérico determinístico.** `NumericSafetyGuard` é o primitivo único que a
  família NaN/INF/overflow/SORT_STRING (57/107 kernels) precisava — finite/safeDivide/
  clamp/safeMean/safeSum/numericSort, total e puro. Prova: `NumericSafetyGuardTest`.
- **(d) Certificação adversarial em todos os caminhos (flag).**
  `atlas.loop.universal_certification` (default OFF): quando ON, o caminho de descoberta
  — não só o framework — roteia cada proposta pelo semantic certifier + painel adversarial
  antes de certified_for_review (fecha o Goodhart do frozen judge auto-escrito). Fail-soft
  por proposta: erro de gate dropa a proposta, nunca a task. Ligar muda a severidade do
  juiz do loop vivo → decisão deliberada destravada junto da política merge-livre (O-3).
  Prova: `AtlasLoopGrindTaskCommandTest::test_universal_certification_gates_the_discovery_path_fail_soft`.

## O-3 "Travessia merge-livre" — reprove funcional + contrato persistido (Campanha Fable, 2026-06-12)

A travessia (promover uma vitória do Loop a código) só é segura se a re-prova ANTES do
merge for real. Três contratos, com teste congelado:

- **Contrato de acceptance viaja COM a proposta.** O runner anexa o contrato frozen
  completo (`acceptance_contract`: commands/frozen_globs/allowed_globs/metric_kind) a cada
  proposta; o store persiste em `quality._acceptance_contract` (sem migração). Antes só
  sobravam `metric` (float) + `acceptance_hash` — re-rodar o teste original era impossível.
- **defaultReprove lê o contrato, não o `metric`.** O `AtlasLoopProposalPromotionGate`
  re-roda o frozen judge contra o contrato persistido; contrato ausente/sem commands =
  fail-closed (nunca promove o que não consegue re-verificar). Fecha o O-1 #1/#2 (o código
  lia `$proposal->metric` numérico como acceptance → sempre []→sempre negava ou, pior,
  promovia sem re-prova real). Prova: `AtlasLoopProposalPromotionGateTest`.
- **Materializer limpa o artefato `atlas.patch`.** Bug descoberto ao wirar o reprove: o
  materializer deixava `atlas.patch` no workspace isolado → o census de escopo do frozen
  judge via um arquivo extra → `out_of_scope` → TODA promoção falharia a re-prova em
  produção. Agora o patch é removido após aplicar; o workspace contém só a mudança.
  Prova: `AtlasLoopProposalReverserTest` (cadeia G5 materialize→promote→reverse) verde.

A política merge-livre (auto-merge pós-veredito + fix-forward + saldo líquido) permanece
destravável pela flag `atlas.loop.universal_certification` (O-2 slice d) + a chave do
operador `atlas.ai.loop.merge_to_source_enabled` (default OFF) — a porta e a fechadura
existem e re-provam de verdade; girar a chave segue decisão do operador.
