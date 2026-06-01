---
id: atlas-axis-n-fleet-live-pilar2-foundry
type: engineering_knowledge
doc_schema: atlas_canonical_module_doc.v1
title: Atlas Axis N Fleet + Live Pilar 2 Frontier Foundry
slug: atlas-axis-n-fleet-live-pilar2-foundry
status: building
implementation_state: design_approved_no_runtime_yet
category: agentic-engineering
priority: 96
summary: >
  Design canonico do eixo N (frota paralela de workers, um worktree por worker)
  somado ao Pilar 2 vivo (AFEF gerando backlog real atraves da armadura I1-I9).
  Reconcilia os mapas existentes (Frontier Evolution Foundry, AFEF Build Plan,
  Plan Execution, Loop Extreme Quality Gate, antifragility N x M) com a critica
  acumulada de dois workflows anteriores (codigo unit-green-mas-MORTO; depois
  end-to-end provado). Define componentes, schemas (fleet plan, worker result,
  integration decision, gap record com evidence anchors + outcome_contract,
  frontier proposal), governanca (budgets, locks, cap de concorrencia,
  proposal-only gating I1-I9), modelo de concorrencia/isolamento (um worktree por
  worker), recuperacao de crash/idempotencia, auditoria append-only,
  observabilidade e a fronteira explicita de provider vivo. Composicao apenas:
  nao cria OS, runtime nem provider novo; embrulha o owner-flow single-worker,
  o ZeroProviderPreflightGate, o MetricLedger/measured-or-reverted, o
  AdversarialProofPanel e a armadura Foundry ja em main.
tags: [atlas-ai, software-company, axis-n, fleet, frontier-evolution-foundry, parallel-workers, worktree-isolation, live-pilar2]
capabilities: [parallel_worker_fleet, worktree_isolation_per_worker, fleet_integration_decision, live_frontier_proposal_generation_gated, measured_or_reverted_fleet]
decisions:
  - Axis N e SO paralelismo governado de workers; cada worker reusa o owner-flow single-worker existente (Ap786OwnerFlowExecutor), nunca um runtime paralelo novo.
  - Um worktree git por worker (isolamento duro); o orquestrador nunca deixa dois workers escreverem o mesmo working tree.
  - Integracao e serializada por um unico lock de merge; workers correm em paralelo, merges acontecem um de cada vez atraves do mesmo gate provider-proof/no-scaffold/quality que o caminho single-worker.
  - Pilar 2 vivo so liga quando #1 (anti-inercia) e #2 (provider-proof + no-scaffold) estao verdes; AFEF permanece proposal-only e atravessa I1-I9 mais o operador (I4).
  - Toda integracao e medida (I5/MetricLedger); regrediu na tolerancia entao git revert automatico do merge daquele worker e o resultado vira refuted_by_reality.
  - Provider vivo (MiniMax/Opus/Codex) e chamado SOMENTE no caminho de execucao real do worker e do juiz adversarial; todo teste do eixo N dirige seams fake deterministicos (zero gasto de provider no workflow).
maintenance:
  - Atualizar antes de mudar o schema de fleet plan/worker result/integration decision, o modelo de lock/concorrencia, o cap de workers ou o mapeamento gate->invariante.
  - Bloquear quando IA tentar paralelizar merges, compartilhar worktree entre workers, auto-canonizar proposta AFEF, ou alegar integracao sem medicao verde real.
risk_level: high
owner: agentic_engineering_os/dev_forge
graph_id: atlas-axis-n-fleet-live-pilar2-foundry
graph_title: Atlas Axis N Fleet + Live Pilar 2
graph_world: atlas
graph_layer: module
graph_kind: module
graph_parent: atlas-frontier-evolution-foundry
graph_status: building
graph_source: repo
depends_on:
  - atlas-frontier-evolution-foundry
  - atlas-afef-build-plan
  - atlas-software-company-stewardship-stack
  - atlas-loop-extreme-quality-gate
  - atlas-cognitive-antifragility-equation
