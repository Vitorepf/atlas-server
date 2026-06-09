---
id: atlas-fase3-worker-repair-loop-escalation-meaningful-tests
type: engineering_knowledge
doc_schema: atlas_canonical_module_doc.v1
human_name: "FASE 3 — Iterative Worker Repair Loop, Opus Escalation & Meaningful-Test Verifier"
canonical_name: "FASE 3 — Iterative Worker Repair Loop, Opus Escalation & Meaningful-Test Verifier"
technical_name: AtlasFase3WorkerRepairLoopEscalationMeaningfulTests
cartography_type: contract
canonical_source: docs/engineering-knowledge-base/atlas-fase3-worker-repair-loop-escalation-meaningful-tests.md
title: FASE 3 — Iterative Worker Repair Loop, Opus Escalation & Meaningful-Test Verifier
status: active
implementation_state: spec_only_failing_harness_no_runtime_change
category: programming-forge
priority: 97
summary: Contrato de implementação para a FASE 3 do loop 24h autônomo. Transforma o repair loop one-shot do Ap786OwnerFlowExecutor num loop iterativo que realimenta a saída de validação no próximo prompt do worker, escala passos difíceis para claude-opus-4-8, e adiciona um verificador de teste-significativo que rejeita coverage-theater. Provider-dependente (MiniMax/Opus ao vivo) — entregue como spec + harness de teste FALHANDO, nunca com provider ao vivo.
human_summary: Define como o worker do loop 24h conserta um diff falho realimentando o erro real do teste, sobe pro Opus quando o passo é difícil, e recusa testes vazios que só simulam cobertura.
human_what: Spec do repair loop iterativo, da escalação de modelo e do verificador meaningful-test, mais um harness de testes PHP marcados incomplete que provam o contrato quando implementado.
human_purpose: Garantir que o loop autônomo realmente conserta código com feedback real (não retry cego), gasta o modelo caro só quando necessário, e nunca conta teatro de cobertura como sucesso.
human_input: owner_result do AP-759, validation output, allowed_files, provider/model choice, repair_attempt_number.
human_output: repair iterativo com feedback realimentado, model_family escalado para claude-opus-4-8, veredito meaningful-test passed/rejected.
human_change_when: Mexa quando AP-786 owner flow, repair feedback builder, model catalog ou as regras de meaningful-test mudarem.
human_block_when: Bloqueie qualquer implementação que rode provider ao vivo nesta fase, que enfraqueça provider-proof/scope/honest-stop, ou que conte coverage-theater como sucesso.
tags:
  - atlas-ai
  - autonomous-loop
  - repair-loop
  - model-escalation
  - claude-opus-4-8
  - meaningful-tests
  - anti-coverage-theater
  - ap-786
capabilities:
  - iterative_worker_repair_loop
  - validation_output_feedback
  - hard_step_model_escalation
  - meaningful_test_verifier
decisions:
  - Este documento e contrato/spec de FASE 3; nao muda runtime enquanto o harness estiver incomplete.
  - Provider ao vivo e proibido nesta fase; validacao acontece por doubles e harness marcado incomplete.
  - O owner runtime segue sendo AP-786 OwnerFlow; nao criar fluxo paralelo de repair/worker.
maintenance:
  - Atualizar quando AP-786 owner-flow, repair feedback, model escalation ou meaningful-test verifier mudarem.
  - Manter implementation_state honesto enquanto o runtime nao existir.
  - Rodar docs-health e o harness FASE 3 apos alteracoes.
related_paths:
  - docs/engineering-knowledge-base/atlas-aaeos-loop-evolution-backlog.md
  - docs/engineering-knowledge-base/atlas-software-company-stewardship-stack.md
  - docs/engineering-knowledge-base/atlas-agentic-engineering-os-runbook.md
  - docs/ap/AP-786-owner-flow-contract.md
  - app/Services/Ai/SoftwareCompanyStewardship/AreaFocusLoop/OwnerFlow/Ap786OwnerFlowExecutor.php
  - app/Services/Ai/SoftwareCompanyStewardship/AreaFocusLoop/RepairAgentFeedbackContextBuilderService.php
  - tests/Unit/Ai/SoftwareCompanyStewardship/AreaFocusLoop/OwnerFlow/Ap786OwnerFlowFase3RepairLoopTest.php
