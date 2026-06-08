---
id: atlas-hermes-executive-mesh
type: engineering_knowledge
title: Atlas Hermes Executive Mesh
status: active
category: architecture
priority: 93
summary: Orquestrador governado que decompoe uma missao em N missoes-filhas Hermes, cada uma com profile especializado, worktree isolado e checkpoints, despachadas como uma frota paralela com teto de concorrencia (delegation + batch), coletadas como ResultPackets selados e reconciliadas em Evidence e Memory Gate, entregando execucao multi-agente real com soberania Atlas.
tags:
  - atlas-ai
  - hermes
  - executive-mesh
  - multi-agent
  - executive-runtime
  - antifragile
capabilities:
  - hermes_mesh_decomposition
  - hermes_mesh_profile_specialization
  - hermes_mesh_worktree_fleet
  - hermes_mesh_parallel_dispatch
  - hermes_mesh_checkpoint_rollback
  - hermes_mesh_session_evidence
  - hermes_mesh_reconciliation
decisions:
  - ATLS e soberano sobre a decomposicao, a politica e a reconciliacao; o Hermes so executa cada missao-filha sob contrato selado.
  - O mesh nasce default-off; dispatch_allowed_now so e true com policy atlas_adapter, subtarefas validas e preconditions atendidas.
  - Concorrencia, profundidade de spawn e timeouts sempre clampados a tetos de config; nenhum filho re-delega, persiste memoria ou envia mensagem (leaf-blocked).
  - Profiles especializados (coder/reviewer/researcher/ops) sao realizados via HERMES_HOME gerenciado, nunca mutando o ~/.hermes do operador.
  - Reconciliacao apenas conta e roteia candidatos (memoria/skill/schedule) para os gates Atlas; nunca promove nada por conta propria.
maintenance:
  - Manter abaixo de 520 linhas; detalhes por componente vivem no codigo e nos testes.
  - Atualizar quando um novo profile, uma nova classe de capacidade paralela (batch, code_execution) ou um novo consumidor do mesh for adicionado.
related_paths:
  - docs/engineering-knowledge-base/atlas-hermes-executive-runtime.md
  - docs/engineering-knowledge-base/atlas-hermes-capability-registry.md
  - docs/engineering-knowledge-base/atlas-hermes-executive-runtime-product-spec.md
  - docs/engineering-knowledge-base/atlas-ai-knowledge-governance-system.md
  - app/Services/Ai/Hermes/Mesh/HermesExecutiveMeshPlanner.php
  - app/Services/Ai/Hermes/Mesh/HermesProfileResolver.php
  - app/Services/Ai/Hermes/Mesh/HermesMeshReconciler.php
  - app/Services/Ai/Hermes/HermesDelegationAdapter.php
doc_schema: atlas_canonical_module_doc.v1
graph_id: atlas-hermes-executive-mesh
graph_title: Atlas Hermes Executive Mesh
graph_world: atlas
graph_layer: module
graph_kind: module
graph_parent: atlas-hermes-executive-runtime
graph_status: active
graph_source: repo
macro_layer: false
human_name: Malha Executiva Multi-Agente do Hermes
canonical_name: Atlas Hermes Executive Mesh
technical_name: atlas-hermes-executive-mesh
cartography_type: runtime
canonical_source: docs/engineering-knowledge-base/atlas-hermes-executive-mesh.md
owner: architecture
repo_paths:
  - docs/engineering-knowledge-base/atlas-hermes-executive-mesh.md
  - app/Services/Ai/Hermes/Mesh/HermesExecutiveMeshPlanner.php
  - app/Services/Ai/Hermes/Mesh/HermesProfileResolver.php
  - app/Services/Ai/Hermes/Mesh/HermesCheckpointPolicy.php
  - app/Services/Ai/Hermes/Mesh/HermesSessionEvidenceImporter.php
  - app/Services/Ai/Hermes/Mesh/HermesDoctorPreflight.php
  - app/Services/Ai/Hermes/Mesh/HermesMeshReconciler.php
  - app/Services/Ai/Hermes/HermesDelegationAdapter.php
  - config/atlas.php
allowed_changes:
  - Adicionar novos profiles, novas heuristicas de decomposicao e novos sinais de reconciliacao, mantendo default-safe e fail-closed.
  - Evoluir o teto de paralelismo, a politica de checkpoint e a importacao de sessoes sob o mesmo contrato de receipts selados.
forbidden_changes:
  - Despachar a frota sem policy atlas_adapter, missao selada e trace ATLS presente.
  - Permitir que um filho re-delegue, persista memoria, rode codigo ou envie mensagem (leaf-block obrigatorio).
  - Promover memoria/skill/schedule na reconciliacao sem passar pelos gates Atlas.
  - Tornar o Hermes a autoridade de decomposicao, roteamento ou verificacao (hermes_mesh_can_decide e sempre false).
