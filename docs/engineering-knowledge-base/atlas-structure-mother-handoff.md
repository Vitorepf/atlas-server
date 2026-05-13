---
id: atlas-structure-mother-handoff
type: engineering_knowledge
title: Atlas Structure Mother Handoff
status: active
category: architecture-handoff
priority: 99
summary: Handoff compacto para novas sessoes continuarem a estrutura mae enterprise do Atlas AI sem depender do historico da conversa.
tags:
  - atlas-ai
  - structure-mother
  - handoff
  - governance
  - backend
capabilities:
  - architecture_handoff
  - documentation_governance
  - session_bootstrap
decisions:
  - Voice/LiveKit permanece scaffold governado e estacionado fora do caminho critico.
  - Memory/Open Brain, Capture, Tasks, Tools, Long-Running Work, Rivals readiness and Proactive contracts now have read-only audit surfaces.
  - Completion requires the real Rivals scored review due on 2026-06-12; do not synthesize scores.
maintenance:
  - Atualizar ao fechar blocos estruturais grandes.
  - Manter objetivo e abaixo de 220 linhas.
related_paths:
  - docs/engineering-knowledge-base/atlas-ai-canonical-architecture-index.md
  - docs/engineering-knowledge-base/atlas-ai-documentation-operating-system.md
  - docs/engineering-knowledge-base/atlas-ai-pipeline.md
  - docs/engineering-knowledge-base/architecture-audit/implemented-vs-scaffold-matrix.md
doc_schema: atlas_canonical_module_doc.v1

graph_id: atlas-structure-mother-handoff

graph_title: Atlas Structure Mother Handoff

graph_world: atlas

graph_layer: system

graph_kind: module

graph_parent: atlas-ai-canonical-architecture-index

graph_status: active

graph_source: repo

owner: architecture-handoff

repo_paths:
  - docs/engineering-knowledge-base/atlas-structure-mother-handoff.md

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
  - architecture-handoff

evidence:
  - docs/engineering-knowledge-base/atlas-structure-mother-handoff.md

required_tests:
  - "php artisan atlas:engineering:knowledge docs-health --json"

requires_evidence: true

risk_level: medium

visual_tags:
  - system
  - module
  - architecture-handoff

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
# Atlas Structure Mother Handoff

## Missao

A estrutura mae do Atlas AI e o backend governado que transforma o Atlas em uma camada superior aos providers, nao um wrapper. O Kernel decide dominio, fluxo, provider/modelo, politica, memoria, gates, evidencias e receipts. Runtimes, surfaces e tools executam, mas nao decidem.

## Ordem Correta Dos Modulos

1. Memory/Context Engine.
2. Knowledge Base/Open Brain.
3. Inbox/Capture Pipeline.
4. Task/Agent Orchestration.
5. Tool/Action Runtime.
6. Autonomy/Long-Running Work.
7. Evaluation/Rivals Framework.
8. Notification/Proactive Layer.
9. Voice/LiveKit Runtime.

Audit canônico atual:

```bash
php artisan atlas:ai:structure-mother-audit --hours=720 --workspace=/Users/vitorepf/develop/Atlas/atlas-server --json
```

Esse comando agrega os oito modulos sem chamar provider, executar runtime,
promover memoria, resolver Inbox ou tocar Voice/Self-Construction. Ele e o gate
para decidir se `update_goal` e permitido. `completion_checklist` e
`prompt_to_artifact_checklist` mapeiam requisitos, artefatos, comandos de
evidencia, regras e blockers.

Estado atual validado em 2026-05-13 14:46 UTC: `implementation_complete=true`,
`complete=false`, `status=operational_blocked`, `ready_count=8` e
`completion_gate.update_goal_allowed=false`. Os oito modulos tem superficie
governada pronta, mas a conclusao operacional ainda depende de calendario/humano.
Nao ha acao tecnica restante nesta lane que possa ser executada sem fabricar
evidencia. O `action_summary` esta em espera de calendario: Rivals fica em
espera ate `review_due_at`.

