---
id: atlas-pre-benchmark-readiness-audit
type: engineering_knowledge
title: Atlas Pre-Benchmark Readiness Audit
status: active
category: programming
priority: 90
summary: Auditoria READ-ONLY (2026-05-18) de pre-prontidao para solicitar autorizacao humana de benchmark externo contra Claude Code/Codex. Veredicto principal — NOT_READY. Substrato substancial entregue (runtime spine 60 porcento, compounding 16/16 verde, telemetry e control plane novos shipados); porem 2 P0 e 4 P1 do canon ainda pendentes. Benchmark NAO foi rodado e NAO sera rodado por esta missao. Esta doc lista o minimo necessario antes de pedir autorizacao.
tags:
  - atlas-ai
  - benchmark
  - pre-benchmark
  - readiness
  - audit
  - 2026-05-18
capabilities:
  - pre_benchmark_readiness_audit
  - blocker_classification
  - authorisation_request_gate
  - benchmark_safety_invariant
decisions:
  - Benchmark contra Claude Code/Codex/Codex CLI permanece BLOCKED por design ate substrato interno fechar.
  - Esta auditoria NUNCA roda rivals battery, NUNCA chama Claude Code/Codex, NUNCA executa provider externo.
  - O veredicto e binario por safety - so READY_TO_REQUEST_AUTHORIZATION quando os P0 listados estiverem PASS e os P1 minimos resolvidos.
  - Telemetry, Control Plane, Compounding feedback loop e Learning Proposal proposal-only ja prontos como infraestrutura nao bloqueante.
maintenance:
  - Regenerar quando AiWorker process consumir Kernel envelope (Phases 4-6 do ADR).
  - Regenerar quando ATLAS_AI_TOOL_RUNTIME_STRICT=true em producao com receipts.
  - Regenerar quando Phase 2 do plano de consolidacao Dev->Forge entregar (route_decision.v1 em Mechanism 4).
  - Regenerar quando E2E HTTP real cobrir POST /ai/interactions ate certification.
related_paths:
  - docs/engineering-knowledge-base/atlas-programming-superiority-architecture.md
  - docs/engineering-knowledge-base/atlas-programming-superiority-contracts.md
  - docs/engineering-knowledge-base/atlas-programming-superiority-roadmap.md
  - docs/engineering-knowledge-base/atlas-runtime-spine-completion-audit.md
  - docs/engineering-knowledge-base/atlas-evidence-certification-runtime.md
  - docs/engineering-knowledge-base/atlas-dev-forge-relationship-critical-audit.md
  - docs/engineering-knowledge-base/atlas-compounding-engineering-intelligence.md
  - docs/engineering-knowledge-base/atlas-aiworker-kernel-integration-adr.md
  - docs/engineering-knowledge-base/atlas-canonical-glossary-and-naming.md
doc_schema: atlas_canonical_module_doc.v1
graph_id: atlas-pre-benchmark-readiness-audit
graph_title: Atlas Pre-Benchmark Readiness Audit
graph_world: atlas
graph_layer: system
graph_kind: module
graph_parent: atlas-programming-superiority-architecture
graph_status: active
graph_source: repo
owner: programming
repo_paths:
  - docs/engineering-knowledge-base/atlas-pre-benchmark-readiness-audit.md
allowed_changes:
  - Atualizar veredicto quando P0/P1 blockers resolverem (com evidencia).
  - Atualizar lista de comandos de readiness quando novos shipar.
  - Adicionar coluna de status "shipped" por blocker quando confirmado em codigo + teste.
forbidden_changes:
  - Reclassificar como READY_TO_REQUEST_AUTHORIZATION sem todos os P0 PASS + minimo de P1 resolvidos.
  - Suavizar veredicto sem evidencia de codigo + teste verde.
  - Permitir benchmark real, rivals battery ou chamada Claude Code/Codex como criterio de prontidao.
  - Confundir telemetry interna com benchmark externo.
depends_on:
  - atlas-runtime-spine-completion-audit
  - atlas-programming-superiority-architecture
  - atlas-dev-forge-relationship-critical-audit
  - atlas-compounding-engineering-intelligence
flows_to:
  - atlas-programming-superiority-roadmap
unlocks:
  - benchmark_authorisation_request_when_ready
governs:
  - pre_benchmark_authorisation_gate
