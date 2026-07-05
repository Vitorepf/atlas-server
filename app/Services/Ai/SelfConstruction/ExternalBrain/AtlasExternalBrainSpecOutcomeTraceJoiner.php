<?php

declare(strict_types=1);

namespace App\Services\Ai\SelfConstruction\ExternalBrain;

/**
 * Pure joiner — bridges an authored task SPEC with its muscle OUTCOME into one compact learning record.
 * Answers "which specs actually deliver", not just "we created tasks". Never reads files, git, the
 * queue, or calls a provider; everything is supplied as input facts.
 *
 * INPUT:
 *   $spec    = { task_packet_id, task_family, allowed_files:list<string>, acceptance_criteria:list<string>,
 *                worker?:string, model?:string }
 *   $outcome = { status?:'success'|'give_back'|null, commit_sha?:string, evidence?:array<string,mixed>,
 *                give_back_reason?:string, root_cause_hint?:string, decision_changed?:bool }
 *
 * OUTPUT (FACTS only):
 *   pending  — outcome status missing → { status:'pending_outcome', task_packet_id, target_files, task_family, task_shape }
 *   success  — { status:'success', success:true, evidence_strength:float, task_shape, commit_evidence,
 *                worker, model, decision_changed, learning_signal, learning_signal_reusable, evidence_gap }
 *   give_back — { status:'give_back', success:false, give_back_reason, root_cause_hint, repair_candidate,
 *                 task_shape, worker, model, decision_changed, learning_signal, learning_signal_reusable, evidence_gap }
 *
 * task_shape: { task_family, allowed_files_count, has_test_file, acceptance_criteria_count }
 *
 * repair_candidate: true when the give_back root_cause_hint/give_back_reason names a SPEC-shape defect
 * (scope, acceptance, objective, ambiguity, missing test path, contradiction) rather than a worker error.
 *
 * learning_signal_reusable: true only when the trace carries concrete root cause AND the signal has
 * future admission or prompt policy impact (spec-shape defects, success patterns, quarantine/gate learnings).
 * Worker-error give_backs and evidence-less weak greens are NOT reusable.
 *
 * evidence_gap: list of missing proof dimensions — missing_allowed_files_scope, missing_commit_proof,
 * missing_test_proof, missing_give_back_reason, missing_root_cause_hint. Empty when the trace is complete.
 *
 * Pure: no I/O, no provider calls, no git/queue access.
 */
final class AtlasExternalBrainSpecOutcomeTraceJoiner
{
    public const SCHEMA = 'atlas.self_construction.external_brain.spec_outcome_trace_joiner.v1';

    public const STATUS_PENDING = 'pending_outcome';

    public const STATUS_SUCCESS = 'success';

    public const STATUS_GIVE_BACK = 'give_back';

    public const STATUS_WEAK_GREEN = 'weak_green';

    public const STATUS_POISON = 'poison';

    public const STATUS_FAILED_GATE = 'failed_gate';

    private const SPEC_SHAPE_DEFECT_PATTERN = '/scope|acceptance|objective|ambig|missing_test|contradict|malformed/i';

    /** Evidence keys whose presence increases the strength of a success record. */
    private const EVIDENCE_KEYS = ['tests_or_gates_result', 'implementation_notes', 'commit_sha'];

    /** Below this many acceptance criteria, the spec itself is under-specified. */
    private const MIN_ACCEPTANCE_CRITERIA = 2;

    /** Above this many allowed_files, the spec is at risk of scope sprawl. */
    private const MAX_ALLOWED_FILES = 5;

    private const RISK_MISSING_TEST_FILE = 'missing_test_file';

    private const RISK_TOO_FEW_ACCEPTANCE_CRITERIA = 'too_few_acceptance_criteria';

    private const RISK_OVERSIZED_ALLOWED_FILES = 'oversized_allowed_files';

    /** Reliability-delta magnitudes per outcome class, applied to worker_model routing. */
    private const RELIABILITY_DELTA_POISON = -1.0;

    private const RELIABILITY_DELTA_FAILED_GATE = -0.5;

    private const RELIABILITY_DELTA_WEAK_GREEN = -0.25;

