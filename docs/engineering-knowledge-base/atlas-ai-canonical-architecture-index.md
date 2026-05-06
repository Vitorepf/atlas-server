---
id: atlas-ai-canonical-architecture-index
type: engineering_knowledge
title: Atlas AI Canonical Architecture Index
status: active
category: architecture
priority: 100
summary: Indice canonico que define a hierarquia oficial entre Constituicao, Kernel, Master Architecture, Human Knowledge Surface / Personal Knowledge Workspace, Pipeline, Core/Domain boundaries e Domain Specs do Atlas AI.
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
  - Atlas AI Thesis (Multiplier/Channel) e o ponto fixo constitucional acima de toda arquitetura. Toda decisao e auditada contra ela.
  - Atlas AI Master Architecture e a camada Layer 2 de produto, planes, dominios, roadmap e estrategia.
  - Atlas AI Kernel Architecture e a camada Layer 1 de contratos executaveis, envelope, receipt, ledger, manifests, SDKs, tests e SLOs.
  - AtlasVault/Obsidian pertence a Human Knowledge Surface / Personal Knowledge Workspace: workspace humano de escrita, leitura, pesquisa, revisao, navegacao e espelho gerenciado, nao fonte operacional crua.
  - Nenhum documento deve disputar o titulo de fonte unica; cada layer tem autoridade especifica.
  - Mudancas de arquitetura devem atualizar este indice quando alterarem autoridade entre docs.
maintenance:
  - Atualizar quando um novo layer, domain spec ou documento constitucional for promovido.
  - Usar este documento como primeira leitura operacional antes de escolher qual spec seguir.
related_paths:
  - docs/engineering-knowledge-base/atlas-ai-thesis-multiplier-channel.md
  - docs/engineering-knowledge-base/atlas-ai-master-architecture.md
  - docs/engineering-knowledge-base/atlas-ai-kernel-architecture.md
  - docs/engineering-knowledge-base/atlas-ai-layer-0-glossary.md
  - docs/engineering-knowledge-base/atlas-ai-continuity-session-state.md
  - docs/engineering-knowledge-base/atlas-ai-telemetry-evidence-performance.md
  - docs/engineering-knowledge-base/atlas-ai-mobile-surface-gateway.md
  - docs/engineering-knowledge-base/atlas-ai-cli-multimodal.md
  - docs/engineering-knowledge-base/atlas-ai-skill-system.md
  - docs/engineering-knowledge-base/atlas-ai-runtime-packets.md
  - docs/engineering-knowledge-base/atlas-local-agent-surface.md
  - docs/engineering-knowledge-base/atlas-ai-governed-backlog.md
  - docs/engineering-knowledge-base/kernel/failure-domain-taxonomy.md
  - docs/engineering-knowledge-base/atlas-ai-vision.md
  - docs/engineering-knowledge-base/atlas-ai-pipeline.md
  - docs/engineering-knowledge-base/atlas-ai-core-vs-domain.md
  - docs/engineering-knowledge-base/domains/README.md
  - docs/engineering-knowledge-base/domains/programming.md
  - docs/engineering-knowledge-base/domains/self-improvement.md
  - docs/engineering-knowledge-base/domains/finance.md
  - docs/engineering-knowledge-base/domains/personal-development.md
  - docs/engineering-knowledge-base/atlas-ai-operating-system.md
  - docs/engineering-knowledge-base/atlas-ai-resolver-corpus-audit.md
  - docs/engineering-knowledge-base/archive/README.md
  - docs/engineering-knowledge-base/obsidian-atlas-vault.md
---

# Atlas AI Canonical Architecture Index

Este documento resolve a hierarquia oficial da arquitetura Atlas AI.

Ele existe para impedir que documentos diferentes declarem "eu sou a mae" sem
fronteira clara.

## Hierarquia Canonica

