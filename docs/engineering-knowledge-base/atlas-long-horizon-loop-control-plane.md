---
id: atlas-long-horizon-loop-control-plane
type: engineering_knowledge
title: Atlas Long-Horizon Loop Control Plane
status: future
category: agentic-engineering
priority: 106
implementation_state: proposal_contract_not_runtime
summary: Doc-mae de hardening do loop longo do Atlas: organiza AP-790, AP-805, AP-806, AP-807, AP-808, AP-809, AP-810 e AP-793 em uma ordem unica de implementacao para 10 ciclos, 24h, 7d e meses sem falso sucesso, provider waste, recovery filler ou sujeira de branches/worktrees.
human_summary: Plano-mae para transformar o loop em um sistema operacional robusto para longas horas.
human_what: Define a ordem de implementacao do Loop Preflight, Cycle Firewall, Post-Cycle Auditor, Loop Assurance Kernel, Chaos Certification, Run Supervisor, Backlog Depth Governor, Cycle Quality Score, Replay/Recovery, Months-Scale Reliability Platform, Enterprise Delivery Block e isolamento L2.
human_purpose: Fazer o loop melhorar o AAEOS e a fabrica de engenharia por muitas horas com honestidade, evidencia e parada segura.
human_input: area/focus, backlog canonico, docs Factory/evolution, envelope de autonomia, lane/main policy, provider budget, packets, receipts e resultados de ciclos.
human_output: Sequencia de implementacao, gates, criterios de pronto, sinais de sucesso e blockers honestos para 24h/7d/30d.
human_change_when: Atualize quando AP-790/AP-805/AP-806/AP-807/AP-808/AP-809/AP-810/AP-793, backlog AAEOS, Self-Construction bridge, integration lane ou politica 24h/7d/30d mudarem.
human_block_when: Bloqueie quando IA tentar declarar 24h pronto sem firewall por ciclo, auditor pos-ciclo, backlog depth, cleanup final e evidencia de merge real.
tags:
  - atlas-ai
  - long-horizon-loop
  - continuous-stewardship-loop
  - area-focus-loop
  - software-company-stewardship
  - aaeos
  - factory-max
capabilities:
  - long_horizon_loop_control_plane
  - loop_preflight_firewall
  - post_cycle_auditor
  - run_supervisor_24h
  - backlog_depth_governor
  - cycle_quality_score
  - replay_recovery
  - isolated_agent_execution
  - loop_assurance_kernel
  - chaos_certification
  - months_scale_reliability
  - enterprise_delivery_block
decisions:
  - This long-horizon loop control plane is subordinate to Atlas Autonomous Engineering Government.
  - 24h/7d/months-scale claims require Task Fabric, Maestro, Verification Court, Merge Governor, Learning Transfer and project-lane isolation when external projects are involved.
  - Este doc e a doc-mae de hardening operacional do loop longo; nao cria OS novo, scheduler novo, provider path novo ou loop paralelo.
  - AP-790 continua sendo o runner 24h; AP-805 e readiness; AP-806 e autonomia/envelope/backlog; AP-807 e firewall/auditor por ciclo; AP-808 e assurance/chaos; AP-809 e confiabilidade mensal; AP-810 e bloco enterprise de entrega por slices; AP-793 e isolamento de agente.
  - O loop so merece rodar 24h quando consegue bloquear antes de gastar provider, auditar depois de cada ciclo e parar honesto quando backlog acaba.
  - Docs Factory/evolution alimentam backlog de alto impacto, mas nao viram runtime authority sem canonical backlog, Self-Construction packet e gates.
  - Long-horizon robustness nao significa ausencia de erro; significa fail-closed, evidencia, recuperacao e nenhuma contagem falsa de sucesso.
maintenance:
  - Manter abaixo de 520 linhas.
  - Atualizar antes de criar AP novo de hardening do loop.
  - Quando uma fase for implementada, registrar evidence e mover implementation_state para partial/implemented.
related_paths:
  - docs/engineering-knowledge-base/atlas-autonomous-engineering-government.md
  - docs/engineering-knowledge-base/atlas-software-company-stewardship-stack.md
  - docs/engineering-knowledge-base/atlas-area-stewardship-layer.md
  - docs/engineering-knowledge-base/atlas-agentic-engineering-os-runtime-gap-matrix.md
  - docs/engineering-knowledge-base/atlas-canonical-glossary-and-naming.md
  - docs/ap/AP-790-reliable-24h-autonomous-loop-runner-contract.md
  - docs/ap/AP-793-atlas-isolated-agent-execution-substrate-contract.md
  - docs/ap/AP-805-ten-cycle-readiness-governor-contract.md
  - docs/ap/AP-806-loop-autonomy-certification-contract.md
  - docs/ap/AP-807-loop-preflight-cycle-firewall-post-cycle-auditor-contract.md
  - docs/ap/AP-808-loop-assurance-kernel-and-chaos-certification-contract.md
  - docs/ap/AP-809-months-scale-autonomous-loop-reliability-platform-contract.md
  - docs/ap/AP-810-long-horizon-loop-enterprise-delivery-block-contract.md