Superficies de revisao humana principais:
`atlas:cli:inbox review-critical`,
`atlas:memory:review-queue --area=semantic_curation --area=memory_delta --json`
e `atlas:semantic:curation-review <proposal-id> --decision=accept
--promote-to-memory --memory-type=strategic_insight --promoted-by=<operator>
--json`. Elas mostram resumo humano, exigem operador e registram receipts.
Toda acao executada via `InboxActionRegistry` registra Evidence Ledger com
`atlas.inbox_action.receipt.v1` e `receipt_hash` SHA-256, inclusive
`mark_read`, `snooze`, `dismiss`, Rivals review e provider cost-rate actions.

Mobile Inbox expoe `GET /v1/mobile/inbox/critical-review` com resumo humano,
metricas visiveis, `decision_options`, endpoints permitidos e receipt
`atlas.inbox_action.receipt.v1`.

Atlas Rivals/Atlas-Bench expoem bateria real pendente: `battery_execution_contract`
explica zeros sem bateria comparavel; o app tem launcher `Justa oficial`,
`Mesmo modelo` e `Maximo`; o backend exige `rivals/battery-plan`, `plan_hash`,
revisao e aceite de custo antes de qualquer run.
Rivals e importante para claims enterprise, mas nao deve capturar a prioridade
imediata da estrutura mae enquanto Memory/Open Brain/Capture/Tasks/Tools ainda
precisarem consolidacao. Estado correto: manter gates fortes e backlog claro,
sem declarar maturidade final. Claim oficial so pode passar com amostra release
comparavel minima, protocolo/gates/pass_without_human em 100%, baseline real,
replay artifact verificavel e export auditavel.
Caso com protocolo Atlas invalido (`atlas_protocol_invalid`) nao conta como
vitoria do Claude, derrota do Atlas nem caso comparavel. O report normaliza
scorecards legados para `winner=null` quando validade experimental falha. Isso
preserva o principio A/B: workspace contaminado, provider/model drift, fallback,
replay ou gates falhos bloqueiam comparacao em vez de enviesar o placar.
No app, a logica pura do launcher fica em `lib/rivalsBatteryModels.ts` e e
coberta por `scripts/rivals-battery.test.ts`, incluido em `npm run
test:engineering`; isso verifica os modos `Justa oficial`, `Mesmo modelo` e
`Maximo` sem disparar providers.
O `operator_action_plan` tambem declara `POST /ai/rivals-strategy/review` para
registrar scores reais quando `review_due_at` vencer; scores sinteticos seguem
proibidos para liberar P4/completion gate.
O mesmo plano agora expoe `approve_rivals_programming_real_battery` como acao
operacional separada: preflight `rivals runbook/readiness/report` e seguro, mas
`quick|medium|full` so pode executar com `--confirm-runbook-reviewed` e
`--confirm-provider-cost`.
Frontend/design harness tambem aparece como `run_frontend_design_harness_enterprise_receipts`:
acao local, sem custo/provider externo, deferivel por decisao de produto/design,
mas com receipts obrigatorios de `atlas.programming.frontend_design_harness.v1`,
visual multi-viewport, a11y/perf ou razao, asset provenance e 5D review.
O comando seguro de revisitas ja lista a proxima acao sem registrar score:
`php artisan atlas:ai:rivals-strategy due-reviews --due-days=30 --json` retorna
`due_review_count=1`, `review_id=019e1f8c-ebd7-727b-b84e-0bc5e90dfa6a` e
`review_due_at=2026-06-12T04:16:29Z`. O teste
`AtlasAiRivalsStrategyCommandTest --filter=due_reviews` cobre a listagem e
`InboxLedgerProjectionActionTest --filter=rivals_review` cobre o registro via
Inbox/action ledger com scores humanos.

