---
id: atlas-forge-elite-execution-audit-2026-07-09
type: engineering_knowledge
title: Atlas Forge Elite Execution Audit 2026-07-09
status: source_material
category: architecture
priority: 86
summary: Auditoria evidence-first do Atlas Forge contra o telos de executar Obras de uma semana a cinco meses continuamente, com o operador concentrado no comissionamento e planejamento, sem babysitting do runtime.
tags:
  - atlas-forge
  - elite-executor
  - long-horizon
  - architecture-audit
capabilities:
  - atlas_forge_execution_audit
  - long_horizon_autonomy_assessment
decisions:
  - Forge e um regime de Obra longa da mesma fabrica elite, nao um executor de qualidade inferior ou um simples planner.
  - Persistencia, scheduling e safe simulation nao podem ser promovidos a entrega de Obra sem execucao, verificacao e release reais.
  - Certificacao estrutural deve ser nomeada separadamente de readiness operacional e outcome observado.
maintenance:
  - Revalidar apos mudancas no execution cycle, long-horizon state, provider topology, continuity, Engineering Kernel ou Golden Obra.
related_paths:
  - docs/engineering-knowledge-base/atlas-forge-operating-system-contracts.md
  - docs/engineering-knowledge-base/atlas-forge-operating-system-runbook.md
  - docs/engineering-knowledge-base/atlas-forge-real-autonomous-authority-build-plan.md
  - docs/engineering-knowledge-base/atlas-real-engineering-execution-kernel.md
  - docs/engineering-knowledge-base/atlas-canonical-glossary-and-naming.md
  - app/Services/Ai/Programming/Forge
  - app/Services/Ai/EngineeringKernel
doc_schema: atlas_canonical_module_doc.v1

graph_id: atlas-forge-elite-execution-audit-2026-07-09
graph_title: Atlas Forge Elite Execution Audit 2026-07-09
graph_world: atlas
graph_layer: system
graph_kind: module
graph_parent: atlas-ai-architecture-audit-readme
graph_status: active
graph_source: repo
human_name: Atlas Forge Elite Execution Audit 2026-07-09
canonical_name: Atlas Forge Elite Execution Audit 2026-07-09
technical_name: atlas-forge-elite-execution-audit-2026-07-09
cartography_type: module
canonical_source: docs/engineering-knowledge-base/architecture-audit/atlas-forge-elite-execution-audit-2026-07-09.md

owner: architecture-audit
repo_paths:
  - docs/engineering-knowledge-base/architecture-audit/atlas-forge-elite-execution-audit-2026-07-09.md
allowed_changes:
  - Atualizar o snapshot quando evidencia de Obras reais, continuidade ou contratos mudar.
forbidden_changes:
  - Chamar safe simulation, structural pass ou estado persistido de Obra entregue.
  - Declarar autonomia longa ou superioridade externa sem execucao observada.
depends_on:
  - atlas-ai-architecture-audit-readme
  - atlas-real-engineering-execution-kernel
flows_to:
  - atlas-elite-engineering-kernel-audit-2026-07-09
unlocks:
  - atlas-forge-runtime-closure
governs:
  - architecture-audit
evidence:
  - docs/engineering-knowledge-base/architecture-audit/atlas-forge-elite-execution-audit-2026-07-09.md
evidence_refs:
  - symbol: ForgeWorkPacketExecutionCycleService
  - symbol: ForgeWorkPacketExecutionCycle
  - symbol: ForgeMultiAgentSchedulerService
  - symbol: AtlasForgeGateAdapter
  - command: atlas:forge:continuum-certify
  - command: atlas:forge:runtime-certify
required_tests:
  - "php artisan atlas:engineering:knowledge docs-health --json"
requires_evidence: true
risk_level: high
visual_tags:
  - domain
  - long-horizon
  - architecture-audit
ai_entrypoints:
  - Leia primeiro Resumo, Matriz de realidade e Snapshot live.
ai_usage_notes:
  - Este arquivo e auditoria datada; nao e autoridade para criar um novo Forge paralelo.
quality_gates:
  - "php artisan atlas:engineering:knowledge docs-health --json"
failure_modes:
  - Confundir controle de Obra com execucao de Obra.
  - Sintetizar receipts de sucesso em simulacao.
  - Selecionar provider por capacidade declarada diferente da capacidade executavel.