    private const RELIABILITY_DELTA_GIVE_BACK_WORKER_FAULT = -0.5;

    private const RELIABILITY_DELTA_GIVE_BACK_REPAIR_CANDIDATE = -0.15;

    private const GAP_MISSING_ALLOWED_FILES_SCOPE = 'missing_allowed_files_scope';

    private const GAP_MISSING_COMMIT_PROOF = 'missing_commit_proof';

    private const GAP_MISSING_TEST_PROOF = 'missing_test_proof';

    private const GAP_MISSING_GIVE_BACK_REASON = 'missing_give_back_reason';

    private const GAP_MISSING_ROOT_CAUSE_HINT = 'missing_root_cause_hint';

    /** Statuses that carry commit/test proof expectations (they attempted work). */
    private const PROOF_BEARING_STATUSES = [
        self::STATUS_SUCCESS,
        self::STATUS_WEAK_GREEN,
        self::STATUS_POISON,
        self::STATUS_FAILED_GATE,
    ];

    /** Statuses whose trace benefits from an explicit root cause for reusable learning. */
    private const ROOT_CAUSE_BEARING_STATUSES = [
        self::STATUS_GIVE_BACK,
        self::STATUS_POISON,
        self::STATUS_FAILED_GATE,
    ];

    /**
     * @param  array<string,mixed>  $spec
     * @param  array<string,mixed>  $outcome
     * @return array<string,mixed>
     */
    public function join(array $spec, array $outcome): array
    {
        $taskShape = $this->taskShape($spec);
        $specShapeRisk = $this->specShapeRisk($taskShape);
        $worker = (string) ($outcome['worker'] ?? $spec['worker'] ?? '');
        $model = (string) ($outcome['model'] ?? $spec['model'] ?? '');
        $decisionChanged = (bool) ($outcome['decision_changed'] ?? false);

        $taskPacketId = (string) ($spec['task_packet_id'] ?? '');
        $targetFiles = array_values(array_map('strval', (array) ($spec['allowed_files'] ?? [])));

        $status = $outcome['status'] ?? null;
        if ($status === null || $status === '') {
            return [
                'schema' => self::SCHEMA,
                'task_packet_id' => $taskPacketId,
                'target_files' => $targetFiles,
                'status' => self::STATUS_PENDING,
                'task_family' => $taskShape['task_family'],
                'task_shape' => $taskShape,
                'spec_shape_risk' => $specShapeRisk,
                'evidence_gap' => $this->evidenceGap($targetFiles, self::STATUS_PENDING, [], '', '', ''),
                'learning_signal_reusable' => false,
            ];
        }

        $status = (string) $status;

        $evidence = is_array($outcome['evidence'] ?? null) ? $outcome['evidence'] : [];
        $commitSha = (string) ($outcome['commit_sha'] ?? '');
        if ($commitSha !== '') {
            $evidence['commit_sha'] = $commitSha;
        }
        $evidenceStrength = $this->evidenceStrength($evidence);

        $giveBackReason = (string) ($outcome['give_back_reason'] ?? '');
        $rootCauseHint = (string) ($outcome['root_cause_hint'] ?? '');

        if ($status === self::STATUS_SUCCESS) {
            $hasValueProof = ! empty($evidence['implementation_notes']) && ! empty($evidence['value_delta']);
            if (! $hasValueProof) {
                return [
                    'schema' => self::SCHEMA,
                    'task_packet_id' => $taskPacketId,
                    'target_files' => $targetFiles,
                    'status' => self::STATUS_WEAK_GREEN,
                    'success' => false,
                    'evidence_strength' => $evidenceStrength,
                    'task_shape' => $taskShape,
                    'spec_shape_risk' => $specShapeRisk,
                    'worker' => $worker,
                    'model' => $model,
                    'decision_changed' => $decisionChanged,
                    'learning_signal' => 'evidence_too_weak_trust_only_partially',
                    'worker_model_reliability_delta' => $this->reliabilityDelta(self::RELIABILITY_DELTA_WEAK_GREEN, 'weak_green_reliability_penalty'),
                    'evidence_gap' => $this->evidenceGap($targetFiles, self::STATUS_WEAK_GREEN, $evidence, $commitSha, $giveBackReason, $rootCauseHint),
                    'learning_signal_reusable' => $this->isLearningSignalReusable(self::STATUS_WEAK_GREEN, $evidence, $rootCauseHint, $giveBackReason, false),
                ];
            }

            return [
                'schema' => self::SCHEMA,
                'task_packet_id' => $taskPacketId,
                'target_files' => $targetFiles,
                'status' => self::STATUS_SUCCESS,
                'success' => true,
                'evidence_strength' => $evidenceStrength,
                'commit_evidence' => $commitSha,
                'task_shape' => $taskShape,
                'spec_shape_risk' => $specShapeRisk,
                'worker' => $worker,
                'model' => $model,
                'decision_changed' => $decisionChanged,
                'learning_signal' => 'reinforce_task_shape_and_worker_pairing',
                'worker_model_reliability_delta' => $this->reliabilityDelta(
                    round(0.5 + 0.5 * $evidenceStrength, 2),
                    'strong_success_reliability_boost',
                ),
                'evidence_gap' => $this->evidenceGap($targetFiles, self::STATUS_SUCCESS, $evidence, $commitSha, $giveBackReason, $rootCauseHint),
                'learning_signal_reusable' => $this->isLearningSignalReusable(self::STATUS_SUCCESS, $evidence, $rootCauseHint, $giveBackReason, false),
            ];
        }

        if ($status === self::STATUS_GIVE_BACK) {
            $repairCandidate = $this->isSpecShapeDefect($giveBackReason) || $this->isSpecShapeDefect($rootCauseHint);

            return [
                'schema' => self::SCHEMA,
                'task_packet_id' => $taskPacketId,
                'target_files' => $targetFiles,
                'status' => self::STATUS_GIVE_BACK,
                'success' => false,
                'evidence_strength' => $evidenceStrength,
                'give_back_reason' => $giveBackReason,
                'root_cause_hint' => $rootCauseHint,
                'repair_candidate' => $repairCandidate,
                'task_shape' => $taskShape,
                'spec_shape_risk' => $specShapeRisk,
                'worker' => $worker,
                'model' => $model,
                'decision_changed' => $decisionChanged,
                'learning_signal' => $repairCandidate
                    ? 'repair_spec_shape_before_resubmitting'
                    : 'investigate_worker_or_environment_issue',
                'worker_model_reliability_delta' => $repairCandidate
                    ? $this->reliabilityDelta(self::RELIABILITY_DELTA_GIVE_BACK_REPAIR_CANDIDATE, 'give_back_repair_candidate_reliability_penalty')
                    : $this->reliabilityDelta(self::RELIABILITY_DELTA_GIVE_BACK_WORKER_FAULT, 'give_back_worker_or_environment_reliability_penalty'),
                'evidence_gap' => $this->evidenceGap($targetFiles, self::STATUS_GIVE_BACK, $evidence, $commitSha, $giveBackReason, $rootCauseHint),
                'learning_signal_reusable' => $this->isLearningSignalReusable(self::STATUS_GIVE_BACK, $evidence, $rootCauseHint, $giveBackReason, $repairCandidate),
            ];
        }

        if ($status === self::STATUS_WEAK_GREEN) {
            return [
                'schema' => self::SCHEMA,
                'task_packet_id' => $taskPacketId,
                'target_files' => $targetFiles,
                'status' => self::STATUS_WEAK_GREEN,
                'success' => false,
                'evidence_strength' => $evidenceStrength,
                'task_shape' => $taskShape,
                'spec_shape_risk' => $specShapeRisk,
                'worker' => $worker,
                'model' => $model,
                'decision_changed' => $decisionChanged,
                'learning_signal' => 'evidence_too_weak_trust_only_partially',
                'worker_model_reliability_delta' => $this->reliabilityDelta(self::RELIABILITY_DELTA_WEAK_GREEN, 'weak_green_reliability_penalty'),
                'evidence_gap' => $this->evidenceGap($targetFiles, self::STATUS_WEAK_GREEN, $evidence, $commitSha, $giveBackReason, $rootCauseHint),
                'learning_signal_reusable' => $this->isLearningSignalReusable(self::STATUS_WEAK_GREEN, $evidence, $rootCauseHint, $giveBackReason, false),
            ];
        }

        if ($status === self::STATUS_POISON) {
            return [
                'schema' => self::SCHEMA,
                'task_packet_id' => $taskPacketId,
                'target_files' => $targetFiles,
                'status' => self::STATUS_POISON,
                'success' => false,
                'evidence_strength' => $evidenceStrength,
                'task_shape' => $taskShape,
                'spec_shape_risk' => $specShapeRisk,
                'worker' => $worker,
                'model' => $model,
                'decision_changed' => $decisionChanged,
                'learning_signal' => 'quarantine_pattern_before_reuse',
                'worker_model_reliability_delta' => $this->reliabilityDelta(self::RELIABILITY_DELTA_POISON, 'poison_reliability_penalty'),
                'evidence_gap' => $this->evidenceGap($targetFiles, self::STATUS_POISON, $evidence, $commitSha, $giveBackReason, $rootCauseHint),
                'learning_signal_reusable' => $this->isLearningSignalReusable(self::STATUS_POISON, $evidence, $rootCauseHint, $giveBackReason, false),
            ];
        }

        if ($status === self::STATUS_FAILED_GATE) {
            return [
                'schema' => self::SCHEMA,
                'task_packet_id' => $taskPacketId,
                'target_files' => $targetFiles,
                'status' => self::STATUS_FAILED_GATE,
                'success' => false,
                'evidence_strength' => $evidenceStrength,
                'task_shape' => $taskShape,
                'spec_shape_risk' => $specShapeRisk,
                'worker' => $worker,
                'model' => $model,
                'decision_changed' => $decisionChanged,
                'learning_signal' => 'strengthen_acceptance_or_gate_alignment',
                'worker_model_reliability_delta' => $this->reliabilityDelta(self::RELIABILITY_DELTA_FAILED_GATE, 'failed_gate_reliability_penalty'),
                'evidence_gap' => $this->evidenceGap($targetFiles, self::STATUS_FAILED_GATE, $evidence, $commitSha, $giveBackReason, $rootCauseHint),
                'learning_signal_reusable' => $this->isLearningSignalReusable(self::STATUS_FAILED_GATE, $evidence, $rootCauseHint, $giveBackReason, false),
            ];
        }

        // Unrecognized status — treat as pending so an unknown shape never gets silently judged success/fail.
        return [
            'schema' => self::SCHEMA,
            'task_packet_id' => $taskPacketId,
            'target_files' => $targetFiles,
            'status' => self::STATUS_PENDING,
            'task_family' => $taskShape['task_family'],
            'task_shape' => $taskShape,
            'spec_shape_risk' => $specShapeRisk,
            'evidence_gap' => $this->evidenceGap($targetFiles, self::STATUS_PENDING, [], '', '', ''),
            'learning_signal_reusable' => false,
        ];
    }

