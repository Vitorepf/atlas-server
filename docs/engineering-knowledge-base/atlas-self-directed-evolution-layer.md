---
id: atlas-self-directed-evolution-layer
type: engineering_knowledge
title: Atlas Self-Directed Evolution Layer
status: active
category: architecture
priority: 100
implementation_state: partial_runtime_with_future_scope
summary: Capability layer that lets Atlas detect canonical gaps, propose specs, synthesize domain or department proposals, forecast future outcomes, propose structural redesigns, route reality outcome feedback and prepare sovereign learning capsules, while the operator remains the curator and approval authority. This is not a new OS and not a new standalone runtime; it is a composition/read-model layer over Self-Construction OS, Subsystem Builder, Self-Improvement, AAEL, Spec OS, Domain Runtime Contract, TEOS/ASRE, Evidence, Trust Ledger and Autonomous Holding.
human_summary: Camada em que Atlas deixa de apenas executar pedidos e passa a propor a propria evolucao com evidencia, simulacao e review humano.
human_what: Contrato canonico para Atlas detectar gaps, escrever propostas, simular futuros e montar backlog governado sem autoaprovar mudancas criticas.
human_purpose: Aumentar autonomia sem perder soberania: o operador deixa de escrever toda spec manualmente e passa a curar propostas geradas pelo Atlas.
human_input: Recebe intents historicas, failures, scorecards, docs, codigo, evidence, TEOS/ASRE signals, Holding readiness e feedback humano.
human_output: Entrega gap candidates, spec proposals, domain/department proposals, roadmap forecasts, architecture redesign proposals, reality feedback e operator curation receipts.
human_change_when: Mexa quando Self-Construction, Spec OS, Domain Runtime, TEOS, ASRE, Evidence, Trust Ledger ou Holding mudarem contratos de proposta, evidence ou curation.
human_block_when: Bloqueie quando IA tentar transformar esta camada em OS novo, executar mudanca critica sem operador, criar owner paralelo ou agir no mundo externo.
tags:
  - atlas-ai
  - self-directed-evolution
  - self-construction
  - spec-operating-system
  - counterfactual
  - domain-runtime
  - reality-outcome
  - autonomous-holding
capabilities:
  - self_directed_evolution_layer
  - canonical_gap_detector
  - autopoietic_spec_proposal_runtime
  - emergent_domain_department_synthesis
  - counterfactual_roadmap_forecaster
  - architecture_evolution_router
  - reality_outcome_feedback_router
  - operator_curation_inbox
  - sovereign_learning_capsule_preparation
decisions:
  - Self-Directed Evolution Layer e camada de composicao, nao OS novo, nao AGOS e nao substitui Self-Construction OS.
  - Self-Directed Evolution v0.1 deve reutilizar `AtlasSelfConstructionSubsystemBuilderService` para gaps/proposals/approval de subsistemas; nao criar outro builder ou registry paralelo.
  - Canonical Gap Detector e read model unificado sobre fontes existentes (Subsystem Builder, Self-Improvement, AAEL, docs-health, ACRUI, Evidence e failures), nao autoridade nova de criacao.
  - Autopoietic Spec Proposal Runtime e adapter proposal-only para Spec OS/AP/docs; nao escreve doc canonico ativo sem Operator Curation Receipt.
  - AAEL continua dono de portfolio/autonomous evolution opportunities; esta camada apenas normaliza oportunidades AAEL para inbox de curadoria quando o operador precisa decidir.
  - Self-Improvement continua dono de proposal backlog, before/after delta, invariant lock, regression sentinel e trust ledger de melhoria.
  - Atlas pode detectar gaps e escrever specs/APs/docs como proposta; promocao exige Operator Curation Receipt.
  - Proposta de domain/departamento usa Domain Routing Governance, Domain Runtime Contract e Department Contract Runtime; nao cria registry paralelo.
  - Counterfactual roadmap usa TEOS-I3/I4 e ASRE; nao cria Counterfactual Reality Engine paralelo.
  - Redesign estrutural usa Architecture Evolution Proposal Runtime; mudanca de feature segue Self-Construction OS normal.
  - Reality feedback usa Reality Outcome Gates, Evidence, ASRE e Holding scorecards; nao promove maturidade sozinho.
  - Federation nesta camada e apenas preparo de learning capsule provider-safe; rede cross-Atlas fica futura sob Sovereign/Epistemic OS.
  - World Action Engine vem depois: esta camada propoe e prioriza, mas nao executa side effects externos.