depends_on:
  - atlas-hermes-executive-runtime
  - atlas-hermes-capability-registry
  - atlas-ai-knowledge-governance-system
flows_to:
  - atlas-runtime-router
unlocks:
  - hermes-many-agent-execution
governs:
  - hermes-mesh-decomposition-dispatch-reconciliation
evidence:
  - docs/engineering-knowledge-base/atlas-hermes-executive-mesh.md
  - app/Services/Ai/Hermes/Mesh/HermesExecutiveMeshPlanner.php
  - app/Services/Ai/Hermes/Mesh/HermesProfileResolver.php
  - app/Services/Ai/Hermes/Mesh/HermesCheckpointPolicy.php
  - app/Services/Ai/Hermes/Mesh/HermesSessionEvidenceImporter.php
  - app/Services/Ai/Hermes/Mesh/HermesDoctorPreflight.php
  - app/Services/Ai/Hermes/Mesh/HermesMeshReconciler.php
  - app/Services/Ai/Hermes/Mesh/HermesExecutiveMeshService.php
  - app/Services/Ai/Hermes/Mesh/HermesMeshProcessWorkerFactory.php
  - app/Services/Ai/Hermes/Mesh/HermesMeshCheckpointExecutor.php
  - app/Services/Ai/Hermes/Mesh/HermesProfileHomeProvisioner.php
  - app/Services/Ai/Hermes/Mesh/HermesMeshRoutingAdvisor.php
  - app/Console/Commands/AtlasHermesMeshCommand.php
  - app/Console/Commands/AtlasHermesOpsCommand.php
required_tests:
  - php artisan test --filter=HermesExecutiveMeshPlannerTest
  - php artisan test --filter=HermesMeshReconcilerTest
  - php artisan atlas:engineering:knowledge docs-health --json
  - php artisan atlas:ai:architecture-validate --json
requires_evidence: true
risk_level: high
visual_tags:
  - runtime
  - sovereignty
  - multi-agent
  - antifragile
ai_entrypoints:
  - Leia Resumo, Fluxo e Contratos antes de propor qualquer execucao multi-agente Hermes.
ai_usage_notes:
  - O mesh e o orquestrador soberano; o Hermes so executa cada missao-filha. Nunca trate o Hermes como decisor de plano.
  - Para usar numeros multi-agente, aumente max_parallel_workers/max_children no config e mantenha o leaf-block e os tetos de delegation.
quality_gates:
  - docs-health status ok
  - architecture-validate status ok
failure_modes:
  - Despacho sem trace/policy (deve falhar fechado).
  - Filho re-delegando ou persistindo memoria (leaf-block quebrado).
  - Reconciliacao promovendo candidatos sem gate.
observability_signals:
  - Cada plano emite atlas.hermes.mesh_plan.v1 selado.
  - Cada reconciliacao emite atlas.hermes.mesh_reconciliation.v1 selado com contagem de filhos e refs de evidencia.
  - Profiles/checkpoints/preflight/sessions emitem recibos atlas.hermes.*.v1 hash-only.
implementation_state: phase_6_executive_mesh_auto_route_consumed
next_actions:
  - Round-trip ao vivo da frota contra os modelos do operador via o caminho create->worker auto-roteado (hoje wired+provado com frota FAKE em teste de integracao; o live real custa tokens e exige autorizacao do operador, como o `atlas:hermes:mesh dispatch --confirm`).
  - Limpeza (forget) do HERMES_HOME por profile apos a missao e granularidade por-tool no MCP.
notes_phase_6:
  - Auto-route CONSUMIDO no caminho de decisao (2026-06-08): o HermesMeshRoutingAdvisor agora emite `execution_route` (mesh|single) gated por um segundo switch dedicado `mesh.auto_route` (default-off, separado de `mesh.policy`). AtlasDecide surfa `execution_route` no receipt; AiGatewayService marca `kind='mesh'` quando ='mesh'; AiWorker (via HermesMeshJobRunner) executa a frota governada e cai de volta a um provider unico se nao puder despachar (try-then-fallback, como o ACP). Triplo fail-closed: policy + signal-por-request + auto_route, e o proprio HermesExecutiveMeshService re-gateia. Provado: testes do advisor (execution_route), do runner (frota fake, no spend), e seam do gateway (decide surfa no path lido). Default-off => `execution_route` fica 'single' e nada muda.
---
# Atlas Hermes Executive Mesh

## Resumo