observability_signals:
  - atlas:forge:continuum-certify --json
  - atlas:forge:runtime-certify --json
  - atlas:code:forge-ux --json
next_actions:
  - Construir e provar um supervisor duravel que drene uma Golden Obra real.
implementation_state: audit_snapshot_2026_07_09
line_limit: 520
---
# Atlas Forge — auditoria do executor de Obras

## Papel no Atlas

Este snapshot foi produzido em 9 de julho de 2026. Ele preserva achados; nao
substitui contratos do Forge, nao promove planos a runtime e nao cria um novo
owner arquitetural.

O telos avaliado e:

- Forge recebe uma Obra de uma semana a dois, tres ou cinco meses;
- o operador participa do comissionamento, da intencao e do planejamento de
  alto nivel;
- depois disso o Forge executa continuamente, recupera-se e segue ate concluir
  a Obra principal e as subobras necessarias;
- bloqueios tecnicos ordinarios sao resolvidos pelo sistema; so escolhas que
  mudam o produto, autoridade, risco ou budget voltam ao operador;
- a barra de software e a mesma de Dev e Autonomos: elite mundial.

## Resumo

O Forge atual e um **control plane substancial de Obras**, com intake,
persistencia, work packets, milestones, long-horizon state, topologia,
capacidade, scheduling, review surfaces e outcome memory.

Ele ainda nao e o **executor continuo de Obras** do telos. A evidencia local
mais forte e simples:

- zero ciclos Forge reais persistidos;
- dois ciclos persistidos, ambos `safe_simulation`;
- uma Obra live, em `blocked_definition` e 15% no cockpit;
- nenhum dispatch, diff, teste, review, release ou Evidence Ledger dessa Obra;
- zero continuation packs ligados a Forge/Obra;
- topologia que selecionava `claude_cli`, enquanto o driver runtime o reportava
  sem configuracao/autorizacao.

Certifiers passaram porque encontraram estrutura local, classes, rotas,
catalogos e bindings. Isso e util, mas nao prova que uma Obra foi executada.

Em uma frase: **Forge tem o esqueleto de comando de uma fabrica longa, mas o
musculo canônico que trabalha por semanas ou meses ainda nao esta fechado**.

## Onde Se Encaixa

| Momento | Telos | Estado atual |
|---|---|---|
| Comissionamento | Operador define resultado, limites e budget | Intake e definition gates existem |
| Planejamento | Co-planejamento de milestones e criterios | Existe, mas a decomposicao observada e simplificada |
| Execucao | Forge drena a Obra continuamente | Nao comprovada; scheduler planeja, nao inicia workers |
| Blockers | Sistema replaneja e resolve tecnicamente | Muitos caminhos retornam `resolve_blocker` ao operador |
| Continuidade | Restart/crash/provider swap sem perda | Estado existe; leases, heartbeat e recovery longos nao foram provados |
| Review/release | Verificacao soberana e release governado | Surfaces existem; nenhum ciclo real completo observado |
| Aprendizado | Cada Obra melhora a proxima | Memoria existe, corpus real e causalidade insuficientes |

Hoje o operador ainda precisa criar/selecionar a Obra, resolver ambiguidade,
autorizar provider e budget, fornecer certas evidencias, destravar blockers,
aprovar decisoes de review/rollback e reiniciar ou drenar a execucao. Isso e
retomada manual governada, nao ausencia de babysitting.

## Fluxo

```text
commissioning
  -> charter + frozen success criteria + budget + authorities
  -> architecture/world model + dependency graph + milestones
  -> admitted work packets
  -> durable supervisor leases workcells
  -> provider/tool execution in governed sandboxes
  -> continuous evidence + checkpoint + replanning
  -> Verification Court + repair + integration
  -> canary/release/rollback under Governor
  -> outcome window + lessons + next-work derivation
```

O fluxo atual chega bem ate intake, persistencia e planejamento. Scheduling
gera uma proposta; os execution cycles inspecionados declaram provider
invocation e file mutation fora de seu escopo. A continuidade consegue guardar
estado, mas nao ha um supervisor unico comprovado que mantenha o ciclo vivo e
feche release.

## Inventario de metodos e recursos