maintenance:
  - Atualize antes de implementar gap detector, proposal runtime, forecaster, curation inbox ou learning capsule exchange.
  - Sincronize owner map quando qualquer owner canonico assumir parte desta camada.
  - Rodar docs-health + sync apos qualquer alteracao.
related_paths:
  - docs/ap/AP-707-self-directed-evolution-reuse-boundary-contract.md
  - docs/ap/AP-708-self-directed-evolution-gap-read-model-v01-contract.md
  - docs/ap/AP-709-self-directed-evolution-curation-inbox-spec-adapter-contract.md
  - app/Services/Ai/SelfDirectedEvolution/SelfDirectedEvolutionGapReadModelService.php
  - app/Services/Ai/SelfDirectedEvolution/SelfDirectedEvolutionCurationInboxService.php
  - app/Services/Ai/SelfDirectedEvolution/SelfDirectedSpecProposalAdapter.php
  - app/Console/Commands/AtlasSelfDirectedEvolutionCommand.php
  - tests/Unit/Ai/SelfDirectedEvolution/SelfDirectedEvolutionGapReadModelServiceTest.php
  - tests/Unit/Ai/SelfDirectedEvolution/SelfDirectedEvolutionCurationInboxServiceTest.php
  - tests/Unit/Ai/SelfDirectedEvolution/SelfDirectedSpecProposalAdapterTest.php
  - docs/engineering-knowledge-base/atlas-ai-self-construction-os.md
  - docs/engineering-knowledge-base/atlas-self-construction-catalog.md
  - app/Services/Ai/SelfConstruction/AtlasSelfConstructionSubsystemBuilderService.php
  - app/Console/Commands/AtlasSelfConstructionDetectGapsCommand.php
  - app/Console/Commands/AtlasSelfConstructionProposeSubsystemCommand.php
  - app/Console/Commands/AtlasSelfConstructionApproveProposalCommand.php
  - docs/engineering-knowledge-base/atlas-ai-spec-operating-system.md
  - docs/engineering-knowledge-base/atlas-autonomous-evolution-loop.md
  - app/Services/Ai/AutonomousEvolution/AtlasAutonomousEvolutionLoopService.php
  - docs/engineering-knowledge-base/atlas-self-improvement-closed-loop-level7-v1.md
  - docs/engineering-knowledge-base/atlas-domain-runtime-contract.md
  - docs/engineering-knowledge-base/domains/domain-routing-governance.md
  - docs/engineering-knowledge-base/atlas-domain-runtime-creation-gate.md
  - docs/engineering-knowledge-base/atlas-architecture-evolution-proposal-runtime.md
  - docs/engineering-knowledge-base/atlas-reality-outcome-gates.md
  - docs/engineering-knowledge-base/atlas-teos-i3-counterfactual.md
  - docs/engineering-knowledge-base/atlas-teos-i4-counterfactual-tree.md
  - docs/engineering-knowledge-base/atlas-strategic-reality-engine.md
  - docs/engineering-knowledge-base/atlas-evidence-certification-runtime.md
  - docs/engineering-knowledge-base/atlas-trust-ledger-canonical.md
  - docs/engineering-knowledge-base/atlas-ai-autonomous-holding-operating-system.md
  - docs/engineering-knowledge-base/atlas-autonomous-company-os-genesis-initiative.md
doc_schema: atlas_canonical_module_doc.v1
graph_id: atlas-self-directed-evolution-layer
graph_title: Atlas Self-Directed Evolution Layer
graph_world: atlas
graph_layer: system
graph_kind: module
graph_parent: atlas-ai-self-construction-os
graph_status: active
graph_source: repo
human_name: Atlas Self-Directed Evolution Layer
canonical_name: Atlas Self-Directed Evolution Layer
technical_name: atlas-self-directed-evolution-layer
cartography_type: module
canonical_source: docs/engineering-knowledge-base/atlas-self-directed-evolution-layer.md
owner: atlas-ai
patamar_after:
  - atlas-ai-self-construction-os
  - atlas-agentic-engineering-os
  - atlas-ai-autonomous-holding-operating-system
patamar_next: []
repo_paths:
  - docs/engineering-knowledge-base/atlas-self-directed-evolution-layer.md
