<?php

declare(strict_types=1);

namespace App\Services\Ai\AgenticEngineeringOs;

use App\Services\Ai\Support\AiValueNormalizer;

/**
 * Autonomous Work Execution OS — PHP implementation of
 * `atlas-autonomous-work-execution-os.md`.
 *
 * Coordinates the autonomous loop:
 *
 *   goal → cycle → step → certify → learn
 *
 * Does NOT execute provider calls — it produces the canonical autonomy
 * envelope that AtlasAutonomousEngineeringService (existing) and the
 * Company Runtime consume. This service is the seam that makes the
 * autonomy ladder (L0 Assist → L7 Self-Evolving) auditable per cycle.
 */
final class AutonomousWorkExecutionOs
{
    public const FIELD_EVALUATED_AT = 'evaluated_at';
    public const FIELD_GOAL = 'goal';
    public const SCHEMA_VERSION = 'atlas.autonomous_work_execution_os.cycle.v1';

    public const AUTONOMY_LEVELS = ['L0', 'L1', 'L2', 'L3', 'L4', 'L5', 'L6', 'L7'];

    public const CYCLE_STAGES = [
        self::FIELD_GOAL_RECORDED,
        self::FIELD_CYCLE_PLANNED,
        self::FIELD_STEPS_DECOMPOSED,
        self::FIELD_STEP_EXECUTED,
        self::FIELD_CERTIFICATION_EVALUATED,
        self::FIELD_LEARNING_EXTRACTED,
    ];

    public const STATUS_PENDING = 'pending';

    public const STATUS_IN_PROGRESS = 'in_progress';

    public const STATUS_SUCCEEDED = 'succeeded';

    public const STATUS_FAILED = 'failed';

    public const STATUS_SKIPPED = 'skipped';

    public const FIELD_COMPLETE = 'complete';

    public const FIELD_BLOCKED = 'blocked';
    public const FIELD_NEXT_STAGE = 'next_stage';
    public const FIELD_CERTIFICATION_BLOCKED = 'certification_blocked';
    public const FIELD_LEARNING_BLOCKED = 'learning_blocked';
    public const FIELD_FAILURE_STAGE = 'failure_stage';
    public const FIELD_STAGE = 'stage';
    public const FIELD_STATUS = 'status';
    public const FIELD_STAGES = 'stages';
    public const FIELD_SCHEMA_VERSION = 'schema_version';
    public const FIELD_AUTONOMY_LEVEL = 'autonomy_level';
    public const FIELD_BLOCKING_REASONS = 'blocking_reasons';
    public const FIELD_OPERATOR_CONSENT_PRESENT = 'operator_consent_present';
    public const FIELD_PRIOR_FAILURE_SIGNATURES = 'prior_failure_signatures';
    public const FIELD_CYCLE_ID = 'cycle_id';
    public const FIELD_GOAL_HASH = 'goal_hash';
    public const FIELD_MAY_PROCEED = 'may_proceed';
    public const FIELD_CYCLE_PLANNED = 'cycle_planned';
    public const FIELD_LEARNING_EXTRACTED = 'learning_extracted';
    public const FIELD_CERTIFICATION_EVALUATED = 'certification_evaluated';
    public const FIELD_SHA256 = 'sha256';
    public const FIELD_STEPS_DECOMPOSED = 'steps_decomposed';
    public const FIELD_STEP_EXECUTED = 'step_executed';
    public const FIELD_GOAL_RECORDED = 'goal_recorded';
    public const FIELD_L6 = 'L6';
    public const FIELD_L7 = 'L7';
    public const FIELD_L4 = 'L4';
    public const FIELD_L5 = 'L5';

    public const STAGE_STATUSES = [
        self::STATUS_PENDING,
        self::STATUS_IN_PROGRESS,
        self::STATUS_SUCCEEDED,
        self::STATUS_FAILED,
        self::STATUS_SKIPPED,
    ];

