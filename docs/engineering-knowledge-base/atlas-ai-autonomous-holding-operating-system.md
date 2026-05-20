---
id: atlas-ai-autonomous-holding-operating-system
type: engineering_knowledge
title: Atlas AI Autonomous Holding Operating System
status: active
category: architecture
priority: 94
summary: Contrato canonico para transformar Domain Company Runtimes em uma holding multi-dominio mensuravel, governada e bloqueada contra falsas claims de autonomia ou maturidade baseada so em manifest.
tags:
  - atlas-ai
  - holding
  - multi-domain
  - autonomy
capabilities:
  - autonomous_holding_readiness
  - company_registry
  - portfolio_governor
decisions:
  - A holding e um read model/governor acima dos Domain Company Runtimes, nao um dominio novo que burla policy.
  - Nota 9 real exige todas as empresas em supervised execution ou melhor, com funcoes declaradas e cobertura funcional observada, agentes declarados e assignments observados, fluxos declarados e execucoes observadas, work products proprios declarados e observados, integracoes declaradas e probes governados observados, recorrencia declarada e jobs recorrentes observados, metricas declaradas e observadas, comandos registrados, rotinas internas executadas e pelo menos um ciclo operacional observado completo.
  - Calendario nao e gate de claim/promocao quando a evidencia operacional acelerada esta completa: o report separa `company_buildout_score` de `operational_history_score`.
  - O Portfolio Governor inicia advisory/read-only e nao aloca capital nem executa ferramentas externas sem AP futuro.
maintenance:
  - Atualizar quando uma empresa ganhar runtime real, comando, integracao ou historico operacional.
  - Manter abaixo de 260 linhas.
related_paths:
  - docs/ap/AP-694-autonomous-holding-readiness-contract.md
  - app/Services/Ai/Holding/AutonomousHoldingReadinessService.php
  - app/Services/Ai/Holding/AutonomousHoldingOperatingCycleService.php
  - app/Services/Ai/Holding/AutonomousHoldingEnterpriseBuildoutService.php
  - app/Console/Commands/AtlasAiAutonomousHoldingCommand.php
  - tests/Unit/Ai/Holding/AutonomousHoldingReadinessServiceTest.php
  - tests/Feature/Ai/Holding/AutonomousHoldingEnterpriseCommandTest.php
  - app/Services/Ai/DomainRuntime/DomainSeedManifests.php
  - app/Services/Ai/MarketingDomain/MarketingRuntimeService.php
doc_schema: atlas_canonical_module_doc.v1

graph_id: atlas-ai-autonomous-holding-operating-system
graph_title: Atlas AI Autonomous Holding Operating System
graph_world: atlas
graph_layer: system
graph_kind: module
graph_parent: atlas-ai-canonical-architecture-index
graph_status: active
graph_source: repo
owner: autonomous-holding
repo_paths:
  - docs/engineering-knowledge-base/atlas-ai-autonomous-holding-operating-system.md
allowed_changes:
  - Atualizar contrato, metricas e evidencias da holding quando maturidade real mudar.
forbidden_changes:
  - Declarar nota 9/10 sem evidencias verificaveis, gates verdes, empresas promovidas e operating model completo por empresa.
depends_on:
  - atlas-ai-knowledge-governance-system
  - atlas-ai-core-vs-domain
  - atlas-kernel-mission-foundation
flows_to:
  - atlas-cartography
  - atlas-code
unlocks:
  - autonomous-holding-readiness
governs:
  - autonomous-holding
evidence:
  - app/Services/Ai/Holding/AutonomousHoldingReadinessService.php
  - app/Services/Ai/Holding/AutonomousHoldingOperatingCycleService.php
  - tests/Unit/Ai/Holding/AutonomousHoldingReadinessServiceTest.php