allowed_changes:
  - Refinar schemas de proposta, gap, forecast e curation quando owners canonicos mudarem.
  - Adicionar sinais de gap ou forecaster se tiverem evidence source e owner claros.
forbidden_changes:
  - Tratar esta camada como OS novo ou runtime de execucao externa.
  - Criar `CanonicalGapDetectorService` ou `AutopoieticSpecProposalService` como autoridade paralela antes de provar reuse dos services existentes.
  - Autoaprovar spec, domain, departamento, redesign ou roadmap critico.
  - Criar engine paralela a Self-Construction, Spec OS, Domain Runtime, TEOS, ASRE, Evidence ou Holding.
  - Compartilhar learning capsule cross-Atlas sem sanitization gate e Sovereign/Epistemic policy.
depends_on:
  - atlas-ai-self-construction-os
  - atlas-ai-spec-operating-system
  - atlas-domain-runtime-contract
  - atlas-domain-runtime-creation-gate
  - atlas-architecture-evolution-proposal-runtime
  - atlas-reality-outcome-gates
  - atlas-teos-i3-counterfactual
  - atlas-teos-i4-counterfactual-tree
  - atlas-ai-autonomous-holding-operating-system
flows_to:
  - atlas-autonomous-company-os-genesis-initiative
  - atlas-ai-autonomous-holding-operating-system
  - atlas-world-action-engine-governed-readiness
unlocks:
  - governed_autonomous_spec_authoring
  - autonomous_gap_detection
  - counterfactual_roadmap_prioritization
  - operator_as_curator_workflow
governs:
  - atlas.self_directed_evolution
  - atlas.evolution.gap_candidates
  - atlas.evolution.operator_curation
evidence:
  - docs/engineering-knowledge-base/atlas-self-directed-evolution-layer.md
required_tests:
  - "php artisan atlas:engineering:knowledge docs-health --json"
  - "php artisan atlas:engineering:knowledge sync --prune --json"
requires_evidence: true
risk_level: critical
visual_tags:
  - layer
  - self-evolution
  - proposal
  - governance
ai_entrypoints:
  - Leia Owner Map e Contratos antes de implementar qualquer runtime de gap detection, spec proposal, forecaster ou curation inbox.
  - Se a tarefa envolver "Atlas propoe sozinho", comece por esta doc e depois va ao owner especifico.
ai_usage_notes:
  - Esta camada produz propostas e evidence, nao side effects externos.
  - Use schemas desta doc como envelopes de composicao; use schemas dos owners para execucao especializada.
quality_gates:
  - self-directed-layer-not-os
  - owner-map-preserved
  - existing-runtime-reuse-first
  - subsystem-builder-not-duplicated
  - aael-portfolio-owner-preserved
  - self-improvement-backlog-owner-preserved
  - operator-curation-required
  - no-external-side-effects
  - evidence-backed-gap-required
failure_modes:
  - IA cria detector/proposal runtime novo ignorando Subsystem Builder, Self-Improvement ou AAEL.
  - IA gera spec sem evidence e vende como plano aprovado.
  - IA cria departamento/domain novo ignorando Domain Creation Gate.
  - IA cria counterfactual engine paralelo a TEOS.
  - IA autoaprova redesign estrutural.
  - IA transforma learning capsule em vazamento cross-Atlas.
observability_signals:
  - gap_candidates_count
  - spec_proposals_submitted_count
  - operator_curation_approval_rate
  - forecast_expected_value_delta
  - rejected_duplicate_authority_count
  - external_side_effect_block_count
next_actions:
  - Implementar Canonical Gap Detector como read model.
  - Implementar Autopoietic Spec Proposal Runtime em modo proposal-only.
  - Integrar Domain/Department proposals ao Domain Runtime Creation Gate.
  - Integrar TEOS/ASRE ao Counterfactual Roadmap Forecaster.
  - Adicionar Operator Curation Inbox ao Mission Control/Holding.
---
# Atlas Self-Directed Evolution Layer

## Resumo

Atlas Self-Directed Evolution Layer e a camada em que Atlas deixa de ser apenas executor de intents e passa a ser **propositor governado da propria evolucao**.

```text
Antes:
operador percebe gap -> operador escreve spec -> Atlas implementa

Depois:
Atlas percebe gap -> Atlas escreve proposta -> Atlas simula impacto
-> Atlas recomenda roadmap/departamento/redesign -> operador aprova ou veta
-> Atlas executa via owners canonicos
```