graph_id: atlas-fase3-worker-repair-loop-escalation-meaningful-tests
graph_title: FASE 3 Worker Repair Loop Escalation Meaningful Tests
graph_world: atlas
graph_layer: module
graph_kind: contract
graph_parent: atlas-software-company-stewardship-stack
graph_status: active
graph_source: repo
owner: programming-forge
repo_paths:
  - docs/engineering-knowledge-base/atlas-fase3-worker-repair-loop-escalation-meaningful-tests.md
allowed_changes:
  - Atualizar contrato, acceptance criteria e harness quando a FASE 3 for implementada.
  - Refinar limites de provider, repair, escalation e meaningful-test sem alterar runtime.
forbidden_changes:
  - Declarar runtime pronto enquanto o harness estiver marked incomplete.
  - Invocar provider ao vivo ou mudar Ap786OwnerFlowExecutor a partir deste doc sem AP/receipt/gates.
  - Criar repair loop paralelo ao owner-flow AP-786.
depends_on:
  - atlas-software-company-stewardship-stack
  - atlas-agentic-engineering-os-runbook
flows_to:
  - stewardship_loop.owner_flow_repair
unlocks:
  - fase3_repair_loop_contract
  - meaningful_test_verifier_acceptance
governs:
  - ap786.owner_flow.repair_loop_spec
evidence:
  - docs/engineering-knowledge-base/atlas-fase3-worker-repair-loop-escalation-meaningful-tests.md
  - tests/Unit/Ai/SoftwareCompanyStewardship/AreaFocusLoop/OwnerFlow/Ap786OwnerFlowFase3RepairLoopTest.php
evidence_refs:
  - symbol: Ap786OwnerFlowExecutor
  - symbol: RepairAgentFeedbackContextBuilderService
  - test: Ap786OwnerFlowFase3RepairLoopTest
required_tests:
  - php artisan test tests/Unit/Ai/SoftwareCompanyStewardship/AreaFocusLoop/OwnerFlow/Ap786OwnerFlowFase3RepairLoopTest.php
  - php artisan atlas:engineering:knowledge docs-health --json
requires_evidence: true
risk_level: high
visual_tags:
  - aaeos
  - owner-flow
  - repair-loop
ai_entrypoints:
  - Leia Resumo, Contratos, Regras para IA e Status & honesty contract antes de mexer em AP-786 repair.
ai_usage_notes:
  - Use este doc como contrato de aceite, nao como prova de runtime entregue.
  - O harness incomplete e evidencia de especificacao pendente, nao falha operacional.
quality_gates:
  - php artisan test tests/Unit/Ai/SoftwareCompanyStewardship/AreaFocusLoop/OwnerFlow/Ap786OwnerFlowFase3RepairLoopTest.php
  - php artisan atlas:code-reality global-duplication-audit --summary --json
failure_modes:
  - Coverage-theater passar como sucesso.
  - Retry cego sem realimentar output de validacao.
  - Escalation gastar Opus fora do budget ou sem hard-step.
observability_signals:
  - repair_attempt.iterations
  - repair_attempt.escalated_model
  - owner_runtime_coverage_theater_rejected
next_actions:
  - Manter doc canônico enquanto FASE 3 permanece spec-only.
  - Implementar runtime somente em ciclo AP-786 dedicado com testes red-then-green.
---

# FASE 3 — Iterative Worker Repair Loop, Opus Escalation & Meaningful-Test Verifier

## Resumo

Contrato canônico da FASE 3 do repair loop AP-786. Ele especifica repair iterativo,
realimentação de output de validação, escalation para `claude-opus-4-8` em passos
difíceis e verificação meaningful-test contra coverage-theater.

## Papel no Atlas

Protege o loop 24h do AAEOS contra retries cegos, provider spend mal direcionado
e testes vazios. O documento governa o aceite futuro; não entrega runtime por si.

## Onde Se Encaixa

```text
atlas-software-company-stewardship-stack
  +-- AP-786 owner-flow
      +-- FASE 3 repair-loop contract
```

## Contratos

- `implementation_state=spec_only_failing_harness_no_runtime_change` e obrigatório
  até existir implementação real.
- O owner runtime é `Ap786OwnerFlowExecutor`; não criar repair loop paralelo.
- Provider vivo é proibido durante autoria deste contrato.

## Fluxo

Ler contrato, manter harness incomplete, implementar em ciclo AP-786 dedicado,
rodar testes focados, provar provider-proof/scope/honest-stop e só então promover
o implementation_state.