flows_to: [atlas-frontier-evolution-foundry]
unlocks: [parallel_governed_evolution, compounding_self_improvement_at_scale]
governs: [fleet_plans, worker_results, integration_decisions, evolution_proposals]
authority_class: planner
related_paths:
  - docs/engineering-knowledge-base/atlas-frontier-evolution-foundry.md
  - docs/engineering-knowledge-base/atlas-afef-build-plan.md
  - app/Services/Ai/Foundry/FoundrySchemas.php
  - app/Services/Ai/Foundry/Frontier/FrontierGenerationOrchestratorService.php
  - app/Services/Ai/SoftwareCompanyStewardship/AreaFocusLoop/OwnerFlow/Ap786OwnerFlowExecutor.php
  - app/Services/Ai/SoftwareCompanyStewardship/AreaFocusLoop/OwnerFlow/ZeroProviderPreflightGate.php
  - app/Services/Ai/SoftwareCompanyStewardship/AreaFocusLoop/PlanExecution/MetricLedgerService.php
  - app/Services/Ai/SoftwareCompanyStewardship/AreaFocusLoop/AdversarialProofPanelService.php
  - app/Services/Ai/SoftwareCompanyStewardship/AreaFocusLoop/OwnerFlow/OwnerSandboxRuntimeRunner.php
repo_paths:
  - docs/engineering-knowledge-base/atlas-axis-n-fleet-live-pilar2-foundry.md
evidence:
  - docs/engineering-knowledge-base/atlas-frontier-evolution-foundry.md
  - app/Services/Ai/Foundry/FoundrySchemas.php
  - app/Services/Ai/SoftwareCompanyStewardship/AreaFocusLoop/OwnerFlow/Ap786OwnerFlowExecutor.php
evidence_refs:
  - symbol: FoundrySchemas
  - test: FoundrySchemasTest
required_tests:
  - "php artisan atlas:engineering:knowledge docs-health --json"
next_actions:
  - Confirmar #1 e #2 verdes em run real antes de ligar geracao Pilar 2 viva.
  - Entregar AP-N1 (fleet plan + scheduler com cap=1, prova paridade com single-worker) antes de subir o cap.
allowed_changes:
  - Refinar schemas, budgets, cap e mapeamento gate->invariante mantendo serializacao de merge e proposal-only.
forbidden_changes:
  - parallelize_merge_integration
  - share_worktree_between_workers
  - auto_canonize_frontier_proposal
  - claim_integration_without_green_measurement
  - introduce_parallel_runtime_or_provider
requires_evidence: false
line_limit: 520
schema:
  - atlas.axis_n.fleet_plan.v1
  - atlas.axis_n.worker_result.v1
  - atlas.axis_n.integration_decision.v1
  - atlas.axis_n.gap_record.v1
  - atlas.foundry.evolution_proposal.v1
---

# Atlas Axis N Fleet + Live Pilar 2 Frontier Foundry

## Resumo

Este doc define duas coisas que se compoem e NADA mais:

1. **Eixo N (frota)** — paralelismo governado de workers de evolucao. O loop de
   stewardship hoje roda um worker por vez (selecao -> owner-flow -> merge
   gated). O eixo N coloca ate `max_workers` workers correndo em paralelo, **um
   worktree git por worker**, e serializa a integracao por **um unico lock de
   merge**. Cada worker reusa o caminho single-worker que ja existe em main
   (`Ap786OwnerFlowExecutor` + `ZeroProviderPreflightGate` + quality gate +
   `MetricLedgerService`); o orquestrador de frota nao reimplementa execucao.

2. **Pilar 2 vivo** — o Atlas Frontier Evolution Foundry (AFEF) gerando backlog
   de evolucao real quando o backlog honesto esgota, atravessando a armadura
   I1-I9 e o operador (I4). Os schemas `foundry.*`, o Evidence Harvester/Verifier
   e o Exhaustion/Rarity Gate ja existem em `app/Services/Ai/Foundry/`. "Vivo"
   significa: a geracao roda atras de flag default-off, so depois de exaustao
   *medida* (I8), e cada proposta promovida vira gap admissivel que entra na frota
   como qualquer outro item.