| Capacidade | O que existe | Limite observado |
|---|---|---|
| Obra intake | definition, readiness e cockpit | Obra live bloqueada por definicao |
| Decomposicao | milestones, packets e dependency fields | Cinco milestones estaticos e pouca prova de critical path |
| Multi-agent | schedules, roles, topology, reservations | Planner/evaluator; nao spawna executores reais |
| Long horizon | estados, checkpoints e continuation builder | Sem Forge pack live; idempotencia/lease insuficientes |
| Provider | capacity, topology, invocation/router | Fontes de verdade divergiram sobre configuracao |
| Execucao | dois execution cycle implementations | Ambos sem provider/file mutation canonicos |
| Verificacao | gates, receipts e certifiers | Muitos checks estruturais ou observe-only |
| Integracao | review/release/rollback services | Nao demonstrada por uma Obra real |
| Learning | outcome/failure memory | Apenas dois outcomes; sem uplift longitudinal |
| Custo | budget/capacity contracts | Ausencia de campanha longa real para calibrar |

## Matriz de realidade

| Camada | Concebida | Implementada | Wired live | Provada em Obra real |
|---|---:|---:|---:|---:|
| Intake e charter | Sim | Sim | Sim | Parcial |
| Milestones/work packets | Sim | Sim | Sim | Nao |
| Scheduler/topology | Sim | Sim | Parcial | Nao |
| Provider invocation | Sim | Sim em seam separado | Parcial | Nao |
| Executor continuo | Sim | Fragmentado | Nao | Nao |
| Continuidade/recovery | Sim | Parcial | Parcial | Nao |
| Verification/release | Sim | Sim | Parcial | Nao |
| Learning | Sim | Parcial | Parcial | Nao |
| Superioridade maior que 10x | Sim | Harness parcial | Nao | Nao |

### Duas implementacoes sobrepostas

Existem `ForgeWorkPacketExecutionCycleService` e
`Execution/ForgeWorkPacketExecutionCycle`. As duas representam partes da mesma
unidade de trabalho, e ambas deixam invocacao e mutacao reais fora do proprio
escopo. Scheduler e parallel coordinator tambem sao plan/evaluator-only.

Isso cria uma interface rasa: para entender “execute este packet”, o chamador
precisa conhecer varios services, bridges, flags e modos. O modulo profundo
desejado teria uma operacao de Obra admitida e esconderia provider, lease,
sandbox, checkpoint, repair e receipt dentro de um contrato unico.

## Safe simulation e verdade de estado

Um dos ciclos sintetiza `work_packet_receipts`, `verification_receipt` e refs
`simulation://`. Testes codificam que essa simulacao pode retornar sucesso e
marcar packet como `done`. O milestone gate verifica principalmente a presenca
do tipo de receipt, sem provar sua proveniencia real.

Este e um gap de honestidade estrutural: planejado/simulado e entregue nao
podem convergir no mesmo estado terminal. O correto e usar namespaces e
projecoes distintas:

```text
simulation_succeeded != execution_succeeded != verified != released
```

Nenhum aggregate de producao deve avancar por `simulation://`.

## Provider topology e capacidade executavel

O read model de capacity declarou quatro de cinco providers disponiveis e
preferiu `claude_cli`. O driver router, que e quem efetivamente invoca, mostrou
Claude, Codex e Gemini CLI sem configuracao/autorizacao; outros drivers eram os
configurados.

O Forge nao pode manter duas verdades para provider readiness. Topology,
capacity, budget e invocation precisam consultar a mesma fonte executavel e a
mesma policy receipt. Provider nao autorizado pode aparecer como candidato
futuro, jamais como lane live selecionada.

## Continuidade de uma semana a cinco meses

Persistir JSON nao basta para autonomia longa. O runtime necessita:

- lease/version por Obra e packet;
- heartbeat e deteccao de worker morto;
- checkpoint atomico e replay idempotente;
- retry classificado por failure taxonomy;
- provider failover sem quebrar spec/evidence lineage;
- critical path e capacidade recalculados;
- cancellation, budget stop e kill switch;
- recovery automatico depois de restart;
- continuation pack deterministico e freshness policy;
- review/release que nao dependam de uma sessao aberta.

