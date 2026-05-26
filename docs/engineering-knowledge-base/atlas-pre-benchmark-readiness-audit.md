---
id: atlas-pre-benchmark-readiness-audit
type: engineering_knowledge
title: Atlas Pre-Benchmark Readiness Audit
status: active
category: programming
priority: 90
summary: Gate READ-ONLY de pre-prontidao para benchmark externo contra Claude Code/Codex. Atualizacao 2026-05-25 — produto/runtime/TEOS/suite estao prontos, mas a execucao real segue BLOCKED ate existir workspace Atlas limpo, baseline separado limpo e aprovacao de custo/provider. Benchmark NAO foi rodado e NAO sera rodado por esta missao.
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
  - Benchmark contra Claude Code/Codex/Codex CLI permanece NAO_EXECUTADO ate autorizacao humana explicita em sessao separada.
  - Esta auditoria NUNCA roda rivals battery, NUNCA chama Claude Code/Codex, NUNCA executa provider externo.
  - READY_TO_REQUEST_AUTHORIZATION exige Product Cert ready, Runtime Release Gate sem blockers criticos, TEOS Final Cert ready, BenchmarkReadiness validate passed, benchmark_status=benchmark_not_run e preflight de execucao com workspaces limpos.
  - Telemetry, Control Plane, Compounding feedback loop e Learning Proposal proposal-only ja prontos como infraestrutura nao bloqueante.
maintenance:
  - Regenerar quando `atlas:programming:pre-benchmark-readiness --json --strict` mudar status, blockers ou dirty_count.
  - Regenerar quando houver clean Atlas workspace, separate clean baseline workspace e autorizacao humana de custo/provider.
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
implementation_status: active_pre_benchmark_gate_blocked_for_external_execution
implementation_boundary: internal_readiness_ready_external_benchmark_not_run_workspace_and_operator_approval_blocked
graph_source: repo
human_name: Atlas Pre-Benchmark Readiness Audit
canonical_name: Atlas Pre-Benchmark Readiness Audit
technical_name: atlas-pre-benchmark-readiness-audit
cartography_type: module
canonical_source: docs/engineering-knowledge-base/atlas-pre-benchmark-readiness-audit.md
owner: programming
repo_paths:
  - docs/engineering-knowledge-base/atlas-pre-benchmark-readiness-audit.md
allowed_changes:
  - Atualizar veredicto quando o gate agregado mudar (com evidencia).
  - Atualizar lista de comandos de readiness quando novos shipar.
  - Adicionar novos checks ao gate agregado quando houver service + teste.
forbidden_changes:
  - Reclassificar como READY_TO_REQUEST_AUTHORIZATION sem `atlas:programming:pre-benchmark-readiness --json --strict` verde.
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
  - app/Services/Ai/Programming/BenchmarkReadiness/AtlasPreBenchmarkReadinessService.php
  - app/Console/Commands/AtlasProgrammingPreBenchmarkReadinessCommand.php
required_tests:
  - "/opt/homebrew/bin/php artisan atlas:programming:pre-benchmark-readiness --json --strict"
  - "/opt/homebrew/bin/php artisan test tests/Feature/Ai/Programming/BenchmarkReadiness/AtlasPreBenchmarkReadinessServiceTest.php"
  - "/opt/homebrew/bin/php artisan atlas:engineering:knowledge docs-health --json"
  - "/opt/homebrew/bin/php artisan atlas:ai:programming-runtime --action=readiness --json"
  - "/opt/homebrew/bin/php artisan atlas:ai:compounding readiness --json"
  - "/opt/homebrew/bin/php artisan atlas:ai:programming-runtime-control-plane --json"
  - "/opt/homebrew/bin/php artisan atlas:ai:programming-runtime-telemetry --action=aggregate --json"
  - "/opt/homebrew/bin/php artisan test --filter='AtlasCanonicalRuntimeE2ETest|AiGatewayMissionBridgeTest|MandatoryRagGateTest|DualCoreRouteDecisionServiceTest|ProgrammingRuntimeReadinessServiceTest'"
requires_evidence: true
risk_level: high
next_actions:
  - Criar workspace Atlas limpo e baseline separado limpo antes de pedir autorizacao.
  - Rodar atlas:programming:pre-benchmark-readiness --json --strict novamente.
  - Manter benchmark_not_run=true ate o operador autorizar explicitamente a bateria.
line_limit: 520
---
# Atlas Pre-Benchmark Readiness Audit

## Resumo

**Veredicto executivo atualizado (2026-05-25):**
`BLOCKED_FOR_EXECUTION_PREFLIGHT`. Produto, runtime, TEOS e suite canonica
estao prontos, mas a bateria real ainda nao deve ser autorizada porque o
workspace atual esta sujo e o baseline precisa ser um workspace separado e
limpo. O gate final e
`php artisan atlas:programming:pre-benchmark-readiness --json --strict`.
Ele agrega Product Cert, Runtime Release Gate, TEOS Final Cert e
BenchmarkReadiness validate, alem do preflight real de rivals, sem executar
benchmark, rivals ou providers.