required_tests:
  - "php artisan test tests/Unit/Ai/Holding/AutonomousHoldingReadinessServiceTest.php"
  - "php artisan test tests/Unit/Ai/Holding/AutonomousHoldingEnterpriseBuildoutServiceTest.php"
  - "php artisan test tests/Feature/Ai/Holding/AutonomousHoldingEnterpriseCommandTest.php"
requires_evidence: true
risk_level: high
visual_tags:
  - system
  - holding
  - governance
ai_entrypoints:
  - Leia Contratos, Regras para IA e Evidencias antes de promover qualquer empresa.
ai_usage_notes:
  - Use `php artisan atlas:ai:autonomous-holding --json` para avaliar nota atual.
  - Use `php artisan atlas:ai:autonomous-holding observe-cycle --json` para registrar o ciclo operacional do dia.
  - Use `php artisan atlas:ai:autonomous-holding enterprise-buildout --json` para auditar a estrutura enterprise, o `premium_enterprise_agent_reference_model` e os `enterprise_flow_operating_packages` das nove empresas.
quality_gates:
  - "php artisan test tests/Unit/Ai/Holding/AutonomousHoldingReadinessServiceTest.php"
failure_modes:
  - Confundir manifests ativos com autonomia operacional real.
observability_signals:
  - autonomous holding current_score
  - blockers count
  - enterprise operating model complete count
next_actions:
  - Completar cada empresa antes de voltar a declarar score 9.
---
# Atlas AI Autonomous Holding Operating System

## Resumo

Este modulo define a camada de holding que agrega Domain Company Runtimes e
mede a distancia ate uma operacao multi-dominio autonoma real nota 9/10 pelo
criterio forte: cada dominio precisa funcionar como empresa.

## Papel no Atlas

A holding nao substitui dominios. Ela le empresas existentes, calcula maturidade,
declara blockers e impede claims prematuras baseadas so em manifests, stages ou
scaffolds.

## Onde Se Encaixa

Fica acima de Software, Research, Strategy, Finance, Marketing, Cyber,
Automation, Operations e Personal Development. A fonte dos contratos de empresa
continua nos manifests de dominio.

## Contratos

- `AutonomousHoldingReadinessService` e read-only.
- `AutonomousHoldingEnterpriseBuildoutService` gera o pacote enterprise
  executavel das nove empresas com schema, agentes, flows, connectors, work
  products, metricas, cadencias, policy, control plane e receipt por empresa.
- Nota 9 exige todas as empresas em supervised execution ou melhor.
- Nota 9 exige pelo menos uma empresa em limited autonomy.
- Nota 9 exige, para cada empresa: funcoes empresariais, agentes especializados,
  fluxos proprios, work products proprios, integracoes governadas, execucao
  recorrente, metricas, historico operacional e superficies de comando.
- Funcao empresarial declarada so conta como operacao quando o packet mais
  recente traz `atlas.ai.company_function_execution.v1` para cada funcao, com
  agente, fluxo, work product, metric key, hash e sem efeito externo.
- Agente especializado declarado so conta como operacao quando o packet mais
  recente traz `atlas.ai.company_agent_assignment.v1` para cada agente, com
  funcao, fluxo, metric key, assignment hash e sem efeito externo.
- Fluxo proprio declarado so conta como operacao quando o packet mais recente
  traz `atlas.ai.company_flow_execution.v1` para cada flow profile, com funcao,
  agente, metric key, flow execution hash e sem efeito externo.
- Cada flow enterprise tambem precisa de playbook e trace de orquestracao:
  buildout com `flow_execution_contracts`, `flow_playbooks`,
  `agent_collaboration_model`, `toolchain` e `enterprise_reference_architecture`;
  packet observado com `atlas.ai.company_orchestration_trace.v1`, checkpoint
  duravel, handoffs, guardrails e tool-call policy com receipt.
- Cada flow precisa de evaluation contract e avaliacao observada:
  `atlas.ai.company.flow_execution_contract.v1` define input/output, budget,
  rubric, benchmark hook e promotion gate; `atlas.ai.company_flow_evaluation.v1`
  registra critic score, rubric scores, benchmark, budget observation e promotion
  decision.