O operador continua soberano. A mudanca e de ergonomia e autonomia: o operador vira curador de propostas em vez de autor manual de toda spec.

## Papel no Atlas

Esta camada fica antes do World Action Engine. Ela aumenta autonomia interna sem habilitar side effects externos.

Ela responde:

- o que esta faltando no Atlas?
- qual doc/spec/AP deveria existir?
- qual domain ou departamento talvez deva nascer?
- qual Obra tem maior expected value sob incerteza?
- qual redesign estrutural o Atlas deveria propor?
- quais outcomes reais mostram que o roadmap deve mudar?

## Onde Se Encaixa

```text
AAEOS / Domain Runtimes / Holding
-> Self-Directed Evolution Layer
   -> gap detection
   -> proposal authoring
   -> counterfactual roadmap
   -> operator curation
-> Self-Construction / Domain Runtime / TEOS / Evidence owners executam
-> World Action Engine governado no futuro
```

Ela nao substitui nenhum owner. Ela orquestra owners.

## Reuso Obrigatorio Antes De Implementar

Esta camada existe porque varias pecas ja existem, mas ainda nao aparecem como um fluxo unico para o operador. IA deve começar por reuso, nao por criacao.

| Capability desejada | Owner/runtime existente | Regra |
|---|---|---|
| Gap de subsystem | `AtlasSelfConstructionSubsystemBuilderService::detectGaps()` | Reusar e normalizar para `gap_candidate.v1`; nao criar detector paralelo. |
| Proposal de subsystem | `AtlasSelfConstructionSubsystemBuilderService::propose()` | Reusar quando a proposta for subsystem/capability; manter `requires_human_approval=true`. |
| Approval/reject | `AtlasSelfConstructionSubsystemBuilderService::approve()` | Converter para Operator Curation Receipt; nao autoaprovar. |
| Backlog de melhoria | Self-Improvement Proposal Backlog + Power Gate | Consumir como fonte de proposta; Self-Improvement continua dono do score/delta. |
| Portfolio de evolucao | AAEL (`AtlasAutonomousEvolutionLoopService`) | Consumir opportunities/experiments; AAEL continua dono de portfolio selection. |
| Spec/AP/doc proposal | Spec OS + docs canonicos | Gerar rascunho proposal-only; doc ativo exige curadoria. |
| Counterfactual roadmap | TEOS-I3/I4 + ASRE/AARS | Reusar forecasts existentes; nao criar engine paralelo. |
| Domain/departamento novo | Domain Creation Gate + Department Contract Runtime | Enviar proposal ao gate; nao criar registry proprio. |
| Redesign estrutural | Architecture Evolution Proposal Runtime | Roteamento apenas; este layer nao promove arquitetura. |

Se algum item acima nao for suficiente, a primeira saida correta e um `gap_candidate.v1` explicando o owner insuficiente, nao um novo runtime.

## Contratos

### 1. Canonical Gap Detector

Read model unificado que detecta gaps a partir de Subsystem Builder, Self-Improvement, AAEL, docs, codigo, intents, failures, scorecards, Evidence, Trust Ledger, Holding readiness e repeated operator requests.

Schema:

```text
atlas.evolution.gap_candidate.v1
fields: gap_id, source_refs, affected_owner_docs, missing_capability,
evidence_strength, recurrence_count, risk_level, suggested_owner,
duplicate_authority_candidates, recommendation
```

Sem evidence refs e duplicate authority review, nao ha gap canonico.

### 2. Autopoietic Spec Proposal Runtime

Atlas escreve specs, APs ou docs **como proposta**, nunca como verdade ativa. Quando a proposta for subsystem/capability, reutilize primeiro `AtlasSelfConstructionSubsystemBuilderService`. Quando for spec/AP/doc, routeie para Spec OS e Documentation Governance.

Schema:

```text
atlas.evolution.spec_proposal.v1
fields: proposal_id, gap_id, proposed_doc_path, doc_kind, owner_doc,
scope, non_goals, acceptance_criteria, risks, required_tests,
duplicate_authority_review, operator_curation_required
```

Estados: `drafted_by_atlas`, `awaiting_operator_review`, `approved`,
`rejected`, `needs_revision`, `archived`.