    /**
     * @return array{value:float, reason:string}
     */
    private function reliabilityDelta(float $value, string $reason): array
    {
        return ['value' => $value, 'reason' => $reason];
    }

    /**
     * Compute the list of missing proof dimensions for a trace.
     *
     * @param  list<string>  $targetFiles
     * @param  array<string,mixed>  $evidence
     * @return list<string>
     */
    private function evidenceGap(
        array $targetFiles,
        string $status,
        array $evidence,
        string $commitSha,
        string $giveBackReason,
        string $rootCauseHint,
    ): array {
        $gaps = [];

        if ($targetFiles === []) {
            $gaps[] = self::GAP_MISSING_ALLOWED_FILES_SCOPE;
        }

        if (in_array($status, self::PROOF_BEARING_STATUSES, true)) {
            if ($commitSha === '') {
                $gaps[] = self::GAP_MISSING_COMMIT_PROOF;
            }
            if (empty($evidence['tests_or_gates_result'])) {
                $gaps[] = self::GAP_MISSING_TEST_PROOF;
            }
        }

        if ($status === self::STATUS_GIVE_BACK && $giveBackReason === '') {
            $gaps[] = self::GAP_MISSING_GIVE_BACK_REASON;
        }

        if (in_array($status, self::ROOT_CAUSE_BEARING_STATUSES, true) && $rootCauseHint === '') {
            $gaps[] = self::GAP_MISSING_ROOT_CAUSE_HINT;
        }

        return $gaps;
    }