evidence:
  - app/Services/Ai/ProgrammingRuntime/ProgrammingRuntimeReadinessService.php
  - app/Services/Ai/ProgrammingRuntime/Telemetry/ProgrammingRuntimeTelemetryAggregator.php
  - app/Services/Ai/ProgrammingRuntime/ControlPlane/ProgrammingRuntimeControlPlaneService.php
  - app/Services/Ai/Compounding/AtlasCompoundingReadinessService.php
  - app/Services/Ai/Compounding/AtlasLearningProposalService.php
  - app/Services/Ai/Compounding/AtlasCompoundingRuntimeService.php
  - app/Services/Ai/ToolRuntime/ToolPolicyBridgeService.php
  - app/Services/Ai/AiWorker.php
required_tests:
  - "/opt/homebrew/bin/php artisan atlas:engineering:knowledge docs-health --json"
  - "/opt/homebrew/bin/php artisan atlas:ai:programming-runtime --action=readiness --json"
  - "/opt/homebrew/bin/php artisan atlas:ai:compounding readiness --json"
  - "/opt/homebrew/bin/php artisan atlas:ai:programming-runtime-control-plane --json"
  - "/opt/homebrew/bin/php artisan atlas:ai:programming-runtime-telemetry --action=aggregate --json"
  - "/opt/homebrew/bin/php artisan test --filter='AtlasCanonicalRuntimeE2ETest|AiGatewayMissionBridgeTest|MandatoryRagGateTest|DualCoreRouteDecisionServiceTest|ProgrammingRuntimeReadinessServiceTest'"
requires_evidence: true
risk_level: high
next_actions:
  - NAO solicitar autorizacao humana ainda.
  - Resolver bloqueio P0 #1 (AiWorker Phase 4-6) antes de qualquer pedido de benchmark.
  - Resolver bloqueio P0 #2 (HTTP path nao percorre Kernel em producao default) com flag flip auditado.
  - Resolver minimo dos P1 listados (Tool strict, consolidacao Mechanism 4, E2E HTTP, heuristicas readiness reauditadas com runtime real).
  - Reauditar com este doc apos cada P0/P1 fechar; reclassificar para READY_TO_REQUEST_AUTHORIZATION quando criterios atendidos.
line_limit: 520
---

# Atlas Pre-Benchmark Readiness Audit

## Resumo

**Veredicto executivo (2026-05-18):** `NOT_READY`. O Atlas NAO esta pronto
para que o operador pe ca autorizacao para um benchmark externo contra Claude
Code/Codex/Codex CLI. Substrato substancial foi entregue nas ultimas sessoes
(runtime spine 60 porcento fechada, Compounding 16/16 verde, Telemetry e
Control Plane novos shipados), mas 2 P0 e 4 P1 do canon continuam pendentes.
Pedir autorizacao agora produziria metricas invalidas e arriscaria a
credibilidade externa do projeto.

**Benchmark explicitamente NAO foi rodado nesta sessao.** Esta missao e
auditoria pura. Zero chamadas a Claude Code, Codex, Codex CLI, rivals
battery ou provider externo. Todos os comandos sao internos readiness ou
read-only.

**Disciplina de claim policy** continua estavel: `temporal_certification`
reporta `ready_to_replace_claude_code_codex: false` e
`requires_external_rival_battery: true`. Control Plane reporta
`benchmark_status.not_run: true` e
`claim_policy.allows_external_superiority_claim: false`. Nenhuma camada
permite que essa decisao seja tomada por inadvertencia.

## Papel no Atlas

Este audit responde uma unica pergunta operacional:

> **Posso, agora, pedir ao operador para autorizar a execucao de um
> benchmark real contra Claude Code / Codex / Codex CLI?**

Resposta direta: **nao ainda.** O documento lista exatamente o que falta
antes de a pergunta poder ser feita com responsabilidade.

Nao reclassifica nem substitui:
- `atlas-runtime-spine-completion-audit.md` (audit operacional 15 requisitos);
- `atlas-programming-superiority-architecture.md` (Top 15 gaps estrategicos);
- `atlas-dev-forge-relationship-critical-audit.md` (estado 4 mecanismos);
- `atlas-programming-superiority-roadmap.md` (M1-M10).

Cita-os; nao os reescreve.

## Onde Se Encaixa

Layer 0.72 Programming. Filho de
`atlas-programming-superiority-architecture.md` (que define a Honest
Comparison Methodology obrigatoria). Cruza com:

- `atlas-runtime-spine-completion-audit.md` (estado real da spine).
- `atlas-aiworker-kernel-integration-adr.md` (Phases 1-6 do worker).
- `atlas-dev-forge-escalation-consolidation-plan.md` (Fases 1-4).
- `atlas-compounding-engineering-intelligence.md` (16 checks verdes).
- `atlas-evidence-certification-runtime.md` (gramatica de evidence).

## Contratos

Auditoria consome, nao define. Schemas relevantes:

- `atlas.programming.runtime_readiness.v1` (ProgrammingRuntimeReadinessService).
- `atlas.ai.compounding.readiness.v1` (AtlasCompoundingReadinessService).
- `atlas.programming.runtime_telemetry.aggregate.v1` (telemetry aggregator).
- `atlas.programming.runtime_control_plane.v1` (control plane snapshot).
- `atlas.ai.compounding.temporal_certification.v1` (temporal cert).

Nenhum contrato novo.

## Fluxo

```text
operator asks: "ready to authorise benchmark?"
  -> Atlas runs internal readiness commands (no benchmark)
  -> compares state vs Honest Comparison Methodology checklist
  -> emits verdict: READY_TO_REQUEST_AUTHORIZATION | NOT_READY | BLOCKED
  -> if NOT_READY: lists P0/P1 blockers + minimum to unblock
  -> if BLOCKED: lists hard refusal reasons (canon violations)
  -> NEVER runs a rival call, NEVER runs the battery
```

## Regras para IA

1. NAO rodar benchmark real, rivals battery, Claude Code/Codex/Codex CLI ou provider externo a partir deste audit.
2. NAO mutar codigo de runtime, contrato ou config para "destravar" o veredicto. Veredicto e leitura.
3. NAO suavizar a classificacao (NOT_READY) por urgencia ou pressao temporal.
4. NAO reclassificar para READY_TO_REQUEST_AUTHORIZATION enquanto qualquer P0 estiver pendente.
5. Sempre citar o comando readiness + arquivo/linha que sustenta cada classificacao.
6. Sempre repetir explicitamente `benchmark_not_run: true` na entrega final.

## Escopo de Implementacao

Pura auditoria. Cobre:

- Inspecao dos 9 dominios canon (runtime spine, Dev features, Forge features, World Model/Compounding, E2E battery, telemetry, control plane, certification, benchmark-readiness harness).
- Execucao de 5 comandos readiness internos (zero benchmark, zero provider externo).
- Producao deste documento canon com veredicto, blockers, next actions.

Fora de escopo: corrigir blockers, refatorar runtime, alterar config,
deprecar legacy, mexer em UX, atualizar docs canon diferentes deste,
implementar telemetria adicional, executar benchmark.

## Dependencias

- `atlas-programming-superiority-architecture.md` (`Honest Comparison Methodology` define os 4 requisitos para qualquer claim externo).
- `atlas-runtime-spine-completion-audit.md` (8 PASS, 5 PARTIAL, 2 FAIL no momento da escrita).
- `atlas-dev-forge-relationship-critical-audit.md` (gap dos 4 mecanismos paralelos).
- `atlas-aiworker-kernel-integration-adr.md` (Phase 1 entregue; Phases 2-6 pendentes).
- `atlas-compounding-engineering-intelligence.md` + readiness service (16/16 verde).
- `atlas-evidence-certification-runtime.md` (gramatica de receipts).

## Evidencias

### 9 dominios auditados (status)