```text
Layer -1 - Tese Central (PONTO FIXO ACIMA DE TUDO)
  atlas-ai-thesis-multiplier-channel.md
  Multiplicador + Canal Unico + Antifragilidade. Imutavel. Toda decisao auditada aqui.

Layer 0 - Constitution / Human Knowledge
  identidade, leis, glossary, master prompt, AtlasVault humano e principios permanentes

Layer 1 - Kernel Architecture
  contratos executaveis: envelope, receipt, ledger, manifests, SDKs, tests, SLOs

Layer 2 - Master Architecture
  produto, planes, dominios, onboarding, self-improvement, maturidade e roadmap

Layer 3 - Operating Topology
  pipeline, core-vs-domain, operating system, resolver corpus, architecture audit

Layer 4 - Domain Specs
  Programming, Finance, Personal Development, Self-Improvement, Marketing scaffold,
  Curator dedicado futuro
```

**Regra de hierarquia**: quando houver conflito entre layers, **Layer -1 vence
sempre**. A tese de multiplicador/canal unico nao e modificavel por decisao
de Master Architecture, Kernel ou Domain Spec — todas elas devem se conformar
a ela.

## Autoridade Por Assunto

| Assunto | Documento que manda |
|---|---|
| Identidade, leis, glossario humano | `atlas-ai-layer-0-glossary.md` |
| Obsidian/AtlasVault como Human Knowledge Surface / Personal Knowledge Workspace | `obsidian-atlas-vault.md` + `atlas-ai-master-architecture.md` |
| Contratos de runtime | `atlas-ai-kernel-architecture.md` |
| Operation Envelope | `atlas-ai-kernel-architecture.md` |
| Decision Receipt | `atlas-ai-kernel-architecture.md` |
| Evidence Ledger | `atlas-ai-kernel-architecture.md` |
| Failure Domain / Failure Handler | `atlas-ai-kernel-architecture.md` + `kernel/failure-domain-taxonomy.md` |
| Capability/Domain/Surface/Provider manifests | `atlas-ai-kernel-architecture.md` |
| Architectural tests e SLOs | `atlas-ai-kernel-architecture.md` |
| Planes e arquitetura de produto | `atlas-ai-master-architecture.md` |
| Dominios principais e onboarding | `atlas-ai-master-architecture.md` |
| Domain Specs e status local | `domains/README.md` |
| Continuidade, compactacao e session state | `atlas-ai-continuity-session-state.md` + `open-brain-context-injection.md` |
| Telemetry, evidence rollups, performance reports e cost health | `atlas-ai-telemetry-evidence-performance.md` + `atlas-ai-kernel-architecture.md` |
| Mobile surface, mobile gateway, push e inbox | `atlas-ai-mobile-surface-gateway.md` + `atlas-ai-operating-system.md` |
| CLI multimodal, paste de imagem e anexos | `atlas-ai-cli-multimodal.md` + `docs/paste-image-setup.md` |
| Skill system | `atlas-ai-skill-system.md` + `atlas-ai-kernel-architecture.md` |
| Runtime packets e projections | `atlas-ai-runtime-packets.md` + `atlas-ai-kernel-architecture.md` |
| Local Mac agent e background readiness | `atlas-local-agent-surface.md` + `atlas-ai-operating-system.md` |
| Backlog legado governado | `atlas-ai-governed-backlog.md` + `atlas-ai-resolver-corpus-audit.md` |
| Arquivo/source material documental | `archive/README.md` + `legacy-documentation-cleanup-report.md` |
| Programming domain | `domains/programming.md` + `atlas-ai-master-architecture.md` + `atlas-ai-operating-system.md` |
| Marketing como dominio exemplo | `atlas-ai-master-architecture.md` |
| Self-Improvement agendado | `domains/self-improvement.md` + `atlas-ai-master-architecture.md` |
| Finance domain | `domains/finance.md` + `atlas-ai-master-architecture.md` |
| Personal Development domain | `domains/personal-development.md` + `atlas-ai-master-architecture.md` |
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
| Layer 0 | Ativo em forma enxuta: `atlas-ai-layer-0-glossary.md` governa identidade/glossario provider-safe; documentos constitucionais longos permanecem source material/human_vault_only |
| Layer 1 | Especificado em `atlas-ai-kernel-architecture.md`; enforcement inicial inclui `AtlasCapabilityRegistry`, `OperationEnvelopeFactory` com envelope tipado, `DecisionReceiptIssuer` com receipt tipado (`dryRun`, `signedBy`), `AtlasDomainOrchestratorRegistry`, `AtlasDomainManifestValidator`, `AtlasAiDomainCatalogService`, `AtlasDomainOnboardingScorecard`, `AtlasEvidenceLedger`, contratos `SurfaceAdapter`/`ProviderDriver`/`IdentityFragment`, `FailureDomain`/`FailureHandler`, SLO targets, `atlas:ai:domains`, `GET /ai/domains` e `atlas:ai:ledger` |
| Layer 2 | Especificado em `atlas-ai-master-architecture.md`, agora com planes de produto e Human Knowledge Surface / Personal Knowledge Workspace como surface humana governada |
| Layer 3 | Ativo: vision, pipeline, core-vs-domain, operating-system, resolver audit |
| Layer 4 | Parcial: `programming`, `finance`, `personal_development` e `self_improvement` sao os dominios implemented/ready atuais. `marketing`, `research`, `health`, `learning`, `writing`, `qa`, `security`, `operations`, `background` e `general` sao scaffold/catalog-ready ate terem runtime proprio. Marketing possui catalogo de 15 flows, mas nao deve ser tratado como implemented/ready. |