Proactive Layer reporta `mobile_push_configuration.delivery_diagnostics`: mobile,
devices, tokens, permissoes e ausencia de tentativa, sem expor token/device id.
Push pendente pode ser auditado com `atlas:cli:mobile replay-push --json`.
Disparo real por CLI ou `POST /ai/mobile/push/replay` e fail-closed: exige
dry-run recente com candidatos, `apply`, confirmacao externa e motivo de
operador. Toda chamada grava receipt `mobile.push_replay.requested` sem
token/device id.
O app consome `GET /ai/structure-mother-audit` para mostrar esse plano no
Atlas-Bench sem depender de JSON cru ou CLI.

## Voice/LiveKit

Voice esta em scaffold governado e validado, mas estacionado fora do caminho
critico. LiveKit local, token issuer, SDK, probes, runtime certification e gates
foram provados; worker/runtime real nao foram promovidos. Motivo: voz e surface
de experiencia. Memory, Open Brain e Orchestration sao nucleo funcional.

## Contratos E Padroes

- Kernel e autoridade de provider, domain, flow, policy e memory.
- Surface, provider, tool e domain nao decidem nem burlam policy.
- Runtime nao executa sem Decision Receipt.
- Promotion exige review/receipt auditavel.
- Tudo relevante vira Evidence Ledger.
- Tudo repetido vira Core.
- Fail-closed por padrao.
- Secrets, tokens e audio cru nunca entram em logs, docs ou ledger.
- Mobile/frontend podem refletir read models governados quando isso remove
  ambiguidade operacional; nao podem decidir, resolver gate ou executar provider.

## Validacoes Obrigatorias

Comandos recorrentes:

```bash
php artisan test <tests focados>
php artisan atlas:ai:structure-mother-audit --hours=720 --workspace=/Users/vitorepf/develop/Atlas/atlas-server
php artisan atlas:ai:structure-mother-audit --hours=720 --json
php artisan atlas:ai:architecture-validate --json
php artisan atlas:ai:runtime-boundary --json
atlas engineering knowledge docs-health --json
atlas engineering knowledge sync --prune --json
atlas engineering knowledge index-code --prune --summary-only --json
git diff --check
```

Quando tocar em voice:

```bash
php artisan atlas:ai:voice runtime-certify --require-sdk --callback-loop-wired --production-sdk-loop-wired --json
PYTHONDONTWRITEBYTECODE=1 PYTHONPATH=runtimes/python/voice_realtime:runtimes/python/voice_realtime/tests runtimes/python/voice_realtime/.venv/bin/python -m unittest discover -s runtimes/python/voice_realtime/tests
```

## Docs Canonicos Para Ler Primeiro

- `docs/engineering-knowledge-base/atlas-ai-canonical-architecture-index.md`
- `docs/engineering-knowledge-base/atlas-ai-documentation-operating-system.md`
- `docs/engineering-knowledge-base/atlas-ai-pipeline.md`
- `docs/engineering-knowledge-base/architecture-audit/implemented-vs-scaffold-matrix.md`
- `docs/engineering-knowledge-base/atlas-ai-voice-realtime-surface.md`
- `runtimes/python/voice_realtime/README.md`

Para Memory/Open Brain, localizar e ler docs/classes existentes antes de criar qualquer contrato novo.

## Restricoes Criticas

- Nao reverter mudancas existentes sem pedido explicito.
- Preservar trabalho de outras frentes/agentes.
- Mobile/frontend so podem refletir read models governados.
- Nao criar fluxo paralelo se ja houver contrato/doc.
- Nao promover scaffold como implemented.
- Nao usar pip global.
- Nao expor secrets.
- Atualizar doc dono/KB e respeitar limites de linhas quando mudar contrato.

## Estado Atual Dos Oito Modulos

- Memory/Context Engine: pronto por scorecard, recall metadata, privacy e
  quality; Open Brain aplicado a Programming agora injeta contexto automatico
  para dev/debug/review/repair, incluindo flow, retomada, stages,
  selected_files, runs/traces anteriores e decisoes previas.