O eixo N e o **N** da equacao N x M (`atlas-cognitive-antifragility-equation.md`,
`parallel_efficiency_factor`). Pilar 2 vivo e parte do **M** (self-construction
compounding). Os dois juntos: a frota multiplica throughput de evolucao provada,
o foundry alimenta a frota com trabalho que vale a pena.

## Critica reconciliada (por que este design e assim)

Dois workflows anteriores deixaram licoes que viram invariantes aqui:

- **"Unit-green mas MORTO"** — codigo com teste verde que nada no runtime
  invocava. Regra: cada componente do eixo N DEVE ter call site provado na frota
  (grep do wiring) e um teste e2e dirigindo o caminho real. Sem call site = nao
  existe.
- **"Tive que provar tudo end-to-end"** — daqui o seam fake deterministico: o
  teste e2e dirige `Ap786OwnerFlowExecutor` e o juiz por portas fake (worker que
  retorna diff conhecido, juiz que retorna veredito fixo), sem nenhum gasto de
  provider vivo dentro do workflow. O provider vivo so aparece em producao.
- **Nunca enfraquecer gate existente** — o eixo N nao cria um caminho de merge
  paralelo "rapido". Todo merge da frota passa pelo MESMO ZeroProviderPreflightGate,
  no-scaffold (`FinalDeliveryQualityGateService`), quality gate diff-scoped e
  measured-or-reverted. Paralelismo e so na execucao; integracao continua
  serializada e identica ao caminho single-worker.

## Papel no Atlas

O eixo N e a fundacao que permite o loop de 24h evoluir em largura sem perder
nenhum fusivel. Hoje o teto e serial: um worker, um item, um ciclo. O eixo N
levanta o teto de throughput (N workers) mantendo o teto de seguranca (1 merge por
vez, todos os gates). Pilar 2 vivo levanta o teto de origem de trabalho: quando o
backlog humano/heuristico esgota honestamente, a frota nao para nem fabrica filler
— ela recebe propostas que sobreviveram a armadura.

## Onde Se Encaixa

```
backlog (heuristico) ---+
                        |   exhausted (I8 medido)
AFEF Pilar 2 vivo ------+----> gap_record[] admissiveis
                        |
                        v
            FLEET SCHEDULER (cap=max_workers, lock-free na execucao)
                        |
        +---------------+---------------+
        v               v               v
   worker#1         worker#2         worker#N      (um worktree git cada)
   owner-flow       owner-flow       owner-flow    (Ap786OwnerFlowExecutor reusado)
        |               |               |
        +-------> worker_result[] (diff capturado, provider-proof)
                        |
                        v
            INTEGRATION SERIALIZER (UM lock de merge global)
              por resultado, em ordem deterministica:
                ZeroProviderPreflightGate -> no-scaffold -> quality gate
                -> merge -> MetricLedger medir -> I5 measured-or-reverted
                        |
                        v
            integration_decision[]  (merged | reverted | blocked)
                        |
                        v
            append-only audit + observabilidade + per-run cleanup
```

Ponto de entrada: o eixo N embrulha o `AutonomousEvolutionSessionService` no
ponto onde hoje ele seleciona e executa um item. Default-off; com flag, em vez de
um item, ele monta um `fleet_plan` de ate N itens independentes.

## Componentes e Responsabilidades

Todos os componentes novos vivem em
`app/Services/Ai/SoftwareCompanyStewardship/AreaFocusLoop/AxisN/`. Nenhum
reimplementa execucao, merge ou medicao — eles orquestram os servicos existentes.

- **FleetPlanBuilderService** — recebe os gaps admissiveis (heuristicos +
  promovidos do AFEF), filtra por independencia (sem overlap de arquivos
  declarados) e emite um `fleet_plan` com ate `max_workers` slots. Itens com
  overlap potencial vao para slots sequenciais, nunca paralelos. Sem dependencia
  de provider.
