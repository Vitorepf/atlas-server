---
id: atlas-ai-canonical-architecture-index
type: engineering_knowledge
title: Atlas AI Canonical Architecture Index
status: active
category: architecture
priority: 100
summary: Indice canonico que define a hierarquia oficial entre Constituicao, Kernel, Master Architecture, Runtime Boundaries, Native Mac Agent, Human Knowledge Surface, Pipeline e Domain Specs.
tags:
  - atlas-ai
  - canonical-architecture
  - architecture-index
  - governance
capabilities:
  - canonical_architecture_index
  - documentation_governance
  - knowledge_governance_system
  - architecture_layering
  - runtime_language_boundaries
  - qualitative_levels_roadmap
decisions:
  - Atlas AI Thesis (Multiplier/Channel) e o ponto fixo constitucional acima de toda arquitetura. Toda decisao e auditada contra ela.
  - Atlas AI Provider Evolution Intelligence governa como lancamentos de Claude, ChatGPT, Gemini, Codex e labs viram capability, benchmark, connector, skill pack, AP, policy signal ou descarte.
  - Atlas AI Knowledge Governance System define como repo docs, Postgres KB, Code Intelligence, Evidence Ledger, Obsidian, AGENTS/CLAUDE e chat se relacionam sem competir por autoridade.
  - Atlas AI Runtime Language Boundaries define Laravel como Kernel/Maestro, Python como AI/Data Runtime, Go como Edge/Concurrency Runtime e Swift como Native Mac Runtime.
  - Atlas AI Qualitative Levels Roadmap governa os patamares P1-P7 como norte qualitativo e fila QL de implementacao.
  - Atlas AI Master Architecture e a camada Layer 2 de produto, planes, dominios, roadmap e estrategia.
  - Atlas AI Kernel Architecture e a camada Layer 1 de contratos executaveis, envelope, receipt, ledger, manifests, SDKs, tests e SLOs.
  - AtlasVault/Obsidian pertence a Human Knowledge Surface / Personal Knowledge Workspace: workspace humano de escrita, leitura, pesquisa, revisao, navegacao e espelho gerenciado, nao fonte operacional crua.
  - Nenhum documento deve disputar o titulo de fonte unica; cada layer tem autoridade especifica.
  - Mudancas de arquitetura devem atualizar este indice quando alterarem autoridade entre docs.
maintenance:
  - Atualizar quando um novo layer, domain spec ou documento constitucional for promovido.
  - Usar este documento como primeira leitura operacional antes de escolher qual spec seguir.