## Regras para IA

- Não vender este doc como runtime entregue.
- Não enfraquecer provider-proof, scope ou honest-stop para passar teste.
- Não contar `assertTrue(true)` ou teste sem símbolo alterado como entrega.

## Escopo de Implementacao

Este arquivo só documenta o contrato FASE 3 e seus critérios de aceite. Código de
runtime pertence ao owner-flow AP-786 e deve ser tratado em ciclo separado.

## Dependencias

- AP-786 owner-flow.
- `Ap786OwnerFlowExecutor`.
- `RepairAgentFeedbackContextBuilderService`.
- Harness `Ap786OwnerFlowFase3RepairLoopTest`.

## Evidencias

- Este doc.
- Harness FASE 3 marcado incomplete.
- Futuro diff AP-786 com testes red-then-green e receipts.

## Riscos

- Dizer que FASE 3 existe só porque a spec existe.
- Criar segundo repair loop fora do owner-flow.
- Transformar meaningful-test em métrica fraca.

## Exemplos

Um repair válido realimenta a mensagem de erro do teste anterior no prompt
seguinte e só aceita diff com validação focada verde e teste significativo.

## Proximas Acoes

Manter este contrato canônico; implementar a FASE 3 apenas quando houver ciclo
dedicado para owner-flow, testes e evidência.

## 0. Status & honesty contract

This document is **spec-only**. No runtime behavior changes when it lands. It ships
together with a **failing-test harness** (`tests/Unit/.../OwnerFlow/Ap786OwnerFlowFase3*Test.php`)
whose cases are marked `markTestIncomplete(...)` so the suite stays green now and
turns red-then-green only after the FASE 3 implementation exists.

FASE 3 is **provider-dependent** (it changes how the MiniMax/Opus worker is
prompted and which model runs hard steps). Per the operator mandate, **no live
provider is invoked while authoring this fase**. Everything below is proven with
interface doubles (the same `OwnerSandboxRuntimeRunner` / `RepairValidationRunner`
seams the existing `Ap786OwnerFlowRepairAgentTest` uses).