### 3. Emergent Domain / Department Synthesis

Quando gaps recorrentes nao cabem em domain, flow, profile ou departamento existente, Atlas pode propor uma nova unidade. A proposta usa:

- `domains/domain-routing-governance.md` para provar que nada existente serve;
- `atlas-domain-runtime-contract.md` para manifest/registry/maturity;
- `atlas-domain-runtime-creation-gate.md` para sandbox, shadow, L0/L1;
- `atlas-agentic-engineering-os-department-contract.md` para departamentos AAEOS.

Esta camada nao cria domain diretamente. Ela preenche proposal e envia ao gate.

### 4. Counterfactual Roadmap Forecaster

Atlas usa TEOS-I3/I4 e ASRE para comparar futuros antes de iniciar Obra.

Schema:

```text
atlas.evolution.roadmap_forecast.v1
fields: forecast_id, candidate_work_items, branching_factor,
time_horizon_days, scenario_count, expected_outcomes,
expected_value_rank, risk_rank, regret_minimization_score,
operator_constraints, recommendation
```

O forecaster recomenda. Ele nao executa alternativa automaticamente.

### 5. Architecture Evolution Router

Quando a proposta muda schema raiz, fases, camadas, departments, authority ou autonomy ladder, ela sai desta camada e entra em `atlas-architecture-evolution-proposal-runtime.md`.

Regra: feature comum vai para Self-Construction normal; mudanca estrutural vai para Architecture Evolution Proposal Runtime.

### 6. Reality Outcome Feedback Router

Reality Outcome Gates alimentam esta camada com sinais de outcome real. Se uma Obra ficou tecnicamente verde mas falhou no mundo real, Atlas deve propor:

- repair;
- rollback;
- roadmap replan;
- learning capsule;
- policy/gate update;
- domain/departamento novo, se o padrao se repetir.

### 7. Sovereign Learning Capsule Preparation

Esta camada pode preparar learning capsules provider-safe para futura federacao, mas nao distribui entre Atlas instances sem protocolo Sovereign/Epistemic.

Schema:

```text
atlas.evolution.learning_capsule_candidate.v1
fields: capsule_id, source_gap_id, sanitized_learning, redaction_status,
privacy_class, sovereignty_class, reusable_pattern, prohibited_payload,
operator_export_decision
```

## Fluxo

```text
Signals
-> Canonical Gap Detector
-> Duplicate Authority Review
-> Owner Routing
-> Spec / Domain / Forecast / Architecture Proposal
-> Counterfactual + Reality Outcome Evidence
-> Operator Curation Inbox
-> Approval / Rejection / Revision
-> Self-Construction or owner-specific implementation
-> Evidence + Trust Ledger + Holding scorecard feedback
```

## Regras para IA

- Nao chamar esta camada de OS.
- Nao criar nome novo quando owner existente cobre a feature.
- Nao promover proposta sem Operator Curation Receipt.
- Nao implementar proposta rejeitada ou `needs_revision`.
- Nao usar counterfactual como fato.
- Nao criar domain/departamento sem Domain Creation Gate.
- Nao tocar arquitetura estrutural sem Architecture Evolution Proposal Runtime.
- Nao compartilhar learning capsule fora do Atlas local sem Sovereign/Epistemic.
- Nao habilitar side effects externos; isso pertence ao futuro World Action.

## Escopo de Implementacao

Servicos devem ser compostores/adapters, nao autoridades paralelas:

- `SelfDirectedEvolutionGapReadModelService`: **v0.1 parcial implementado** (AP-708). Normaliza gaps vindos de Subsystem Builder, Self-Improvement e AAEL. docs-health, ACRUI e Evidence ficam para a proxima fatia.
- `SelfDirectedEvolutionCurationInboxService`: **v0.2 parcial implementado** (AP-709). Projecao read-only do gap read model em itens de curadoria `pending_operator_review`; nao persiste, nao aprova.
- `SelfDirectedSpecProposalAdapter`: **v0.2 parcial implementado** (AP-709). Gera draft de AP/spec/doc em modo proposal-only; nunca escreve doc canonico nem materializa arquivo.
- `SelfDirectedRoadmapForecasterService`: chama TEOS/ASRE/AAEL e rankeia Obras. **(future)**
- `LearningCapsuleCandidateService`: prepara capsule sanitizada, sem export. **(future)**

