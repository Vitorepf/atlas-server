---
id: atlas-aaeos-factory-runtime-bridge-backlog
type: engineering_knowledge
title: AAEOS Factory Runtime Bridge Backlog
doc_schema: atlas_canonical_module_doc.v1
status: planned
implementation_state: backlog_only_no_runtime
authority_class: backlog
category: agentic-engineering
priority: 97
summary: Loop-ready atomic extraction of the valuable runtime ideas from the mixed Claude 2 AAEOS/Factory backlog. Each row converts a broad multi-file integration risk into one pure decision, gate, scoring, or certification kernel that the stewardship loop can implement safely before heavier runtime wiring.
owner: operator (Vitor)
risk_level: medium
tags:
  - atlas-ai
  - aaeos
  - stewardship-loop
  - factory-runtime
  - dev-forge
capabilities:
  - loop_ready_atomic_runtime_bridge_backlog
  - factory_quality_gates
  - evidence_and_merge_truth_kernels
  - repair_and_compounding_kernels
decisions:
  - Claude 2 broad runtime rows are valuable but not safe for direct provider execution.
  - Loop-ready extraction must be one new pure class plus one paired meaningful unit test.
  - Runtime wiring, migrations, controllers, provider calls, and merge actions remain gated future work.
maintenance:
  - Keep every Section 6 row atomic with explicit target class and paired test.
  - Do not add rows that edit existing files or require live provider/runtime state.
  - Run docs-health and plan decompose after any change.
related_paths:
  - docs/engineering-knowledge-base/atlas-aaeos-evolution-backlog-index.md
  - docs/engineering-knowledge-base/atlas-aaeos-loop-evolution-backlog.md
  - docs/engineering-knowledge-base/atlas-canonical-glossary-and-naming.md
  - docs/engineering-knowledge-base/atlas-software-company-stewardship-stack.md
graph_id: atlas-aaeos-factory-runtime-bridge-backlog
graph_title: AAEOS Factory Runtime Bridge Backlog
graph_world: atlas
graph_layer: module
graph_kind: index
graph_parent: atlas-aaeos-evolution-backlog-index
graph_status: planned
graph_source: repo
repo_paths:
  - docs/engineering-knowledge-base/atlas-aaeos-factory-runtime-bridge-backlog.md
allowed_changes:
  - Add or refine atomic pure-kernel rows extracted from gated runtime backlog items.
  - Move any broad row back to the mixed backlog until it is decomposed.
forbidden_changes:
  - Do NOT treat backlog rows as delivered runtime.
  - Do NOT wire providers, controllers, migrations, queues, or merge commands in this doc.
  - Do NOT add benchmark/Rivals work to this AAEOS Dev+Forge loop-ready set.
depends_on:
  - atlas-aaeos-evolution-backlog-index
  - atlas-aaeos-loop-evolution-backlog
flows_to:
  - atlas-software-company-stewardship-stack
unlocks:
  - loop_ready_factory_runtime_bridge_kernels
  - safer_runtime_wiring_child_slices
governs:
  - stewardship_loop.plan_backlog
evidence:
  - docs/engineering-knowledge-base/atlas-aaeos-factory-runtime-bridge-backlog.md
required_tests:
  - php artisan atlas:engineering:knowledge docs-health --json
  - php artisan atlas:plan-execution:decompose --doc=docs/engineering-knowledge-base/atlas-aaeos-factory-runtime-bridge-backlog.md --json
requires_evidence: true
next_actions:
  - Let AP-790 consume these rows through governed plan backlog selection and owner-flow execution.
---

## Resumo

Este backlog pega o que realmente importa do Claude 2 e transforma em fatias que o loop consegue executar com alta previsibilidade. O doc amplo original continua como fonte e DAG; este arquivo e a versao atomica para execucao.

## Papel no Atlas

Fortalecer a fabrica AAEOS/Dev+Forge com nucleos pequenos que reduzem falso sucesso: autonomia governada, provider routing honesto, evidence, merge truth, repair, compounding, validation e post-execution phases.

