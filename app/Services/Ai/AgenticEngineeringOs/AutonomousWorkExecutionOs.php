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
    public const SCHEMA_VERSION = 'atlas.autonomous_work_execution_os.cycle.v1';

    public const AUTONOMY_LEVELS = ['L0', 'L1', 'L2', 'L3', 'L4', 'L5', 'L6', 'L7'];

    public const CYCLE_STAGES = [
        'goal_recorded',
        'cycle_planned',
        'steps_decomposed',
        'step_executed',
        'certification_evaluated',
        'learning_extracted',
    ];

    public const STATUS_PENDING = 'pending';

    public const STATUS_IN_PROGRESS = 'in_progress';

    public const STATUS_SUCCEEDED = 'succeeded';

    public const STATUS_FAILED = 'failed';

    public const STATUS_SKIPPED = 'skipped';

    public const FIELD_COMPLETE = 'complete';

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

        $level = AiValueNormalizer::upperTrimmedString($request['autonomy_level'] ?? '');
        if (! in_array($level, self::AUTONOMY_LEVELS, true)) {
            $blocking[] = "invalid autonomy_level '{$level}' (must be L0..L7)";
        }

        $goal = AiValueNormalizer::trimmedStringOrNull($request['goal'] ?? null) ?? '';
        if ($goal === '') {
            $blocking[] = 'goal text required';
        }

        // L4+ requires explicit operator consent
        $consentRequired = in_array($level, ['L4', 'L5', 'L6', 'L7'], true);
        if ($consentRequired && ($request['operator_consent_present'] ?? null) !== true) {
            $blocking[] = sprintf('autonomy_level %s requires operator_consent_present=true', $level);
        }

        // L6+ requires zero prior failure signatures with same goal hash
        $priorFailures = AiValueNormalizer::arrayOrEmpty($request['prior_failure_signatures'] ?? null);
        if (in_array($level, ['L6', 'L7'], true) && $priorFailures !== []) {
            $blocking[] = sprintf('autonomy_level %s blocks when prior failure signatures exist (%d found)', $level, count($priorFailures));
        }

        $mayProceed = $blocking === [];

        $stages = array_map(
            static fn (string $stage): array => [
                'stage' => $stage,
                'status' => self::STATUS_PENDING,
            ],
            self::CYCLE_STAGES,
        );

        return [
            'schema_version' => self::SCHEMA_VERSION,
            'cycle_id' => 'cycle-'.bin2hex(random_bytes(6)),
            'goal_hash' => hash('sha256', $goal),
            'autonomy_level' => $level,
            'may_proceed' => $mayProceed,
            'blocking_reasons' => $blocking,
            'stages' => $stages,
            'evaluated_at' => now()->toAtomString(),
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
            static fn (array $s): array => $s['stage'] === $stage ? ['stage' => $stage, 'status' => $status] : $s,
            AiValueNormalizer::arrayOrEmpty($cycle['stages'] ?? null),
        );

        return array_merge($cycle, ['stages' => $stages]);
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
        foreach (AiValueNormalizer::arrayOrEmpty($cycle['stages'] ?? null) as $entry) {
            $stageKey = AiValueNormalizer::trimmedStringOrNull($entry['stage'] ?? null) ?? '';
            $statusByStage[$stageKey]
                = AiValueNormalizer::trimmedStringOrNull($entry['status'] ?? null) ?? self::STATUS_PENDING;
        }

        foreach (self::CYCLE_STAGES as $stage) {
            if (($statusByStage[$stage] ?? self::STATUS_PENDING) === self::STATUS_FAILED) {
                return [
                    'next_stage' => null,
                    'blocked' => true,
                    self::FIELD_COMPLETE => false,
                    'certification_blocked' => true,
                    'learning_blocked' => true,
                    'failure_stage' => $stage,
                ];
            }
        }

        foreach (self::CYCLE_STAGES as $stage) {
            if (($statusByStage[$stage] ?? self::STATUS_PENDING) === self::STATUS_PENDING) {
                return [
                    'next_stage' => $stage,
                    'blocked' => false,
                    self::FIELD_COMPLETE => false,
                    'certification_blocked' => false,
                    'learning_blocked' => false,
                    'failure_stage' => null,
                ];
            }
        }

        return [
            'next_stage' => null,
            'blocked' => false,
            self::FIELD_COMPLETE => true,
            'certification_blocked' => false,
            'learning_blocked' => false,
            'failure_stage' => null,
        ];
    }
}
