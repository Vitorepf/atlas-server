---
id: atlas-hermes-kanban-substrate
type: engineering_knowledge
title: Atlas Hermes Kanban Substrate
status: active
category: architecture
priority: 92
summary: Driver governado pelo Atlas para o Hermes Kanban Swarm — um grafo DURAVEL de workers paralelos -> verifier -> synthesizer, complementar (nao duplicado) ao Executive Mesh efemero. O Atlas compoe o grafo (cards de worker + perfis verifier/synthesizer), e dono do ciclo de vida de um board efemero por-missao (cria e apaga, com o SQLite enraizado num HERMES_HOME escolhido pelo Atlas), dirige o dispatch ONE-SHOT (nunca o daemon/gateway), reconcilia a partir do stats do board e sela recibos atlas.hermes.kanban_swarm_*.v1 — entregando swarm duravel com verifier+synthesizer sob soberania Atlas, fail-closed e default-off.
tags:
  - atlas-ai
  - hermes
  - kanban
  - swarm
  - multi-agent
  - executive-runtime
  - antifragile
capabilities:
  - hermes_kanban_swarm_compose
  - hermes_kanban_board_lifecycle
  - hermes_kanban_oneshot_dispatch
  - hermes_kanban_verifier_synthesizer
  - hermes_kanban_reconciliation
decisions:
  - ATLS e soberano sobre a decomposicao (compoe os cards de worker), o ciclo de vida do board e a reconciliacao; o Hermes Kanban so executa o swarm sob contrato.
  - O board e EFEMERO e Atlas-owned (slug por-missao, criado e apagado pelo Atlas, SQLite sob um HERMES_HOME escolhido pelo Atlas); nunca um board Hermes persistente nem o daemon/gateway dispatcher (que seriam um segundo scheduler/estado = trap de soberania).
  - Default-off + fail-closed: um run ao vivo exige kanban.policy=atlas_adapter E confirm explicito; senao so compoe/preview (sem board, sem spawn, sem tokens).
  - Goal e titulos de worker sao mascarados (hash) em todo preview/recibo; nada de texto cru de tarefa em superficie de auditoria.
  - Kanban (swarm duravel: workers->verifier->synthesizer, retries, dependencias) e COMPLEMENTAR ao Executive Mesh (fan-out efemero): rajada rapida vs campanha duravel; nao sao caminhos duplicados.
maintenance:
  - Manter abaixo de 400 linhas; detalhes por componente vivem no codigo e nos testes.
  - Atualizar quando um novo consumidor (Forge/Mission Mode) ou uma nova etapa do grafo for adicionada, ou se as formas JSON do `hermes kanban` mudarem.
related_paths:
  - docs/engineering-knowledge-base/atlas-hermes-executive-mesh.md
  - docs/engineering-knowledge-base/atlas-hermes-executive-runtime.md
  - docs/engineering-knowledge-base/atlas-ai-knowledge-governance-system.md
  - app/Services/Ai/Hermes/Kanban/HermesKanbanSwarmService.php
  - app/Services/Ai/Hermes/Kanban/HermesKanbanCli.php
  - app/Services/Ai/Hermes/Kanban/HermesKanbanProcessCli.php
  - app/Console/Commands/AtlasHermesKanbanCommand.php
  - app/Services/Ai/Programming/Forge/ForgeKanbanSwarmDispatcher.php
  - app/Console/Commands/AtlasForgeKanbanDispatchCommand.php
doc_schema: atlas_canonical_module_doc.v1
graph_id: atlas-hermes-kanban-substrate
human_name: Atlas Hermes Kanban Substrate
canonical_name: Atlas Hermes Kanban Substrate
technical_name: HermesKanbanSwarmService
cartography_type: module
canonical_source: docs/engineering-knowledge-base/atlas-hermes-kanban-substrate.md
graph_title: Atlas Hermes Kanban Substrate
graph_world: atlas
graph_layer: module
graph_kind: module
graph_parent: atlas-hermes-executive-runtime
graph_status: active
graph_source: repo
owner: atlas-ai
implementation_state: phase_2_kanban_substrate_with_forge_consumer
repo_paths:
  - app/Services/Ai/Hermes/Kanban/HermesKanbanSwarmService.php
  - app/Services/Ai/Hermes/Kanban/HermesKanbanProcessCli.php
  - app/Console/Commands/AtlasHermesKanbanCommand.php
  - app/Services/Ai/Programming/Forge/ForgeKanbanSwarmDispatcher.php
  - app/Console/Commands/AtlasForgeKanbanDispatchCommand.php
allowed_changes:
  - Update swarm composition, board lifecycle and reconciliation contracts when Hermes Kanban JSON shapes change.
  - Add governed consumers that keep Atlas as planner, dispatcher owner and reconciler.
