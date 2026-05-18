<?php

declare(strict_types=1);

namespace App\Services\Ai\Programming\AtlasDev\Repair;

use App\Services\Ai\Mission\MissionCanonicalHash;
use App\Services\Ai\Programming\AtlasDev\Schemas\Components\RepairPolicy;
use App\Services\Ai\Programming\AtlasDev\Schemas\EscalationPacket;
use App\Services\Ai\Programming\AtlasDev\Schemas\FailureCapsule;
use App\Services\Ai\Programming\ProgrammingRepairExecutor;
use App\Services\Ai\Programming\ProgrammingTestImpactAnalyzer;

/**
 * Daily-Dev Repair Loop coordinator. Compared to {@see RepairOrchestrator}
 * (which drives a heavy provider/evaluator loop), this service is a single,
 * deterministic call that turns one observed test/gate failure into a
 * structured `atlas.programming.dev_repair_receipt.v1` so the daily Dev path
 * can decide between retry / blocker / forge handoff without re-implementing
 * any of the existing repair primitives:
 *
 *   - {@see FailureCapsuleBuilder}      → failure_capture invariants.
 *   - {@see FailureModeClassifier}      → failure_classification.
 *   - {@see ProgrammingRepairExecutor}  → repair_candidate (repair_capsule).
 *   - {@see ProgrammingTestImpactAnalyzer} → focused_retest_command(s).
 *   - {@see RepairAttemptLimits}        → R4/R5 escalation + per-risk budget.
 *   - {@see EscalationPacket}::issue    → escalate_to_forge handoff payload.
 *
 * This service NEVER executes shell commands, calls a provider or mutates a
 * codebase. It produces the next-action signal + an audit-stable receipt.
 * Callers (CLI, HTTP, Dev pipeline) decide whether to apply the candidate,
 * record success, escalate or block.
 *
 * Outcome semantics (mirrors the doc's failure-honesty rules):
 *   - `recovered`  caller passed `attempt_outcome.status=passed`;
 *   - `escalated`  scope grew, signature repeated, or risk forbids repair;
 *   - `blocked`    attempts exhausted or context retrieval impossible;
 *   - `planned`    next attempt is allowed and a repair_candidate is ready.
 */
final class DevRepairLoopService
{
    public const RECEIPT_SCHEMA_VERSION = 'atlas.programming.dev_repair_receipt.v1';

    public const OUTCOME_PLANNED = 'planned';

    public const OUTCOME_RECOVERED = 'recovered';

    public const OUTCOME_BLOCKED = 'blocked';

    public const OUTCOME_ESCALATED = 'escalated';

    public const ALLOWED_OUTCOMES = [
        self::OUTCOME_PLANNED,
        self::OUTCOME_RECOVERED,
        self::OUTCOME_BLOCKED,
        self::OUTCOME_ESCALATED,
    ];

    public function __construct(
        private readonly FailureCapsuleBuilder $capsuleBuilder,
        private readonly FailureModeClassifier $classifier,
        private readonly ProgrammingRepairExecutor $repairExecutor,
        private readonly ProgrammingTestImpactAnalyzer $testImpact,
        private readonly RepairAttemptLimits $limits,
    ) {}