- Cada empresa precisa ter `enterprise_operating_system` com board, OKRs, SLA
  catalog, risk register, backlog system, runbooks, escalation policy e audit
  contract.
- A holding precisa ter `cross_company_fabric` com contratos tipados de handoff,
  shared service catalog, dependency map, fabric gates e fabric hash para provar
  colaboracao entre empresas, nao apenas maturidade isolada por dominio.
- A holding precisa ter `portfolio_governance_stack`
  `atlas.ai.holding.portfolio_governance_stack.v1` com board de portfolio,
  decision rights, dependency registry, protocolo de conflito, resource
  allocation, observability e hash de governanca.
- O buildout declara `structural_completion_policy`: a janela observada nao
  bloqueia consolidacao estrutural enterprise, mas segue obrigatoria para claim
  de target 9 e autonomia externa real.
- Cada company packet declara uma matriz de capacidade por flow
  `atlas.ai.company.enterprise_capability_matrix_row.v1`, cobrindo
  sense/reason/produce/verify/handoff com critic review, typed handoff e receipt.
- Cada flow declara `flow_runtime_blueprint`
  `atlas.ai.company.flow_runtime_blueprint.v1` com durable checkpointed graph,
  runtime queue, DLQ, state machine, tool permission matrix, retry/recovery,
  observability events, schemas e promotion controls.
- Cada company packet declara `enterprise_flow_orchestration_runbook_stack`
  `atlas.ai.company.enterprise_flow_orchestration_runbook_stack.v1` com runtime
  agentico supervisionado, runbook por flow, intake packet, agent graph, tool
  plan, checkpoint lattice, evaluation/acceptance, handoff/delivery, connector
  backplane, observability e hash de orquestracao.
- Cada company packet declara `enterprise_agent_registry` com entrada versionada
  `atlas.ai.company.enterprise_agent_registry_entry.v1` por agente, ownership de
  flows, backup/escalation, connector scope, memory scope e evaluation contract.
- Cada company packet declara `enterprise_flow_runtime_implementation_stack`
  `atlas.ai.company.enterprise_flow_runtime_implementation_stack.v1` com source
  catalog de padroes agenticos/durable execution, executable flow packets por
  flow, agent/tool routing, artifact IO contracts, supervision/shadow gates,
  connector runtime adapters, runtime event/outbox contract e observability;
  calendario nao bloqueia buildout nem target 9 quando o ciclo observado esta
  completo; acao externa continua exigindo operator mandate.
- Cada company packet declara `enterprise_flow_fixture_simulation_stack`
  `atlas.ai.company.enterprise_flow_fixture_simulation_stack.v1` com fixtures
  canonicos por flow, stubs offline por connector, trajetoria de trace esperada,
  assertion suite, failure injection, dry-run command plan, promotion gates e
  observability de simulacao sem side effects externos.
- Cada company packet declara `enterprise_flow_action_runtime_stack`
  `atlas.ai.company.enterprise_flow_action_runtime_stack.v1` com action runtime
  catalog por flow, command adapter matrix, handler state schemas, event
  emission plan, operator checkpoint contracts, promotion gates e observability;
  buildout nao e bloqueado pela janela observada e acao externa continua sem
  autoaprovacao.
- Cada comando declarado no action runtime catalog roda em modo fixture e
  retorna `atlas.ai.company.enterprise_flow_fixture_action_run.v1` com
  `status=fixture_completed`, state schema, event emission plan, operator
  checkpoint, blocked operations e receipt hash, sem side effects externos.
- A holding expoe `php artisan atlas:ai:autonomous-holding
  --action=enterprise-fixture-suite --json`, que executa todos os flow fixtures
  declarados e retorna `atlas.ai.holding.enterprise_flow_fixture_suite.v1` com
  pass_rate, receipt coverage, state schema coverage, event plan coverage e
  operator checkpoint coverage por empresa; suite verde habilita candidato a
  shadow mode, mas nao claim de autonomia externa sem governanca/operator
  mandate.