- Knowledge Base/Open Brain: pronto por docs health, sync/index-code e Open Brain safety/audit.
- Code Intelligence: `EngineeringCodeIntelligenceService` indexa modulos,
  simbolos, rotas, comandos, migrations, testes e doc links; agora tambem
  usa `nikic/php-parser` para relacoes PHP AST (`php_use_ast`,
  `class_constant_ast`, `test_symbol_reference_ast`) e persiste em metadata
  `dependency_edges`, `symbol_references`, `test_targets` e
  `code_intelligence_depth=symbols_dependencies_tests_docs` para conectar
  codigo, testes e documentacao no context pack.
- Inbox/Capture Pipeline: pronto por `capture-inbox-pipeline-report`; legacy capture backfill aplicado sem abrir provider/context/memory.
- Task/Agent Orchestration: pronto por receipt/hash-chain report e backfill local seguro.
- Programming Orchestration: `AtlasProgrammingOrchestrator` emite
  `atlas.programming.orchestration.v1` para todos os planos de programacao,
  com retomada por `parent_plan_id`, ordem canonica `plan/review/patch/test/
  repair`, receipts `atlas.programming.stage_receipt.v1` por etapa e regra de
  superficie unica para CLI/app/chat.
- Tool/Action Runtime: alem do report/evidence, `AiToolRuntime` tem aliases
  operacionais canonicos para programacao: `programming.test`,
  `programming.lint`, `programming.quality_scan`,
  `programming.visual_smoke`, `programming.git_diff` e
  `programming.code_search`. Cada resultado recebe
  `atlas.tool_action_runtime.contract.v1` com dry-run, evidence hashes,
  rollback/checkpoint quando existir e bloqueio explicito de provider/control
  plane.
- Autonomy/Long-Running Work: pronto como report/autonomy receipt read-only; baseline declarada com 5 schedules desabilitados via `atlas:ai:long-running-work-declare-baseline --apply --json`, sem dispatch, `enabled=false` e `next_run_at=null`.
- Evaluation/Rivals Framework: implementado; P4 operacional bloqueado ate review real pontuada.
- Notification/Proactive Layer: pronto; `active_critical_insight_item_count=0`,
  cost rates claros e push diagnostics sem expor token/device id.

## Auditoria Da Meta Programacao

Esta auditoria cobre a meta operacional: fortalecer a estrutura mae para
programacao antes de voltar para Voice, Self-Construction amplo ou UI/design.

| Item | Status | Evidencia |
| --- | --- | --- |
| Memory/Open Brain aplicado a programacao | Entregue | `AtlasOpenBrainContextInjectionService` injeta `summary.programming_context` e `## Programming Context` para dev/debug/review/repair com docs, Code Intelligence, historico, traces e decisoes previas. Teste: `AtlasOpenBrainContextInjectionServiceTest`. |
| Programming Orchestration | Entregue | `AtlasProgrammingOrchestrator` emite `atlas.programming.orchestration.v1`, `plan_id`, `parent_plan_id`, stages `plan/review/patch/test/repair`, receipts por etapa e regra unica para CLI/app/chat. Teste: `AtlasProgrammingOrchestratorTest`. |
| Tool Runtime real para programacao | Entregue | `AiToolRuntime` expoe `programming.test`, `programming.lint`, `programming.quality_scan`, `programming.visual_smoke`, `programming.git_diff` e `programming.code_search`, todos com `atlas.tool_action_runtime.contract.v1`, dry-run, evidence hashes e rollback/checkpoint quando aplicavel. Teste: `AiToolRuntimeTest`. |
| Code Intelligence mais profundo | Entregue | `EngineeringCodeIntelligenceService` usa `nikic/php-parser` para relacoes PHP AST e indexa dependencias, symbol references, test targets e doc links. Teste: `AtlasEngineeringKnowledgeBaseTest --filter=code_intelligence`; index real: `index-code --prune --summary-only --json`. |
| Rivals-Programming | Pronto para execucao governada, nao executado | Bateria real contra providers externos exige revisao/custo do operador. O backend de plano/gate/hash foi validado sem provider externo por `EngineeringHarnessRunnerTest --filter='rivals_battery|fair_claude_runbook|fair_claude_report|readiness'` e `EngineeringBenchmarkFairClaudeScorecardTest`. |
| Frontend/design harness | Backend governado entregue; craft visual deferido | `programming.frontend` agora tem flow/profile proprio e `AtlasProgrammingOrchestrator` emite `atlas.programming.frontend_design_harness.v1` em plano/dispatch/completion. A tela/design final fica para Claude; Atlas governa context pack, gates e receipts. |