    /**
     * @param  array<string,mixed>  $input
     * @return array<string,mixed>
     */
    public function evaluate(array $input): array
    {
        $runId = (string) ($input['run_id'] ?? '');
        $taskContractHash = (string) ($input['task_contract_hash'] ?? '');
        $riskLevel = $this->normalizeRiskLevel((string) ($input['risk_level'] ?? 'R2'));
        $attemptCount = max(0, (int) ($input['attempt_count'] ?? 0));
        $contractMaxAttempts = max(0, (int) ($input['max_attempts'] ?? 2));
        $attemptsAllowed = $this->limits->attemptsAllowed($riskLevel, $contractMaxAttempts);

        $failurePacket = is_array($input['failure_packet'] ?? null) ? $input['failure_packet'] : [];
        $previousCapsule = $this->buildPreviousCapsule($input['previous_capsule'] ?? null);
        $changedFiles = $this->normalizeStringList($input['changed_files'] ?? []);
        $previousChangedFiles = $this->normalizeStringList($input['previous_changed_files'] ?? []);

        $attemptOutcomeStatus = is_string($input['attempt_outcome'] ?? null)
            ? strtolower(trim((string) $input['attempt_outcome']))
            : null;

        $capsule = $this->captureFailure(
            runId: $runId,
            taskContractHash: $taskContractHash,
            attemptCount: $attemptCount,
            failurePacket: $failurePacket,
            changedFiles: $changedFiles,
            previousCapsule: $previousCapsule,
            attemptsAllowed: $attemptsAllowed,
        );

        $classification = $this->classifier->classify(
            capsule: $capsule,
            completionState: $attemptOutcomeStatus ?? 'failed',
            attemptCount: max(1, $attemptCount),
            escalationSignals: $capsule->escalationSignalDelta,
        );

        $contextRetrieval = $this->contextRetrievalNeeded(
            classification: $classification,
            retrievalPlan: is_array($input['retrieval_plan'] ?? null) ? $input['retrieval_plan'] : [],
            gapCritic: is_array($input['gap_critic'] ?? null) ? $input['gap_critic'] : [],
            capsule: $capsule,
        );

        $repairCandidate = $this->repairExecutor->attemptPlan(
            failurePacket: array_merge($failurePacket, [
                'changed_files' => $changedFiles,
                'previous_failure_hash' => $previousCapsule?->failureSignature ?? '',
                'failure_hash' => $capsule->failureSignature,
            ]),
            retrievalPlan: is_array($input['retrieval_plan'] ?? null) ? $input['retrieval_plan'] : [],
            attempt: max(1, $attemptCount + 1),
            maxAttempts: $attemptsAllowed,
        );

        $focusedRetest = $this->focusedRetestCommand(
            capsule: $capsule,
            changedFiles: $changedFiles,
            codeGraph: is_array($input['code_graph'] ?? null) ? $input['code_graph'] : [],
            riskLevel: $riskLevel,
        );

        $diffGrew = $this->detectDiffGrowth($changedFiles, $previousChangedFiles);

        $stopConditions = $this->stopConditions(
            riskLevel: $riskLevel,
            attemptCount: $attemptCount,
            attemptsAllowed: $attemptsAllowed,
            capsule: $capsule,
            diffGrew: $diffGrew,
        );

        [$outcome, $blocker, $escalation] = $this->resolveOutcome(
            attemptOutcomeStatus: $attemptOutcomeStatus,
            stopConditions: $stopConditions,
            capsule: $capsule,
            classification: $classification,
            input: $input,
            diffGrew: $diffGrew,
            riskLevel: $riskLevel,
            attemptCount: $attemptCount,
            attemptsAllowed: $attemptsAllowed,
        );

        $payload = [
            'schema_version' => self::RECEIPT_SCHEMA_VERSION,
            'run_id' => $runId,
            'task_contract_hash' => $taskContractHash,
            'risk_level' => $riskLevel,
            'attempt_count' => $attemptCount,
            'attempts_allowed' => $attemptsAllowed,
            'outcome' => $outcome,
            'failure_capture' => $this->capsuleToArray($capsule),
            'failure_classification' => [
                'schema_version' => 'atlas.programming.failure_classification.v1',
                'mode' => $classification,
                'taxonomy' => $repairCandidate['repair_capsule']['failure_taxonomy'] ?? null,
            ],
            'context_retrieval_needed' => $contextRetrieval,
            'repair_candidate' => $repairCandidate,
            'focused_retest_command' => $focusedRetest,
            'stop_conditions' => $stopConditions,
            'blocker' => $blocker,
            'escalate_to_forge' => $escalation,
        ];

        $hashPayload = $payload;
        // Strip volatile timestamps before hashing so replay produces an
        // identical receipt_hash for the same failure state.
        unset($hashPayload['generated_at']);
        $payload['generated_at'] = now()->toIso8601String();
        $payload['receipt_hash'] = MissionCanonicalHash::sha256($hashPayload);

        return $payload;
    }