Hard rules preserved (never weakened):
- **Provider-proof (SEC-001 / Bug #2)**: a patch with zero provider calls is
  scaffold, never mergeable.
- **Scope**: every changed file must be inside `allowed_files`.
- **Honest-stop**: repeated-repair detection and the review-lock ceiling still
  short-circuit before spending another provider call on a dead slice.
- **Real-or-blocked**: a repair only counts as `repaired=true` with a real scoped
  diff AND a passing focused validation command AND (FASE 3) a meaningful-test
  verdict of `passed`.

Canonical owner: `app/Services/Ai/SoftwareCompanyStewardship/AreaFocusLoop/OwnerFlow/Ap786OwnerFlowExecutor.php`.

## 1. Problem statement (today's gaps)

### 1.1 The repair loop is effectively one-shot and does not feed validation output back
`runRepairLoop()` sets `$maxRepairs = REPAIR_REVIEW_LOCK_THRESHOLD - 1 = 1`, so a
failing slice gets exactly **one** repair attempt. Worse, the feedback context for
that attempt is built from the **first** `owner_result`'s captured diagnostics
(`RepairAgentFeedbackContextBuilderService::build`), but the **actual output of the
pre-return validation command** (`validateRepair()` → `pre_return_validation_result.output_excerpt`)
is computed *after* the worker already ran and is **never threaded into a
subsequent worker prompt**. The loop therefore cannot iterate "run → read the real
test failure → fix → re-run" the way a senior engineer does.

### 1.2 No model escalation for hard steps
`modelFamily()` is static: it returns `composer-2.5-fast` / `sonnet` / `MiniMax-M2.7`
based only on the provider, and `--composer-model=` is fixed for every attempt.
There is no path that detects a hard step (a slice that failed the first cheap
attempt, or a finding flagged hard) and routes the **next** attempt to
`claude-opus-4-8` (the canonical premium model id, see
`AtlasClaudeCliFrontierGeneratorService::MODEL`).

### 1.3 No meaningful-test verifier — coverage-theater can pass
`validateRepair()` only checks (a) changed files are in scope and (b) the
validation command exits `0`. A worker can satisfy both by writing a test that
asserts nothing real — `assertTrue(true)`, an empty test body, a test that never
references the changed symbol, or a `@test` method with zero assertions. That is
**coverage-theater**: green exit code, zero proof the change is exercised. Nothing
rejects it today.

## 2. FASE 3 design

### 2.1 Iterative repair loop with validation-output feedback

Replace the single-shot constant with a bounded iteration that **realimenta** the
real validation output into the next worker prompt.

- New cap constant: `MAX_REPAIR_ITERATIONS = 3` (worker attempts after the initial
  senior-loop/worker run). The review-lock ceiling is widened to match so the loop
  is allowed to iterate but never unbounded.
  - `REPAIR_REVIEW_LOCK_THRESHOLD` becomes `1 (initial) + MAX_REPAIR_ITERATIONS`.
- Each iteration, in order:
  1. Build feedback **including the previous iteration's** `pre_return_validation_result`
     (`output_excerpt`, `exit_code`, `command`) — not only the first run's
     diagnostics. The feedback context gains a key `previous_validation_output`
     (string) and `previous_validation_exit_code` (int). These are passed into
     `RepairAgentFeedbackContextBuilderService::build` via new input keys
     `previous_validation_output` / `previous_validation_exit_code` and rendered
     by `repairFeedbackSegment()` as `PREVIOUS_VALIDATION_OUTPUT: ...` /
     `PREVIOUS_VALIDATION_EXIT: n`.
  2. Run the worker (`runOwnerRuntimeCommand`) with the repair command carrying
     that feedback.
  3. Repeated-repair detection (unchanged): if the still-failing diff hash repeats,
     short-circuit `repeated_repair_no_progress`.
  4. Pre-return validation (`validateRepair`) — capture output; **store it** to feed
     iteration n+1.
  5. Meaningful-test verification (§2.3) — only on a candidate that passed scope +
     exit 0.
  6. If `repairCompleted && diffIsReal && validation.passed && meaningful.passed`
     → `repaired=true`, return.
- Observability: `repair_attempt.iterations` becomes a `list<array>` with one entry
  per iteration: `{attempt_number, diff_hash, validation_exit_code, validation_passed,
  meaningful_test, model_family, fed_previous_validation_output:bool}`.
- Honest-stop is preserved: `repeated_repair_no_progress` and `review_locked`
  short-circuits are unchanged in meaning; only the iteration budget grows.

**Invariant proven by harness**: when iteration 1 fails with a specific test error,
the worker command for iteration 2 MUST contain that error text (the loop fed the
real validation output back). This is the load-bearing difference vs. blind retry.

### 2.2 Hard-step model escalation to claude-opus-4-8

- New constant: `ESCALATION_MODEL = 'claude-opus-4-8'` (matches
  `AtlasClaudeCliFrontierGeneratorService::MODEL`; never `claude-opus-4-7`).
- A step is **hard** (eligible for escalation) when ANY of:
  - the finding is flagged hard: `finding.difficulty === 'hard'` or
    `finding.escalate_to_premium === true`; OR
  - the cheap model already burned its first repair iteration without a validated
    fix (i.e. escalate on repair iteration `>= ESCALATE_AFTER_ITERATION` where
    `ESCALATE_AFTER_ITERATION = 2`).
- When escalation triggers, the repair command for that iteration is rebuilt with
  `--composer-model=claude-opus-4-8` and `--provider-choice=claude_cli` (Opus runs
  under the Claude CLI provider, never MiniMax), via a new helper
  `escalatedRepairCommand(array $command): array` that rewrites/append the
  `--composer-model=` and `--provider-choice=` flags. For the minimax-worker
  command shape (no `--composer-model`), escalation switches the dispatch to the
  senior-loop Claude command instead (minimax has no premium tier locally).
- `modelFamily()` gains an escalation-aware overload used only inside the repair
  loop: `escalationModelFor(string $provider, bool $hard): string` returning
  `ESCALATION_MODEL` when `$hard`, else the normal family.
- The escalation is **recorded**: `repair_attempt.escalated === true`,
  `repair_attempt.escalated_model === 'claude-opus-4-8'`, and the per-iteration
  entry carries `model_family: 'claude-opus-4-8'`.
- Cost honesty: escalation never bypasses the review-lock ceiling — Opus gets at
  most the remaining iteration budget, never an extra free attempt.

**Invariant proven by harness**: given a hard finding (or a slice that failed the
first cheap iteration), the escalated repair command contains
`--composer-model=claude-opus-4-8` and `--provider-choice=claude_cli`, and the
report exposes `repair_attempt.escalated_model === 'claude-opus-4-8'`.

### 2.3 Meaningful-test verifier (anti coverage-theater)

New seam (interface) `MeaningfulTestVerifier` in the `OwnerFlow` namespace:

```php
interface MeaningfulTestVerifier
{
    /**
     * @param  list<string>  $changedFiles  files the repair touched (scoped)
     * @param  list<string>  $testFiles      the test files among changedFiles / declared tests
     * @return array{passed:bool, reason:string, signals:array<string,mixed>}
     */
    public function verify(string $worktree, array $changedFiles, array $testFiles): array;
}
```

A repair is rejected as coverage-theater (`passed=false`) when, for the test files
that are supposed to prove the change:
- a `@test`/`test_*`/`public function test` method has **zero assertions**; OR
- the only assertions are vacuous: `assertTrue(true)`, `assertSame($x, $x)`,
  `assertEquals(1, 1)`, `markTestIncomplete`, `markTestSkipped`, an empty body, or
  `$this->expectNotToPerformAssertions()`; OR
- **no** test file references any symbol (class/method/function basename) defined or
  changed in the non-test changed files (the test does not exercise the change).

`passed=true` requires at least one test method with at least one **non-vacuous**
assertion that references a changed production symbol.

Production implementation `StaticMeaningfulTestVerifier` reads the changed test
files from the worktree and applies the static signals above (no provider, no
shelling beyond reading files — it is a pure static analysis). Tests inject a fake
so the unit suite never reads the real FS.

Wiring: `validateRepair()`'s success path now additionally calls the verifier; the
repair only counts when `meaningful.passed === true`. A rejected verdict yields a
new blocker `owner_runtime_coverage_theater_rejected` and the slice continues
iterating (the worker is told, via feedback `MEANINGFUL_TEST_REJECTION: <reason>`,
to write a test that actually asserts the changed behavior) until the review-lock
ceiling.

**Invariant proven by harness**: a repair whose diff is scoped and whose validation
command exits 0, but whose test file only does `assertTrue(true)` (or has no
assertion / no reference to the changed symbol), MUST NOT be reported as
`STATUS_COMPLETED` / `repaired=true`; the report must carry
`owner_runtime_coverage_theater_rejected`.

## 3. Constructor / signature deltas (surgical)

`Ap786OwnerFlowExecutor::__construct` gains one more optional nullable seam:
`?MeaningfulTestVerifier $meaningfulVerifier = null` (defaults to
`new StaticMeaningfulTestVerifier`). This mirrors the existing
`?RepairValidationRunner` / `?RepairAgentFeedbackContextBuilderService` pattern, so
no caller breaks and tests can inject a fake.

New public constants on the executor:
- `MAX_REPAIR_ITERATIONS = 3`
- `ESCALATION_MODEL = 'claude-opus-4-8'`
- `ESCALATE_AFTER_ITERATION = 2`
- `STATUS`/blocker string `owner_runtime_coverage_theater_rejected`

`REPAIR_REVIEW_LOCK_THRESHOLD` is recomputed as `1 + MAX_REPAIR_ITERATIONS`.

## 4. Acceptance criteria (each maps to a harness test)

1. **Iterative feedback** — the iteration-2 worker command contains the literal
   test-error text emitted by iteration-1's validation output.
2. **Bounded** — the loop never exceeds `MAX_REPAIR_ITERATIONS` worker runs after
   the initial run; on exhaustion it `review_locked`.
3. **Repeated-repair still short-circuits** before the budget is exhausted when the
   same broken diff repeats.
4. **Escalation on hard finding** — escalated repair command carries
   `--composer-model=claude-opus-4-8` + `--provider-choice=claude_cli`; report
   exposes `escalated_model`.
5. **Escalation on persistent cheap failure** — same, triggered by iteration count.
6. **Meaningful-test rejection** — `assertTrue(true)` / no-assertion / no-reference
   test is rejected with `owner_runtime_coverage_theater_rejected`; never merged.
7. **Meaningful-test pass** — a test with a real assertion referencing the changed
   symbol passes the verifier and (with scope + exit 0) yields `repaired=true`.
8. **Provider-proof preserved** — a repaired diff with zero provider calls is still
   rejected (unchanged law).

## 5. Out of scope for FASE 3
- Running any live provider (forbidden this fase).
- Changing the senior-loop / minimax-worker artisan commands themselves.
- Touching merge governance, AWIS gates, or evidence ledger schemas.