doc_schema: atlas_canonical_module_doc.v1
graph_id: atlas-long-horizon-loop-control-plane
graph_title: Atlas Long-Horizon Loop Control Plane
graph_world: atlas
graph_layer: system
graph_kind: policy
graph_parent: atlas-software-company-stewardship-stack
graph_status: future
graph_source: repo
human_name: Long-Horizon Loop Control Plane
canonical_name: Atlas Long-Horizon Loop Control Plane
technical_name: atlas-long-horizon-loop-control-plane
cartography_type: policy
canonical_source: docs/engineering-knowledge-base/atlas-long-horizon-loop-control-plane.md
owner: software_company_stewardship
repo_paths:
  - docs/engineering-knowledge-base/atlas-long-horizon-loop-control-plane.md
allowed_changes:
  - Ajustar ordem de implementacao, gates, DoD e evidencias conforme o loop evolui.
forbidden_changes:
  - Declarar 24h/7d pronto sem evidencia runtime.
  - Transformar Factory/evolution proposals em execucao direta sem packet pequeno.
  - Criar loop paralelo fora do Stewardship/Area Focus Loop.
depends_on:
  - atlas-software-company-stewardship-stack
  - atlas-agentic-engineering-os-runtime-gap-matrix
flows_to:
  - continuous_stewardship_loop
  - area_focus_loop
  - aaeos_high_impact_backlog
unlocks:
  - safe_24h_loop_execution
  - ten_cycle_real_certification
  - long_horizon_aaeos_self_improvement
governs:
  - atlas.software_company_stewardship.long_horizon_loop_control_plane
evidence:
  - docs/engineering-knowledge-base/atlas-long-horizon-loop-control-plane.md
required_tests:
  - "atlas engineering knowledge docs-health"
  - "php artisan atlas:ai:architecture-validate --json"
requires_evidence: true
risk_level: critical
ai_entrypoints:
  - Leia antes de implementar AP-807, AP-808, 10 ciclos, 24h, 7d ou loop AAEOS factory_max.
quality_gates:
  - docs-health
  - architecture-validate
  - no-runtime-claim-without-evidence
failure_modes:
  - Loop gasta provider em candidato que deveria bloquear no preflight.
  - Loop mergeia com judge repair_required e chama isso de sucesso.
  - Loop roda 24h com backlog raso e cai em filler/recovery.
  - Loop termina com locks, worktrees, branches ou provider processes orfaos.
observability_signals:
  - loop_preflight_block_rate
  - provider_calls_blocked_before_cost
  - post_cycle_audit_violation_count
  - admissible_packet_depth
  - valid_cycle_quality_score
next_actions:
  - Implementar AP-807 Loop Preflight + Cycle Firewall antes de qualquer nova prova longa.
  - Implementar AP-807 Post-Cycle Auditor antes de contar ciclos como sucesso.
  - Implementar AP-808 Loop Assurance Kernel e Chaos Certification antes de 24h.
  - Implementar AP-809 Months-Scale Reliability Platform antes de 7d/30d.
  - Implementar AP-810 delivery ledger para coordenar todos os slices como bloco enterprise.
  - Implementar Run Supervisor 24h para heartbeat, stall detection, cleanup final e processos orfaos.
  - Implementar Backlog Depth Governor para bloquear 24h quando nao houver packets suficientes.
  - Implementar Cycle Quality Score para separar merge valido de avanco valioso.
  - Implementar Replay/Recovery antes de claims de 7d.
  - Promover isolamento AP-793 L2 antes de claims de semanas/meses unattended.
---

> ⚠️ **ARQUITETURA FINAL:** 24h/7d/months-scale autonomy is governed by
> `docs/engineering-knowledge-base/atlas-autonomous-engineering-government.md`.
> This doc hardens long-horizon Loop execution, but it is not the full final
> architecture. Long runs must be admitted through Self-Construction Control
> Plane, Task Fabric, Maestro, Verification Court, Merge Governor and Learning
> Transfer.


# Atlas Long-Horizon Loop Control Plane

## Resumo