| # | Dominio | Status | Comando readiness | Detalhe curto |
|---|---------|--------|-------------------|----------------|
| 1 | Runtime Spine (HTTP -> Kernel -> Mission -> Cert) | PARTIAL | `atlas:ai:programming-runtime` | 7 green, 2 warn, 1 blocked. Phase 1 ADR entregue; Phases 4-6 do AiWorker pendentes. |
| 2 | Atlas Dev superiority features (RAG mandatorio, PEVR loop, Senior Loop, Patch Verifier, Repair) | GREEN_WITH_CAVEAT | `atlas:ai:programming-runtime` checks | MandatoryRagGate enforced + fail-closed; Patch/Repair/SeniorLoop em codigo. Caveat: HTTP path real (default) ainda nao percorre o pipeline canonical. |
| 3 | Atlas Forge superiority features (Obras, SDD, milestones, work packets, runtime cert) | GREEN_WITH_CAVEAT | `atlas:ai:programming-runtime-control-plane` | Tabelas + servicos + handoff adapter prontos; Mechanism 4 (Forge HTTP) ainda nao emite `route_decision.v1` em todos os controllers. |
| 4 | World Model + Compounding | GREEN | `atlas:ai:compounding readiness` | 16/16 checks passed. Feedback loop + Learning Proposal proposal-only entregues; Telemetria emitida em runtime_record_completed. |
| 5 | E2E canonical battery | PARTIAL | `php artisan test --filter='AtlasCanonicalRuntimeE2ETest|...'` (51 verdes anteriormente, 53 agora) | Cobre IntentKernel -> Router -> Adapter -> Mission -> Certification + dev_to_forge. NAO cobre HTTP controller real (pin explicito no ADR). |
| 6 | Programming Runtime Telemetry | GREEN | `atlas:ai:programming-runtime-telemetry --action=aggregate --json` | Recorder + aggregator + canon shipped; emitido pelo Compounding runtime. `claim_policy.benchmark_not_run: true` invariante. |
| 7 | Programming Runtime Control Plane | GREEN | `atlas:ai:programming-runtime-control-plane --json` | Aggregator unico cobre missions / dev runs / forge obras / work packets / RAG gates / repair / telemetry / blockers / certification / next_actions. `benchmark_status.not_run: true` invariante. |
| 8 | Certification (Mission + Temporal) | PARTIAL | `atlas:ai:control-plane readiness` (degraded) + `atlas:ai:compounding readiness` (passed) | MissionCertification quality-aware (severity tiers + critical gate) shipped; temporal certification reporta `ready_to_replace_claude_code_codex: false`. Caveat: cobertura HTTP pendente. |
| 9 | Benchmark readiness harness | NOT_READY | n/a | Harness existe estruturalmente (`forge_rivals_*` + `ForgeRivals/` services em modo dry-run apenas). NUNCA executar live ate substrato + autorizacao humana explicita. |

### Comandos rodados (todos internos, zero benchmark)

```bash
$ /opt/homebrew/bin/php artisan atlas:ai:programming-runtime --action=readiness --json
  status: blocked  total=10 green=7 warn=2 blocked=1 (p0_blocked=0, p1_blocked=1)
  blockers:
    [P1] tool_policy_evidence_strict_mode (config atlas_ai.tool_runtime.strict_mode=false)
  warn:
    [P0] aiworker_kernel_integration (Phase 1 gateway bridge shipped; Phases 4-6 pendentes)
    [P1] dev_forge_no_parallel_escalation_schemas (3 mecanismos paralelos remanescentes)

$ /opt/homebrew/bin/php artisan atlas:ai:compounding readiness --json
  status: passed  total=16 passed=16 failed=0
  contracts: atlas.ai.compounding.{outcome,learning_candidate,memory,heuristic_update,rag.feedback,benchmark_case,temporal_certification}.v1

$ /opt/homebrew/bin/php artisan atlas:ai:programming-runtime-control-plane --json
  runtime_status: blocked  (cascata dos P0/P1 acima)
  benchmark_status: { not_run: true, rivals_compared: false, requires_human_authorization: true }
  claim_policy.allows_external_superiority_claim: false

$ /opt/homebrew/bin/php artisan atlas:ai:programming-runtime-telemetry --action=aggregate --json
  total_events: 0 distinct_runs: 0
  claim_policy: { benchmark_not_run: true, rivals_compared: false }

$ /opt/homebrew/bin/php artisan test \
    --filter='AtlasCanonicalRuntimeE2ETest|AiGatewayMissionBridgeTest|MandatoryRagGateTest|DualCoreRouteDecisionServiceTest|ProgrammingRuntimeReadinessServiceTest'
  Tests: 53 passed (354 assertions)

$ /opt/homebrew/bin/php artisan test \
    tests/Feature/Ai/ProgrammingRuntime tests/Unit/Ai/ProgrammingRuntime \
    tests/Unit/Ai/Compounding tests/Feature/Ai/AtlasCompoundingEngineeringIntelligenceTest.php
  Tests: 55 passed (389 assertions)
```

Nenhum desses comandos executa benchmark, chama Claude/Codex ou compara
rivals. Todos sao read-only ou readiness.

### Veredicto canonico

```
classification: NOT_READY
benchmark_not_run: true
reason_summary: |
  Atlas tem substrato suficiente para medicao interna mas nao tem o
  caminho HTTP real percorrendo o Kernel canonical em modo enforce. Os
  P0 abaixo bloqueiam qualquer pedido honesto de autorizacao externa.
allows_request_to_user_for_benchmark_authorisation: false
```