    /**
     * @param  array<string,mixed>  $failurePacket
     * @param  list<string>  $changedFiles
     */
    private function captureFailure(
        string $runId,
        string $taskContractHash,
        int $attemptCount,
        array $failurePacket,
        array $changedFiles,
        ?FailureCapsule $previousCapsule,
        int $attemptsAllowed,
    ): FailureCapsule {
        $policy = new RepairPolicy(
            maxAttempts: max(1, $attemptsAllowed > 0 ? $attemptsAllowed : 1),
            sameProvider: true,
            requiresFailedGateOutput: true,
            abortOnSameSignatureTwice: true,
        );

        return $this->capsuleBuilder->build(
            runId: $runId !== '' ? $runId : 'dev-repair-loop',
            taskContractHash: $taskContractHash !== '' ? $taskContractHash : 'unknown-contract',
            attemptIndex: max(0, $attemptCount),
            gate: (string) ($failurePacket['gate'] ?? 'verification_gate'),
            command: $this->stringOrNull($failurePacket['command'] ?? null),
            exitCode: isset($failurePacket['exit_code']) ? (int) $failurePacket['exit_code'] : null,
            primaryErrorRaw: (string) ($failurePacket['primary_error_excerpt'] ?? $failurePacket['primary_error'] ?? $failurePacket['error'] ?? ''),
            fullErrorLogPath: $this->stringOrNull($failurePacket['full_error_log_path'] ?? null),
            failingTest: $this->stringOrNull($failurePacket['failing_test'] ?? null),
            diffHash: $this->stringOrNull($failurePacket['diff_hash'] ?? null),
            changedFiles: $changedFiles,
            previousCapsule: $previousCapsule,
            policy: $policy,
            attemptsAllowed: $attemptsAllowed,
        );
    }

    /**
     * @param  array<string,mixed>  $retrievalPlan
     * @param  array<string,mixed>  $gapCritic
     * @return array<string,mixed>
     */
    private function contextRetrievalNeeded(
        string $classification,
        array $retrievalPlan,
        array $gapCritic,
        FailureCapsule $capsule,
    ): array {
        $reasons = [];

        $sufficiency = (string) data_get($retrievalPlan, 'context_sufficiency_gate.status', '');
        if ($retrievalPlan === []) {
            $reasons[] = 'retrieval_plan_absent';
        } elseif ($sufficiency === 'failed_closed') {
            $reasons[] = 'context_sufficiency_failed_closed';
        } elseif ($sufficiency === 'degraded') {
            $reasons[] = 'context_sufficiency_degraded';
        }

        $gapStatus = strtolower((string) data_get($gapCritic, 'status', ''));
        if ($gapStatus === 'blocked' || $gapStatus === 'degraded') {
            $reasons[] = 'gap_critic_'.$gapStatus;
        }

        if (str_contains(strtolower($classification), 'context')) {
            $reasons[] = 'failure_mode_is_context_error';
        }
        if ($capsule->failingTest !== null && str_contains(strtolower($capsule->failingTest), 'missing')) {
            $reasons[] = 'failing_test_signals_missing_anchor';
        }

        $reasons = array_values(array_unique($reasons));

        return [
            'schema_version' => 'atlas.programming.context_retrieval_decision.v1',
            'required' => $reasons !== [],
            'reasons' => $reasons,
            'recommended_action' => $reasons === []
                ? 'reuse_current_context_pack'
                : 'rerun_retrieval_planner_with_failure_anchors',
        ];
    }

