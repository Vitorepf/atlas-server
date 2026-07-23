<?php

declare(strict_types=1);

namespace App\Services\Ai\SelfConstruction\ExternalBrain;

/**
 * Pure proof gate: classifies each config key as keep, merge, delete or proof_first from
 * REAL usage, default, override and runtime-critical facts — never a blanket config cleanup.
 *
 * CLASSIFICATION (first matching rule wins, per key):
 *   proof_first — usage_known === false (nobody has actually traced whether this key is read
 *                 at runtime) OR runtime_critical === true. FAIL CLOSED: unknown or
 *                 runtime-critical config is held with the exact proof required, never
 *                 reduced on a guess.
 *   delete      — usage_count === 0 AND last_used_days_ago >= STALE_DAYS_THRESHOLD AND
 *                 overridden === false: genuinely stale, nothing reads it, no environment
 *                 overrides it.
 *   merge       — duplicate_of is set (a canonical key already covers this one) and it is
 *                 still in active use: fold into the canonical key instead of deleting.
 *   keep        — everything else: actively used with no known safe reduction.
 *
 * Pure. No I/O, no provider calls, deterministic.
 */
final class AtlasExternalBrainConfigSurfaceReducer
{
    public const SCHEMA = 'atlas.external_brain.config_surface_reducer.v1';

    public const ACTION_KEEP = 'keep';

    public const ACTION_MERGE = 'merge';

    public const ACTION_DELETE = 'delete';

    public const ACTION_PROOF_FIRST = 'proof_first';

    private const STALE_DAYS_THRESHOLD = 90;

    /**
     * @param  array{config_keys?: list<array<string,mixed>>}  $input
     * @return array<string,mixed>
     */
    public function reduce(array $input): array
    {
        $configKeys = is_array($input['config_keys'] ?? null) ? $input['config_keys'] : [];

        $decisions = [];
        $counts = [
            self::ACTION_KEEP => 0,
            self::ACTION_MERGE => 0,
            self::ACTION_DELETE => 0,
            self::ACTION_PROOF_FIRST => 0,
        ];

        foreach ($configKeys as $configKey) {
            if (! is_array($configKey)) {
                continue;
            }
            $entry = $this->classifyOne($configKey);
            $decisions[] = $entry;
            $counts[$entry['action']]++;
        }

        return [
            'schema' => self::SCHEMA,
            'decisions' => $decisions,
            'summary' => [
                'total' => count($decisions),
                'keep_count' => $counts[self::ACTION_KEEP],
                'merge_count' => $counts[self::ACTION_MERGE],
                'delete_count' => $counts[self::ACTION_DELETE],
                'proof_first_count' => $counts[self::ACTION_PROOF_FIRST],
            ],
        ];
    }

    /**
     * @param  array<string,mixed>  $configKey
     * @return array<string,mixed>
     */
    private function classifyOne(array $configKey): array
    {
        $key = (string) ($configKey['key'] ?? '');
        $usageKnown = (bool) ($configKey['usage_known'] ?? true);
        $usageCount = max(0, (int) ($configKey['usage_count'] ?? 0));
        $lastUsedDaysAgo = max(0, (int) ($configKey['last_used_days_ago'] ?? 0));
        $hasDefault = (bool) ($configKey['has_default'] ?? false);
        $overridden = (bool) ($configKey['overridden'] ?? false);
        $runtimeCritical = (bool) ($configKey['runtime_critical'] ?? false);
        $duplicateOf = trim((string) ($configKey['duplicate_of'] ?? ''));

        [$action, $reason, $requiredProof] = match (true) {
            ! $usageKnown => [self::ACTION_PROOF_FIRST, 'usage_unknown', 'runtime_usage_trace_across_all_environments'],
            $runtimeCritical => [self::ACTION_PROOF_FIRST, 'runtime_critical', 'runtime_criticality_review_and_rollback_plan'],
            $usageCount === 0 && $lastUsedDaysAgo >= self::STALE_DAYS_THRESHOLD && ! $overridden => [self::ACTION_DELETE, 'stale_unused', null],
            $duplicateOf !== '' && $usageCount > 0 => [self::ACTION_MERGE, 'duplicate_of:'.$duplicateOf, null],
            default => [self::ACTION_KEEP, 'actively_used', null],
        };

        return [
            'key' => $key,
            'action' => $action,
            'reason' => $reason,
            'required_proof' => $requiredProof,
            'usage_known' => $usageKnown,
            'usage_count' => $usageCount,
            'last_used_days_ago' => $lastUsedDaysAgo,
            'has_default' => $hasDefault,
            'overridden' => $overridden,
            'runtime_critical' => $runtimeCritical,
            'duplicate_of' => $duplicateOf !== '' ? $duplicateOf : null,
        ];
    }
}