**Benchmark explicitamente NAO foi rodado nesta sessao.** Esta missao e
auditoria pura. Zero chamadas a Claude Code, Codex, Codex CLI, rivals
battery ou provider externo. Todos os comandos sao internos readiness ou
read-only.

O unico warning aceito no gate final e `control_plane_runtime`, quando o
Runtime Release Gate esta `partial` por traces historicos/operator-watch, sem
blockers e sem critical_failed. Qualquer outro warning, qualquer blocker, ou
qualquer sinal de benchmark executado rebaixa o gate para `blocked`.

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

Resposta direta atual: **nao ainda para executar**, porque falta worktree
limpa e baseline separado. Quando esse preflight ficar verde, a proxima acao
sera pedir autorizacao humana explicita.

Nao reclassifica nem substitui:
- `atlas-runtime-spine-completion-audit.md` (runtime spine readiness green no escopo auditado);
- `atlas-programming-superiority-architecture.md` (Top 15 gaps estrategicos);
- `atlas-dev-forge-relationship-critical-audit.md` (estado 4 mecanismos);
- `atlas-programming-superiority-roadmap.md` (M1-M10).

Cita-os; nao os reescreve.

## Onde Se Encaixa

Layer 0.72 Programming. Filho de
`atlas-programming-superiority-architecture.md` (que define a Honest
Comparison Methodology obrigatoria). Cruza com:

- `atlas-runtime-spine-completion-audit.md` (estado real da spine).
- `atlas-aiworker-kernel-integration-adr.md` (historico do worker; estado atual vem do runtime spine readiness).
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
- `atlas.programming.pre_benchmark_readiness.v1` (gate final agregado).

Contrato novo: `atlas.programming.pre_benchmark_readiness.v1`, emitido por
`AtlasPreBenchmarkReadinessService`.

## Fluxo

```text
operator asks: "ready to authorise benchmark?"
  -> Atlas runs internal readiness commands (no benchmark)
  -> compares state vs Honest Comparison Methodology checklist
  -> runs Product Cert + Runtime Release Gate + TEOS Final Cert + BenchmarkReadiness validate
  -> emits verdict: ready | partial | blocked
  -> if ready: allows asking human to authorise a separate benchmark session
  -> if BLOCKED: lists hard refusal reasons (canon violations)
  -> NEVER runs a rival call, NEVER runs the battery
```

## Regras para IA

1. NAO rodar benchmark real, rivals battery, Claude Code/Codex/Codex CLI ou provider externo a partir deste audit.
2. NAO mutar codigo de runtime, contrato ou config para "destravar" o veredicto. Veredicto e leitura.
3. NAO suavizar a classificacao se o gate agregado ficar `partial` ou `blocked`.
4. NAO reclassificar para READY_TO_REQUEST_AUTHORIZATION sem o comando `atlas:programming:pre-benchmark-readiness --json --strict` verde.
5. Sempre citar o comando readiness + arquivo/linha que sustenta cada classificacao.
6. Sempre repetir explicitamente `benchmark_not_run: true` na entrega final.

## Escopo de Implementacao

Pura auditoria. Cobre:

- Inspecao dos 9 dominios canon (runtime spine, Dev features, Forge features, World Model/Compounding, E2E battery, telemetry, control plane, certification, benchmark-readiness harness).
- Execucao de 5 comandos readiness internos (zero benchmark, zero provider externo).
- Producao deste documento canon com veredicto, blockers, next actions.

Fora de escopo: executar benchmark, chamar rivals/providers, alterar pesos de
comparacao, ou declarar superioridade externa.

## Dependencias

- `atlas-programming-superiority-architecture.md` (`Honest Comparison Methodology` define os 4 requisitos para qualquer claim externo).
- `atlas-runtime-spine-completion-audit.md` (spine green no readiness atual; nao implica benchmark externo).
- `atlas-dev-forge-relationship-critical-audit.md` (gap dos 4 mecanismos paralelos).
- `atlas-aiworker-kernel-integration-adr.md` (historico de integracao; checar estado atual via runtime spine readiness).
- `atlas-compounding-engineering-intelligence.md` + readiness service (16/16 verde).
- `atlas-evidence-certification-runtime.md` (gramatica de receipts).

## Evidencias

### Dominios auditados pelo gate agregado

| Dominio | Status aceito | Evidencia |
|---|---|---|
| Atlas AI Product E2E | ready | `AtlasAiProductCertificationService` |
| Runtime Release Gate | ready ou partial sem blockers e apenas `control_plane_runtime` | `AtlasAiRuntimeReleaseGateService` |
| TEOS Final Local Runtime | ready | `AtlasTeosFinalCertificationService` |
| Benchmark Suite Readiness | validation passed + benchmark_not_run | `BenchmarkReadinessHarness::validate()` |
| Rivals Execution Preflight | workspace Atlas limpo + baseline separado limpo + run command pronto | `ProgrammingRivalsReadinessService` |
| Claim Policy | sem benchmark/rivals/providers e sem claim externa | `AtlasPreBenchmarkReadinessService` |

### Comandos rodados (todos internos, zero benchmark)