- **FleetSchedulerService** — aloca um worktree por slot via
  `OwnerSandboxRuntimeRunner` (ja faz worktree AP-756), dispara cada worker
  atraves de `Ap786OwnerFlowExecutor` no SEU worktree, coleta `worker_result`.
  Respeita `concurrency_cap`, budgets e arquivos pause/kill. Crash de um worker
  nao derruba a frota (resultado vira `failed`, frota continua).
- **WorkerLeaseService** — lease por worker (worktree + branch + slot) append-only;
  garante idempotencia e que um restart nao duplica trabalho nem worktree.
- **IntegrationSerializerService** — adquire o **lock global unico de merge**,
  drena `worker_result[]` em ordem deterministica (por `slot_index`), e para CADA
  um roda a cadeia gated identica ao single-worker. Emite `integration_decision`.
  Nunca processa dois merges em paralelo.
- **FleetMetricGuardService** — fino wrapper sobre `MetricLedgerService` que aplica
  I5 por integracao: mede `outcome_contract`, e se regrediu na tolerancia faz
  `git revert` do merge daquele worker e marca `refuted_by_reality`. Outros
  workers nao sao afetados.
- **FleetAuditLedgerService** — JSONL append-only por run (plan, leases, results,
  decisions, revertidos), replayable para crash recovery.
- **FleetObservabilityService** — projeta metricas do run (ver Observabilidade).

Pilar 2 vivo reusa o que ja esta em main, sem componente novo de geracao:
`FoundryExhaustionRarityGateService` (I8), `FrontierGenerationOrchestratorService`
(armadura I1/I3/I6/I7/I9), `FrontierProposalAdversarialJudgeService` (I3),
`FrontierProposalPacketDecomposerService` (I7),
`FrontierProposalToGapCandidateAdapter` (proposta promovida -> gap admissivel).
O unico wiring novo e: ligar o adapter de saida do foundry na entrada do
`FleetPlanBuilderService`, atras de flag.

## Contratos (Schemas)

Todos os schemas do eixo N sao registrados ao lado dos `foundry.*` existentes. Os
do AFEF NAO mudam (reuso). Nenhum schema autoriza merge sozinho.

- **`atlas.axis_n.fleet_plan.v1`** — `plan_id`, `run_id`, `created_at`,
  `max_workers`, `concurrency_cap`, `budgets {wall_clock_s, premium_spend,
  max_merges, max_blocked_in_row}`, `slots[] { slot_index, gap_record_ref,
  declared_paths[], origin: heuristic|frontier_proposal, isolation: parallel|sequential }`.
  Slots com `declared_paths` em conflito recebem `isolation=sequential`.
- **`atlas.axis_n.worker_result.v1`** — `result_id`, `plan_id`, `slot_index`,
  `worktree_path`, `branch`, `status: produced|blocked|failed`, `diff_ref`
  (provider-proof: captura do diff real), `provider_proof { ran_real_provider:
  bool, provider, model, evidence_ref }`, `gate_preflight: pass|fail`,
  `started_at`, `ended_at`, `failure_reason?`. `status=produced` NUNCA significa
  merged.
- **`atlas.axis_n.integration_decision.v1`** — `decision_id`, `plan_id`,
  `slot_index`, `result_id`, `verdict: merged|reverted|blocked`, `gate_chain[]
  { gate, verdict, evidence_ref }` (preflight, no_scaffold, quality, metric),
  `merge_commit?`, `revert_commit?`, `outcome_contract_ref`,
  `measured: { property, baseline, observed, target, within_tolerance: bool }`,
  `decided_at`. `verdict=reverted` exige `revert_commit` real.
- **`atlas.axis_n.gap_record.v1`** — `gap_id`, `title`, `origin:
  heuristic|frontier_proposal`, `evidence_anchors[]` (I1: cycle_ids,
  commit_hashes, `file:line`, `repro_cmd` — verificaveis), `declared_paths[]`,
  **`outcome_contract`** `{ property (propriedade canonica medida, I9),
  baseline, target, measure_cmd (executavel, I2), tolerance, rollback {
  trigger, method: git_revert, verify_cmd } }`, `frontier_proposal_ref?`
  (preenchido quando origin=frontier_proposal, aponta para o
  `atlas.foundry.evolution_proposal.v1` que originou). Gap sem
  `outcome_contract` falsificavel = inadmissivel (nao entra no fleet_plan).