## Proxima Implementacao Obrigatoria

1. Capability Registry executavel. Status: iniciado com `AtlasCapabilityRegistry`, `CapabilityComplianceTest` e `atlas:ai:architecture-validate`.
2. Operation Envelope tipada. Status: implementado como contrato/factory inicial com `OperationEnvelopeFactory`, emissao `ENVELOPE_CREATED` e unit tests.
3. Decision Receipt v2. Status: implementado como receipt tipado via `DecisionReceiptIssuer`, com `dryRun`, `signedBy` e integracao em `AtlasDecideService`.
4. Domain Manifest + validator. Status: iniciado com `AtlasDomainManifestValidator`, `DomainProfileComplianceTest`, `atlas:ai:architecture-validate`, `AtlasAiDomainCatalogService`, `AtlasDomainOnboardingScorecard`, `atlas:ai:domains`, `GET /ai/domains` e filtro operacional de onboarding status; `programming`, `self_improvement`, `finance` e `personal_development` estao implemented/ready, e `marketing`, `research`, `health`, `learning`, `writing`, `qa`, `security`, `operations`, `background` e `general` aparecem como scaffolds auditaveis.
5. Evidence Ledger. Status: iniciado com `atlas_ledger_events`, `AtlasEvidenceLedger`, `LedgerEventType`, emissao `ENVELOPE_CREATED` pela `OperationEnvelopeFactory`, emissao `DECISION_ISSUED` pelo Decide, eventos de runtime/provider no `AiWorker`, eventos de gate/repair no repair nativo, eventos do Engineering Harness, eventos do Super Tool Runtime e replay via `atlas:ai:ledger`.
6. Self-Improvement jobs. Status: iniciado com `AtlasSelfImprovementOrchestrator`, `AtlasSelfImprovementRuntime`, `AtlasSelfImprovementScheduleService`, comando `atlas:ai:self-improve --flow=...`, `--list-flows`, `--plan-only`, `AtlasInitiativeRun`, 12 flow profiles, plano de dominio/flow, leitura do Evidence Ledger, read models de SLO/Repair/Kernel Pipeline, emissao de `LEARNING_PROPOSED` e agendamento multi-flow por `ATLAS_AI_SELF_IMPROVEMENT_FLOWS`. O default recorrente executa `nightly_review`, `weekly_architecture_audit`, `repair_loop_review` e `kernel_pipeline_review`, com cadencia daily/weekly explicita.

Cada etapa deve entregar codigo, teste e doc.