```bash
$ php artisan atlas:programming:pre-benchmark-readiness --json --strict
  status: blocked
  checks: 5/6 pass
  blocker: rivals_execution_preflight
  blocker detail: current_workspace_ready_for_provider_battery=false, dirty_count=93
  accepted_warnings: [control_plane_runtime]
  claim_policy: benchmark_not_run=true, rivals_compared=false, provider_calls_made=false

$ php artisan atlas:ai:product-certify --json --strict
  status: ready

$ php artisan atlas:teos:final-certify --json --strict
  status: ready

$ php artisan atlas:programming:benchmark-readiness validate --json
  validation: passed
  benchmark_status: benchmark_not_run
```

Nenhum desses comandos executa benchmark, chama Claude/Codex ou compara
rivals. Todos sao read-only ou readiness.

### Gate final agregado (2026-05-25)

Comando canonico:

```bash
php artisan atlas:programming:pre-benchmark-readiness --json --strict
```

Resultado real observado:

```json
{
  "schema_version": "atlas.programming.pre_benchmark_readiness.v1",
  "status": "blocked",
  "summary": {"total": 6, "pass": 5, "warn": 0, "fail": 1},
  "accepted_warnings": ["control_plane_runtime"],
  "blockers": ["rivals_execution_preflight"],
  "claim_policy": {
    "benchmark_not_run": true,
    "rivals_compared": false,
    "provider_calls_made": false,
    "allows_external_superiority_claim": false
  },
  "writes": false
}
```

Checks agregados:

| Check | Criterio de pass | Evidencia |
|---|---|---|
| Product Cert | `status=ready` | `atlas:ai:product-certify --json --strict` |
| Runtime Release Gate | `ready` ou `partial` sem blockers e apenas warning aceito `control_plane_runtime` | `atlas:ai:runtime-release-gate --json --strict` |
| TEOS Final Cert | `status=ready` | `atlas:teos:final-certify --json --strict` |
| BenchmarkReadiness | `validation.status=passed` e `benchmark_status=benchmark_not_run` | `atlas:programming:benchmark-readiness validate --json` |
| Rivals Execution Preflight | workspace Atlas limpo + baseline separado limpo | `atlas:programming:rivals-readiness --json` |
| Claim Policy | zero benchmark/rivals/providers e zero claim externa | hard fence no service |

### Classificacao formal

```yaml
verdict: BLOCKED_FOR_EXECUTION_PREFLIGHT
benchmark_not_run: true
rivals_compared: false
provider_calls_made: false
allows_external_superiority_claim: false
allows_request_to_user_for_benchmark_authorisation: false
next_step: preparar clean-atlas-workspace e separate-clean-baseline-workspace
```

## Riscos

1. Rodar benchmark no workspace sujo atual torna a evidencia nao auditavel.
2. Rodar Atlas e baseline no mesmo workspace invalida comparabilidade.
3. Rodar benchmark sem autorizacao humana explicita invalida a governanca.
4. Confundir readiness interna com "Atlas venceu rivais" criaria claim falsa; este gate nao mede rivais.
5. Warning `control_plane_runtime` aceito cobre apenas operator-watch/historico sem blockers; se virar blocker real, o gate deve bloquear.

## Exemplos

**Veredicto operacional atual:**
```
BLOCKED_FOR_EXECUTION_PREFLIGHT
- Product Cert: ready
- Runtime Release Gate: partial com warning aceito control_plane_runtime
- TEOS Final Cert: ready
- BenchmarkReadiness: validate passed, benchmark_not_run
- Rivals Execution Preflight: blocked porque workspace atual esta dirty e exige aprovacao de custo/provider
- Proximo passo: criar worktrees limpas e baseline separado
```

**Veredicto bloqueado:**
```
BLOCKED
- Runtime Release Gate com blocker, warning nao aceito, ou critical_failed
- TEOS/Product Cert nao ready
- BenchmarkReadiness diferente de benchmark_not_run
- claim_policy permite superioridade externa
```

## Proximas Acoes

1. Criar `clean-atlas-workspace` a partir do estado que sera medido.
2. Criar `separate-clean-baseline-workspace` a partir da mesma base.
3. Confirmar `git status --short` vazio nos dois workspaces.
4. Rodar `php artisan atlas:programming:pre-benchmark-readiness --json --strict` novamente.
5. Se ficar `ready`, apresentar escopo e pedir autorizacao humana explicita.
6. Manter `benchmark_not_run: true` ate essa autorizacao existir.

## Definition Of Done

Esta auditoria esta pronta quando:

- 9 dominios canon foram classificados com evidencia (arquivo/linha/comando) **OK**.
- Veredicto final explicito (`BLOCKED_FOR_EXECUTION_PREFLIGHT`) **OK**.
- Gate agregado com CLI strict implementado **OK**.
- Testes do gate cobrem ready, blockers, warnings nao aceitos, benchmark executado, workspace sujo e CLI strict **OK**.
- `benchmark_not_run: true` confirmado em texto e em outputs de comando **OK**.
- 0 rivals battery, 0 chamadas Claude Code/Codex/Codex CLI **OK**.
- docs-health sem violacao nova neste doc **OK**.
- `git diff --check` exit 0 **OK**.