## Onde Se Encaixa

```text
atlas-aaeos-loop-evolution-backlog
  +-- atlas-aaeos-factory-runtime-bridge-backlog
      +-- AP-790 plan backlog bridge
```

## Contratos

- Cada row cria uma classe nova, final, pura, sem constructor deps, sem I/O e sem provider.
- Cada row inclui um teste novo com assercoes computadas e fronteiras.
- Nenhuma row promete runtime wired; ela entrega o kernel que torna a wiring posterior menor e julgavel.

## Fluxo

O loop seleciona uma row ready, cria a classe e o teste, valida, julga, repara se necessario e mergeia apenas com main avancando. Depois, runtime wiring pode consumir esses kernels em slices separados.

## Regras para IA

- Nao editar classes existentes a partir deste doc.
- Nao chamar provider externo dentro das classes geradas.
- Nao usar scaffold ou teste shape-only.
- Nao transformar approval, merge ou revert em efeito colateral dentro destes kernels.

## Escopo de Implementacao

Somente novos kernels PHP e testes pareados nos paths declarados. Toda integracao multi-arquivo fica fora deste doc.

## Dependencias

- AP-790 Reliable 24h Loop Runner.
- AP-786 owner-flow.
- `atlas-aaeos-loop-evolution-backlog.md` como fonte ampla.

## Evidencias

- Decompose completo deste doc.
- Testes pareados gerados por cada slice quando executado.
- Receipts do AP-790/AP-786 para cada entrega real.

## Riscos

- Achar que um kernel puro ja fez a wiring de runtime.
- Criar classe com logica estatica sem computar dos inputs.
- Duplicar kernels ja existentes em vez de manter nomes novos e escopo claro.

## Exemplos

Uma row segura transforma "autonomy tier promotion" em um avaliador puro que decide se a promocao seria permitida a partir de receipt, area state e kill switch. Ela nao altera config nem liga provider.

## Proximas Acoes

- Consumir este doc junto dos outros backlogs atomicos.
- So criar child slices de wiring depois que o kernel correspondente existir e estiver testado.

## 6. Decomposicao em slices ordenados

