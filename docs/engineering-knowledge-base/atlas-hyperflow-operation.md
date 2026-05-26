---
id: atlas-hyperflow-operation
type: engineering_knowledge
title: Operacao Atlas Hyperflow
status: active
category: atlas-ai
priority: 100
summary: Contrato canonico da Operacao Atlas Hyperflow: transformar Atlas AI na interface principal de engenharia agentica, subordinada ao Atlas Agentic Engineering OS, substituindo Claude Code/Codex por roteamento inteligente, specialist flows profundos, Atlas Dev, Atlas Forge, evidencia, telemetry e self-improvement.
tags:
  - atlas-ai
  - hyperflow
  - engineering-os
  - router
  - specialist-flows
  - atlas-dev
  - atlas-forge
capabilities:
  - atlas_hyperflow_engineering_runtime
  - intent_kernel
  - router_runtime
  - hyperflow_specialist_flow_consumption
  - atlas_dev_delegation
  - atlas_forge_promotion
  - evidence_telemetry_loop
  - hyperflow_100x_certification
decisions:
  - Atlas AI deve ser a interface principal de engenharia agentica, nao apenas um chat acima de ferramentas externas.
  - O objetivo nao e copiar Claude Code/Codex; e supera-los por orquestracao, contratos, evidencia, memoria, delegacao Dev/Forge e self-improvement.
  - O usuario nao deve precisar escolher manualmente pesquisar, planejar, debugar, revisar, programar ou promover para Forge em casos comuns.
  - Atlas AI deve entender intencao, risco, evidencia faltante, flow correto e nivel de engenharia necessario.
  - Fechar Router para baixo vem antes de polir UX pesada; a UX deve consumir contratos prontos, nao compensar backend incompleto.
maintenance:
  - Atualize este doc quando mudar a ambicao, fase, gate ou criterio de certificacao do Hyperflow.
  - Nao use este doc para detalhar internals de cada runtime; use os docs relacionados como autoridade local.
  - Mantenha este doc como mapa executivo e operacional da operacao completa.
related_paths:
  - docs/engineering-knowledge-base/atlas-agentic-software-engineering-authority-map.md
  - docs/engineering-knowledge-base/atlas-agentic-engineering-os.md
  - docs/engineering-knowledge-base/atlas-ai-router-runtime-enterprise-upgrade.md
  - docs/engineering-knowledge-base/atlas-dual-core-engineering-system.md
  - docs/engineering-knowledge-base/atlas-hyperflow-certification-runbook-v1.md
  - docs/engineering-knowledge-base/atlas-hyperflow-completion-audit-v1.md
  - docs/engineering-knowledge-base/atlas-dev-efficient-programming-flow-v1.md
  - docs/engineering-knowledge-base/atlas-forge-operating-system.md
  - docs/engineering-knowledge-base/atlas-ai-router-flow-routing-contract-v1.md
  - app/Services/Ai/Router/AtlasAiRouterService.php
  - app/Services/Ai/Router/AtlasAiSpecialistFlowRuntimeService.php
  - app/Services/Ai/Router/AtlasAiSpecialistFlowExecutionService.php
  - docs/engineering-knowledge-base/atlas-canonical-glossary-and-naming.md
doc_schema: atlas_canonical_module_doc.v1
graph_id: atlas-hyperflow-operation
graph_title: Operacao Atlas Hyperflow
graph_world: atlas
graph_layer: system
graph_kind: contract
graph_parent: atlas-ai-canonical-architecture-index
graph_status: active
graph_source: repo
human_name: Operacao Atlas Hyperflow
canonical_name: Operacao Atlas Hyperflow
technical_name: atlas-hyperflow-operation
cartography_type: contract
canonical_source: docs/engineering-knowledge-base/atlas-hyperflow-operation.md
owner: atlas-ai
repo_paths:
  - docs/engineering-knowledge-base/atlas-hyperflow-operation.md
allowed_changes:
  - Refinar fases, gates, benchmark e matriz de substituicao Claude Code/Codex.
  - Adicionar flows novos quando houver contrato, execution packet, receipt e teste.
forbidden_changes:
  - Declarar 100x sem benchmark e evidencia verificavel.
  - Transformar Atlas AI em simples wrapper de Claude Code/Codex.
  - Fundir Atlas Dev e Atlas Forge em um runtime unico.
  - Permitir flow executar fora do contrato, sem receipt ou sem telemetry.