forbidden_changes:
  - Do not use Hermes daemon or gateway as a second scheduler.
  - Do not persist Atlas-owned ephemeral boards after the run.
  - Do not expose raw task goals in audit surfaces.
depends_on:
  - atlas-hermes-executive-runtime
  - atlas-hermes-executive-mesh
flows_to:
  - forge_kanban_swarm_dispatcher
unlocks:
  - governed_durable_swarm_execution
governs:
  - hermes_kanban_swarm_plan
  - hermes_kanban_reconciliation
  - hermes_kanban_swarm_run
evidence:
  - app/Services/Ai/Hermes/Kanban/HermesKanbanSwarmService.php
  - app/Services/Ai/Programming/Forge/ForgeKanbanSwarmDispatcher.php
  - tests/Unit/Ai/Hermes/Kanban/HermesKanbanSwarmServiceTest.php
  - tests/Unit/Ai/Programming/Forge/ForgeKanbanSwarmDispatcherTest.php
required_tests:
  - php artisan test tests/Unit/Ai/Hermes/Kanban/HermesKanbanSwarmServiceTest.php tests/Unit/Ai/Programming/Forge/ForgeKanbanSwarmDispatcherTest.php
requires_evidence: true
risk_level: high
next_actions:
  - Round-trip ao vivo do swarm (real workers) sob autorizacao do operador, como `atlas:hermes:mesh dispatch --confirm` — hoje provado com CLI FAKE (orquestracao) + seam real do ProcessCli ate dispatch --dry-run (sem spawn/tokens).
  - Auto-selecao do backend kanban dentro do ciclo de execucao do Forge (hoje o consumer e explicito via ForgeKanbanSwarmDispatcher + comando; o hot path single-provider do Forge NAO foi tocado por design).
---

# Atlas Hermes Kanban Substrate

## Resumo

O Hermes Kanban e um **board duravel (SQLite) multi-profile** com claim atomico,
dependencias entre tarefas, retries e um modo **Swarm v1** (workers paralelos ->
verifier -> synthesizer). O Atlas o usa como **substrate de execucao governado**
para trabalho duravel/pesado (Forge / Mission Mode), SEM ceder o cerebro nem o
estado: o Atlas compoe o grafo, e dono do board e dirige o dispatch.

Isto e **complementar** ao [[atlas-hermes-executive-mesh]], nao um caminho
duplicado:

| Eixo | Executive Mesh | Kanban Substrate |
|---|---|---|
| Forma | fan-out EFEMERO (uma missao -> N filhos -> reconcilia -> some) | board DURAVEL (tarefas persistem, dependencias, retries) |
| Etapas | workers + reconciliacao (+ verifier-de-rollback) | workers -> verifier -> **synthesizer** (uma saida final) |
| Uso | rajada paralela rapida | campanha duravel, resiliente |
| Estado | nenhum apos o run | board Atlas-owned efemero, apagado no fim |

## Papel no Atlas

Hermes Kanban gives Atlas a durable multi-agent substrate while Atlas keeps planning,
board ownership, dispatch policy, reconciliation and receipts.

## Onde Se Encaixa

It complements the ephemeral Executive Mesh and feeds governed consumers such as
Forge without replacing the single-provider hot path.

## Contratos

Contracts are the masked swarm plan, ephemeral board lifecycle, one-shot dispatch,
reconciliation receipt and run receipt. Live dispatch is default-off and confirm-gated.

## Fluxo

Atlas composes worker/verifier/synthesizer cards, creates an ephemeral board, seeds the
graph, runs bounded one-shot dispatch passes, reconciles stats, deletes the board and
seals receipts.

## Regras para IA

Never make Hermes decide the decomposition, never route through daemon/gateway state,
and never expose raw task text in preview or audit receipts.

## Escopo de Implementacao

Runtime orchestration and Forge consumer exist; real worker round-trip remains
operator-authorized spend and is not implied by dry-run proof.

## Dependencias

Depends on Hermes CLI JSON shapes, the Atlas process CLI seam, the Executive Runtime
policy config and Forge's optional Kanban dispatcher.

## Evidencias

Evidence is the Hermes Kanban service, CLI command, Forge dispatcher and their unit
tests.

## Riscos

Main risks are sovereignty loss to Hermes scheduling, leaked raw task text, persistent
foreign state, and live spend without explicit confirmation.

## Exemplos

`dispatch --dry-run` proves argv/board orchestration without spawning workers; a live
run requires `kanban.policy=atlas_adapter` and `--confirm`.

## Proximas Acoes

Keep the substrate default-off for live runs and add any new consumer only through an
Atlas-owned planning/reconciliation adapter.