Este e o documento-mae de hardening operacional do loop longo. Ele consolida a
documentacao ja criada para AP-807 (Loop Preflight + Cycle Firewall e
Post-Cycle Auditor) e adiciona os proximos patamares: AP-808 Loop Assurance
Kernel, Chaos Certification, AP-809 Months-Scale Reliability Platform, AP-810
Enterprise Delivery Block, Run Supervisor 24h, Backlog Depth Governor, Cycle
Quality Score, Replay/Recovery e isolamento L2.

Nao e claim de runtime pronto. O loop so esta pronto para longas horas quando
cada fase tiver evidence runtime, teste, receipt e auditoria.

## Papel no Atlas

O papel deste control plane e transformar o loop de "tentativa de execucao" em
um sistema operacional robusto. Ele nao promete ausencia de erro; ele promete:

- bloquear antes de gastar provider quando o trabalho nao e admissivel;
- executar somente packets pequenos, governados e com teste;
- mergear somente com judge aceito;
- separar `main` de `integration_lane` sem ambiguidade;
- auditar cada ciclo depois do fato;
- parar honestamente quando backlog acaba;
- limpar locks, worktrees, branches e processos;
- deixar replay/evidence suficiente para entender qualquer ciclo dias depois.

## Onde Se Encaixa

Final parent:

```text
Atlas Autonomous Engineering Government
  -> Atlas Self-Construction OS
      -> Control Plane
      -> Task Fabric / Task Economy
      -> Maestro Scheduler
      -> Worker Swarm
      -> Verification Court
      -> Merge / Release Governor
      -> Learning Transfer System
      -> Autopoiesis Lab / Loop
```

| Camada | Documento | Papel |
|---|---|---|
| Runner longo | AP-790 | Executa por tempo/ciclos com lock, budget, kill switch e ledger. |
| Readiness antes do run | AP-805 | Decide se pode tentar 10 ciclos. |
| Autonomia/envelope/backlog | AP-806 | Mede autonomia, arma lane envelope e admite packets. |
| Firewall/auditor por ciclo | AP-807 | Bloqueia antes do provider e audita depois de cada ciclo. |
| Assurance/chaos | AP-808 | Simula, prova invariantes e injeta falhas antes de runs longos. |
| Confiabilidade mensal | AP-809 | Supervisor always-on, estado duravel, compaction, SLOs e DR. |
| Bloco enterprise | AP-810 | Agrupa tudo em slices implementaveis, testaveis e certificaveis. |
| Isolamento de agente | AP-793 | Define provider port, sandbox provider, session store e isolamento L1/L2. |
| Doc-mae atual | Este doc | Ordena 10 ciclos, 24h, 7d e meses. |

For external projects, every 24h run must have a project-stewardship lane:
project objective, workspace boundary, project-specific gates, project merge
policy, receipts and knowledge sync. A long-horizon Atlas run and a
long-horizon external-project run may coexist only when their lanes, files,
secrets, budgets and release governors are isolated.

## Contratos

| Contrato | Estado alvo |
|---|---|
| Loop Preflight + Cycle Firewall | Obrigatorio antes de qualquer provider call. |
| Post-Cycle Auditor | Obrigatorio antes de contar ciclo como sucesso. |
| Loop Assurance Kernel | Simula 1.000 ciclos, prova invariantes e roda chaos suite. |
| Months-Scale Reliability Platform | Sustenta semanas/meses com supervisor, SLOs, archival, DR e backlog regeneration. |
| Enterprise Delivery Block | Divide todo o programa em slices LHL-00..LHL-19. |
| Run Supervisor 24h | Monitora heartbeat, stalls, provider hung, locks e cleanup. |
| Backlog Depth Governor | Prova profundidade suficiente de packets antes de run longo. |
| Cycle Quality Score | Mede valor real do ciclo, nao so "mergeou". |
| Replay/Recovery | Permite reconstruir qualquer ciclo por receipts. |
| Isolamento L2 | Sai de worktree-only para isolamento de processo/container. |

## Fluxo

```text
discover findings / canonical backlog
  -> Self-Construction packet admission
  -> AP-807 preflight firewall
  -> provider / owner runtime / multi-agent workcell
  -> judge / merge governor / lane-main policy
  -> AP-807 post-cycle audit
  -> AP-808 invariant replay / assurance report
  -> quality score
  -> AP-810 slice ledger / bundle promotion
  -> AP-809 supervisor / SLO / archive / drift control
  -> learning / backlog feedback / next packet
  -> run supervisor heartbeat + cleanup
```

## Regras para IA