depends_on:
  - atlas-ai-router-runtime-enterprise-upgrade
  - atlas-dual-core-engineering-system
  - atlas-dev-efficient-programming-flow-v1
  - atlas-forge-operating-system
flows_to:
  - atlas_ai
  - atlas_ai_router_runtime
  - atlas_research
  - atlas_debug
  - atlas_review
  - atlas_explain
  - atlas_plan
  - atlas_dev
  - atlas_forge
unlocks:
  - atlas_ai_as_primary_engineering_interface
  - claude_codex_replacement_strategy
  - hyperflow_100x_certification
governs:
  - atlas.hyperflow
  - atlas_ai.primary_engineering_os
  - atlas_ai.router_to_execution
evidence:
  - docs/engineering-knowledge-base/atlas-hyperflow-operation.md
  - docs/engineering-knowledge-base/atlas-ai-router-runtime-enterprise-upgrade.md
required_tests:
  - "php artisan test tests/Unit/Ai/Router tests/Feature/Ai/AtlasAiRouterRuntimeTest.php tests/Feature/Ai/AtlasAiRouterRuntimeBootstrapApiTest.php tests/Feature/Ai/AtlasAiRouterRuntimeReadinessApiTest.php"
  - "php artisan atlas:engineering:knowledge docs-health --json"
requires_evidence: true
risk_level: high
ai_entrypoints:
  - Leia este doc antes de discutir a meta de substituir Claude Code/Codex pelo Atlas AI.
  - Leia Router Runtime Enterprise Upgrade antes de implementar qualquer parte tecnica do Hyperflow.
ai_usage_notes:
  - Trate "100x" como objetivo de sistema medido por fluxo completo, nao como claim sem benchmark.
  - Primeiro feche backend/flows/gates; depois acople UX.
quality_gates:
  - router-chooses-correct-flow
  - specialist-flows-have-real-contracts
  - dev-forge-delegation-explicit
  - evidence-and-receipts-persisted
  - telemetry-visible
  - backend-certification-passed
  - hyperflow-rivals-benchmark-passed
failure_modes:
  - Router virar seletor superficial sem execucao especializada.
  - Atlas AI depender do usuario para escolher manualmente todo flow.
  - Research inventar fonte ou esconder incerteza.
  - Debug fingir causa raiz sem logs/repro.
  - Review responder como resumo e enterrar findings.
  - Dev absorver obra que deveria virar Forge.
  - Forge ser chamado para trabalho pequeno e matar velocidade.
observability_signals:
  - intent_class
  - flow_id
  - routing_reason
  - delegation_status
  - receipt_id
  - contract_hash
  - evidence_count
  - first_pass_success
  - flow_quality_score
  - provider_comparison_score
next_actions:
  - Completar backend dos specialist flows profundos.
  - Criar Hyperflow backend certification.
  - Criar benchmark comparativo contra Claude Code/Codex.
  - Depois acoplar UX Desktop ao bootstrap/readiness/flow-status.
line_limit: 700
---
# Operacao Atlas Hyperflow

## Resumo

Operacao Atlas Hyperflow e a iniciativa para transformar o Atlas AI na
interface principal de engenharia agentica, subordinada ao Atlas Agentic
Engineering OS.

O objetivo nao e criar um clone de Claude Code ou Codex. O objetivo e fazer o
Atlas AI substituir esses fluxos no uso diario e supera-los por arquitetura:
roteamento de intencao, flows especialistas, Atlas Dev, Atlas Forge, evidencia,
receipts, telemetry, memoria operacional e self-improvement.

Nome tecnico:

```text
Atlas Hyperflow Engineering Runtime
```

Marco final:

```text
Atlas Hyperflow 100x Certification
```

## Papel no Atlas

Hyperflow e a camada que torna Atlas AI a entrada principal de engenharia. Ele
coordena Router Runtime, specialist flows, Atlas Dev, Atlas Forge, evidence,
telemetry e self-improvement.

## Onde Se Encaixa

Fica acima dos runtimes de execucao e abaixo da surface humana:

