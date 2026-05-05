---
id: atlas-ai-canonical-architecture-index
type: engineering_knowledge
title: Atlas AI Canonical Architecture Index
status: active
category: architecture
priority: 100
summary: Indice canonico que define a hierarquia oficial entre Constituicao, Kernel, Master Architecture, Human Knowledge Plane, Pipeline, Core/Domain boundaries e Domain Specs do Atlas AI.
tags:
  - atlas-ai
  - canonical-architecture
  - architecture-index
  - governance
capabilities:
  - canonical_architecture_index
  - documentation_governance
  - architecture_layering
decisions:
  - Atlas AI Master Architecture e a camada Layer 2 de produto, planes, dominios, roadmap e estrategia.
  - Atlas AI Kernel Architecture e a camada Layer 1 de contratos executaveis, envelope, receipt, ledger, manifests, SDKs, tests e SLOs.
  - AtlasVault/Obsidian pertence ao Human Knowledge Plane: camada humana bidirecional, nao fonte operacional crua.
  - Nenhum documento deve disputar o titulo de fonte unica; cada layer tem autoridade especifica.
  - Mudancas de arquitetura devem atualizar este indice quando alterarem autoridade entre docs.
maintenance:
  - Atualizar quando um novo layer, domain spec ou documento constitucional for promovido.
  - Usar este documento como primeira leitura operacional antes de escolher qual spec seguir.
related_paths:
  - docs/engineering-knowledge-base/atlas-ai-master-architecture.md
  - docs/engineering-knowledge-base/atlas-ai-kernel-architecture.md
  - docs/engineering-knowledge-base/atlas-ai-vision.md
  - docs/engineering-knowledge-base/atlas-ai-pipeline.md
  - docs/engineering-knowledge-base/atlas-ai-core-vs-domain.md
  - docs/engineering-knowledge-base/atlas-ai-operating-system.md
  - docs/engineering-knowledge-base/atlas-ai-resolver-corpus-audit.md
  - docs/engineering-knowledge-base/obsidian-atlas-vault.md
---

# Atlas AI Canonical Architecture Index

Este documento resolve a hierarquia oficial da arquitetura Atlas AI.

Ele existe para impedir que documentos diferentes declarem "eu sou a mae" sem
fronteira clara.

## Hierarquia Canonica

```text
Layer 0 - Constitution / Human Knowledge
  identidade, leis, glossary, master prompt, AtlasVault humano e principios permanentes

Layer 1 - Kernel Architecture
  contratos executaveis: envelope, receipt, ledger, manifests, SDKs, tests, SLOs

Layer 2 - Master Architecture
  produto, planes, dominios, onboarding, self-improvement, maturidade e roadmap

Layer 3 - Operating Topology
  pipeline, core-vs-domain, operating system, resolver corpus, architecture audit

Layer 4 - Domain Specs
  Programming, Marketing, Finance, Personal Development, Curator, Self-Improvement
```

## Autoridade Por Assunto

| Assunto | Documento que manda |
|---|---|
| Identidade, leis, glossario humano | Layer 0 Constitution docs, ainda a promover para KB |
| Obsidian/AtlasVault e Human Knowledge Plane | `obsidian-atlas-vault.md` + `atlas-ai-master-architecture.md` |
| Contratos de runtime | `atlas-ai-kernel-architecture.md` |
| Operation Envelope | `atlas-ai-kernel-architecture.md` |
| Decision Receipt | `atlas-ai-kernel-architecture.md` |
| Evidence Ledger | `atlas-ai-kernel-architecture.md` |
| Capability/Domain/Surface/Provider manifests | `atlas-ai-kernel-architecture.md` |
| Architectural tests e SLOs | `atlas-ai-kernel-architecture.md` |
| Planes e arquitetura de produto | `atlas-ai-master-architecture.md` |
| Dominios principais e onboarding | `atlas-ai-master-architecture.md` |
| Marketing como dominio exemplo | `atlas-ai-master-architecture.md` |
| Self-Improvement agendado | `atlas-ai-master-architecture.md` |
| Pipeline resumido | `atlas-ai-pipeline.md` |
| Fronteira Core vs Domain vs Surface | `atlas-ai-core-vs-domain.md` |
| Operating model geral | `atlas-ai-operating-system.md` |
| Triagem do corpus resolver | `atlas-ai-resolver-corpus-audit.md` |