O aggregate `AiForgeLongHorizonState` e mutavel e a vistoria nao encontrou lock
transacional/optimista no core. O continuation builder declara nao ser
idempotente. Nao foi observado schedule periodico drenando Obras. O capability
catalog ainda informou `background_allowed=false`. Isso impede chamar o estado
atual de runtime continuo.

Dois defeitos concretos agravam recovery: `computeNextAction()` pode emitir
`obra_completed` quando nao ha milestone corrente antes de o aggregate ser
concluido, e o parallel durable coordinator inclui reservations expiradas no
conjunto de locks antes de marca-las para release.

## Certificacao estrutural versus operacional

Na vistoria:

- `atlas:forge:continuum-certify` retornou disponibilidade estrutural;
- `atlas:forge:runtime-certify` passou/ficou disponivel em verificacoes locais;
- `atlas:programming:dev-forge-flow-certify` passou 7/7 e declarou que
  certificava apenas wiring local;
- TEOS continuava parcial;
- o cockpit da unica Obra continuava `blocked_definition`, sem execution.

Os nomes e schemas devem expor tres degraus que nao podem ser confundidos:

1. `structural_wiring_passed`;
2. `operational_run_ready`;
3. `executed_observed_and_verified`.

## Snapshot live

Leitura da DB local em 2026-07-09:

| Artefato | Quantidade / estado |
|---|---|
| Intakes | 3: dois ready, um blocked |
| Work packets | 4: um done, tres proposed |
| Milestones | 15: todos pending |
| Long-horizon states | 3: um active, dois blocked |
| Execution cycles | 2: um success, um blocked |
| Ciclos reais | 0 |
| Ciclos safe simulation | 2 |
| Multi-agent schedules | 2: um ready, um conflict |
| Workcell routes | 2 |
| Outcome memories | 2 |
| Continuation packs | 7 genericos; zero Forge/Obra |
| Obras observadas | 1, bloqueada na definicao |

Este snapshot pode mudar. Ele prova que a unidade de valor “Obra real concluida”
ainda nao esta representada no corpus local.

## Barra de qualidade

O Forge herda uma ambicao correta de spec congelada, evidence grammar,
Verification Court, completion governor, rollback e learning. Na implementacao,
o execution gate e observe por default, um ciclo usa o adapter Dev e `complete()`
pode aceitar pelo menos um gate passado em vez de exigir o conjunto obrigatorio.
O `EliteExecutorKernel` tambem e nullable no ciclo/intake, e o service provider
pode capturar falha de construcao e continuar; assim, a barra pode estar ausente,
nao apenas em observe.

Assim, a arquitetura de qualidade e rica, mas a prova e fraca. A barra comum so
e comum se o mesmo bundle real for produzido e o mesmo verdict bloquear todos
os modos. Adapter com defaults, flag observe e receipt sintetico quebram essa
equivalencia.

## Scorecard

Notas de desenho medem coerencia/completude do target; notas operacionais usam
`0` inexistente, `5` parcial/utilizavel, `8` forte/integrado e `10` prova mundial
repetivel. Nao se calcula media entre as duas rubricas.

| Dimensao | Nota | Justificativa curta |
|---|---:|---|
| Telos e contratos | 8.2 | A Obra longa esta bem descrita |
| Arquitetura conceitual | 7.5 | Control plane abrangente e boas fronteiras desejadas |
| Arquitetura runtime consolidada | 4.0 | Ciclos sobrepostos e executor canônico ausente |
| Wiring operacional | 4.0 | Persistencia live; execution/drain incompletos |
| Qualidade de prova | 2.5 | Apenas simulacoes; gates estruturais/observe |
| Autonomia longa | 1.5 | Nenhuma Obra real, nenhum periodo continuo provado |
| Compounding | 3.0 | Mecanismos com corpus minimo e sem uplift causal |
| Prontidao maior que 10x | 1.0 | Nenhum baseline ou outcome comparativo |

**Nota de estrutura: 7,5/10 no desenho e 4,0/10 como arquitetura runtime.
Nota de qualidade operacional comprovada: 2,5/10. Prontidao global contra o
telos de Obra continua: 2,5/10.**

A nota baixa nao indica falta de codigo. Ela usa como unidade de valor a Obra
real entregue, e essa unidade ainda nao foi observada.

## Gaps prioritarios