    /**
     * @param  list<string>  $changedFiles
     * @param  array<string,mixed>  $codeGraph
     * @return array<string,mixed>
     */
    private function focusedRetestCommand(
        FailureCapsule $capsule,
        array $changedFiles,
        array $codeGraph,
        string $riskLevel,
    ): array {
        $impact = $this->testImpact->analyze(
            changedFiles: $changedFiles,
            codeGraph: $codeGraph,
            risk: $this->riskWordFor($riskLevel),
        );

        $commands = (array) ($impact['recommended_commands'] ?? []);

        $primary = null;
        $reason = (string) ($impact['selection_reason'] ?? 'derived_from_test_impact_analyzer');
        if ($capsule->failingTest !== null && trim($capsule->failingTest) !== '') {
            $primary = '/opt/homebrew/bin/php artisan test '.$capsule->failingTest;
            $reason = 'rerun_failing_test_from_capsule';
        } elseif ($commands !== []) {
            $primary = (string) $commands[0];
        }

        return [
            'schema_version' => 'atlas.programming.focused_retest.v1',
            'primary_command' => $primary,
            'fallback_commands' => array_values(array_filter(
                $commands,
                static fn ($c): bool => is_string($c) && $c !== '' && $c !== $primary,
            )),
            'selected_tests' => array_values((array) ($impact['selected_tests'] ?? [])),
            'selection_reason' => $reason,
            'minimum_policy' => (string) ($impact['minimum_policy'] ?? 'targeted_unit_or_reason'),
            'requires_no_test_reason' => (bool) ($impact['requires_no_test_reason'] ?? false),
        ];
    }

    /**
     * @return array<int,array<string,mixed>>
     */
    private function stopConditions(
        string $riskLevel,
        int $attemptCount,
        int $attemptsAllowed,
        FailureCapsule $capsule,
        bool $diffGrew,
    ): array {
        $conditions = [];

        if ($this->limits->escalateImmediately($riskLevel)) {
            $conditions[] = [
                'condition' => 'risk_level_forbids_dev_repair',
                'severity' => 'critical',
                'detail' => sprintf('risk_level=%s requires Forge intake', $riskLevel),
            ];
        }

        if ($attemptCount >= $attemptsAllowed && $attemptsAllowed > 0) {
            $conditions[] = [
                'condition' => 'max_attempts_reached',
                'severity' => 'high',
                'detail' => sprintf('attempt_count=%d >= attempts_allowed=%d', $attemptCount, $attemptsAllowed),
            ];
        }

        if (in_array(FailureCapsuleBuilder::SIGNAL_SAME_SIGNATURE_TWICE, $capsule->escalationSignalDelta, true)) {
            $conditions[] = [
                'condition' => 'same_signature_twice',
                'severity' => 'high',
                'detail' => 'failure signature repeated across consecutive attempts',
            ];
        }

        if ($diffGrew || in_array(FailureCapsuleBuilder::SIGNAL_DIFF_GROWTH, $capsule->escalationSignalDelta, true)) {
            $conditions[] = [
                'condition' => 'diff_growth',
                'severity' => 'high',
                'detail' => 'changed_files set grew vs previous attempt — scope expansion candidate',
            ];
        }

        if (in_array(FailureCapsuleBuilder::SIGNAL_SCOPE_VIOLATION, $capsule->escalationSignalDelta, true)) {
            $conditions[] = [
                'condition' => 'scope_violation',
                'severity' => 'critical',
                'detail' => 'attempt touched files outside the contract',
            ];
        }

        if (in_array(FailureCapsuleBuilder::SIGNAL_BLOCKED_PREFLIGHT, $capsule->escalationSignalDelta, true)) {
            $conditions[] = [
                'condition' => 'blocked_preflight',
                'severity' => 'medium',
                'detail' => 'preflight/permission/ambiguity blocked the attempt',
            ];
        }

        return $conditions;
    }