O Executive Mesh e a camada que transforma o runtime executivo Hermes de "um
executor por missao" em uma **frota multi-agente governada**. Ele recebe uma
missao-pai selada (`atlas.hermes.executive_mission.v1`) e uma decomposicao em N
subtarefas, e para cada subtarefa monta uma missao-filha com **profile
especializado** (toolset/provider/skills por papel), **worktree isolado** e
**checkpoints**, despacha tudo como uma **frota paralela com teto de
concorrencia** (usando os primitivos reais do Hermes: delegation, batch e
worktree), coleta os `HermesResultPacket` selados de cada filho e **reconcilia**
o conjunto em uma evidencia agregada que roteia candidatos para os gates Atlas.

E a resposta direta a pergunta "como usar numeros multi-agente": a potencia de
paralelismo do Hermes (subagentes, batch, worktrees, profiles) so vira util
quando **o Atlas e dono do plano, do teto e da reconciliacao**. O mesh nunca
cede essa autoridade ao Hermes (`hermes_mesh_can_decide` e sempre `false`).

## Decisao Executiva

A tese N×M exige um orquestrador soberano, nao um "ligar tudo do Hermes". O
Hermes oferece a multiplicacao operacional (N agentes em paralelo); o Atlas
adiciona a sua (M): plano selado, profiles governados, tetos clampados,
leaf-block, checkpoints/rollback como politica, sessoes como evidencia e
reconciliacao com gate. O resultado e execucao em escala **auditavel,
reversivel e local-first**, sem segunda surface, sem segundo cerebro e sem
segunda memoria canonica.

## Papel no Atlas

O mesh e um **modulo consumidor** do Executive Runtime e do Capability Registry.
Ele e acionado pelo Atlas Decide / Forge quando uma missao se beneficia de
decomposicao paralela (migracao, varredura, auditoria, refator amplo). Continua
valendo a separacao de poder: o ATLS detem intencao, contexto, policy,
verificacao, memoria e verdade; o Hermes detem apenas a execucao de cada
missao-filha. O mesh e o ponto onde a "Executive Mesh" do roadmap vira runtime.

## Onde Se Encaixa

- **Acima:** Atlas Decide / Forge decide que uma missao vira mesh e fornece a
  decomposicao (ou a heuristica de decomposicao).
- **Dentro:** o planner sela o plano; o profile resolver especializa cada filho;
  a checkpoint policy decide snapshot/rollback; o preflight checa o ambiente; o
  servico orquestrador despacha a frota; o reconciler agrega.
- **Abaixo:** o `HermesDelegationAdapter` clampa os caps de subagentes; o
  `HermesCliProvider` executa cada missao-filha (`hermes chat` + worktree +
  profile via HERMES_HOME gerenciado); cada filho devolve `result_packet.v1`.
- **Lateral:** o Capability Registry diz quais toolsets/capacidades existem; o
  Evidence Ledger recebe os recibos; os gates (memory/skill/schedule) recebem os
  candidatos da reconciliacao.

## Fluxo

1. **Decompor** — Atlas fornece subtarefas `[{objective, role, toolsets?,
   worktree?, success_criteria?}]`.
2. **Planejar** — `HermesExecutiveMeshPlanner::plan()` sela um
   `atlas.hermes.mesh_plan.v1`: filhos (objetivo so como hash), profile/worktree/
   checkpoints por filho, `max_parallel_workers` clampado, `mesh_enabled` e
   `dispatch_allowed_now` (so true com policy + subtarefas validas).
3. **Especializar** — `HermesProfileResolver::resolve(role, manifest)` resolve
   toolset/provider/skills do papel, filtrando ao que o manifest suporta.
4. **Proteger** — `HermesCheckpointPolicy::decide(mission, mode)` liga snapshot
   antes + rollback no verifier falho, so em write/danger sob policy.
5. **Preflight** — `HermesDoctorPreflight::assess()` transforma a saida read-only
   de `hermes --version/doctor/status` em evidencia de saude do ambiente.
6. **Despachar** — o servico orquestrador monta uma missao-filha por subtarefa
   (reusando `HermesExecutiveMissionFactory`), aplica o profile e o worktree, e
   executa a frota respeitando o teto de concorrencia e os caps de delegation.
7. **Coletar** — cada filho devolve um `atlas.hermes.result_packet.v1`.
8. **Reconciliar** — `HermesMeshReconciler::reconcile(plan, packets)` sela um
   `atlas.hermes.mesh_reconciliation.v1` com status por filho, agregado,
   `evidence_refs` e `memory_candidate_count` roteado aos gates.

## Contratos

- `HermesExecutiveMeshPlanner::plan(AiJob $job, array $parentMission, array $subtasks, array $policy): array`
  -> `atlas.hermes.mesh_plan.v1`. Fail-closed: `dispatch_allowed_now=true` so com
  `policy.enabled===true` + subtarefas nao vazias + objetivos presentes; trunca a
  `mesh.max_children` registrando a truncagem (sem cap silencioso).