    /**
     * @param  array{
     *   goal: string,
     *   autonomy_level: string,
     *   operator_consent_present?: bool,
     *   prior_failure_signatures?: list<string>,
     * }  $request
     * @return array{
     *   schema_version: string,
     *   cycle_id: string,
     *   goal_hash: string,
     *   autonomy_level: string,
     *   may_proceed: bool,
     *   blocking_reasons: list<string>,
     *   stages: list<array{stage: string, status: string}>,
     *   evaluated_at: string
     * }
     */
    public function evaluateCycle(array $request): array
    {
        $blocking = [];

        $level = AiValueNormalizer::upperTrimmedString($request[self::FIELD_AUTONOMY_LEVEL] ?? '');
        if (! in_array($level, self::AUTONOMY_LEVELS, true)) {
            $blocking[] = "invalid autonomy_level '{$level}' (must be L0..L7)";
        }

        $goal = AiValueNormalizer::trimmedStringOrNull($request[self::FIELD_GOAL] ?? null) ?? '';
        if ($goal === '') {
            $blocking[] = 'goal text required';
        }

        // L4+ requires explicit operator consent
        $consentRequired = in_array($level, [self::FIELD_L4, self::FIELD_L5, self::FIELD_L6, self::FIELD_L7], true);
        if ($consentRequired && ($request[self::FIELD_OPERATOR_CONSENT_PRESENT] ?? null) !== true) {
            $blocking[] = sprintf('autonomy_level %s requires operator_consent_present=true', $level);
        }

        // L6+ requires zero prior failure signatures with same goal hash
        $priorFailures = AiValueNormalizer::arrayOrEmpty($request[self::FIELD_PRIOR_FAILURE_SIGNATURES] ?? null);
        if (in_array($level, [self::FIELD_L6, self::FIELD_L7], true) && $priorFailures !== []) {
            $blocking[] = sprintf('autonomy_level %s blocks when prior failure signatures exist (%d found)', $level, count($priorFailures));
        }

        $mayProceed = $blocking === [];

        $stages = array_map(
            static fn (string $stage): array => [
                self::FIELD_STAGE => $stage,
                self::FIELD_STATUS => self::STATUS_PENDING,
            ],
            self::CYCLE_STAGES,
        );

        return [
            self::FIELD_SCHEMA_VERSION => self::SCHEMA_VERSION,
            self::FIELD_CYCLE_ID => 'cycle-'.bin2hex(random_bytes(6)),
            self::FIELD_GOAL_HASH => hash(self::FIELD_SHA256, $goal),
            self::FIELD_AUTONOMY_LEVEL => $level,
            self::FIELD_MAY_PROCEED => $mayProceed,
            self::FIELD_BLOCKING_REASONS => $blocking,
            self::FIELD_STAGES => $stages,
            self::FIELD_EVALUATED_AT => now()->toAtomString(),
        ];
    }

    /**
     * Update the status of a cycle stage. Returns the new envelope (the
     * cycle envelope is treated as immutable; this returns a new envelope
     * rather than mutating).
     */
    public function transitionStage(array $cycle, string $stage, string $status): array
    {
        if (! in_array($stage, self::CYCLE_STAGES, true)) {
            throw new \InvalidArgumentException("unknown stage '{$stage}'");
        }
        if (! in_array($status, self::STAGE_STATUSES, true)) {
            throw new \InvalidArgumentException("unknown status '{$status}'");
        }

        $stages = array_map(
            static fn (array $s): array => $s[self::FIELD_STAGE] === $stage ? [self::FIELD_STAGE => $stage, self::FIELD_STATUS => $status] : $s,
            AiValueNormalizer::arrayOrEmpty($cycle[self::FIELD_STAGES] ?? null),
        );

        return array_merge($cycle, [self::FIELD_STAGES => $stages]);
    }

    /**
     * Deterministic next-stage decision: reads the immutable cycle envelope
     * and decides what to do next, without executing any provider.
     *
     * - The first stage (in CYCLE_STAGES order) whose status is "failed"
     *   blocks the cycle: certification_blocked and learning_blocked are
     *   both true, next_stage is null, and failure_stage names it.
     * - Otherwise the first stage still "pending" is the next_stage to run.
     * - When every stage has succeeded (or was skipped), the cycle is
     *   complete: next_stage is null and complete=true.
     *
     * @param  array{stages?: list<array{stage: string, status: string}>}  $cycle
     * @return array{
     *   next_stage: ?string,
     *   blocked: bool,
     *   complete: bool,
     *   certification_blocked: bool,
     *   learning_blocked: bool,
     *   failure_stage: ?string
     * }
     */
    public function nextStageDecision(array $cycle): array
    {
        $statusByStage = [];
        foreach (AiValueNormalizer::arrayOrEmpty($cycle[self::FIELD_STAGES] ?? null) as $entry) {
            $stageKey = AiValueNormalizer::trimmedStringOrNull($entry[self::FIELD_STAGE] ?? null) ?? '';
            $statusByStage[$stageKey]
                = AiValueNormalizer::trimmedStringOrNull($entry[self::FIELD_STATUS] ?? null) ?? self::STATUS_PENDING;
        }

        foreach (self::CYCLE_STAGES as $stage) {
            if (($statusByStage[$stage] ?? self::STATUS_PENDING) === self::STATUS_FAILED) {
                return [
                    self::FIELD_NEXT_STAGE => null,
                    self::FIELD_BLOCKED => true,
                    self::FIELD_COMPLETE => false,
                    self::FIELD_CERTIFICATION_BLOCKED => true,
                    self::FIELD_LEARNING_BLOCKED => true,
                    self::FIELD_FAILURE_STAGE => $stage,
                ];
            }
        }

        foreach (self::CYCLE_STAGES as $stage) {
            if (($statusByStage[$stage] ?? self::STATUS_PENDING) === self::STATUS_PENDING) {
                return [
                    self::FIELD_NEXT_STAGE => $stage,
                    self::FIELD_BLOCKED => false,
                    self::FIELD_COMPLETE => false,
                    self::FIELD_CERTIFICATION_BLOCKED => false,
                    self::FIELD_LEARNING_BLOCKED => false,
                    self::FIELD_FAILURE_STAGE => null,
                ];
            }
        }

        return [
            self::FIELD_NEXT_STAGE => null,
            self::FIELD_BLOCKED => false,
            self::FIELD_COMPLETE => true,
            self::FIELD_CERTIFICATION_BLOCKED => false,
            self::FIELD_LEARNING_BLOCKED => false,
            self::FIELD_FAILURE_STAGE => null,
        ];
    }
}