- **`atlas.foundry.evolution_proposal.v1`** (REUSO, sem mudanca) — `proposal_id`,
  `horizon`, `thesis`, `evidence_refs[]` (I1), `success_metric` (I2/I9),
  `rollback` (git_revert). A frontier proposal so vira `gap_record` apos
  promocao do operador (I4) via `FrontierProposalToGapCandidateAdapter`.

## Governanca

- **Budgets** (no `fleet_plan`, enforced pelo scheduler/serializer): `wall_clock_s`
  por run, `premium_spend` (teto de gasto de provider por janela, herda I8),
  `max_merges` por run, `max_blocked_in_row` (honest-stop: para a frota se N
  integracoes seguidas bloqueiam).
- **Locks**: lease por worker (worktree/branch/slot, append-only) + **um lock
  global de merge** segurado pelo `IntegrationSerializerService`. Proibido
  paralelizar merge (`forbidden_changes.parallelize_merge_integration`).
- **Cap de concorrencia**: `concurrency_cap <= max_workers`; AP-N1 entrega com
  `cap=1` para provar paridade byte-identica com o caminho single-worker antes de
  subir o cap.
- **Proposal-only gating I1-I9** (Pilar 2 vivo): toda frontier proposal atravessa
  I1 (evidencia verificavel) -> I2 (metrica falsificavel) -> I6 (anti-dup) -> I3
  (juiz independente, modelo != gerador, refutar por default) -> I7 (decomposicao
  bounded) -> I9 (drift para propriedade canonica); falha em qualquer = drop+log.
  I8 (exhaustion/rarity) gateia o disparo. I4 (operador) gateia a promocao para
  `gap_record`. I5 (measured-or-reverted) gateia a permanencia pos-merge. Nenhuma
  proposta vira producao ou doc canonico sozinha.

## Modelo de Concorrencia e Isolamento

- **Um worktree git por worker.** O scheduler aloca `worktree_path` distinto e
  `branch` distinto por slot via `OwnerSandboxRuntimeRunner`. Dois workers NUNCA
  compartilham working tree (`forbidden_changes.share_worktree_between_workers`).
- **Execucao paralela, integracao serial.** Workers correm concorrentes; o
  `IntegrationSerializerService` integra um por vez sob o lock global, em ordem de
  `slot_index`, contra o estado de `main` ja atualizado pelos merges anteriores do
  run. Isso elimina merge concorrente e mantem o gate determinístico.
- **Independencia de plano.** O `FleetPlanBuilderService` so paraleliza slots com
  `declared_paths` disjuntos; overlap vira `isolation=sequential`. Reduz conflito
  de merge na origem em vez de resolver depois.

## Falha / Crash Recovery e Idempotencia

- **Lease append-only**: no restart, o `WorkerLeaseService` le o ledger e
  reconcilia — worktrees orfaos sao limpos, slots ja integrados nao reexecutam.
- **Idempotencia por `plan_id` + `slot_index`**: reprocessar um resultado ja
  integrado e no-op (a `integration_decision` ja existe no ledger).
- **Crash de worker**: vira `worker_result.status=failed`; a frota continua; o
  worktree e limpo no cleanup do run.
- **Crash do serializer no meio de um merge**: o lock e baseado em arquivo +
  PID-stale-check; no restart, se o `merge_commit` foi escrito mas a decisao nao,
  a reconciliacao completa a `integration_decision` a partir do estado git real
  (fonte da verdade = repo, nao memoria).
- **measured-or-reverted reentrante**: se o processo morre apos merge e antes de
  medir, o restart re-mede pelo `outcome_contract`; regrediu = revert.

## Auditoria Append-Only