## Blockers Reais

1. Rivals/P4: primeira review real vence em 2026-06-12 04:16:29 UTC. Nao registrar score
   sintetico de regret/alignment/agency.
2. P6/P7 continuam futuros: presence/eclipse amplo e memoria longitudinal.

## Registro Enterprise De Fechamento

Este registro e a fila oficial para fechar a estrutura mae sem gastar provider
externo por engano e sem promover claim sem evidencia.

| Pendencia | Estado atual | Acao permitida agora | Acao proibida | Evidencia que libera |
| --- | --- | --- | --- | --- |
| Rivals/P4 review real | Bloqueado por calendario; proxima review em `2026-06-12T04:16:29Z` | Aguardar a data e manter o gate `no_synthetic_scores` ativo | Registrar regret/alignment/agency sintetico, declarar P4+ ou mudar `completion_gate` | `php artisan atlas:ai:rivals-strategy record-review --review-id='019e1f8c-ebd7-727b-b84e-0bc5e90dfa6a' --regret=<0-100> --alignment=<0-100> --agency=<0-100> --json` seguido de `structure-mother-audit` verde |
| Rivals-Programming bateria real | Pronto para execucao governada; nao executado por custo externo | Gerar/mostrar plano, hash e estimativa; executar somente com aceite humano explicito | Chamar Claude/Codex/Gemini sem `operator_plan_reviewed`, `operator_cost_acknowledged` e `rivals_battery_plan_hash` atual | Run real com providers, paired scorecard, replay manifest, gate de integridade, custo registrado e relatorio comparativo |
| Frontend/design harness | Backend governado entregue; craft visual/produto ainda separado | Usar `programming.frontend` com contrato `atlas.programming.frontend_design_harness.v1`; entregar craft visual com Claude quando solicitado | Misturar design/UI nessa lane backend ou declarar harness final completo sem visual/a11y/perf evidence | Runs com context pack frontend, asset provenance, visual/a11y/perf/state gates, 5D critique e receipts |
| P6 presence/eclipse amplo | Mobile tem opt-out, manual eclipse, quiet hours e receipts hash-only; ambiente amplo ainda futuro | Manter governanca pointer-only e read-model reports | Transformar proatividade em automacao invasiva, vigilancia ou dispatch autonomo | Presence surfaces com opt-in, retention, privacy, audit receipts, no-surveillance default e gates de friccao |
| P7 memoria longitudinal | Roadmap existe em `evolution/personal-longitudinal-roadmap.md`; evidence longitudinal ainda insuficiente | Continuar acumulando Ledger/Memory com privacy e revisoes | Prometer padroes de anos/decadas sem historico real | Projecoes longitudinais com dados suficientes, privacy vault, human review e provas de padroes nao-obvios |

Regra operacional: se uma pendencia exigir calendario, custo externo ou revisao
humana, o agente deve parar aquela trilha, registrar o motivo e seguir para a
proxima acao local verificavel. Nao entrar em loop de tentativa, nao rodar
provider externo para "provar" maturidade e nao reduzir gate para parecer verde.