    /**
     * @param  array<int,array<string,mixed>>  $stopConditions
     * @param  array<string,mixed>  $input
     * @return array{0:string,1:array<string,mixed>|null,2:array<string,mixed>|null}
     */
    private function resolveOutcome(
        ?string $attemptOutcomeStatus,
        array $stopConditions,
        FailureCapsule $capsule,
        string $classification,
        array $input,
        bool $diffGrew,
        string $riskLevel,
        int $attemptCount,
        int $attemptsAllowed,
    ): array {
        if ($attemptOutcomeStatus === 'passed') {
            return [self::OUTCOME_RECOVERED, null, null];
        }

        $stopNames = array_map(static fn (array $c): string => (string) $c['condition'], $stopConditions);

        $escalateNames = ['risk_level_forbids_dev_repair', 'diff_growth', 'scope_violation', 'same_signature_twice'];
        $needsEscalation = array_intersect($escalateNames, $stopNames) !== [];

        if ($needsEscalation) {
            $escalation = $this->buildEscalationCandidate(
                input: $input,
                capsule: $capsule,
                classification: $classification,
                stopConditions: $stopConditions,
                riskLevel: $riskLevel,
                attemptCount: $attemptCount,
                attemptsAllowed: $attemptsAllowed,
            );

            return [self::OUTCOME_ESCALATED, null, $escalation];
        }

        if (in_array('max_attempts_reached', $stopNames, true) || in_array('blocked_preflight', $stopNames, true)) {
            $blocker = [
                'schema_version' => 'atlas.programming.dev_repair_blocker.v1',
                'kind' => in_array('max_attempts_reached', $stopNames, true) ? 'safety_gate' : 'inconclusive_result',
                'severity' => 'high',
                'reasons' => $stopNames,
                'description' => 'Dev repair loop blocked: '.implode(',', $stopNames),
                'next_action' => 'open_operator_review_or_escalate_to_forge',
                'evidence_refs' => $this->evidenceRefsFromInput($input),
            ];

            return [self::OUTCOME_BLOCKED, $blocker, null];
        }

        return [self::OUTCOME_PLANNED, null, null];
    }

    /**
     * @param  array<string,mixed>  $input
     * @param  array<int,array<string,mixed>>  $stopConditions
     * @return array<string,mixed>
     */
    private function buildEscalationCandidate(
        array $input,
        FailureCapsule $capsule,
        string $classification,
        array $stopConditions,
        string $riskLevel,
        int $attemptCount,
        int $attemptsAllowed,
    ): array {
        $originalIntent = (string) ($input['original_user_intent'] ?? 'dev_repair_loop_failure');
        $promotionReason = sprintf(
            'Dev repair loop exceeded daily envelope at attempt %d/%d (risk %s, failure_mode=%s): %s',
            $attemptCount,
            max(1, $attemptsAllowed),
            $riskLevel,
            $classification,
            implode(',', array_map(static fn (array $c): string => (string) $c['condition'], $stopConditions)),
        );

        $triggers = $this->triggersFromStopConditions($stopConditions, $riskLevel);

        try {
            $packet = EscalationPacket::issue(
                packetId: 'erp-'.bin2hex(random_bytes(8)),
                originalUserIntent: $originalIntent,
                normalizedIntent: $originalIntent,
                promotionReason: $promotionReason,
                promotionTriggers: $triggers,
                scopeAssessment: $this->scopeAssessmentFromCapsule($capsule),
                riskAssessment: 'Dev repair loop classified failure as '.$classification.' at risk '.$riskLevel.'.',
                ambiguityAssessment: 'See failure_capsule.gate + classification mode.',
                currentDevFindings: $this->stringListOrEmpty($input['current_dev_findings'] ?? []),
                completedDevActions: $this->stringListOrEmpty($input['completed_dev_actions'] ?? []),
                incompleteDevActions: $this->stringListOrEmpty($input['incomplete_dev_actions'] ?? []),
                recommendedForgeMode: $this->forgeModeFor($riskLevel),
                suggestedWorkPackets: $this->suggestedWorkPacketsFromCapsule($capsule, $classification),
                definitionOfDone: ['repair_resolution_completed_under_forge_governance'],
                requiredEvidence: ['plan', 'failure_capsules', 'verification_receipt'],
                evidenceRefs: EscalationPacket::emptyEvidenceRefs(),
                contextRefs: $capsule->changedFiles,
                contextPackHash: $this->stringOrNull(data_get($input, 'retrieval_plan.professional_context_pack.context_pack_hash')),
                constraints: ['preserve_existing_test_signal'],
                nonGoals: ['rewrite_scope_outside_failure_neighborhood'],
                createdAt: now()->toIso8601String(),
            );

            return $packet->toCanonicalArray();
        } catch (\Throwable $e) {
            // Honesty over silence: when packet emission fails (e.g. missing
            // fields), surface the partial intent so the operator/audit can
            // still act, instead of swallowing the escalation request.
            return [
                'schema_version' => 'atlas.programming.dev_repair_escalation_candidate.v1',
                'status' => 'packet_emission_failed',
                'reason' => $e->getMessage(),
                'promotion_reason' => $promotionReason,
                'promotion_triggers' => $triggers,
                'recommended_forge_mode' => $this->forgeModeFor($riskLevel),
            ];
        }
    }