- A holding expoe `php artisan atlas:ai:autonomous-holding
  --action=enterprise-shadow-readiness --json`, que consome a fixture suite
  verde e retorna `atlas.ai.holding.enterprise_shadow_readiness.v1` com planos
  shadow por flow, allowed/blocked operations, gates de entrada e bloqueio
  explicito de autonomia externa enquanto faltar governanca/operator mandate.
- A holding expoe `php artisan atlas:ai:autonomous-holding
  --action=enterprise-supervised-activation-plan --json`, que consome shadow
  readiness verde e retorna
  `atlas.ai.holding.enterprise_supervised_activation_plan.v1` com plano
  supervisionado por flow, mandate template, rollback plan, incident route,
  connector certification evidence e bloqueio de ativacao ou side effects sem
  operator mandate assinado.
- A holding expoe `php artisan atlas:ai:autonomous-holding
  --action=enterprise-supervised-runtime --json`, que consome o plano
  supervisionado verde, emite mandato interno assinado por empresa e executa
  todos os flows como `atlas.ai.company.enterprise_supervised_flow_run.v1` com
  trace duravel, tool receipts, artifact, evaluation verde, rollback
  attestation, incident attestation e external action packet bloqueado contra
  autoexecucao.
- A holding expoe `php artisan atlas:ai:autonomous-holding
  --action=enterprise-connector-certification --json`, que certifica todos os
  conectores declarados por empresa com adapter contract, auth boundary,
  sandbox probe, consumer-provider contract result, lineage, replay fixture,
  SLO/failure attestation, receipt e flow usage attestations; write/paid mode
  permanece desligado sem operator mandate especifico.
- A holding expoe `php artisan atlas:ai:autonomous-holding
  --action=enterprise-external-action-mandates --json`, que cruza runtime
  supervisionado e certificacao de conectores para preparar pacotes de mandato
  externo por flow com connector scope, hashes de origem, controles de risco,
  preflight, rollback/compensation, incident route e budget envelope; execucao
  automatica e side effects externos permanecem desligados ate assinatura do
  operador e segundo revisor.
- A holding expoe `php artisan atlas:ai:autonomous-holding
  --action=enterprise-external-action-register --json`, que persiste os
  pacotes na tabela `ai_holding_external_action_mandates` como
  `queued_for_operator_review`, mantendo hashes de origem, blocked operations,
  controles de risco, preflight checks e flags `auto_execute_allowed=false` e
  `external_side_effects_enabled=false`.
- A holding expoe `php artisan atlas:ai:autonomous-holding
  --action=enterprise-external-action-preflight --mandate-hash=<hash> --json`,
  que valida um mandato persistido, atualiza o status para
  `preflight_green_awaiting_signatures` quando os controles internos estao
  completos e ainda retorna `external_execution_allowed=false` enquanto faltar
  assinatura explicita do operador e segundo revisor.
- A holding expoe `php artisan atlas:ai:autonomous-holding
  --action=enterprise-external-action-request-approval --mandate-hash=<hash>
  --json`, que cria duas aprovacoes formais em `ai_operator_approvals`:
  `operator_signature` e `second_reviewer_signature`, com evidence refs,
  connector scope, blocked operations e
  `external_execution_allowed_after_approval=false`.
- A holding expoe `php artisan atlas:ai:autonomous-holding
  --action=enterprise-external-action-approve|enterprise-external-action-reject
  --approval-uuid=<uuid> --json`, que registra a decisao auditavel, consolida o
  status do mandato e mantem `external_execution_allowed=false`; duas
  aprovacoes deixam o mandato em `signed_mandate_ready_manual_execution_only`,
  rejeicao deixa em `rejected_by_operator_gate`.