| ID | Item | Aceite | DoD |
| --- | --- | --- | --- |
| S301 | Create a new PHP class AutonomyTierPromotionDecisionEvaluator at app/Services/Ai/SoftwareCompanyStewardship/AreaFocusLoop/AtomicBacklog/AutonomyTierPromotionDecisionEvaluator.php. Implement `public function decide(array $receipt, array $areaState, array $runtimeSignals): array` as a pure evaluator for Claude 2 S49. It computes whether an area can move from tier 0 scan-only to tier 1 execute from signed operator receipt, registered area, requested tier, budget, and kill-switch signals. [area=loop route=atlas_dev r_level=R1 north_star=false status=ready src=docs/engineering-knowledge-base/atlas-aaeos-loop-evolution-backlog.md:S49] | Return schema_version `atlas.loop.autonomy_tier_promotion_decision.v1`, decision `promote` or `block`, active_tier int, blockers list, and receipt_required bool. Tests in tests/Unit/Ai/SoftwareCompanyStewardship/AreaFocusLoop/AtomicBacklog/AutonomyTierPromotionDecisionEvaluatorTest.php assert signed approved receipt moves 0 to 1, missing signature blocks at tier 0, kill_switch_active always blocks, requested tier above max blocks, and identical input is deterministic. | Pure final class, zero deps, no I/O, no mutation, no promotion side effect; paired test passes. |
| S302 | Create a new PHP class ProductiveExecutionModeGateEvaluator at app/Services/Ai/SoftwareCompanyStewardship/AreaFocusLoop/AtomicBacklog/ProductiveExecutionModeGateEvaluator.php. Implement `public function evaluate(array $confirmGates, array $providerAuthorization, array $work): array` as a pure gate for Claude 2 S50. It decides execute, fixture_only, or blocked without invoking providers. [area=loop route=atlas_dev r_level=R1 north_star=false status=ready src=docs/engineering-knowledge-base/atlas-aaeos-loop-evolution-backlog.md:S50] | Return schema_version `atlas.productive_execution.mode_gate.v1`, mode, execute_allowed bool, blocking_reasons, and gate_summary. Tests assert all six gates plus provider auth produce mode execute, any failed confirm gate blocks, smoke_only work produces fixture_only, missing provider auth blocks, and fallback reason is explicit. New unit test path tests/Unit/Ai/SoftwareCompanyStewardship/AreaFocusLoop/AtomicBacklog/ProductiveExecutionModeGateEvaluatorTest.php. | Pure final class; computes from arrays only; no provider, no controller, no fixture execution; paired test passes. |
| S303 | Create a new PHP class SddMutationApprovalGateEvaluator at app/Services/Ai/SoftwareCompanyStewardship/AreaFocusLoop/AtomicBacklog/SddMutationApprovalGateEvaluator.php. Implement `public function evaluate(array $request, array $gate, array $approval): array` for Claude 2 S51. It decides whether an SDD mutation request is allowed, needs human approval, or blocked. [area=aaeos route=atlas_dev r_level=R1 north_star=false status=ready src=docs/engineering-knowledge-base/atlas-aaeos-loop-evolution-backlog.md:S51] | Return schema_version `atlas.sdd.mutation_approval_gate.v1`, verdict, write_allowed bool, required_receipts, and blockers. Tests assert approved POST execute with matching allowed path permits write, missing approval returns needs_human_approval, failed gate blocks, target outside allowed files blocks, and read-only dry request never writes. Test path tests/Unit/Ai/SoftwareCompanyStewardship/AreaFocusLoop/AtomicBacklog/SddMutationApprovalGateEvaluatorTest.php. | New pure class and test only; no route, no file write, no SDD runtime call. |
| S304 | Create a new PHP class AtlasDevRunProfileGuardEvaluator at app/Services/Ai/SoftwareCompanyStewardship/AreaFocusLoop/AtomicBacklog/AtlasDevRunProfileGuardEvaluator.php. Implement `public function evaluate(array $profile, array $job, array $decision): array` for Claude 2 S52. It validates governed run profile, receipt-before-provider, and promotion-preview escalation signals. [area=aaeos route=atlas_dev r_level=R1 north_star=false status=ready src=docs/engineering-knowledge-base/atlas-aaeos-loop-evolution-backlog.md:S52] | Return schema_version `atlas.dev.run_profile_guard.v1`, run_allowed bool, escalation_required bool, route_decision, and blockers. Tests assert scoped profile with receipt permits run, global run_enabled blocks, missing pre_provider_receipt blocks, promotion_preview sets escalation_required with packet_required, and ordinary A1 job stays fast path. Test path tests/Unit/Ai/SoftwareCompanyStewardship/AreaFocusLoop/AtomicBacklog/AtlasDevRunProfileGuardEvaluatorTest.php. | Pure final class; no config writes, no provider calls, no controller edits. |
| S305 | Create a new PHP class ProviderFallbackHonestyClassifier at app/Services/Ai/SoftwareCompanyStewardship/AreaFocusLoop/AtomicBacklog/ProviderFallbackHonestyClassifier.php. Implement `public function classify(array $providerState, array $request, array $fallback): array` for Claude 2 S53 and provider honesty. It distinguishes semantic_provider, explicit_local_fallback, and blocked_silent_fallback. [area=aaeos route=atlas_dev r_level=R1 north_star=false status=ready src=docs/engineering-knowledge-base/atlas-aaeos-loop-evolution-backlog.md:S53] | Return schema_version `atlas.provider.fallback_honesty.v1`, mode, provider_invoked bool, fallback_honest bool, blockers, and audit_reason. Tests assert configured compatible provider chooses semantic_provider, unavailable provider with declared fallback chooses explicit_local_fallback with provider_invoked false, silent fallback blocks, privacy mismatch blocks, and timeout/rate_limit are transient provider failures not success. Test path tests/Unit/Ai/SoftwareCompanyStewardship/AreaFocusLoop/AtomicBacklog/ProviderFallbackHonestyClassifierTest.php. | Pure classifier only; no embedding call, no network, no provider spend. |
| S306 | Create a new PHP class LocalAgentMemoryPromotionGateEvaluator at app/Services/Ai/SoftwareCompanyStewardship/AreaFocusLoop/AtomicBacklog/LocalAgentMemoryPromotionGateEvaluator.php. Implement `public function evaluate(array $candidate): array` for Claude 2 S54. It gates local-agent ingestion promotion into durable memory using eligibility, classification, lineage, confidence, and secret scan signals. [area=aaeos route=atlas_dev r_level=R1 north_star=false status=ready src=docs/engineering-knowledge-base/atlas-aaeos-loop-evolution-backlog.md:S54] | Return schema_version `atlas.local_agent.memory_promotion_gate.v1`, verdict, promote_allowed bool, blockers, and required_evidence. Tests assert eligible classified candidate with lineage and secret_scan pass permits promotion, memory_eligible false blocks, missing classification blocks, secret_scan fail blocks, confidence below threshold blocks with numeric deficit. Test path tests/Unit/Ai/SoftwareCompanyStewardship/AreaFocusLoop/AtomicBacklog/LocalAgentMemoryPromotionGateEvaluatorTest.php. | Pure gate only; no DB insert, no memory write, no secret scanner invocation. |
| S307 | Create a new PHP class RealCycleEvidenceCompletenessEvaluator at app/Services/Ai/SoftwareCompanyStewardship/AreaFocusLoop/AtomicBacklog/RealCycleEvidenceCompletenessEvaluator.php. Implement `public function evaluate(array $cycle): array` for Claude 2 S55 and the operator's real-cycle criteria. [area=loop route=atlas_dev r_level=R1 north_star=false status=ready src=docs/engineering-knowledge-base/atlas-aaeos-loop-evolution-backlog.md:S55] | Return schema_version `atlas.loop.real_cycle_evidence_completeness.v1`, counted_real bool, final_status, missing_receipts, merge_truth_ok bool, and quality_floor_passed bool. Tests assert a full cycle with slice plan, lane receipts, provider honest state, branch/worktree, validation, judge, evidence ref, and main_before != main_after counts real; merge with equal main hashes fails merge_truth_ok; missing lane receipts fails; provider_invoked true without provider receipt fails; blocked_honest_after_real_attempt can count as real with blocker receipt. Test path tests/Unit/Ai/SoftwareCompanyStewardship/AreaFocusLoop/AtomicBacklog/RealCycleEvidenceCompletenessEvaluatorTest.php. | Pure evaluator; no git, no provider, no ledger writes. |
| S308 | Create a new PHP class LearningLiftAttributionScorer at app/Services/Ai/SoftwareCompanyStewardship/AreaFocusLoop/AtomicBacklog/LearningLiftAttributionScorer.php. Implement `public function score(array $baseline, array $after, array $attribution): array` for Claude 2 S56. It computes measurable lift from cost, flow quality, test pass rate, and repair-loop reduction. [area=loop route=atlas_dev r_level=R1 north_star=false status=ready src=docs/engineering-knowledge-base/atlas-aaeos-loop-evolution-backlog.md:S56] | Return schema_version `atlas.loop.learning_lift_attribution.v1`, lift_score int, verdict positive, neutral, or regression, component_deltas, attribution_confidence, and blockers. Tests assert lower cost plus higher quality yields positive, higher repair rate yields regression, missing baseline blocks, low attribution confidence yields neutral_not_attributable, and weights clamp score to -100..100. Test path tests/Unit/Ai/SoftwareCompanyStewardship/AreaFocusLoop/AtomicBacklog/LearningLiftAttributionScorerTest.php. | Pure scorer only; no Evidence Ledger append, no auto-apply. |
| S309 | Create a new PHP class MeasuredLearningApplyGateEvaluator at app/Services/Ai/SoftwareCompanyStewardship/AreaFocusLoop/AtomicBacklog/MeasuredLearningApplyGateEvaluator.php. Implement `public function evaluate(array $proposal, array $proof, array $gate): array` for Claude 2 S57. It allows applying a learning only when approved or proven, rsi_meta_judge passes, scope is bounded, and rollback proof exists. [area=loop route=atlas_dev r_level=R1 north_star=false status=ready src=docs/engineering-knowledge-base/atlas-aaeos-loop-evolution-backlog.md:S57] | Return schema_version `atlas.loop.measured_learning_apply_gate.v1`, apply_allowed bool, decision, blockers, required_next_action. Tests assert proven positive lift plus rsi gate pass permits apply, unproven proposal blocks, rsi_meta_judge fail blocks, missing rollback plan blocks, and scope outside allowed paths blocks. Test path tests/Unit/Ai/SoftwareCompanyStewardship/AreaFocusLoop/AtomicBacklog/MeasuredLearningApplyGateEvaluatorTest.php. | Pure gate; no mutation runtime call, no git, no side effect. |
| S310 | Create a new PHP class RegressionRevertDecisionEvaluator at app/Services/Ai/SoftwareCompanyStewardship/AreaFocusLoop/AtomicBacklog/RegressionRevertDecisionEvaluator.php. Implement `public function decide(array $appliedLearning, array $measurement, array $workspaceState): array` for Claude 2 S58. It decides no_op, revert, or block_revert. [area=loop route=atlas_dev r_level=R1 north_star=false status=ready src=docs/engineering-knowledge-base/atlas-aaeos-loop-evolution-backlog.md:S58] | Return schema_version `atlas.loop.regression_revert_decision.v1`, decision, revert_allowed bool, blockers, and git_operation `revert_no_edit` or `none`. Tests assert measured regression after applied learning chooses revert_no_edit, no regression chooses no_op, dirty human work blocks revert, missing revert port blocks, and reset_hard is never emitted. Test path tests/Unit/Ai/SoftwareCompanyStewardship/AreaFocusLoop/AtomicBacklog/RegressionRevertDecisionEvaluatorTest.php. | Pure decision kernel; no git command execution. |
| S311 | Create a new PHP class CompoundingFlywheelCertificationEvaluator at app/Services/Ai/SoftwareCompanyStewardship/AreaFocusLoop/AtomicBacklog/CompoundingFlywheelCertificationEvaluator.php. Implement `public function certify(array $cycles, array $options = []): array` for Claude 2 S59. It checks whether consecutive real cycles prove compounding lift without unmeasured auto-apply. [area=loop route=atlas_dev r_level=R1 north_star=false status=ready src=docs/engineering-knowledge-base/atlas-aaeos-loop-evolution-backlog.md:S59] | Return schema_version `atlas.loop.compounding_flywheel_certification.v1`, certified bool, cycle_count, positive_lift_count, unmeasured_auto_apply_count, blockers, and aggregate_lift. Tests assert K consecutive cycles with positive aggregate lift and measured deltas certify, any unmeasured auto_apply blocks, insufficient K blocks, one regression below tolerance blocks, and replay_hash mismatch blocks. Test path tests/Unit/Ai/SoftwareCompanyStewardship/AreaFocusLoop/AtomicBacklog/CompoundingFlywheelCertificationEvaluatorTest.php. | Pure certification only; no replay or ledger access. |
| S312 | Create a new PHP class EvidenceLedgerHashChainIntegrityVerifier at app/Services/Ai/SoftwareCompanyStewardship/AreaFocusLoop/AtomicBacklog/EvidenceLedgerHashChainIntegrityVerifier.php. Implement `public function verify(array $events): array` for Claude 2 S60. Events contain event_id, scope_key, event_hash, prev_event_hash, and canonical_payload_hash; the class verifies per-scope chain links. [area=aaeos route=atlas_dev r_level=R1 north_star=false status=ready src=docs/engineering-knowledge-base/atlas-aaeos-loop-evolution-backlog.md:S60] | Return schema_version `atlas.evidence.ledger_hash_chain_integrity.v1`, status ok, gap, or tampered, chain_length, gap_count, tampered_event_ids, first_event_id, last_event_id. Tests assert two independent scopes both start with null prev hash, valid chain returns ok, broken prev hash returns gap, changed event_hash returns tampered for exact event id, and empty events return ok with length 0. Test path tests/Unit/Ai/SoftwareCompanyStewardship/AreaFocusLoop/AtomicBacklog/EvidenceLedgerHashChainIntegrityVerifierTest.php. | Pure verifier; no migration, no DB, no ledger read. |
| S313 | Create a new PHP class ProviderRouteFallbackDecisionEvaluator at app/Services/Ai/SoftwareCompanyStewardship/AreaFocusLoop/AtomicBacklog/ProviderRouteFallbackDecisionEvaluator.php. Implement `public function decide(array $job, array $learnedRoute, array $defaultRoute): array` for Claude 2 S61. It chooses learned provider only when task_category, role, privacy, availability, and ADML verdict allow it. [area=aaeos route=atlas_dev r_level=R1 north_star=false status=ready src=docs/engineering-knowledge-base/atlas-aaeos-loop-evolution-backlog.md:S61] | Return schema_version `atlas.provider.route_fallback_decision.v1`, selected_provider, route_source learned or default, fallback_reason, receipt_required bool, and provider_invoked false. Tests assert valid learned route wins, missing role falls back to default, verdict not follow_learned falls back with reason, privacy mismatch blocks learned route, and default unavailable blocks honestly. Test path tests/Unit/Ai/SoftwareCompanyStewardship/AreaFocusLoop/AtomicBacklog/ProviderRouteFallbackDecisionEvaluatorTest.php. | Pure router decision; no AiProviderManager call. |
| S314 | Create a new PHP class ValidationCommandCoveragePlanner at app/Services/Ai/SoftwareCompanyStewardship/AreaFocusLoop/AtomicBacklog/ValidationCommandCoveragePlanner.php. Implement `public function plan(array $changedFiles, array $declaredCommands, array $toolchain): array` for Claude 2 S64. It computes required validation commands and missing toolchain flags without touching the filesystem. [area=aaeos route=atlas_dev r_level=R1 north_star=false status=ready src=docs/engineering-knowledge-base/atlas-aaeos-loop-evolution-backlog.md:S64] | Return schema_version `atlas.validation.command_coverage_plan.v1`, commands, missing_toolchain, coverage_status full, partial, or blocked, and covered_extensions. Tests assert php file plus phpstan available adds phpstan, declared duplicate command is not duplicated, ts file without tsc adds missing_toolchain:ts, secret scan available adds secret command once, and changed files with no validation produce blocked. Test path tests/Unit/Ai/SoftwareCompanyStewardship/AreaFocusLoop/AtomicBacklog/ValidationCommandCoveragePlannerTest.php. | Pure planner only; no `which`, no process, no VerificationGate call. |
| S315 | Create a new PHP class RepairRetryHintPolicyEvaluator at app/Services/Ai/SoftwareCompanyStewardship/AreaFocusLoop/AtomicBacklog/RepairRetryHintPolicyEvaluator.php. Implement `public function evaluate(array $failedCycle, array $state, int $maxAttempts = 3): array` for Claude 2 S65. It decides retry, resolved, escalate, or exhausted from repair hints and attempt counters. [area=aaeos route=atlas_dev r_level=R1 north_star=false status=ready src=docs/engineering-knowledge-base/atlas-aaeos-loop-evolution-backlog.md:S65] | Return schema_version `atlas.repair.retry_hint_policy.v1`, decision, next_attempt_allowed bool, attempt_count_next, stop_reason, and evidence_required. Tests assert retry_with_fresh_context under max attempts retries, gate_passed returns resolved, escalate_to_human stops, request_operator_input stops, and max attempts returns exhausted. Test path tests/Unit/Ai/SoftwareCompanyStewardship/AreaFocusLoop/AtomicBacklog/RepairRetryHintPolicyEvaluatorTest.php. | Pure policy kernel; no cycle start, no ledger write. |
| S316 | Create a new PHP class CycleOutcomeSelectionSignalScorer at app/Services/Ai/SoftwareCompanyStewardship/AreaFocusLoop/AtomicBacklog/CycleOutcomeSelectionSignalScorer.php. Implement `public function score(array $historyByGapKind): array` for Claude 2 S66. It computes merge_rate, blocker_rate, outcome_met_rate, repair_exhausted_rate, and a deterministic priority delta per gap_kind. [area=loop route=atlas_dev r_level=R1 north_star=false status=ready src=docs/engineering-knowledge-base/atlas-aaeos-loop-evolution-backlog.md:S66] | Return schema_version `atlas.loop.cycle_outcome_selection_signal.v1`, signals_by_gap_kind, best_gap_kind, worst_gap_kind, and priority_deltas. Tests assert 9 merges and 1 block gives merge_rate 0.9 and positive delta, blocker_rate over 0.6 gives negative delta, repair_exhausted over threshold penalizes, zero history gives delta 0 with confidence 0, and tie order is deterministic by gap_kind. Test path tests/Unit/Ai/SoftwareCompanyStewardship/AreaFocusLoop/AtomicBacklog/CycleOutcomeSelectionSignalScorerTest.php. | Pure scorer; no JSONL read, no ranker mutation. |
| S317 | Create a new PHP class PostExecutionPhaseEmissionPlanBuilder at app/Services/Ai/SoftwareCompanyStewardship/AreaFocusLoop/AtomicBacklog/PostExecutionPhaseEmissionPlanBuilder.php. Implement `public function build(array $job, array $attempt, array $result, array $options = []): array` for Claude 2 S82. It computes the P10-P16 envelope plan after provider execution without dispatching. [area=aaeos route=atlas_dev r_level=R1 north_star=false status=ready src=docs/engineering-knowledge-base/atlas-aaeos-loop-evolution-backlog.md:S82] | Return schema_version `atlas.aaeos.post_execution_phase_emission_plan.v1`, enabled bool, envelopes list, failed_phase_ids, and dispatcher_marker_required bool. Tests assert flag false returns enabled false and empty envelopes, ok result builds seven P10-P16 envelopes, failed result marks P10 failed and downstream blocked, non-mission skips P14 human review canonically, and exception policy is never_throw. Test path tests/Unit/Ai/SoftwareCompanyStewardship/AreaFocusLoop/AtomicBacklog/PostExecutionPhaseEmissionPlanBuilderTest.php. | Pure plan builder; no AiWorker edit, no enqueue, no dispatch. |
| S318 | Create a new PHP class LongRunQualityDriftPromotionGate at app/Services/Ai/SoftwareCompanyStewardship/AreaFocusLoop/AtomicBacklog/LongRunQualityDriftPromotionGate.php. Implement `public function apply(array $ladder, array $qualityDriftReport): array` for Claude 2 S79. It blocks rung promotion above highest_passed when quality drift says stop_promotion. [area=loop route=atlas_dev r_level=R1 north_star=false status=ready src=docs/engineering-knowledge-base/atlas-aaeos-loop-evolution-backlog.md:S79] | Return schema_version `atlas.loop.long_run_quality_drift_promotion_gate.v1`, ladder, changed bool, blocked_rungs, and next_blocker. Tests assert stop_promotion true blocks every rung above highest_passed with next_blocker quality_drift_stop_promotion, stop_promotion false returns byte-equivalent ladder with changed false, missing report returns unchanged, already-blocked rung keeps its blocker, and highest_passed itself stays passed. Test path tests/Unit/Ai/SoftwareCompanyStewardship/AreaFocusLoop/AtomicBacklog/LongRunQualityDriftPromotionGateTest.php. | Pure gate; no ladder service edit, no certification mutation. |

## 10. Sequenciamento

S301-S318 sao raizes atomicas com dependencias leves apenas entre kernels de certificacao composta. Edges: S307 -> S311, S308 -> S309, S309 -> S310, S310 -> S311, S314 -> S315. Wiring runtime posterior deve depender do kernel correspondente ja implementado.