1. Separar estados e receipts de simulacao dos aggregates de producao.
2. Escolher um execution cycle canônico e ligar provider, sandbox, mutation,
   verification e receipt reais sob ele.
3. Colocar todos os gates obrigatorios em enforce; verdict nao promovido bloqueia.
4. Fazer capacity/topology consumir o driver router executavel.
5. Criar supervisor duravel por Obra com lease, heartbeat, recovery, retry,
   idempotencia, drain automatico e budget stop.
6. Completar o contrato persistente de authority, forbidden scope, rollback,
   artifacts, integration e release.
7. Qualificar certifiers como structural, operational ou executed-observed.
8. Rodar Golden Obra progressiva de 24 horas, 7 dias e 30 dias, incluindo
   restart, provider failure, conflito, repair, rollback e release.

## Contratos

- Planejar nao e executar; simular nao e verificar; verificar nao e liberar.
- Uma Obra tem um unico supervisor/aggregate autoritativo.
- Cada transition exige evidence refs reais e hash/proveniencia consistente.
- Falha de worker ou provider nao devolve babysitting ordinario ao operador.
- A mesma barra soberana de Dev e Autonomos deve bloquear Forge por default.
- Nenhum certifier pode produzir linguagem mais forte que sua evidencia.

## Regras para IA

- Nao chamar scheduler de executor.
- Nao usar ciclos `safe_simulation` como corpus de delivery.
- Nao multiplicar quantidade de services por maturidade.
- Nao criar um terceiro execution cycle; consolidar o owner existente.
- Nao declarar continuidade sem teste de restart e periodo sustentado.

## Escopo de Implementacao

Este audit nao autoriza a implementacao dos gaps. Cada correcao deve entrar por
placement, Obra/AP, allowed files, migration safety e gates correspondentes.

## Dependencias

Depende de Mission/Obra Control, Policy Plane, provider runtime, sandbox/tool
runtime, Engineering Kernel, Spec/Verification Courts, Governor, Evidence
Ledger, long-horizon state, budget e outcome learning.

## Evidencias

Comandos e surfaces inspecionados no commit `7c6e729b8a84`:

- `php artisan atlas:forge:provider-capacity --workspace="$PWD" --json`;
- `php artisan atlas:forge:provider-invoke --obra=<obra-id> --driver-status --json`;
- `php artisan atlas:forge:continuum-certify --obra=<obra-id> --json`;
- `php artisan atlas:forge:runtime-certify --obra=<obra-id> --json`;
- `php artisan atlas:programming:dev-forge-flow-certify --json`;
- `php artisan atlas:code:forge-ux --obra=<obra-id> --json`;
- `php artisan atlas:code:forge-intake --obra=<obra-id> --show --json`.

`atlas:forge:provider-topology` nao aceita `--obra`; isolado, ele retorna o
blocker de Obra ausente. Counts vieram de queries read-only da DB local; os
testes `ForgeWorkPacketExecutionCycle*Test` fixam simulation e observe mode.

Paths centrais:

- `app/Services/Ai/Programming/Forge/ForgeWorkPacketExecutionCycleService.php`;
- `app/Services/Ai/Programming/Forge/Execution/ForgeWorkPacketExecutionCycle.php`;
- `app/Services/Ai/Programming/Forge/ForgeMultiAgentSchedulerService.php`;
- `app/Services/Ai/EngineeringKernel/Adapters/AtlasForgeGateAdapter.php`.

## Riscos

- DB local nao representa necessariamente uma instalacao produtiva completa.
- A arvore estava suja; o snapshot nao e certificacao de release.
- A bateria de testes Forge iniciada durante a auditoria nao terminou; por isso
  este documento nao reivindica fresh-green.
- Certifiers locais podem validar presenca sem exercitar provider ou filesystem.

## Exemplos

Uma Golden Obra valida mais que um demo feliz: continua apos restart, troca de
provider, blocker, patch recusado e conflito; conserva spec e budget; repara;
faz release/rollback; e deixa evidencia que permite ao Forge seguinte melhorar.

## Proximas Acoes

O primeiro gate e executar uma Obra real de 24 horas sem `simulation://`. So
depois deve-se estender para sete e trinta dias. A metrica decisiva e resultado
verificado por intervencao humana, nao numero de packets criados.