```text
Atlas AI Surface
-> Hyperflow
-> Router Runtime
-> Specialist Flow / Atlas Dev / Atlas Forge
-> Evidence / Telemetry / Learning
```

## Contratos

Contratos canonicos da operacao:

- `atlas.ai.router.flow_decision.v1`
- `atlas.ai.intent_kernel.v1`
- `atlas.ai.specialist_flow_runtime.v1`
- `atlas.ai.specialist_flow_receipt.v1`
- `atlas.ai.specialist_flow_execution.v1`
- `atlas.ai.flow_status.v1`
- `atlas.ai.router_runtime_readiness.v1`
- `atlas.ai.router_runtime_bootstrap.v1`
- `atlas.ai.hyperflow_certification.v1`
- `atlas.ai.hyperflow_rivals_battery.v1`
- `atlas.hyperflow.external_rivals_evidence_pack.v1`
- `atlas.ai.hyperflow_completion_audit.v1`

`atlas.ai.specialist_flow_execution.v1` deve carregar, para todos os specialist
flows, `provider_prompt_contract`, `response_shape`, `audit_checks`,
`quality_rubric`, `completion_checks` e `failure_modes`. Esse e o contrato que
impede research/debug/review/explain/conversation/plan de virarem respostas
genericas sem criterio de conclusao.

## Fluxo

Fluxo alvo:

```text
prompt -> intent -> risk/evidence -> router -> flow -> contract -> execution/delegation -> receipt -> telemetry -> learning
```

## Tese

Claude Code e Codex operam principalmente como agentes de programacao:

```text
prompt -> interpretacao -> plano/pesquisa/debug/codigo -> resposta
```

Atlas Hyperflow deve operar como um sistema operacional de engenharia:

```text
prompt
-> intent kernel
-> risk/evidence assessment
-> router runtime
-> specialist flow ou Atlas Dev ou Atlas Forge
-> execution contract
-> receipt/hash
-> evidence collection
-> telemetry
-> learning loop
-> next action
```

O "100x" nao deve ser tratado como marketing. Ele significa ganho sistemico em:

- menos escolhas manuais do operador
- menos prompts necessarios para chegar no flow certo
- mais acerto em prompt ambiguo
- mais evidencia e menos alucinacao
- melhor separacao entre tarefa pequena, media e Obra
- rastreabilidade completa
- aprendizado a partir dos erros
- menor custo cognitivo para o operador

## Principio Central

O Atlas AI nao deve perguntar primeiro "qual ferramenta voce quer usar?".

Ele deve inferir:

- qual e a intencao real
- qual e o risco
- que evidencia falta
- qual flow e dono do trabalho
- se precisa planejar antes de executar
- se precisa pesquisar antes de responder
- se precisa debugar, revisar, explicar ou programar
- se deve delegar para Atlas Dev
- se deve promover para Atlas Forge
- qual prova precisa deixar registrada

## Camadas

1. Intent Kernel: classifica conversa, explain, research, debug, review, plan,
   dev, forge, mixed intent e unsafe/blocked.
2. Router Runtime: escolhe `atlas_conversation`, `atlas_explain`,
   `atlas_research`, `atlas_debug`, `atlas_review`, `atlas_plan`,
   `atlas_dev` ou `atlas_forge`. Router decide; flow executa.
3. Specialist Flow Runtime: Research exige fontes/incerteza; Debug exige
   sintomas/logs/repro; Review e findings-first; Explain e read-only;
   Conversation conversa e sugere handoff; Plan decompõe risco/dependencias.
4. Atlas Dev: executor leve/medio para bug fix, feature pequena/media,
   refactor limitado, test loop, debug/review com workspace e patch com
   evidencia. Nao deve virar Forge mini.
5. Atlas Forge: executor pesado para Obras grandes, multi-modulo, longas,
   enterprise, alto risco ou com SDD/governanca. Nao substitui Dev.
6. Execution Contract Layer: schema, flow dono, handler, side effect policy,
   evidence, forbidden actions, response shape, delegation, receipt e hash.
7. Evidence & Telemetry Layer: mede flow, motivo, confidence, override,
   delegacao, tempo, custo, qualidade, first-pass, rework e falhas.
8. Self-Improvement Layer: erros de roteamento, evidencia, delegacao ou
   qualidade viram dados para melhorar o proximo ciclo.