- Nao rode provider se o preflight ainda nao existe ou nao permitiu.
- Nao conte blocked como sucesso.
- Nao conte sandbox commit como merge.
- Nao conte plan-only Forge como implementacao.
- Nao use docs Factory/evolution como prompt amplo; transforme em canonical
  backlog e depois em Self-Construction packets pequenos.
- Nao rode 24h se AP-808 nao provar simulacao, invariantes e chaos suite.
- Nao rode 24h se o Backlog Depth Governor nao provar profundidade suficiente.
- Nao declare 7d/30d sem AP-809 supervisor, durable state, archival, drift e SLOs.
- Nao implemente "o bloco todo" de uma vez; use AP-810 slices LHL-00..LHL-19.
- Nao declare run limpo com worktree, branch, lock ou provider process orfao.
- Nao crie loop paralelo; tudo deve passar pelo Stewardship/Area Focus Loop.

## Escopo de Implementacao

### Fase 0 - Congelar realidade e nomes

Antes de mexer: ler este doc, AP-790, AP-805, AP-806, AP-807 e AP-793; confirmar
`main`, lane, locks, worktrees, provider processes e envelope; registrar backlog
admissivel. DoD: estado atual em receipt e docs-health/architecture-validate ok.

### Fase 1 - AP-810 Enterprise Delivery Block

Criar o delivery ledger e implementar por slices LHL-00..LHL-19, nunca como
prompt amplo. Bundles: A Ten-Cycle Truth, B 24h Production Readiness, C 7d/30d
Reliability. DoD: cada slice tem status, evidence, tests, rollback e gate de
promocao.

### Fase 2 - AP-807 Loop Preflight + Cycle Firewall

Bloqueia recovery/filler em `factory_max`, missing-test rotineiro em modo de
certificacao, benchmark/rivals como implementacao, cross-system sem envelope,
cross-system para `main`, packet sem allowed_files/required_tests/active_slice e
repeticao do mesmo finding/packet/blocker. DoD: provider mock nao e chamado
quando o preflight bloqueia.

### Fase 3 - AP-807 Post-Cycle Auditor

Verifica provider truth, judge truth, merge truth, evidence truth, cleanup truth
e progression truth. DoD: detecta falso sucesso historico (`judge=repair_required`
+ merge), invalida cleanup sujo e faz todo ledger AP-790 apontar para preflight e
post-cycle audit receipts.

### Fase 4 - AP-808 Loop Assurance Kernel e Chaos Certification

Antes de 24h, roda simulador deterministico, invariant harness, fault injection,
transactional cycle protocol, resource governor e flight recorder. DoD: 1.000
ciclos simulados sem critical invariant violation, chaos suite pre-24h passando
e AP-790 recusando 24h quando AP-808 estiver ausente/stale/failed.

### Fase 5 - Run Supervisor 24h

Monitora heartbeat, ciclo sem progresso, provider pendurado, locks, worktrees,
blocked streak, budget, kill/pause e finalizer. DoD: se provider trava, para ou
pausa com receipt; status final nao pode ser clean com orfaos vivos.

### Fase 6 - Backlog Depth Governor

Antes do run, prova quantidade de parents canonicos, packets executaveis, risco,
origem dos docs/gaps, locks/quarantines e ciclos uteis estimados. Fontes:
AAEOS Runtime Gap Matrix, AP-806 canonical backlog, docs Factory/evolution
quando presentes, e Self-Construction bridge. DoD: 24h bloqueia se
`admissible_packet_depth` estiver abaixo do piso.

### Fase 7 - Cycle Quality Score

Cada ciclo recebe score por relacao com AAEOS/Factory/evolution, reducao de
blocker real, avanco de packet, impacto em contexto/memoria/qualidade/agentes,
teste/evidence e ausencia de filler. DoD: merge trivial recebe score baixo e nao
conta como "salto".

### Fase 8 - Replay e Crash Recovery

Receipts minimos: preflight hash, selected packet, provider/session refs, diff,
validation, judge, merge target/hash, evidence refs, cleanup e next packet.
DoD: run interrompida nao repete packet completado e replay explica qualquer
commit sem depender de chat antigo.

### Fase 9 - Isolamento L2 (AP-793)

L1 worktree isola arquivos, mas provider ainda roda no host. Para semanas/meses,
L2 deve isolar filesystem, env/secrets, network, tempo/processo, comandos
permitidos e artefatos exportados. DoD: provider executa atras de
`sandbox_provider` e AP-807 auditor enxerga o isolamento usado.

### Fase 10 - AP-809 Months-Scale Reliability Platform