- A holding expoe `php artisan atlas:ai:autonomous-holding
  --action=enterprise-external-action-approval-status --mandate-hash=<hash>
  --json`, que reporta aprovacoes, papeis, rejeicoes, status consolidado e o
  blocker `manual_execution_only_even_after_signatures`.
- A holding expoe `enterprise-control-tower`, `enterprise-activation-cockpit`, `enterprise-activation-backlog-register|status|run`, `enterprise-connector-activation-register|probe|status`, `enterprise-flow-run-queue-register|execute|replay|status`, `enterprise-flow-operations-runbook-register|drill|status` e `enterprise-operating-packet-status` para consolidar mandatos, gaps, work packages, probes, fila/DLQ/replay, SLO, incident route, reconciliacao, operating-packet evidence e receipts; todos mantem `external_execution_allowed=false`.
- Cada company packet declara `enterprise_domain_agent_toolkit_stack`
  `atlas.ai.company.enterprise_domain_agent_toolkit_stack.v1` com source catalog
  de frameworks/agentes, perfis de toolkit por agente, assignments por flow,
  watchlist de repositorios e fontes do dominio, matriz de certificacao por
  agente e observability; runtime com toolkit nao certificado permanece
  bloqueado.
- Cada company packet declara `enterprise_workforce_capacity_stack`
  `atlas.ai.company.enterprise_workforce_capacity_stack.v1` com org model,
  capacity plan por agente, staffing matrix por flow, training/enablement,
  succession/continuity e observability de capacidade.
- Cada company packet declara `enterprise_portfolio_dependency_stack`
  `atlas.ai.company.enterprise_portfolio_dependency_stack.v1` com papel da
  empresa no portfolio, intake contract de dependencia, dependency map de
  handoffs, integration dependency map, routing por flow, escalation/conflict
  model e reporting contract para o portfolio operating review.
- Cada company packet declara `domain_data_model`
  `atlas.ai.company.domain_data_model.v1` com entidades de flow run, work
  product, handoff packet e metric observation, mais lineage, source refs e
  state hash.
- Cada company packet declara `business_process_map`
  `atlas.ai.company.business_process_map.v1` por flow, com trigger, swimlanes,
  estados, controles, outputs e hash de processo.
- Cada company packet declara `deliverable_quality_contracts`
  `atlas.ai.company.deliverable_quality_contract.v1` por work product, com
  secoes obrigatorias, acceptance/rejection criteria e score floor.
- Cada company packet declara `go_to_production_pack`
  `atlas.ai.company.go_to_production_pack.v1` com environment model
  contract/sandbox/shadow/supervised production, observability, SLO/SLI por
  flow, incident response, capacity plan e integration enablement por connector.
- Cada company packet declara `commercial_operating_stack`
  `atlas.ai.company.commercial_operating_stack.v1` com service catalog por work
  product, work intake, pricing/cost model, fulfillment lifecycle, business KPIs
  e customer success model.
- Cada company packet declara `enterprise_customer_market_operations_stack`
  `atlas.ai.company.enterprise_customer_market_operations_stack.v1` com modelo
  de customer/stakeholder, market positioning, offer packaging por work product,
  lifecycle journey por flow, voice-of-customer feedback loop,
  growth/retention operating model, customer success scorecard e guardrails de
  acao externa.
- Cada company packet declara `enterprise_account_contract_delivery_stack`
  `atlas.ai.company.enterprise_account_contract_delivery_stack.v1` com account
  360 model, account segment playbooks, contract/entitlement catalog, onboarding
  success plans por flow, service review/renewal calendar por flow,
  billing/revenue ops notional, account health/risk register, QBR/executive
  reporting e observability sem billing externo automatico.
- Cada company packet declara `enterprise_vendor_legal_procurement_stack`
  `atlas.ai.company.enterprise_vendor_legal_procurement_stack.v1` com
  procurement policy, vendor due diligence por connector, terms review por
  source, contract lifecycle, routing de procurement por flow,
  legal/compliance review, vendor operability scorecard e observability de
  procurement.