## Estrategia De Substituicao Claude Code/Codex

Substituir nao significa remover providers. Providers podem continuar existindo
como motores. O que muda e a interface operacional:

```text
Antes: operador -> Claude Code/Codex
Depois: operador -> Atlas AI -> Hyperflow -> Dev/Forge/provider/tools
```

O Atlas AI vira a porta principal. Claude Code/Codex deixam de ser o produto e
viram, quando necessario, capacidade interna ou rival benchmark.

## Surface Plane e Rich Input

Atlas Unified Rich Input Runtime e a capability **compartilhada** entre Atlas Desktop, Atlas Mobile e Forge/Obras para imagens, documentos, URLs/YouTube, texto/codigo, source manifest, token estimate e payload canonico.

Atlas Desktop AI Hyperflow Integration Canon:

- Desktop Hyperflow Runtime Integration: Desktop deve enviar o contrato Surface Plane para o Hyperflow antes do router legado.
- Atlas Unified Rich Input Runtime: mobile, desktop e Forge devem consumir o canon compartilhado, sem runtimes paralelos.
- Forge Rich Input Adapter: Obras recebem o mesmo payload canonico sem raw text sensivel.
- No Legacy Programming Dev Default: auto/auto permanece o default; programming.dev so entra por decisao de rota.
- Composer to Hyperflow Enterprise Path: composer, bridge, backend e resource precisam preservar o envelope enterprise.

Detalhes de Desktop Surface, Rich Input e integracao Hyperflow foram extraidos para manter este contrato como mapa executivo:

- `atlas-hyperflow-operation-surface-rich-input.md` — contrato anti-regressao da Surface Plane, Rich Input compartilhado e integracao Desktop Hyperflow.

## Fases

1. Router Para Baixo: backend antes de UX pesada; Router real, specialist
   contracts, receipts, persistence, delegation, telemetry, readiness,
   bootstrap, flow-status e tests.
2. Specialist Flows Profundos: Research, Debug, Review, Explain, Conversation
   e Plan viram especialistas reais com handlers e matriz de edge cases.
3. Atlas Dev Superior A Claude Code/Codex: Senior Engineer Loop decide quando
   planejar, executar, testar, depurar, registrar evidencia e promover.
4. Atlas Forge Para Obras: handoff com promotion reason, obra intake packet,
   SDD readiness, evidence refs, expected duration e governance gate.
5. Observabilidade E Aprendizado: agregados por flow, qualidade, delegacao,
   override, falha e comparacao com Claude Code/Codex.
6. Hyperflow 100x Certification: endpoints `/ai/hyperflow/certification`,
   `/ai/hyperflow/rivals-battery`, `/ai/hyperflow/rivals-battery/prepare`,
   `/ai/hyperflow/rivals-battery/run` e
   `/ai/hyperflow/rivals-battery/external-evidence`, incluindo template,
   candidates, runbook, preflight, export e import;
   mantém suite canonica com casos reais de bug/feature ambiguos, pesquisa,
   review, debug, plano, conversa e Forge. A battery mede acerto de flow,
   delegacao, contracts, receipts e auditabilidade, mas nao destrava claim contra
   Claude Code/Codex sem execucao externa aprovada. O gate final exige
   `external_provider_call=true`, baselines `claude_code` e `codex`/`codex_cli`,
   `protocol_valid=true`, `comparable=true`, `operator_approved=true`,
   `evidence_receipt_hash` de 64 chars e outcome aceito.

## Certificacao Hyperflow

A certificacao operacional fica no runbook canonico
`atlas-hyperflow-certification-runbook-v1.md`.

Resumo do caminho:

```text
prepare -> run -> template/preflight/export -> import -> certify
```

O caminho preferido para evidencia externa e exportar runs Forge/Rivals reais
contra Claude Code e Codex CLI, importar o evidence pack Hyperflow e entao
rodar `php artisan atlas:ai:hyperflow certify --json`. A certificacao falha
fechado enquanto `rivals_battery.external_provider_execution` nao estiver
provado.

### Readiness da Etapa 1 (Hyperflow + Specialist Flows)

A Etapa 1 ganhou um gate dedicado de prontidao em
`AtlasHyperflowSpecialistFlowsReadinessService` (schema
`atlas.ai.hyperflow_specialist_flows_readiness.v1`). O comando