## Soberania (Atlas e o cerebro; Hermes e o musculo)

- **Decomposicao = Atlas.** O `swarm` recebe cards de worker explicitos compostos
  pelo Atlas (`--worker PROFILE:TITLE[:SKILL]`). O `hermes kanban decompose`
  (auto-decompose do Hermes) NAO e usado — decidir como dividir e funcao de cerebro.
- **Board = Atlas-owned + efemero.** Um slug por-missao (`atlas-mission-<hash>`)
  que o Atlas cria e (por default) apaga; o `kanban.db` mora sob um HERMES_HOME
  escolhido pelo Atlas (`<home>/kanban/boards/<slug>/kanban.db`). Nunca um board
  Hermes persistente.
- **Dispatch = one-shot.** O Atlas roda `hermes kanban dispatch` em passes
  limitados; NUNCA o `daemon` (deprecado) nem o dispatcher do gateway — que seriam
  um segundo scheduler/estado vivo do Hermes (trap de soberania, como cron/gateway).
- **Fail-closed + default-off.** `kanban.policy` default `off`; um run ao vivo
  exige `policy=atlas_adapter` E `--confirm`. Caso contrario so `compose`/`preview`
  (sem board, sem spawn, sem tokens). `authority` e sempre Atlas;
  `hermes_kanban_can_decide` e sempre false.

## Fluxo (run ao vivo, fail-closed)

1. `compose(spec)` — valida o spec, deriva o slug do board, sela
   `atlas.hermes.kanban_swarm_plan.v1` com `dispatch_allowed_now` (= policy +
   estrutura validas). Goal vira `goal_hash`; nada de goal cru.
2. Cria o board Atlas-owned (`boards create <slug>`).
3. Semeia o grafo (`swarm <goal> --worker … --verifier … --synthesizer …
   --idempotency-key <slug>`) -> `{root_id, worker_ids, verifier_id,
   synthesizer_id}`.
4. Passes one-shot de `dispatch --max N` ate o board ficar terminal (sem
   ready/todo/running pelo `stats`) ou ate o teto `max_dispatch_passes`.
5. Reconcilia do `stats.by_status` -> `atlas.hermes.kanban_reconciliation.v1`
   (`aggregate_status`: all_completed | incomplete | blocked | partial | empty).
6. Apaga o board (`boards rm <slug> --delete`) — sem estado Hermes persistente.
7. Sela `atlas.hermes.kanban_swarm_run.v1` (grafo + reconciliacao + passes).

## Componentes

- `HermesKanbanSwarmService` — o cerebro de orquestracao: `compose` (puro),
  `previewArgv` (puro, mascarado), `run` (ao vivo, fail-closed). Tetos de config
  (max_workers, max_dispatch_passes, max_spawns_per_pass).
- `HermesKanbanCli` (interface) + `HermesKanbanProcessCli` (Symfony Process,
  one-shot, decodifica `--json`) — o unico seam de I/O de processo; testes
  injetam um fake e provam a orquestracao sem spawn nem tokens.
- `AtlasHermesKanbanCommand` (`atlas:hermes:kanban status|plan|dispatch`) — espelha
  o `atlas:hermes:mesh`: `status`/`plan` read-only; `dispatch --dry-run` so imprime
  o argv mascarado; `dispatch` real exige `policy=atlas_adapter` + `--confirm`.

## Consumidor Forge (Forge -> Kanban)

`ForgeKanbanSwarmDispatcher` (+ `atlas:forge:kanban-dispatch`) e o caminho de
chamada pelo qual o Forge/Mission despacha um obra DECOMPOSTO (work packets) como
um swarm kanban duravel. Ele mapeia `task_summary` -> goal e cada packet
(`objective` + `role_slot`) -> worker card, deriva verifier/synthesizer de config,
e delega ao `HermesKanbanSwarmService->run()`. **Triplo fail-closed**:
`kanban.policy=atlas_adapter` E `kanban.dispatch_for_forge=true` (consentimento
dedicado para o Forge, separado de `policy`) E `--confirm`. NAO toca o hot path
single-provider provado do Forge — e um backend OPCIONAL, default-off.

## Estado provado (sem tokens)

- 9 testes do `HermesKanbanSwarmServiceTest` (compose fail-closed, cap de workers,
  mascaramento de argv, run completo via CLI FAKE com as formas JSON REAIS,
  fail-closed de seed, teto de passes).
- Seam real do `HermesKanbanProcessCli` provado ao vivo ate `dispatch --dry-run`
  (boards create -> swarm cria o grafo -> dispatch dry-run -> stats -> boards rm),
  sem spawn de worker e sem custo de token.
- Pendente por design: round-trip com workers reais (spend) sob autorizacao do
  operador.