- Cada company packet declara `enterprise_resilience_continuity_stack`
  `atlas.ai.company.enterprise_resilience_continuity_stack.v1` com resilience
  policy, business continuity plan, failure mode analysis por flow, connector
  resilience plan, backup/restore contract, incident exercise program, crisis
  communication model e observability de resiliencia.
- Cada company packet declara `enterprise_analytics_decision_intelligence_stack`
  `atlas.ai.company.enterprise_analytics_decision_intelligence_stack.v1` com
  decision intelligence policy, metric lineage catalog, executive dashboards,
  decision register por flow, scenario/forecast model, work product analytics
  map e observability de decisoes.
- Cada company packet declara `enterprise_knowledge_memory_learning_stack`
  `atlas.ai.company.enterprise_knowledge_memory_learning_stack.v1` com memory
  governance policy, knowledge source registry, learning loop por flow,
  postmortem/retrospective program, playbook change control, feedback memory
  por work product, connector sync plan e observability de aprendizado.
- Cada company packet declara `enterprise_identity_access_data_sovereignty_stack`
  `atlas.ai.company.enterprise_identity_access_data_sovereignty_stack.v1` com
  zero-trust access policy, matriz de acesso por agente, fronteira de dados por
  flow, vault binding por connector, catalogo de dados sensiveis,
  purpose/consent registry, isolamento tenant, break-glass/revocation e
  observability de soberania.
- Cada company packet declara `enterprise_control_tower_run_operations_stack`
  `atlas.ai.company.enterprise_control_tower_run_operations_stack.v1` com run
  operations policy, control tower lane por flow, run queue/DLQ, scheduler por
  cadence, incident/exception desk, change/release calendar, connector probe
  plan, dashboard operations map, human interrupt/resume e observability de
  execucao.
- Cada company packet declara `enterprise_semantic_operating_graph_stack`
  `atlas.ai.company.enterprise_semantic_operating_graph_stack.v1` como digital
  twin read-model, com node catalog para funcoes/agentes/flows/connectors/
  metricas/work products/cadences, relationship edges por flow, operating
  views, drift detection rules, export contract e graph observability.
- Cada company packet declara `enterprise_delivery_assurance_stack`
  `atlas.ai.company.enterprise_delivery_assurance_stack.v1` com intake
  contract, delivery contract por work product, SLA por flow,
  acceptance/feedback loop, observability e delivery risk controls.
- Cada company packet declara `portfolio_finance_stack`
  `atlas.ai.company.portfolio_finance_stack.v1` com budget envelope, unit
  economics, investment committee packet, capital allocation gates, cost center
  por flow e resource allocation model.
- Cada company packet declara `enterprise_unit_economics_capacity_simulation_stack`
  `atlas.ai.company.enterprise_unit_economics_capacity_simulation_stack.v1`
  com economics policy notional, unit economics por flow, capacity simulation
  por flow, pricing ladder por work product, agent capacity cost model,
  connector cost/limit model, investment prioritization e observability
  economica sem claims financeiros sinteticos.
- Cada company packet declara `strategic_intelligence_stack`
  `atlas.ai.company.strategic_intelligence_stack.v1` com market signals,
  competitive benchmark model, rival/alternative map por flow, roadmap, learning
  loop e intelligence KPIs.
- Cada company packet declara `enterprise_grc_stack`
  `atlas.ai.company.enterprise_grc_stack.v1` com control framework, data
  classification, privacy/security, vendor/tool risk por connector,
  continuidade de negocio e audit evidence por flow.
- Cada company packet declara `external_integration_catalog` por connector com
  contrato MCP/API ou governance gate, modo read/internal, credential vault,
  least privilege, sandbox/read-only probe, receipts e operator signed
  side-effect mandate antes de qualquer write/publish/spend/trade/deploy.
