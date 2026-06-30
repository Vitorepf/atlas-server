<?php

declare(strict_types=1);

namespace App\Services\Ai\SelfConstruction\ExternalBrain;

/**
 * Pure pattern library. Distills high-quality frontier reasoning into abstract,
 * provider-free decision patterns that improve future smaller-model runs without
 * copying prompts or depending on a frontier model being present.
 *
 * Accepted pattern types: decision_pattern, failure_check, task_shaping_heuristic
 *
 * Provider-safety (AC2):
 *   patterns containing raw provider prompts or private traces are rejected
 *   immediately with violation reasons; they are never stored.
 *
 * Ranking + retirement (AC3):
 *   reusable_patterns are sorted DESC by success_count.
 *   Patterns are retired when:
 *     - give_back_count ≥ RETIRE_GIVE_BACK_THRESHOLD, OR
 *     - give_back_rate > RETIRE_GIVE_BACK_RATE (when enough data), OR
 *     - success_count = 0 AND low_value_count ≥ RETIRE_LOW_VALUE_THRESHOLD
 *
 * AC4: output always includes reusable_patterns, retired_patterns,
 *      provider_safe_summary, and next_run_injection_rules.
 *
 * Pure: no I/O, no side effects.
 */
final class AtlasExternalBrainFrontierDistillationPatternLibrary
{
    public const SCHEMA = 'atlas.external_brain.frontier_distillation_pattern_library.v1';

    public const TYPE_DECISION_PATTERN        = 'decision_pattern';
    public const TYPE_FAILURE_CHECK           = 'failure_check';
    public const TYPE_TASK_SHAPING_HEURISTIC  = 'task_shaping_heuristic';

    public const REJECTION_PROVIDER_PROMPT    = 'provider_prompt_violation';
    public const REJECTION_PRIVATE_TRACE      = 'private_trace_violation';
    public const REJECTION_INVALID_TYPE       = 'invalid_pattern_type';

    public const RETIRE_REASON_GIVE_BACK_COUNT  = 'give_back_count_threshold_exceeded';
    public const RETIRE_REASON_GIVE_BACK_RATE   = 'give_back_rate_too_high';
    public const RETIRE_REASON_ZERO_VALUE       = 'zero_success_with_low_value_signal';

    private const VALID_TYPES = [
        self::TYPE_DECISION_PATTERN,
        self::TYPE_FAILURE_CHECK,
        self::TYPE_TASK_SHAPING_HEURISTIC,
    ];

    private const RETIRE_GIVE_BACK_THRESHOLD = 3;
    private const RETIRE_GIVE_BACK_RATE      = 0.50;
    private const RETIRE_LOW_VALUE_THRESHOLD = 2;
    private const MIN_OUTCOMES_FOR_RATE      = 4;

    private const MAX_INJECTION_RULES = 3;

    /**
     * @param  array{patterns?: list<array<string,mixed>>}  $input
     * @return array{schema:string, reusable_patterns:list<array<string,mixed>>, retired_patterns:list<array<string,mixed>>, provider_safe_summary:string, next_run_injection_rules:list<string>}
     */
    public function distill(array $input): array
    {
        $patterns = (array) ($input['patterns'] ?? []);

        $reusable  = [];
        $retired   = [];
        $rejected  = [];

        foreach ($patterns as $idx => $pattern) {
            $patternId    = (string) ($pattern['pattern_id']   ?? "pattern_{$idx}");
            $type         = (string) ($pattern['type']         ?? '');
            $abstractRule = trim((string) ($pattern['abstract_rule'] ?? ''));

            // AC2: provider-safety checks first
            if (! empty($pattern['contains_provider_prompt'])) {
                $rejected[] = ['pattern_id' => $patternId, 'rejection_reason' => self::REJECTION_PROVIDER_PROMPT];
                continue;
            }
            if (! empty($pattern['contains_private_trace'])) {
                $rejected[] = ['pattern_id' => $patternId, 'rejection_reason' => self::REJECTION_PRIVATE_TRACE];
                continue;
            }
            if (! in_array($type, self::VALID_TYPES, true)) {
                $rejected[] = ['pattern_id' => $patternId, 'rejection_reason' => self::REJECTION_INVALID_TYPE];
                continue;
            }

            $successCount  = max(0, (int) ($pattern['success_count']   ?? 0));
            $giveBackCount = max(0, (int) ($pattern['give_back_count'] ?? 0));
            $lowValueCount = max(0, (int) ($pattern['low_value_count'] ?? 0));
            $totalOutcomes = $successCount + $giveBackCount + $lowValueCount;

            $retireReason = $this->retireReason($successCount, $giveBackCount, $lowValueCount, $totalOutcomes);

            $entry = [
                'pattern_id'    => $patternId,
                'type'          => $type,
                'abstract_rule' => $abstractRule,
                'success_count' => $successCount,
                'give_back_count' => $giveBackCount,
                'low_value_count' => $lowValueCount,
            ];

            if ($retireReason !== null) {
                $entry['retire_reason'] = $retireReason;
                $retired[]              = $entry;
            } else {
                $reusable[] = $entry;
            }
        }

        // AC3: rank reusable by success_count DESC
        usort($reusable, fn (array $a, array $b): int => $b['success_count'] <=> $a['success_count']);

        $injectionRules = $this->buildInjectionRules($reusable);

        $summary = sprintf(
            '%d reusable pattern(s), %d retired, %d rejected as provider-unsafe. Top type: %s.',
            count($reusable),
            count($retired),
            count($rejected),
            $this->topType($reusable),
        );

        return [
            'schema'                    => self::SCHEMA,
            'reusable_patterns'         => $reusable,
            'retired_patterns'          => $retired,
            'provider_safe_summary'     => $summary,
            'next_run_injection_rules'  => $injectionRules,
        ];
    }

    private function retireReason(int $success, int $giveBack, int $low, int $total): ?string
    {
        if ($giveBack >= self::RETIRE_GIVE_BACK_THRESHOLD) {
            return self::RETIRE_REASON_GIVE_BACK_COUNT;
        }

        if ($total >= self::MIN_OUTCOMES_FOR_RATE && ($giveBack / $total) > self::RETIRE_GIVE_BACK_RATE) {
            return self::RETIRE_REASON_GIVE_BACK_RATE;
        }

        if ($success === 0 && $low >= self::RETIRE_LOW_VALUE_THRESHOLD) {
            return self::RETIRE_REASON_ZERO_VALUE;
        }

        return null;
    }

    /** @return list<string> */
    private function buildInjectionRules(array $reusable): array
    {
        $rules = [];
        foreach (array_slice($reusable, 0, self::MAX_INJECTION_RULES) as $pattern) {
            $rules[] = "[{$pattern['type']}] {$pattern['abstract_rule']} (success_count={$pattern['success_count']})";
        }

        return $rules;
    }

    private function topType(array $reusable): string
    {
        if ($reusable === []) {
            return 'none';
        }

        $counts = array_count_values(array_column($reusable, 'type'));
        arsort($counts);

        return (string) array_key_first($counts);
    }
}