related_paths:
  - docs/engineering-knowledge-base/atlas-ai-session-bootstrap.md
  - docs/engineering-knowledge-base/atlas-ai-provider-evolution-intelligence.md
  - docs/engineering-knowledge-base/atlas-ai-documentation-operating-system.md
  - docs/engineering-knowledge-base/atlas-ai-knowledge-governance-system.md
  - docs/engineering-knowledge-base/atlas-ai-runtime-language-boundaries.md
  - docs/engineering-knowledge-base/atlas-native-mac-agent.md
  - docs/engineering-knowledge-base/atlas-ai-qualitative-levels-roadmap.md
  - docs/engineering-knowledge-base/atlas-ai-thesis-multiplier-channel.md
  - docs/engineering-knowledge-base/atlas-ai-master-architecture.md
  - docs/engineering-knowledge-base/atlas-ai-kernel-architecture.md
  - docs/engineering-knowledge-base/atlas-ai-layer-0-glossary.md
  - docs/engineering-knowledge-base/atlas-ai-continuity-session-state.md
  - docs/engineering-knowledge-base/atlas-ai-telemetry-evidence-performance.md
  - docs/engineering-knowledge-base/atlas-ai-local-performance-memory-strategy.md
  - docs/engineering-knowledge-base/atlas-ai-scenario-simulation-harness.md
  - docs/engineering-knowledge-base/atlas-ai-mobile-surface-gateway.md
  - docs/engineering-knowledge-base/atlas-ai-voice-realtime-surface.md
  - docs/engineering-knowledge-base/atlas-ai-cli-multimodal.md
  - docs/engineering-knowledge-base/atlas-ai-skill-system.md
  - docs/engineering-knowledge-base/atlas-ai-runtime-packets.md
  - docs/engineering-knowledge-base/atlas-local-agent-surface.md
  - docs/engineering-knowledge-base/atlas-ai-autonomy-power-backlog.md
  - docs/engineering-knowledge-base/atlas-ai-governed-backlog.md
  - docs/engineering-knowledge-base/kernel/failure-domain-taxonomy.md
  - docs/engineering-knowledge-base/atlas-ai-vision.md
  - docs/engineering-knowledge-base/atlas-ai-flow-visual-map.md
  - docs/engineering-knowledge-base/atlas-ai-pipeline.md
  - docs/engineering-knowledge-base/atlas-ai-core-vs-domain.md
  - docs/engineering-knowledge-base/domains/README.md
  - docs/engineering-knowledge-base/domains/programming.md
  - docs/engineering-knowledge-base/domains/self-improvement.md
  - docs/engineering-knowledge-base/domains/finance.md
  - docs/engineering-knowledge-base/domains/personal-development.md
  - docs/engineering-knowledge-base/domains/background.md
  - docs/engineering-knowledge-base/domains/general.md
  - docs/engineering-knowledge-base/domains/health.md
  - docs/engineering-knowledge-base/atlas-ai-operating-system.md
  - docs/engineering-knowledge-base/atlas-ai-resolver-corpus-audit.md
  - docs/ap/AP-146-provider-cost-rate-inbox-replay.md
  - docs/ap/AP-147-dynamic-compute-market-shadow-surface.md
  - docs/engineering-knowledge-base/cognitive/README.md
  - docs/engineering-knowledge-base/cognitive/visual-map.md
  - docs/engineering-knowledge-base/assets/cognitive-plane-visual-map-v1.png
  - docs/engineering-knowledge-base/cognitive/implementation-briefing.md
  - docs/engineering-knowledge-base/cognitive/overview.md
  - docs/engineering-knowledge-base/cognitive/principles.md
  - docs/engineering-knowledge-base/cognitive/capabilities-core.md
  - docs/engineering-knowledge-base/cognitive/multiplier-edge.md
  - docs/engineering-knowledge-base/cognitive/pipeline-overlay.md
  - docs/engineering-knowledge-base/cognitive/roadmap.md
  - docs/ap/AP-163-cognitive-dreyfus-dynamic-pedagogy.md
  - docs/ap/AP-164-cognitive-worked-example-engine.md
  - docs/ap/AP-165-cognitive-process-pattern-catalog.md
  - docs/ap/AP-166-cognitive-failure-signature-tracker.md
  - docs/ap/AP-167-cognitive-self-regulated-learning-orchestrator.md
  - docs/ap/AP-168-cognitive-productive-failure-flow.md
  - docs/ap/AP-169-cognitive-personal-worked-examples-generator.md
  - docs/ap/AP-170-cognitive-predictive-failure-insertion.md
---
# Atlas AI Canonical Architecture Index
Indice oficial que impede documentos diferentes de disputarem autoridade arquitetural.
## Hierarquia Canonica

```text
Layer -1 - Tese Central (PONTO FIXO ACIMA DE TUDO)
  atlas-ai-thesis-multiplier-channel.md
  Multiplicador + Canal Unico + Antifragilidade. Imutavel. Toda decisao auditada aqui.

Layer 0 - Constitution / Human Knowledge
  identidade, leis, glossary, master prompt, AtlasVault humano e principios permanentes

Layer 0.5 - Documentation Operating System
  bootstrap de sessao, feature placement, split plan, limites de linhas, ownership e promocao
  comandos: atlas:ai:session-bootstrap, atlas:ai:place-feature, atlas:ai:docs-split-plan

Layer 1 - Kernel Architecture
  contratos executaveis: envelope, receipt, ledger, manifests, SDKs, tests, SLOs

Layer 1.5 - Runtime Boundaries: Laravel Kernel, Python AI/Data, Go Edge, Swift Native Mac

Layer 2 - Master Architecture
  produto, planes, dominios, onboarding, self-improvement, maturidade e roadmap

Layer 2.5 - Qualitative Levels: P1-P7, co-estrategista, Rivals Strategy

Layer 3 - Operating Topology
  pipeline, core-vs-domain, operating system, resolver corpus, architecture audit

Layer 4 - Domain Specs
  Programming, Finance, Personal Development, Self-Improvement, Marketing, QA, Security, Operations,
  Curator dedicado futuro
```