### Blockers P0 (devem ser PASS antes de qualquer pedido)

**P0-1. AiWorker process bypassa Kernel apos enqueue.**
- Estado: Phase 1 do ADR shipped (`AiGatewayMissionBridge` no gateway); Phases 4-6 pendentes.
- Evidencia: `app/Services/Ai/AiWorker.php` constructor ainda nao injeta `MissionLifecycleService`, `PermissionGateService`, `CertificationRuntimeService`, `FlowRouterService`, `DomainRouterService`.
- Detalhe canon: `atlas-runtime-spine-completion-audit.md` linhas 117-130 + 416-426.
- Razao por que bloqueia benchmark: rodar bateria com worker fora do Kernel produz metricas que NAO refletem a arquitetura que se quer comparar. Resultado parece bom ou ruim por motivos errados.

**P0-2. `ATLAS_AI_KERNEL_HTTP_INTEGRATION_ENABLED` default false em producao.**
- Estado: feature flag existe, testes cobrem ambos os estados, mas default OFF significa que `POST /ai/interactions` em producao NAO escreve `payload.kernel`.
- Evidencia: `config/atlas_ai.php` flag default; `AiGatewayMissionBridgeTest` (8 cenarios) cobre on/off.
- Razao por que bloqueia benchmark: sem o caminho HTTP exercitando o Kernel em modo enforce, nao se pode declarar que a "arquitetura Atlas" foi medida.

### Blockers P1 (minimo recomendado antes de pedir autorizacao)

**P1-1. `ATLAS_AI_TOOL_RUNTIME_STRICT` default false.**
- Estado: strict_mode implementado em `ToolPolicyBridgeService` e `ToolReceiptService`; producao mantem fallback silencioso ate flip auditado.
- Razao: marker `evidence_runtime_unavailable` ainda pode aparecer em receipts; bateria com fallback silencioso fica nao-deterministica.
- Acao minima: auditar callers Tool e flipar em CI/dev antes de pedir autorizacao.

**P1-2. Phase 2 do plano de consolidacao Dev->Forge pendente.**
- Estado: Mechanism 4 (Forge HTTP `/works/{project}/forge/*`) ja emite `route_decision.v1` no caminho `live-executions` (post-fix 2026-05-18), mas os outros 3 controllers Forge (`fast-path`, `async`, `operating-room`) ainda nao.
- Razao: assimetria por surface = bateria sobre Forge tem cobertura desigual.

**P1-3. E2E canonical NAO cobre HTTP real.**
- Estado: `AtlasCanonicalRuntimeE2ETest` parte do `IntentKernelService`. `test_http_to_kernel_integration_remains_a_documented_blocker` e um pin explicito.
- Razao: bateria precisa exercitar exatamente o que producao expoe.

**P1-4. Heuristicas residuais de readiness ainda nao reauditadas com runtime real.**
- Estado: `ProgrammingRuntimeReadinessService::checkDevForgeNoParallelEscalationSchemas` ainda marca `warn` por presenca dos 3 mecanismos paralelos ao adapter canonical, mesmo apos Phase 1 de consolidacao.
- Razao: warn perpetuo ja noise mas nao gap real; ajuste honesto antes de declarar prontidao.

### Minimo necessario antes de pedir autorizacao (sequencial)

| # | Acao | Severidade | Custo estimado | Bloqueia |
|---|------|------------|----------------|----------|
| 1 | AP Phase-4 ADR (PermissionGate Meta 3 warn-only no AiWorker) | P0 | 4-6h | independente |
| 2 | AP Phase-5 ADR (Evidence attach Meta 4 no AiWorker) | P0 | 4-6h | depende de #1 |
| 3 | AP Phase-6 ADR (Certification gate no AiWorker + remocao do test pin) | P0 | 6-8h | depende de #2 |
| 4 | Auditar callers Tool e flipar `ATLAS_AI_TOOL_RUNTIME_STRICT=true` em CI/dev | P1 | 4-8h | rollout em prod |
| 5 | Estender `ForgeIntakeRouteDecisionRecorder` aos 3 controllers Forge restantes (fast-path/async/operating-room) | P1 | 2-4h | independente |
| 6 | E2E HTTP real: novo teste partindo de `POST /ai/interactions` ate `certification.passed` (substitui o pin) | P1 | 6-10h | depende de #1-#3 |
| 7 | Reauditar este documento e ProgrammingRuntimeReadinessService heuristicas com runtime real | doc | 1-2h | depende de #1-#6 |