- Cada company packet declara `enterprise_connector_certification_stack`
  `atlas.ai.company.enterprise_connector_certification_stack.v1` com source
  catalog, adapter contracts, auth/secret boundary, sandbox probes,
  consumer-provider contract tests, data mapping/lineage, usage matrix por
  flow, replay fixtures/mock server plan, SLO/failure modes, promotion gates e
  observability; write/paid modes continuam bloqueados por default.
- Cada company packet declara `enterprise_tooling_research_stack`
  `atlas.ai.company.enterprise_tooling_research_stack.v1` com source catalog de
  frameworks/agentes, adoption strategy, benchmark por flow, backlog de
  integracao por connector, watchlist de repos/agentes e gates de adocao
  enterprise.
- Cada company packet declara `enterprise_domain_solution_stack`
  `atlas.ai.company.enterprise_domain_solution_stack.v1` com source catalog
  especifico do dominio, solution module por flow, managed agent templates,
  data products com lineage e operating model sem side effects externos por
  default.
- Cada company packet declara `enterprise_integration_activation_plan`
  `atlas.ai.company.enterprise_integration_activation_plan.v1` com activation
  policy sem bloqueio de buildout pela janela observada, source activation
  tracks, connector activation tracks, flow activation matrix e observability de
  ativacao.
- Cada company packet declara `api_surface`, `evaluation_harness` e
  `autonomy_promotion_ladder` com comandos, packet endpoints, state contract,
  suites por flow, trace fields, policy scan, handoff acceptance test e quatro
  estagios de promocao.
- Cada company packet declara `enterprise_flow_benchmark_replay_stack`
  `atlas.ai.company.enterprise_flow_benchmark_replay_stack.v1` com policy
  offline/online eval, source catalog, dataset contract por flow, trace grading
  rubric por flow, adversarial regressions, deterministic state assertions,
  replay/comparison matrix, promotion quality gates e observability sem
  synthetic score claims.
- Work product declarado so conta como entrega quando o packet mais recente traz
  `atlas.ai.company_work_product.v1` para cada delivery type, com fluxo, metric
  key, work product hash e sem efeito externo.
- Integracao declarada so conta como evidencia operacional quando o packet mais
  recente traz pelo menos tres probes `atlas.ai.company_integration_probe.v1`
  com contrato governado, hash e `external_side_effects=false`.
- Cadencia recorrente declarada so conta como execucao recorrente quando o
  packet mais recente traz `atlas.ai.company_recurring_job.v1` para cada
  cadencia, com flow profile, metric key, recurring job hash e sem efeito
  externo.
- Superficie de comando e verificada contra comandos Artisan registrados; string
  declarada no manifest sem comando real nao conta.
- Rotina interna executada e verificada a partir de `routine_runs` no packet da
  holding; cada empresa captura pelo menos duas rotinas enterprise commandadas
  com sucesso e sem efeito externo.
- Metricas observadas sao derivadas do packet da holding: routine success,
  cobertura funcional, cobertura de agentes, cobertura de fluxos, work
  products observados, jobs recorrentes observados, quality gates, probes de
  integracao governada e blocked actions.
  Nomes de metricas no manifest sem observacao recente nao bastam.
- O ciclo operacional diario grava um `company_operating_packet` com funcoes,
  agentes, flows, runbook evidence por flow quando registrada, delivery types,
  function executions, agent assignments, flow executions, work products
  observados, quality gates, metricas, observed metrics, probes de integracao
  governada, jobs recorrentes observados, routine runs, handoff queue,
  `company_management_system_snapshot` e `company_cross_handoff_execution`.
- `php artisan atlas:ai:autonomous-holding enterprise-buildout --json` deve
  retornar nove company packets `atlas.ai.company.enterprise_buildout.v1`, cada
  um com readiness verde, flows especificos do dominio, connectors sem efeito
  externo, source links obrigatorios, audit/policy gates e operator review para
  acao externa.