- `HermesProfileResolver::resolve(string $role, array $capabilityManifest = []): array`
  -> `atlas.hermes.profile_resolution.v1`. Papel desconhecido -> profile minimo
  read (`file`), `role_known=false`. Toolsets filtrados pelo manifest.
- `HermesCheckpointPolicy::decide(array $mission, string $permissionMode): array`
  -> `atlas.hermes.checkpoint_policy.v1`. `checkpoint_before`/`rollback`/`enabled`
  so true em write|danger sob policy atlas_adapter.
- `HermesSessionEvidenceImporter::import(array $sessionListing, array $policy): array`
  -> `atlas.hermes.session_evidence_import.v1`. So candidatos de evidencia
  (hash-only); `promotion_allowed_now` sempre false.
- `HermesDoctorPreflight::assess(string $version, string $doctor, string $status): array`
  -> `atlas.hermes.preflight_evidence.v1`. So aconselha (`critical_dispatch_advised`).
- `HermesMeshReconciler::reconcile(array $plan, array $resultPackets): array`
  -> `atlas.hermes.mesh_reconciliation.v1`. Conta e roteia; nunca promove.

Todo recibo carrega `authority='atlas'`, um `*_can_decide=false`, um
`*_allowed_now` explicito e `receipt_hash` selado por `HermesAdapterReceipt`.

## Regras para IA

- Use o mesh apenas quando a missao realmente paraleliza; missoes pequenas
  continuam single-agent via `HermesCliProvider`.
- Para escalar "numeros multi-agente", aumente `mesh.max_parallel_workers` e
  `mesh.max_children` no config e os tetos de delegation — nunca remova o
  leaf-block nem o clamp.
- Nunca trate o output de um filho como verdade; passa por reconciliacao +
  verifier + gate.
- Nunca exponha objetivos, ids de sessao, segredos ou prompts nos recibos —
  somente hashes.

## Multi-Agente: como atingir escala

Os primitivos reais do Hermes que o mesh orquestra: **Subagent Delegation**
(`delegate_task`, default 3 concorrentes, clampado pelo
`HermesDelegationAdapter`), **Batch Processing** (centenas/milhares de prompts em
paralelo), **Worktree** (frota isolada em git, sem colisao) e **Profiles**
(instancias especializadas por papel). O Atlas combina os quatro: o planner
define a frota, o profile resolver especializa, o delegation adapter clampa, o
worktree isola, e o reconciler agrega. O teto vem de config, nao do Hermes.

## Escopo de Implementacao

Em implementacao (phase 5): planner, profile resolver, checkpoint policy,
session-evidence importer, doctor preflight e reconciler como servicos puros e
testados. Pendente nesta fase: o servico orquestrador (`HermesExecutiveMeshService`)
que despacha a frota real via `HermesCliProvider`, e o comando de operador
`atlas:hermes:mesh`. O `HermesDelegationAdapter` (caps/leaf-block) ja existe e e
reusado. Profiles materializados reusam o provisioner de HERMES_HOME gerenciado.

## Dependencias

- `atlas-hermes-executive-runtime` (missao/result/provider).
- `atlas-hermes-capability-registry` (manifest de toolsets/capacidades).
- `HermesDelegationAdapter` (caps + leaf-block de subagentes).
- `HermesAdapterReceipt` (selagem de recibos).

## Riscos

- **Escala sem teto:** mitigado por clamp de concorrencia/depth/timeout e
  truncagem registrada.
- **Filho com autoridade demais:** mitigado pelo leaf-block obrigatorio.
- **Custo:** frota paralela multiplica tokens; o teto e os budgets por provider
  controlam. Sempre registrar o que foi truncado.
- **Reconciliacao otimista:** status por filho e conservador (missing/failed
  explicito); nada promove sem gate.

## Exemplos

- **Migracao ampla:** decompor por modulo -> N filhos coder em worktrees
  separados -> reconciliar -> Memory Gate so promove o que o verifier aprovou.
- **Auditoria:** N filhos researcher/ops read-only -> evidencias agregadas ->
  sem mutacao, sem promocao automatica.

## Evidencias

- Codigo dos seis componentes em `app/Services/Ai/Hermes/Mesh/`.
- Recibos selados `atlas.hermes.mesh_plan.v1` e `atlas.hermes.mesh_reconciliation.v1`.
- Testes unitarios por componente em `tests/Unit/Ai/Hermes/Mesh/`.

## Proximas Acoes

- Ligar `HermesExecutiveMeshService` ao provider para despacho real da frota.
- Expor `atlas:hermes:mesh` (plan/dispatch/status/reconcile).
- Habilitar profiles por dominio e o teto de paralelismo desejado via config.