`FleetAuditLedgerService` escreve JSONL por `run_id` em
`storage/.../axis_n/<run_id>.jsonl`, append-only, com eventos tipados:
`fleet_plan_built`, `worker_leased`, `worker_result`, `integration_decision`,
`reverted_by_measurement`, `run_stopped {reason}`. Cada evento carrega
`schema`, `at`, e refs verificaveis. O ledger e replayable e e a fonte para
crash recovery e para o evidence pack do run. Nunca sobrescreve; correcao e novo
evento.

## Observabilidade (Metricas)

Projetadas por `FleetObservabilityService` a partir do ledger:

- `workers_dispatched`, `workers_produced`, `workers_failed`.
- `merges_committed`, `merges_reverted_by_measurement`, `merges_blocked`.
- `parallel_efficiency_factor` (throughput real vs serial equivalente) — alimenta
  `atlas-cognitive-antifragility-equation.md`.
- `provider_proof_rate` (% de merges com `ran_real_provider=true`).
- `frontier_proposals_generated / promoted / merged / refuted_by_reality`.
- `blocked_in_row` (gatilho de honest-stop).
- `mean_time_to_integrate`, `lock_wait_p95`.

## Fronteira de Provider Vivo (explicita)

- **Onde o provider vivo roda**: (1) dentro de cada worker, no caminho de execucao
  real do `Ap786OwnerFlowExecutor` (gera o diff real); (2) no juiz adversarial I3
  (`FrontierProposalAdversarialJudgeService`), em modelo DIFERENTE do gerador.
- **Onde NUNCA roda**: em qualquer teste do eixo N ou do Pilar 2 vivo. Todo teste
  e2e dirige o caminho real por **portas fake deterministicas** — worker fake que
  devolve um `diff_ref` conhecido e `provider_proof` sintetico marcado como fake,
  juiz fake com veredito fixo. Zero gasto de MiniMax/Opus/Codex dentro do
  workflow. O `provider_proof.ran_real_provider=false` em teste e o sinal honesto
  de que aquilo foi um seam, nao uma corrida real.
- **Gate de honestidade**: um `worker_result` so conta como provider-proof real se
  `ran_real_provider=true` com `evidence_ref` valido; caso contrario o
  `ZeroProviderPreflightGate` ja bloqueia o merge no caminho existente.

## Escopo de Implementacao (fatias reversiveis, ordem dura)

Condicao de entrada (herdada do AFEF Build Plan): **#1 anti-inercia e #2
provider-proof + no-scaffold verdes em run real** antes de qualquer geracao Pilar 2
viva.

1. **AP-N1** Fleet plan + scheduler com `cap=1` — prova paridade byte-identica com
   o single-worker (mesmos merges, mesmos gates). Sem geracao, sem paralelismo
   real ainda. e2e com worker fake.
2. **AP-N2** Worktree-por-worker + lease + cleanup — sobe `cap` para 2, prova
   isolamento (dois worktrees, zero overlap) com merge ainda serial. Crash
   recovery testado.
3. **AP-N3** Integration serializer + FleetMetricGuard (I5 por integracao) — prova
   measured-or-reverted na frota com `outcome_contract` fake medido.
4. **AP-N4** Audit ledger + observabilidade — prova replay e metricas.
5. **AP-N5** Pilar 2 vivo wiring — liga `FrontierProposalToGapCandidateAdapter` na
   entrada do `FleetPlanBuilderService` atras de flag, apos I8 medido. Proposta
   promovida (I4) entra na frota como gap_record normal.

## Padroes Proibidos

- Paralelizar merge/integracao (sempre 1 lock global).
- Compartilhar worktree entre workers.
- Auto-canonizar frontier proposal (I4 exige receipt humano).
- Alegar integracao sem medicao verde real (I5).
- Criar OS/runtime/provider paralelo (composicao apenas).
- Vocabulario `benchmark`/`rivals`/`superiority`/`concurrent` (no sentido de
  competidor).
- Codigo unit-green sem call site na frota (regra anti-MORTO).