Qualquer write real deve passar por Self-Construction, Domain Runtime, Architecture Evolution, Evidence e gates do owner.

### Implementacao v0.1 (parcial, read-only)

`SelfDirectedEvolutionGapReadModelService::project(array $input = [])` compoe tres owners existentes **somente leitura** e devolve um relatorio `atlas.self_directed_evolution.gap_read_model.v1`:

| Fonte | Owner | Metodo lido | Nunca invocado |
|---|---|---|---|
| `self_construction` | `AtlasSelfConstructionSubsystemBuilderService` | `detectGaps()` | `propose()`, `approve()` |
| `self_improvement` | `AtlasSelfImprovementProposalBacklogService` | `listBacklog()` | `createProposal()`, `evaluateProposal()`, `prioritize()` |
| `aael` | `AtlasAutonomousEvolutionLoopService` | `controlPlane()` | `runCycle()`, `observeOpportunities()` |

O relatorio contem: `schema_version`, `status` (`ready|partial|blocked`), `generated_at`, `source_summary`, `candidates[]`, `blockers[]`, `owner_reuse_matrix`, `claim_policy`, `report_hash` (deterministico, exclui `generated_at`).

Cada candidate usa `atlas.evolution.gap_candidate.v1` com:
`candidate_id`/`candidate_hash` deterministicos, `source_owner`,
`source_schema_version`, `source_ref`, `gap_kind`, `title`, `rationale`,
`capability`, `risk_level`, `priority_score`, `evidence_refs[]`,
`owner_doc_refs[]`, `proposed_next_action`, `requires_operator_curation=true`,
`autoapproval_allowed=false`, `external_side_effect_allowed=false` e
`duplicate_authority_guard` (owner real preservado, `parallel_authority_created=false`).

Garantias v0.1: sem write de estado, sem provider, sem autoaprovacao, sem side effect externo, sem registry paralelo. `$input` aceita overrides (`gaps`, `self_improvement_backlog`, `aael_control_plane`, `backlog_filters`, `hours`, `limit`) para projecao deterministica e testavel sem side effects; fonte indisponivel vira blocker `source_unavailable` sem quebrar o relatorio.

CLI read-only: `php artisan atlas:self-directed-evolution gap-read-model --json [--hours=24] [--limit=50]`.

### Implementacao v0.2 (parcial, curation-only + proposal-only)

**Operator Curation Inbox** —
`SelfDirectedEvolutionCurationInboxService::project(array $input = [])` e uma
projecao read-only sobre o gap read model. Aceita override `gap_read_model`
(relatorio completo) para projecao deterministica sem reexecutar deteccao.
Cada candidate vira `atlas.self_directed_evolution.curation_item.v1` com
`item_id` deterministico, `status=pending_operator_review`, classificacao
(source/risk/priority band), `operator_actions=[approve,veto,needs_revision]` e
`routes_to_owner` (owner canonico para execucao). Relatorio
`atlas.self_directed_evolution.curation_inbox.v1` com `counts`, `items[]`,
`claim_policy` e `inbox_hash` deterministico (exclui `generated_at`).

`buildOperatorCurationReceipt(array $decision)` so produz
`atlas.self_directed_evolution.operator_curation_receipt.v1` a partir de uma
decisao **explicita** do operador (`approve|veto|needs_revision` + `actor` +
`candidate_hash`); o receipt declara `atlas_auto_decided=false`,
`autoapproval=false`, `executed=false`, `requires_owner_execution=true` e
`routes_to_owner`. Execucao real fica no owner canonico (ex.: SubsystemBuilder
`propose()`/`approve()`), nunca nesta camada.

**Spec Proposal Adapter** — `SelfDirectedSpecProposalAdapter::draft(array
$candidate)` transforma um `gap_candidate.v1` em
`atlas.self_directed_evolution.spec_proposal_draft.v1` (concretiza o contrato
ilustrativo `atlas.evolution.spec_proposal.v1` em modo draft). Inclui
`proposed_doc_kind`, `proposed_doc_path` (alvo canonico **NAO escrito**),
`staging_path`, `owner_doc_refs` (sempre referencia Spec OS), `evidence_refs`,
`scope`, `non_goals`, `acceptance_gates`, `rollback_plan`, `required_tests`,
`forbidden_paths`, `duplicate_authority_review` e `draft_hash` deterministico.
Garante `operator_approval_required=true`, `canonical_doc_write_allowed=false`,
`autoimplementation_allowed=false`, `autoapproval_allowed=false`, `written=false`.

