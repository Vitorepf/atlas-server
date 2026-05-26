<?php

declare(strict_types=1);

namespace App\Services\Ai\AgenticEngineeringOs;

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

        $level = (string) ($request['autonomy_level'] ?? '');
        if (! in_array($level, self::AUTONOMY_LEVELS, true)) {
            $blocking[] = "invalid autonomy_level '{$level}' (must be L0..L7)";
        }

        $goal = (string) ($request['goal'] ?? '');
        if (trim($goal) === '') {
            $blocking[] = 'goal text required';
        }

        // L4+ requires explicit operator consent
        $consentRequired = in_array($level, ['L4', 'L5', 'L6', 'L7'], true);
        if ($consentRequired && ($request['operator_consent_present'] ?? null) !== true) {
            $blocking[] = sprintf('autonomy_level %s requires operator_consent_present=true', $level);
        }

        // L6+ requires zero prior failure signatures with same goal hash
        $priorFailures = (array) ($request['prior_failure_signatures'] ?? []);
        if (in_array($level, ['L6', 'L7'], true) && $priorFailures !== []) {
            $blocking[] = sprintf('autonomy_level %s blocks when prior failure signatures exist (%d found)', $level, count($priorFailures));
        }

        $mayProceed = $blocking === [];

        $stages = array_map(
            static fn (string $stage): array => [
                'stage' => $stage,
                'status' => 'pending',
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
        $allowedStatuses = ['pending', 'in_progress', 'succeeded', 'failed', 'skipped'];
        if (! in_array($status, $allowedStatuses, true)) {
            throw new \InvalidArgumentException("unknown status '{$status}'");
        }

        $stages = array_map(
            static fn (array $s): array => $s['stage'] === $stage ? ['stage' => $stage, 'status' => $status] : $s,
            (array) ($cycle['stages'] ?? []),
        );

        return array_merge($cycle, ['stages' => $stages]);
    }
}