- Cada empresa tambem expoe `enterprise-analysis` no command proprio:
  `atlas:ai:engineering-company enterprise-analysis`,
  `atlas:ai:research-domain --action=enterprise-analysis`,
  `atlas:ai:strategy-domain --action=enterprise-analysis`,
  `atlas:ai:finance-domain --action=enterprise-analysis`,
  `atlas:ai:marketing-domain --action=enterprise-analysis`,
  `atlas:ai:cyber-domain --action=enterprise-analysis`,
  `atlas:ai:personal-development-domain --action=enterprise-analysis`,
  `atlas:ai:automation-domain --action=enterprise-analysis` e
  `atlas:ai:operations-domain --action=enterprise-analysis`.
- Score estrutural pode passar de 9 quando os modelos operacionais e packets
  observados estiverem completos; `ok` nao espera calendario fixo.
- `company_buildout_score` mede consolidacao estrutural e pode chegar a 10;
  `operational_history_score` mede cobertura de ciclo operacional observado
  completo.
- Autonomia externa continua bloqueada sem operator mandate, rollback, incident
  route, certificacao de conectores e aceite de governanca.
- O ciclo pode refrescar somente o registro do dia atual quando o packet estiver
  incompleto; isso nao cria backfill nem aumenta `operating_review_days`.
- `operating_review_days` vem de dias distintos com `ai_domain_runtime_records`
  concluidos para cada dominio.
- Toda promocao exige receipts, Evidence Ledger, benchmark, rollback e review.
- Portfolio Governor inicia advisory e nao executa capital allocation real.

## Fluxo

1. Ler `DomainSeedManifests`.
2. Montar Company Registry.
3. Avaliar capabilities, safety, evidence, handoffs e operating model real.
4. Contar ciclos operacionais concluidos em `ai_domain_runtime_records`.
5. Calcular score ponderado.
6. Emitir blockers e proximas acoes.

## Regras para IA

Nao declare target 9 enquanto o comando retornar `status=attention`. Nao promova
empresa alterando apenas numero de stage; a promocao precisa de runtime,
evidencia, testes, benchmark, comando, metrica e ciclo observado proprio.
Nao preencher historico por metadado manual; use ciclos reais via Domain Runtime.

## Escopo de Implementacao

Este modulo entrega avaliador e governor read-only. O criterio forte exige que
Software, Research, Strategy, Finance, Marketing, Cyber, Personal Development,
Automation e Operations sejam tratadas como empresas completas. Enquanto qualquer
uma faltar cobertura funcional observada, cobertura de agentes, cobertura de
fluxos, execution contract, playbook, orchestration trace, flow evaluation,
management snapshot, cross-company handoff execution, work products observados,
integracoes, jobs recorrentes observados ou metricas, o modelo operacional fica
incompleto.
Quando isso estiver completo e houver ciclo observado atual por empresa, o report
pode atingir target 9 sem esperar uma janela fixa de calendario.

## Dependencias

Depende de Domain Runtime, Knowledge Governance, Evidence Ledger e Decision
Receipt. Futuramente deve consumir Truth Graph e Cartographic OS.

## Evidencias

Evidencia primaria: comando `atlas:ai:autonomous-holding --json` e teste unitario
focado.

## Riscos

O risco principal e usar a existencia de muitos manifests como prova de holding
autonoma. O score separa estrutura pronta de autonomia externa: sem mandate,
rollback, incident route, certificacao de conectores e governanca, ainda nao ha
autonomia externa mesmo com operating model completo.

## Exemplos

No estado atual, se apenas manifests e alguns runtimes estiverem presentes, o
report deve apontar `attention` e listar blockers por dimensao ausente. Isso e
intencional: o objetivo agora e maturidade operacional real, nao formato minimo.

## Proximas Acoes

Completar dominio por dominio com funcoes, agentes, fluxos, work products,
integracoes, cadencias, metricas declaradas e observadas, ciclo operacional
observado, comandos verificaveis e rotinas internas executadas. Depois rodar
Rivals externos e revisao final.