**Regra de hierarquia**: quando houver conflito entre layers, **Layer -1 vence sempre**. A tese de multiplicador/canal unico nao e modificavel por decisao de Master Architecture, Kernel ou Domain Spec; todas elas devem se conformar a ela.

## Autoridade Por Assunto

| Assunto | Documento que manda |
|---|---|
| Identidade, leis, glossario humano | `atlas-ai-layer-0-glossary.md` |
| Bootstrap de sessao nova | `atlas-ai-session-bootstrap.md` |
| Documentacao de alta performance, limite de linhas e promocao | `atlas-ai-documentation-operating-system.md` |
| Fonte de verdade entre repo docs, Postgres KB, Code Intelligence, Evidence Ledger, Obsidian, AGENTS/CLAUDE e chat | `atlas-ai-knowledge-governance-system.md` + `atlas-ai-documentation-operating-system.md` + `obsidian-atlas-vault.md` |
| Fronteira Laravel/Python/Go/Swift e APIs Apple | `atlas-ai-runtime-language-boundaries.md` + `atlas-native-mac-agent.md` |
| Patamares P1-P7, outro patamar e co-estrategista | `atlas-ai-qualitative-levels-roadmap.md` |
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
| Telemetry, evidence rollups, local AI/RAM, performance reports e cost health | `atlas-ai-telemetry-evidence-performance.md` + `atlas-ai-local-performance-memory-strategy.md` + `atlas-ai-kernel-architecture.md` |
| Content Intelligence, curadoria, YouTube, source quality e roteamento de conhecimento | `atlas-ai-content-intelligence-curation.md` |
| Business Contexts, Blackink, empresas futuras e product domains | `atlas-ai-business-contexts.md` |
| Agent behavior, suposicoes, simplicidade, diff cirurgico e verificacao | `atlas-ai-agent-behavior-contract.md` |
| Model selection, Atlas Decide, AP-99, provider performance e cost governance | `atlas-ai-model-selection-strategy.md` + `atlas-ai-telemetry-evidence-performance.md` + `docs/ap/AP-146-provider-cost-rate-inbox-replay.md` + `docs/ap/AP-147-dynamic-compute-market-shadow-surface.md` |
| Scenario simulation, MiroFish/OASIS patterns e outcome calibration | `atlas-ai-scenario-simulation-harness.md` + `atlas-ai-autonomy-power-backlog.md` |
| Mobile surface, mobile gateway, push e inbox | `atlas-ai-mobile-surface-gateway.md` + `atlas-ai-operating-system.md` |
| Voice Realtime Surface mobile-first, LiveKit Agents SDK, Swift Mac edge futuro, eclipse modes e Rivals-Voice | `atlas-ai-voice-realtime-surface.md` + `atlas-ai-mobile-surface-gateway.md` + `atlas-native-mac-agent.md` + `atlas-ai-runtime-language-boundaries.md` |
| Catalogo executavel da arquitetura mae: comandos, endpoints API e endpoints mobile por operacao | `AtlasArchitectureOperationsCatalog` + `/ai/architecture/operations` + Open Brain MCP `architecture_operations` |
| Constelacao / Motor de Serendipidade mobile | `atlas-constelacao-surface.md` + source material constitucional em `atlas-vault-backup/00-constituicao/constelacao-*.md` |
| CLI multimodal, paste de imagem e anexos | `atlas-ai-cli-multimodal.md` + `docs/paste-image-setup.md` |
| Skill system | `atlas-ai-skill-system.md` + `atlas-ai-kernel-architecture.md` |
| Runtime packets e projections | `atlas-ai-runtime-packets.md` + `atlas-ai-kernel-architecture.md` |
| Local Mac agent e background readiness | `atlas-local-agent-surface.md` + `atlas-native-mac-agent.md` + `atlas-ai-operating-system.md` |
| Autonomia proativa, Tool Synthesis, Dynamic Compute Market e backlog de poder | `atlas-ai-autonomy-power-backlog.md` + `atlas-ai-evolution-roadmap.md` |
| Backlog legado governado | `atlas-ai-governed-backlog.md` + `atlas-ai-resolver-corpus-audit.md` |
| Arquivo/source material documental | `archive/README.md` + `legacy-documentation-cleanup-report.md` |
| Programming domain | `domains/programming.md` + `atlas-ai-master-architecture.md` + `atlas-ai-operating-system.md` |
| Programming specialist profiles, frontend superpower e modelo por especialidade | `domains/programming-specialist-profiles.md` + `domains/programming-frontend-superpower.md` + `atlas-ai-model-selection-strategy.md` |
| Marketing como dominio exemplo | `atlas-ai-master-architecture.md` |
| Self-Improvement agendado | `domains/self-improvement.md` + `atlas-ai-master-architecture.md` |
| Finance domain | `domains/finance.md` + `atlas-ai-master-architecture.md` |
| Personal Development domain | `domains/personal-development.md` + `atlas-ai-master-architecture.md` |
| Fluxo visual canonico e operating model geral | `atlas-ai-flow-visual-map.md` + `atlas-ai-operating-system.md` |
| Triagem do corpus resolver | `atlas-ai-resolver-corpus-audit.md` |
| Cognitive Development Plane (sub-arquitetura cognitiva, Pareto, Dreyfus, multiplier edge cognitivo) | `cognitive/README.md` (bootstrap) + `cognitive/implementation-briefing.md` (implementacao) + `cognitive/overview.md` + `cognitive/principles.md` + `cognitive/capabilities-core.md` + `cognitive/multiplier-edge.md` + `cognitive/pipeline-overlay.md` + `cognitive/roadmap.md` |
| Cognitive Dreyfus Dynamic Pedagogy (Fase 1, read-model operacional) | `docs/ap/AP-163-cognitive-dreyfus-dynamic-pedagogy.md` |
| Cognitive Worked Example Engine + Process Fading (eixo Tim Cook, read-model operacional) | `docs/ap/AP-164-cognitive-worked-example-engine.md` |
| Cognitive Process Pattern Catalog (Latticework formalizado, read-model operacional) | `docs/ap/AP-165-cognitive-process-pattern-catalog.md` |
| Cognitive Failure Signature Classifier + Bayesian Tracker (C20 read-model operacional) | `docs/ap/AP-166-cognitive-failure-signature-tracker.md` |
| Cognitive Self-Regulated Learning Orchestrator (Zimmerman overlay metacognitivo, read-model operacional) | `docs/ap/AP-167-cognitive-self-regulated-learning-orchestrator.md` |
| Cognitive Productive Failure Flow (Kapur 3 fases, scaffold) | `docs/ap/AP-168-cognitive-productive-failure-flow.md` |
| Cognitive Personal Worked Examples Generator (Multiplier Edge cardinal, scaffold) | `docs/ap/AP-169-cognitive-personal-worked-examples-generator.md` |
| Cognitive Predictive Failure Insertion (Multiplier Edge cardinal, scaffold) | `docs/ap/AP-170-cognitive-predictive-failure-insertion.md` |

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
| Layer 2 | Especificado em `atlas-ai-master-architecture.md`; Layer 2.5 em `atlas-ai-qualitative-levels-roadmap.md` governa outro patamar sem autorizar autonomia direta |
| Layer 3 | Ativo: vision, pipeline, core-vs-domain, operating-system, resolver audit |
| Layer 4 | Parcial: `programming`, `finance`, `personal_development`, `self_improvement`, `strategic_decision`, `marketing`, `research`, `writing`, `learning`, `qa`, `security`, `operations`, `background`, `general` e `health` sao implemented/ready. |