Total estimado para `READY_TO_REQUEST_AUTHORIZATION`: 27-44h efetivas
distribuidas em 5-7 sessoes. So apos isso o operador deve ser convidado a
autorizar a execucao de uma bateria externa.

### Classificacao formal

```
verdict: NOT_READY
reason: 2 P0 + 4 P1 blockers per Honest Comparison Methodology
benchmark_not_run: true
rivals_compared: false
allows_request_to_user_for_benchmark_authorisation: false
next_step: resolve P0-1 (AiWorker Phases 4-6) before anything else
```

## Riscos

1. Pedir autorizacao agora produz numeros que NAO refletem a arquitetura Atlas, porque o path HTTP real ainda nao percorre o Kernel canonical em producao default.
2. Bateria com strict_mode=false emite receipts com marker `evidence_runtime_unavailable`, contaminando metricas auditadas.
3. Assimetria entre controllers Forge (Mechanism 4 parcialmente consolidado) torna comparacao Forge-vs-rival nao-uniforme.
4. Falta de E2E HTTP real significa que o que e medido na bateria nao corresponde ao que o usuario experimenta.
5. Suavizar o veredicto para acelerar a bateria queima credibilidade externa permanentemente.
6. Confundir telemetry interna ja shipada com benchmark externo apropria  evidencias que NAO existem.

## Exemplos

**Veredicto operacional (cenario atual):**
```
NOT_READY
- P0 pendentes: AiWorker Phases 4-6; HTTP flag default false em producao
- P1 pendentes: Tool strict default false; Mechanism 4 incompleto;
                E2E HTTP ausente; heuristicas readiness pendentes
- Next step: AP Phase-4 ADR (PermissionGate warn-only no AiWorker)
- Pedido de autorizacao ao operador: NAO ainda
```

**Veredicto operacional (apos resolver minimos):**
```
READY_TO_REQUEST_AUTHORIZATION
- P0: all green (AiWorker -> Kernel ponta-a-ponta no path HTTP real)
- P1: Tool strict default true; Mechanism 4 emitindo route_decision.v1
      em todos os controllers; E2E HTTP real cobrindo POST -> certified
- Next step: APRESENTAR este documento ao operador e perguntar:
             "autorizar bateria contra Claude Code/Codex com escopo X?"
- Bateria so executa apos resposta explicita do operador.
```

**Cenario de refusal (cenario BLOCKED):**
```
BLOCKED
- Qualquer P0 listado for revertido ou regredir.
- Substrato Compounding/Telemetry/Control Plane NAO podem ser desfeitos.
- Refusar pedido de autorizacao mesmo sob pressao temporal.
```

## Proximas Acoes

1. NAO pedir autorizacao ao operador. Repetir explicitamente: `benchmark_not_run: true`.
2. Iniciar AP Phase-4 ADR (PermissionGate Meta 3 warn-only no AiWorker apos enqueue). Custo 4-6h.
3. Em sessao posterior, AP Phase-5 (Evidence attach) + Phase-6 (Certification gate + remocao do pin E2E HTTP).
4. Em paralelo (independente), AP Tool strict-mode flip auditado e extensao `ForgeIntakeRouteDecisionRecorder` aos 3 controllers Forge restantes.
5. Quando os 4 P0 + 4 P1 atendidos, reauditar este documento e reclassificar para `READY_TO_REQUEST_AUTHORIZATION`. So entao apresentar a pergunta ao operador.
6. Apos autorizacao explicita (e nao antes), apresentar plano de bateria com escopo, casos canonicos, metricas, periodo de observacao, criterios de paragem.

## Definition Of Done

Esta auditoria esta pronta quando:

- 9 dominios canon foram classificados com evidencia (arquivo/linha/comando) **OK**.
- Veredicto final explicito (`NOT_READY` neste momento) **OK**.
- Blockers P0 e P1 listados com remediation + ordem **OK**.
- `benchmark_not_run: true` confirmado em texto e em outputs de comando **OK**.
- 0 rivals battery, 0 chamadas Claude Code/Codex/Codex CLI **OK**.
- docs-health `status: ok` **OK** (137 warnings pre-existentes unchanged).
- `git diff --check` exit 0 **OK**.
- Sem alteracao em runtime/contratos/config alem deste doc **OK**.