    /**
     * A learning signal is reusable only when the trace has concrete root cause
     * AND the signal carries future admission or prompt policy impact.
     *
     * @param  array<string,mixed>  $evidence
     */
    private function isLearningSignalReusable(
        string $status,
        array $evidence,
        string $rootCauseHint,
        string $giveBackReason,
        bool $repairCandidate,
    ): bool {
        $hasConcreteCause = match ($status) {
            self::STATUS_SUCCESS, self::STATUS_WEAK_GREEN =>
                ! empty($evidence['implementation_notes']) || ! empty($evidence['value_delta']),
            self::STATUS_GIVE_BACK =>
                $rootCauseHint !== '' || $this->isSpecShapeDefect($giveBackReason),
            self::STATUS_POISON, self::STATUS_FAILED_GATE =>
                $rootCauseHint !== '' || ! empty($evidence),
            default => false,
        };

        $hasPolicyImpact = match ($status) {
            self::STATUS_GIVE_BACK => $repairCandidate,
            self::STATUS_SUCCESS, self::STATUS_WEAK_GREEN,
            self::STATUS_POISON, self::STATUS_FAILED_GATE => true,
            default => false,
        };

        return $hasConcreteCause && $hasPolicyImpact;
    }

    /**
     * @param  array{task_family:string, allowed_files_count:int, has_test_file:bool, acceptance_criteria_count:int}  $taskShape
     * @return list<string>
     */
    private function specShapeRisk(array $taskShape): array
    {
        $risks = [];
        if (! $taskShape['has_test_file']) {
            $risks[] = self::RISK_MISSING_TEST_FILE;
        }
        if ($taskShape['acceptance_criteria_count'] < self::MIN_ACCEPTANCE_CRITERIA) {
            $risks[] = self::RISK_TOO_FEW_ACCEPTANCE_CRITERIA;
        }
        if ($taskShape['allowed_files_count'] > self::MAX_ALLOWED_FILES) {
            $risks[] = self::RISK_OVERSIZED_ALLOWED_FILES;
        }

        return $risks;
    }

