<?php

declare(strict_types=1);

namespace App\Services\Ai\SelfConstruction\ExternalBrain;

/**
 * Detects when small-model work is being used as a proxy for real capability
 * and requires escalation only for bounded hard cases.
 *
 * Pure: no I/O, no side effects.
 */
final class AtlasExternalBrainSmallModelEscalationAvoidanceGate
{
    public const SCHEMA = 'atlas.external_brain.small_model_escalation_avoidance_gate.v1';

    public const VERDICT_LOCAL = 'local';
    public const VERDICT_REJECT_PROXY = 'reject_proxy';
    public const VERDICT_ESCALATE = 'escalate';

    /**
     * @param  array<string, mixed>  $input
     * @return array<string, mixed>
     */
    public function evaluate(array $input): array
    {
        $task = is_array($input['task'] ?? null) ? $input['task'] : [];
        $originatorId = (string) ($input['originator_id'] ?? '');
        $roundId = (string) ($input['round_id'] ?? '');

        $difficulty = (string) ($task['difficulty'] ?? 'easy');
        $hasLocalRuntime = (bool) ($task['has_local_runtime'] ?? false);
        $isProxy = (bool) ($task['is_proxy_for_real_capability'] ?? false);
        $bounded = (bool) ($task['bounded_hard_case'] ?? false);
        $evidence = (array) ($task['escalation_evidence'] ?? []);

        $verdict = match (true) {
            $isProxy => self::VERDICT_REJECT_PROXY,
            $difficulty === 'hard' && $bounded && $evidence !== [] => self::VERDICT_ESCALATE,
            $difficulty === 'hard' && ! $bounded => self::VERDICT_REJECT_PROXY,
            default => self::VERDICT_LOCAL,
        };

        $reasons = [];
        if ($verdict === self::VERDICT_LOCAL) {
            $reasons[] = $hasLocalRuntime
                ? 'easy_local_task_with_runtime'
                : 'easy_local_task_no_runtime_needed';
        }
        if ($verdict === self::VERDICT_REJECT_PROXY) {
            $reasons[] = $isProxy
                ? 'rejected_proxy_for_real_capability'
                : 'unbounded_hard_case_requires_bounds';
        }
        if ($verdict === self::VERDICT_ESCALATE) {
            $reasons[] = 'bounded_hard_case_with_evidence_allows_escalation';
        }

        return [
            'schema_version' => self::SCHEMA,
            'originator_id' => $originatorId,
            'round_id' => $roundId,
            'verdict' => $verdict,
            'reasons' => $reasons,
            'difficulty' => $difficulty,
            'has_local_runtime' => $hasLocalRuntime,
            'is_proxy_for_real_capability' => $isProxy,
            'bounded_hard_case' => $bounded,
            'escalation_evidence_count' => count($evidence),
        ];
    }
}