`atlas:ai:qualitative-levels --hours=720 --json` agora expoe
`advanced_readiness.p6_presence_eclipse` e
`advanced_readiness.p7_longitudinal_memory`, ambos com `promotion_allowed=false`,
evidence existente, lacunas, e claims proibidos. Isso permite acompanhar P6/P7
sem fingir maturidade nem executar runtime novo.

`atlas:ai:structure-mother-audit --json` agora tambem expoe
`enterprise_closure_plan` (`atlas.structure_mother.enterprise_closure_plan.v1`)
para rastrear fechamento enterprise sem misturar os blockers:

- `rivals_p4_real_review`: bloqueado por calendario/revisao humana real.
- `rivals_programming_real_battery`: exige plano, hash, aceite de custo,
  providers reais, paired scorecard, replay manifest, integridade de artefatos
  e cost receipts. O audit expoe preflight seguro (`rivals runbook`,
  `rivals readiness`, `rivals report`, export e verify) e separa os comandos de
  execucao real, que so podem rodar com `--confirm-runbook-reviewed` e
  `--confirm-provider-cost`.
- `frontend_design_harness_enterprise_runs`: backend contract pronto; runs
  enterprise precisam receipts de visual/a11y/perf/state, provenance e 5D
  review.
- `p6_p7_advanced_readiness`: read model pronto, promocao bloqueada ate haver
  opt-in cross-surface, friccao/regret, anos de historico e privacy review.

## Resolvido Neste Ciclo

- Provider cost rates ativos foram preenchidos para `claude_cli`,
  `codex_cli` e `gemini_cli`; performance reports agora recuperam custo
  estimado e nao ficam opacos por `unknown cost`.
  `claude-sonnet-4-6` tem fonte oficial Anthropic: 3000/15000 uUSD por 1K
  tokens. `gpt-5.3-codex-spark` e `gemini-3.1-pro-preview` ficam marcados em
  metadata como estimativas operacionais/research-preview porque as fontes
  oficiais ainda nao publicam rate final especifico desses aliases.
- `claude-opus-4-7` foi registrado em `claude_cli` com 5000/25000 uUSD por 1K
  tokens conforme preco publico da Anthropic: US$5/M input e US$25/M output.
- `claude-opus-4-7` tambem foi registrado em `engineering_harness` com
  5000/25000 uUSD por 1K tokens; `atlas:ai:telemetry:cost-rates --missing
  --hours=240 --json` retorna `missing_rates=[]`.
- Proactive Layer ganhou diagnostico de push delivery para explicar quando Inbox
  foi criado mas push nao chegou.
- Mobile CLI ganhou `replay-push` dry-run/apply para reprocessar push pendente;
  apply exige confirmacao explicita, motivo e dry-run recente com candidatos.
- Atlas Rivals e Atlas-Bench ganharam contrato visual para bateria real pendente,
  launcher com aceite de custo e metricas zeradas explicadas.
- Execucao Rivals agora exige preflight hash, revisao do plano e aceite de custo
  no backend antes de criar run. CLI oficial e wrapper tambem exigem
  `--confirm-runbook-reviewed` e `--confirm-provider-cost`; sem isso retornam
  `fair_claude_provider_execution_confirmation_required` antes de provider call
  e sem criar benchmark run.
- Relatorio Rivals passou a tratar protocolo invalido como inconclusivo:
  `atlas_protocol_invalid`, `comparable=false`, `winner=null`. Testes:
  `EngineeringBenchmarkFairClaudeScorecardTest` e
  `EngineeringHarnessRunnerTest --filter='atlas_rivals_wrapper_requires_explicit_runbook_and_cost_confirmation|fair_claude_provider_run_requires_explicit_runbook_and_cost_confirmation|fair_claude_runbook_reports_real_battery_commands_and_start_blockers'`.

## Resumo

Handoff compacto para novas sessoes continuarem a estrutura mae enterprise do Atlas AI sem depender do historico da conversa.

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