    /**
     * @param  array<string,mixed>  $spec
     * @return array{task_family:string, allowed_files_count:int, has_test_file:bool, acceptance_criteria_count:int}
     */
    private function taskShape(array $spec): array
    {
        $allowedFiles = array_values(array_map('strval', (array) ($spec['allowed_files'] ?? [])));
        $hasTestFile = false;
        foreach ($allowedFiles as $f) {
            $norm = ltrim(str_replace('\\', '/', trim($f)), '/');
            if (str_starts_with($norm, 'tests/') || str_contains($norm, '/tests/') || str_ends_with($norm, 'Test.php')) {
                $hasTestFile = true;
                break;
            }
        }

        return [
            'task_family' => (string) ($spec['task_family'] ?? ''),
            'allowed_files_count' => count($allowedFiles),
            'has_test_file' => $hasTestFile,
            'acceptance_criteria_count' => count((array) ($spec['acceptance_criteria'] ?? [])),
        ];
    }

    /**
     * @param  array<string,mixed>  $evidence
     */
    private function evidenceStrength(array $evidence): float
    {
        $present = 0;
        foreach (self::EVIDENCE_KEYS as $key) {
            if (! empty($evidence[$key])) {
                $present++;
            }
        }

        return count(self::EVIDENCE_KEYS) > 0 ? round($present / count(self::EVIDENCE_KEYS), 2) : 0.0;
    }

    private function isSpecShapeDefect(string $text): bool
    {
        return $text !== '' && preg_match(self::SPEC_SHAPE_DEFECT_PATTERN, $text) === 1;
    }
}