## Regra De Resolucao De Conflito

Quando dois documentos parecerem conflitar:

1. Se for contrato executavel, o Kernel vence.
2. Se for direcao de produto/domain/roadmap, a Master Architecture vence.
3. Se for resumo operacional, Pipeline/Core/Operating System devem ser ajustados
   para refletir Kernel + Master.
4. Se for material legacy, Resolver Corpus Audit decide se promove, referencia
   ou arquiva.

## Estado Atual

| Layer | Estado |
|---|---|
| Layer 0 | Parcial; identidade/glossary/master prompt existem no AtlasVault e precisam promocao controlada |
| Layer 1 | Especificado em `atlas-ai-kernel-architecture.md`; enforcement inicial iniciado por `AtlasCapabilityRegistry`, `OperationEnvelopeFactory`, `DecisionReceiptIssuer`, `AtlasDomainOrchestratorRegistry`, `AtlasDomainManifestValidator`, `AtlasAiDomainCatalogService`, `AtlasDomainOnboardingScorecard`, `AtlasEvidenceLedger`, `atlas:ai:domains`, `GET /ai/domains` e `atlas:ai:ledger` |
| Layer 2 | Especificado em `atlas-ai-master-architecture.md`, agora com sete planes incluindo Human Knowledge Plane |
| Layer 3 | Ativo: vision, pipeline, core-vs-domain, operating-system, resolver audit |
| Layer 4 | Parcial: Programming, Self-Improvement, Marketing, Finance e Personal Development estao `ready 9/9` no Domain Onboarding Scorecard; Marketing possui 15 flows canonicos; Self-Improvement possui 10 flows canonicos de auditoria/evolucao; Finance possui 10 flows enterprise analysis-only; Personal Development possui 10 flows privados plan-only; Curator ainda precisa spec propria |

## Proxima Implementacao Obrigatoria

1. Capability Registry executavel. Status: iniciado com `AtlasCapabilityRegistry`, `CapabilityComplianceTest` e `atlas:ai:architecture-validate`.
2. Operation Envelope tipada. Status: iniciado com `OperationEnvelopeFactory` + unit tests.
3. Decision Receipt v2. Status: iniciado com `DecisionReceiptIssuer` + integracao em `AtlasDecideService`.
4. Domain Manifest + validator. Status: iniciado com `AtlasDomainManifestValidator`, `DomainProfileComplianceTest`, `atlas:ai:architecture-validate`, `AtlasAiDomainCatalogService`, `AtlasDomainOnboardingScorecard`, `atlas:ai:domains`, `GET /ai/domains`; Programming, Self-Improvement, Marketing, Finance e Personal Development estao `ready 9/9`, e os demais dominios ativos aparecem como scaffolds auditaveis.
5. Evidence Ledger. Status: iniciado com `atlas_ledger_events`, `AtlasEvidenceLedger`, `LedgerEventType`, emissao `ENVELOPE_CREATED`/`DECISION_ISSUED` no Decide, eventos de runtime/provider no `AiWorker`, eventos de gate/repair no repair nativo, eventos do Engineering Harness, eventos do Super Tool Runtime e replay via `atlas:ai:ledger`.
6. Self-Improvement jobs. Status: iniciado com `AtlasSelfImprovementOrchestrator`, `AtlasSelfImprovementRuntime`, comando `atlas:ai:self-improve --flow=...`, `--list-flows`, `--plan-only`, `AtlasInitiativeRun`, 10 flow profiles, plano de dominio/flow, leitura do Evidence Ledger, emissao de `LEARNING_PROPOSED` e agendamento multi-flow por `ATLAS_AI_SELF_IMPROVEMENT_FLOWS`.

Cada etapa deve entregar codigo, teste e doc.