```
php artisan atlas:ai:hyperflow-specialists readiness --json
```

cobre 13 checks especificos do substrato Specialist Flows: entry wired,
auto/auto preservado, 14 flows registrados, intents nao-programacao nunca
caem em `atlas_dev`, runtime emite contrato com receipt, execution emite
handler com rubric/completion_checks/failure_modes, receipts/evidence/
telemetry disponiveis, handoff Dev/Forge explicito, alem de invariantes
`benchmark_not_run = true` e `allows_external_superiority_claim = false`.
O gate NAO roda bateria de rivals e NAO declara Atlas completo — declara
apenas prontidao da Etapa 1 quando todos os 13 checks passarem.

## Gates De Conclusao

Hyperflow nao esta completo ate que todos os gates abaixo passem:

1. Router escolhe flow correto em matriz de prompt ambiguo.
2. Cada specialist flow tem contrato proprio, handler e tests.
3. Dev bridge executa programacao leve/media com evidencia.
4. Forge handoff existe, e Obra nao fica presa no Dev.
5. Receipts e contract hashes persistem.
6. Telemetry agrega flow, qualidade, delegacao e falha.
7. Bootstrap/readiness/flow-status cobrem Desktop/API.
8. Docs canonicos explicam a operacao para outra IA.
9. Benchmark contra Claude Code/Codex existe.
10. Certificacao final prova ganho operacional, sem declarar 100x sem evidencia.

## Regras para IA

- Nao declarar Hyperflow completo sem certificacao final.
- Nao declarar 100x sem benchmark comparativo.
- Nao transformar Atlas AI em wrapper passivo de Claude Code/Codex.
- Nao fundir Dev e Forge.
- Nao permitir specialist flow sem contrato, receipt e audit checks.
- Primeiro backend/flows/gates; depois UX pesada.

## Escopo de Implementacao

Escopo incluido:

- Router Runtime
- Specialist Flows profundos
- Atlas Dev delegation
- Atlas Forge promotion
- execution contracts
- receipts
- telemetry
- readiness/bootstrap/flow-status
- benchmark e certificacao

Fora de escopo deste doc:

- detalhes internos completos do Forge
- detalhes internos completos do Dev
- design visual final da UI

## Dependencias

- Atlas AI Router Runtime Enterprise Upgrade
- Atlas Dual-Core Engineering System
- Atlas Dev Efficient Programming Flow
- Atlas Forge Operating System
- Atlas AI Router Flow Routing Contract

## Evidencias

Evidencias esperadas:

- docs canonicos atualizados
- testes Router/flows/Dev bridge/telemetry verdes
- readiness Hyperflow/backend passando
- flow-status emitindo receipt/hash/next action
- benchmark comparativo registrado
- certificacao final com resultado verificavel

## Riscos

- Declarar 100x cedo demais.
- Criar UX bonita sobre backend incompleto.
- Router virar executor.
- Dev virar catch-all e absorver Obra.
- Forge ser usado para tarefas pequenas.
- Specialist flow responder sem evidencia.

## Exemplos

Exemplos de roteamento esperado:

- "implemente endpoint" com workspace -> `atlas_dev`
- "explique esta arquitetura" -> `atlas_explain`
- "pesquise o estado da arte" -> `atlas_research`
- "debug esse stack trace" -> `atlas_debug` ou delegacao Dev
- "review deste diff" -> `atlas_review`
- "obra enterprise multi-modulo" -> `atlas_forge`

## Proximas Acoes

1. Completar specialist flows profundos.
2. Fortalecer Dev/Forge handoff.
3. Criar observabilidade agregada por flow.
4. Criar Hyperflow backend certification.
5. Criar benchmark contra Claude Code/Codex.
6. Depois acoplar UX Desktop.

## Definicao De Pronto

UX vem depois do motor: `backend/flows/gates -> bootstrap/readiness/flow-status
-> Desktop UX`. Hyperflow so esta pronto quando backend decide/delega/audita sem
escolha manual comum, flows tem comportamento distinto, prompts ambiguos vencem
baseline Claude Code/Codex, todo resultado relevante deixa receipt/evidence e a
certificacao final passa. Status antes disso:
`advanced_backend_foundation_not_final_certified`.