## Proximos Blocos Enterprise
AP-146 e contrato operacional do ciclo AP-99/model selection/cost governance:
o replay prova se `configure_provider_cost_rates` aplicou rates humanos ou ficou em preview. Novos APs entram como specs curtas em `docs/ap/`, linkados por `START_HERE`, este indice e doc dono, sem duplicar texto longo em docs mae.
1. Capability Registry executavel. Status: iniciado com `AtlasCapabilityRegistry`, `CapabilityComplianceTest` e `atlas:ai:architecture-validate`.
2. Operation Envelope tipada. Status: implementado como contrato/factory inicial com `OperationEnvelopeFactory`, emissao `ENVELOPE_CREATED` e unit tests.
3. Decision Receipt v2. Status: implementado como receipt tipado via `DecisionReceiptIssuer`, com `dryRun`, `signedBy` e integracao em `AtlasDecideService`.
4. Domain Manifest + validator. Status: iniciado com `AtlasDomainManifestValidator`, `DomainProfileComplianceTest`, `atlas:ai:architecture-validate`, `AtlasAiDomainCatalogService`, `AtlasDomainOnboardingScorecard`, `atlas:ai:domains`, `GET /ai/domains` e filtro operacional de onboarding status; `programming`, `self_improvement`, `finance`, `personal_development`, `strategic_decision`, `marketing`, `research`, `writing`, `learning`, `qa`, `security`, `operations`, `background`, `general` e `health` estao implemented/ready.
5. Evidence Ledger. Status: iniciado com `atlas_ledger_events`, `AtlasEvidenceLedger`, `AtlasLedgerEvent` append-only por runtime guard, `LedgerEventType`, emissao `ENVELOPE_CREATED` pela `OperationEnvelopeFactory`, emissao `DECISION_ISSUED` pelo Decide, eventos de runtime/provider no `AiWorker`, eventos de gate/repair no repair nativo, eventos do Engineering Harness, eventos do Super Tool Runtime, replay via `atlas:ai:ledger`, drift report das projection tables em `kernel.ledger_projections.drift`, projection worker idempotente via `atlas:ai:ledger-project --limit=500 --json` para `ai_traces`, `atlas_engineering_runs` e `atlas_tool_runs`, registrado no scheduler a cada 10 minutos com janela default de 24h, health operacional em `/ai/observability.ledger_projection_health`, tool MCP read-only `atlas_ledger_projection_health`, action assistida `run_ledger_projection` no Inbox/CLI para backfill revisavel com `INBOX_ACTION_RECORDED`, Curator emitindo proposta acionavel com `available_actions[]=run_ledger_projection` quando detectar drift, e action `record_rivals_review` para fechar revisitas do Rivals Strategy com scores humanos e evento `INBOX_ACTION_RECORDED`.
6. Self-Improvement jobs. Status: iniciado com `AtlasSelfImprovementOrchestrator`, `AtlasSelfImprovementRuntime`, `AtlasSelfImprovementScheduleService`, comando `atlas:ai:self-improve --flow=...`, `--list-flows`, `--plan-only`, `AtlasInitiativeRun`, 13 flow profiles, plano de dominio/flow, leitura do Evidence Ledger, read models de SLO/Repair/Kernel Pipeline/Agent Behavior, finding de drift das projection tables, emissao de `LEARNING_PROPOSED` e agendamento multi-flow por `ATLAS_AI_SELF_IMPROVEMENT_FLOWS`. O default recorrente executa `nightly_review`, `weekly_architecture_audit`, `repair_loop_review`, `kernel_pipeline_review` e `agent_behavior_review`, com cadencia daily/weekly explicita.
Cada etapa deve entregar codigo, teste e doc.