    /**
     * @param  array<int,array<string,mixed>>  $stopConditions
     * @return list<string>
     */
    private function triggersFromStopConditions(array $stopConditions, string $riskLevel): array
    {
        $triggers = [];
        foreach ($stopConditions as $condition) {
            $triggers[] = match ($condition['condition'] ?? '') {
                'risk_level_forbids_dev_repair' => EscalationPacket::TRIGGER_HIGH_RISK,
                'diff_growth' => EscalationPacket::TRIGGER_SCOPE_TOO_LARGE,
                'scope_violation' => EscalationPacket::TRIGGER_SCOPE_TOO_LARGE,
                'same_signature_twice' => EscalationPacket::TRIGGER_EVIDENCE_INSUFFICIENT,
                'max_attempts_reached' => EscalationPacket::TRIGGER_TIME_BUDGET_EXCEEDED,
                'blocked_preflight' => EscalationPacket::TRIGGER_EVIDENCE_INSUFFICIENT,
                default => null,
            };
        }
        $triggers = array_values(array_unique(array_filter($triggers, static fn ($t): bool => is_string($t) && $t !== '')));
        if ($triggers === []) {
            $triggers[] = EscalationPacket::TRIGGER_OPERATOR_REQUESTED;
        }
        if (in_array($riskLevel, ['R4', 'R5'], true) && ! in_array(EscalationPacket::TRIGGER_HIGH_RISK, $triggers, true)) {
            $triggers[] = EscalationPacket::TRIGGER_HIGH_RISK;
        }

        return $triggers;
    }

    private function forgeModeFor(string $riskLevel): string
    {
        return match ($riskLevel) {
            'R4', 'R5' => EscalationPacket::RECOMMENDED_FORGE_MODE_SDD_INTAKE,
            'R3' => EscalationPacket::RECOMMENDED_FORGE_MODE_OBRA_INTAKE,
            default => EscalationPacket::RECOMMENDED_FORGE_MODE_OBRA_INTAKE,
        };
    }

    private function scopeAssessmentFromCapsule(FailureCapsule $capsule): string
    {
        $count = count($capsule->changedFiles);

        return sprintf(
            'Repair loop touched %d file(s); gate=%s; failing_test=%s.',
            $count,
            $capsule->gate,
            $capsule->failingTest ?? 'n/a',
        );
    }

    /**
     * @return list<array<string,mixed>>
     */
    private function suggestedWorkPacketsFromCapsule(FailureCapsule $capsule, string $classification): array
    {
        return [[
            'id' => 'wp-001',
            'title' => 'Forge takeover for Dev repair escalation',
            'objective' => sprintf(
                'Resolve failure (mode=%s) at gate %s with full SDD/QA harness.',
                $classification,
                $capsule->gate,
            ),
            'expected_files' => $capsule->changedFiles,
            'acceptance_criteria' => [
                'Original failing test '.($capsule->failingTest ?? 'covered by verification plan').' passes',
                'No scope expansion beyond declared work packet allowed_files',
            ],
            'required_evidence' => ['plan', 'failure_capsules', 'verification_receipt', 'certification'],
            'suggested_tests' => $capsule->failingTest !== null ? [$capsule->failingTest] : [],
            'risks' => ['Carried over from Dev repair loop scope expansion'],
        ]];
    }