Garantias v0.2: sem write de estado, sem provider, sem doc canonico, sem
materializar arquivo, sem registry paralelo, sem autoaprovacao/autoimplementacao.

CLI: `php artisan atlas:self-directed-evolution curation-inbox --json` e
`php artisan atlas:self-directed-evolution spec-draft --candidate=<hash> --json`.

#### Como o operador revisa / aprova / veta

1. roda `curation-inbox` e le os itens (todos `pending_operator_review`);
2. roda `spec-draft --candidate=<hash>` para inspecionar um draft proposal-only;
3. decide; um `operator_curation_receipt.v1` e montado a partir da decisao
   explicita e **roteado ao owner canonico** para execucao. Atlas nao aprova nem
   implementa sozinho.

## Dependencias

- Self-Construction OS: executa evolucao aprovada.
- Spec OS: compila intent/gap em spec operacional.
- Domain Routing Governance + Domain Runtime Contract: governam domains.
- Department Contract Runtime: governa departamentos AAEOS.
- TEOS-I3/I4 + ASRE: governam contrafactual e forecast.
- Evidence Certification + Trust Ledger: governam prova e aprendizado.
- Reality Outcome Gates: trazem outcome real.
- Autonomous Holding: agrega scorecards, backlog e readiness.
- Sovereign/Epistemic OS: futuros gates de federacao e export.

## Evidencias

Evidencia minima para promover esta camada alem do runtime parcial atual:

- gap read model consumindo no minimo tres sources reais, incluindo uma fonte Self-Construction existente;
- spec proposal adapter gerando docs em status `awaiting_operator_review`;
- curation receipt persistido;
- duplicate authority review obrigatorio;
- TEOS/ASRE forecast para pelo menos cinco candidate work items;
- zero side effects externos;
- docs-health e tests verdes.

## Riscos

- **Critico**: Atlas escreve doc e trata como aprovado. Mitigacao:
  `operator_curation_required=true`.
- **Critico**: duplicar owners existentes. Mitigacao: duplicate authority review.
- **Alto**: forecast probabilistico virar certeza. Mitigacao: separar scenario,
  evidence e recommendation.
- **Alto**: learning capsule vaza dado sensivel. Mitigacao: redaction e
  Sovereign/Epistemic export gate.
- **Critico**: camada virar atalho para World Action. Mitigacao: no external
  side effects hard gate.

## Exemplos

### Exemplo 1: Atlas escreve spec sozinho

Atlas detecta que 11 failures recentes mencionam "Mission Control nao mostra
outcome real". Ele cria `atlas.evolution.gap_candidate.v1`, gera proposta de
doc/AP, aponta `atlas-reality-outcome-gates.md` como owner e aguarda review.

### Exemplo 2: Novo departamento

Atlas detecta 18 intents sobre trading strategy backtest. Antes de criar
departamento, ele prova que finance/research/strategy nao cobrem o caso como
flow simples. Se passar, envia proposal ao Domain Runtime Creation Gate.

### Exemplo 3: Roadmap proativo

Atlas compara 20 Obras possiveis para 90 dias, simula cenarios TEOS/ASRE, ranqueia
por expected value e regret minimization, e recomenda tres Obras. O operador
aprova uma. So entao Self-Construction abre execucao governada.

## Proximas Acoes

1. ~~Implementar `SelfDirectedEvolutionGapReadModelService` em modo read-only.~~ **Feito (v0.1, AP-708)**. Falta estender o read model com docs-health, ACRUI e Evidence como fontes adicionais.
2. ~~Implementar `SelfDirectedSpecProposalAdapter` em modo proposal-only.~~ **Feito (v0.2, AP-709)**. Falta materializacao de draft em staging path protegido por receipt (fatia futura).
3. ~~Criar Operator Curation Inbox consumindo Subsystem Builder, Self-Improvement e AAEL.~~ **Feito (v0.2, AP-709)** como projecao read-only. Falta surface no Mission Control/Holding e persistencia de receipt via owner canonico.
4. Ligar TEOS/ASRE/AAEL ao Roadmap Forecaster.
5. Adicionar tests para bloquear runtime paralelo, autoaprovacao, duplicate authority e external
   side effects.