Antes de 7d/30d, transformar o loop em plataforma operacional: Always-On
Supervisor, Durable Loop State Machine, Ledger Compaction/Archival, Provider
Reliability Layer, Backlog Regeneration Engine, Quality Drift Detector,
Self-Healing Maintenance Windows, Disaster Recovery e Autonomy SLOs. DoD:
24h/3d/7d certificados com reports, replay preservado e SLOs hard passando.

### Fase 11 - Certificacao Longa

Ordem: 10 ciclos simulados; 1.000 ciclos simulados; chaos suite; 1 ciclo real em
lane; 3 packets sucessivos; 10 ciclos com quality floor; 24h com backlog depth
suficiente; 7d com supervisor e replay; meses somente com L2 isolation e
metricas acumuladas.

## Dependencias

- AP-790 para runner, lock, kill switch, ledger e cleanup.
- AP-805 para readiness antes de tentar 10 ciclos.
- AP-806 para autonomia, envelope, canonical backlog, Self-Construction bridge e
  slice progression.
- AP-807 para firewall e auditor por ciclo.
- AP-808 para simulacao, invariantes, chaos, recursos e flight recorder.
- AP-809 para supervisor always-on, estado duravel, compaction, provider reliability, backlog regeneration, drift, maintenance, DR e SLOs.
- AP-810 para agrupar todo o programa em slices LHL-00..LHL-19 e bundles A/B/C.
- AP-793 para isolamento de agentes.
- AAEOS Runtime Gap Matrix para backlog de alto impacto.
- Atlas canonical glossary para nomes Dev/Forge/Stewardship.

## Evidencias

Evidencia minima para claim de 10 ciclos:

- preflight receipt por ciclo;
- provider receipt por ciclo contado;
- judge accepted antes de merge;
- merge target/hash real;
- AP-791 evidence receipt;
- post-cycle audit receipt;
- cleanup final;
- quality score por ciclo.
- assurance/invariant report quando o run for longo.

Evidencia minima para claim de 24h:

- AP-805 ready;
- AP-808 assurance/chaos pre-24h passing;
- AP-809 months-readiness quando o horizonte for 7d/30d;
- Backlog Depth Governor acima do piso;
- Run Supervisor ativo;
- zero false merges;
- zero recovery filler;
- zero unclassified dirty state no final.

## Riscos

- Falso sucesso: merge com judge ruim.
- Provider waste: candidato ruim passa ate provider.
- Falha nao testada: run longo inicia sem chaos suite.
- Filler loop: recovery ou missing-test substitui backlog real.
- Backlog raso: run longo termina sem trabalho e mascara como progresso.
- Ambiguidade lane/main: trabalho cross-system altera main direto.
- Isolamento fraco: provider no host em run de dias/semanas.
- Replay fraco: ninguem consegue explicar por que um commit existe.
- Acumulo mensal: ledger/disco/provider/backlog/qualidade degradam sem supervisor e SLO.

## Exemplos

Exemplo correto para 10 ciclos: AP-805 ready, AP-807 enabled, 12+ packets
admissiveis, envelope lane armado, provider real, judge accepted, lane/main truth
audited e cleanup final limpo.

Exemplo correto para backlog Factory/evolution: uma proposta "Reality Compiler"
vira canonical finding, depois 3 a 5 Self-Construction packets pequenos; cada
packet roda em um ciclo, com teste e merge target explicito.

Exemplo correto para AP-808: simular 1.000 ciclos provider-free, matar provider
em fixture, corromper lock, gerar branch conflict e provar que nenhum caso vira
success falso.

Exemplo correto para AP-809: depois de 24h limpo, rodar maintenance window,
compactar ledger, recalcular backlog depth, checar provider circuit breakers,
emitir quality drift e so entao promover para 3d/7d.

Exemplo incorreto: pegar a doc Factory/evolution inteira e mandar para provider
como "implemente tudo"; isso deve bloquear no preflight.

## Proximas Acoes

1. Implementar AP-807 Loop Preflight + Cycle Firewall.
2. Implementar AP-807 Post-Cycle Auditor.
3. Implementar AP-808 Loop Assurance Kernel e Chaos Certification.
4. Implementar AP-810 delivery ledger e slice tracking para o bloco enterprise.
5. Implementar Run Supervisor 24h.
6. Implementar Backlog Depth Governor.
7. Implementar Cycle Quality Score.
8. Implementar Replay/Recovery.
9. Implementar AP-809 Months-Scale Reliability Platform.
10. Promover AP-793 L2 isolation antes de claims de semanas/meses unattended.
11. Rodar certificacao incremental: simulacao, chaos, 1 ciclo, 3 packets, 10 ciclos, 24h, 3d, 7d, 30d.