    /**
     * @param  array<string,mixed>  $input
     * @return list<string>
     */
    private function evidenceRefsFromInput(array $input): array
    {
        $refs = [];
        $runId = $this->stringOrNull($input['run_id'] ?? null);
        if ($runId !== null) {
            $refs[] = 'dev_repair_run:'.$runId;
        }
        $contextPackHash = $this->stringOrNull(data_get($input, 'retrieval_plan.professional_context_pack.context_pack_hash'));
        if ($contextPackHash !== null) {
            $refs[] = 'context_pack:'.$contextPackHash;
        }

        return $refs;
    }

    private function buildPreviousCapsule(mixed $value): ?FailureCapsule
    {
        if (! is_array($value) || $value === []) {
            return null;
        }
        // Caller might already supply a FailureCapsule via toArray(); rebuild
        // it for hash invariants. We only need failure_signature continuity
        // (same_signature detection) so the rest is best-effort.
        $signature = (string) ($value['failure_signature'] ?? '');
        if ($signature === '') {
            return null;
        }

        return FailureCapsule::issue(
            runId: (string) ($value['run_id'] ?? 'dev-repair-loop'),
            taskContractHash: (string) ($value['task_contract_hash'] ?? 'unknown-contract'),
            attemptIndex: max(0, (int) ($value['attempt_index'] ?? 0)),
            gate: (string) ($value['gate'] ?? 'verification_gate'),
            command: $this->stringOrNull($value['command'] ?? null),
            exitCode: isset($value['exit_code']) ? (int) $value['exit_code'] : null,
            primaryErrorExcerpt: (string) ($value['primary_error_excerpt'] ?? 'previous failure'),
            fullErrorLogPath: $this->stringOrNull($value['full_error_log_path'] ?? null),
            failingTest: $this->stringOrNull($value['failing_test'] ?? null),
            diffHash: $this->stringOrNull($value['diff_hash'] ?? null),
            changedFiles: $this->normalizeStringList($value['changed_files'] ?? []),
            decision: (string) ($value['decision'] ?? FailureCapsule::DECISION_RETRY),
            escalationSignalDelta: $this->normalizeStringList($value['escalation_signal_delta'] ?? []),
        );
    }

    /**
     * @param  list<string>  $current
     * @param  list<string>  $previous
     */
    private function detectDiffGrowth(array $current, array $previous): bool
    {
        if ($previous === []) {
            return false;
        }

        return count($current) > count($previous);
    }

    /**
     * @return array<string,mixed>
     */
    private function capsuleToArray(FailureCapsule $capsule): array
    {
        return $capsule->toCanonicalArray();
    }

    private function normalizeRiskLevel(string $raw): string
    {
        $candidate = strtoupper(trim($raw));
        if (preg_match('/^R[0-5]$/', $candidate) === 1) {
            return $candidate;
        }

        return 'R2';
    }

    private function riskWordFor(string $riskLevel): string
    {
        return match ($riskLevel) {
            'R0', 'R1' => 'low',
            'R2' => 'medium',
            'R3' => 'high',
            'R4', 'R5' => 'critical',
            default => 'medium',
        };
    }

    private function stringOrNull(mixed $value): ?string
    {
        if (! is_string($value)) {
            return null;
        }
        $value = trim($value);

        return $value === '' ? null : $value;
    }

    /**
     * @return list<string>
     */
    private function normalizeStringList(mixed $value): array
    {
        if (! is_array($value)) {
            return [];
        }

        return array_values(array_filter(
            array_map(static fn ($v): ?string => is_string($v) ? trim($v) : null, $value),
            static fn (?string $v): bool => $v !== null && $v !== '',
        ));
    }

    /**
     * @return list<string>
     */
    private function stringListOrEmpty(mixed $value): array
    {
        return $this->normalizeStringList($value);
    }
}
