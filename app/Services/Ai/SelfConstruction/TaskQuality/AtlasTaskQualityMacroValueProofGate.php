<?php

declare(strict_types=1);

namespace App\Services\Ai\SelfConstruction\TaskQuality;

/**
 * Pure gate that requires macro-task specs to state their real capability
 * improvement and rejects queue-depth, wrapper-count or test-count proxy wins.
 *
 * Proxy-only objectives (mentioning queue depth, wrapper count, or test count
 * without a real capability improvement) fail.
 *
 * Real capability improvements pass:
 *   - closed_loop_autonomy
 *   - collision_prevention
 *   - repair_conversion
 *
 * Pure: no I/O, no side effects.
 */
final class AtlasTaskQualityMacroValueProofGate
{
    public const SCHEMA = 'atlas.self_construction.task_quality_macro_value_proof_gate.v1';

    private const PROXY_MARKERS = [
        'queue depth', 'queue_depth', 'wrapper count', 'wrapper_count',
        'test count', 'test_count', 'task count', 'task_count',
        'more tasks', 'more tests', 'more wrappers',
    ];

    private const REAL_CAPABILITY_MARKERS = [
        'closed_loop_autonomy', 'closed-loop autonomy', 'closed loop autonomy',
        'collision_prevention', 'collision prevention',
        'repair_conversion', 'repair conversion',
        'capability_expansion', 'capability expansion',
        'autonomy_repair', 'autonomy repair',
    ];

    /**
     * @param  array<string, mixed>  $spec
     * @return array<string, mixed>
     */
    public function verify(array $spec): array
    {
        $objective = strtolower(trim((string) ($spec['objective'] ?? '')));
        $capabilityImprovement = strtolower(trim((string) ($spec['capability_improvement'] ?? '')));
        $combined = $objective.' '.$capabilityImprovement;

        $failures = [];

        // Check for proxy-only markers.
        $hasProxy = false;
        foreach (self::PROXY_MARKERS as $marker) {
            if (str_contains($combined, $marker)) {
                $hasProxy = true;
                break;
            }
        }

        // Check for real capability markers.
        $hasRealCapability = false;
        foreach (self::REAL_CAPABILITY_MARKERS as $marker) {
            if (str_contains($combined, $marker)) {
                $hasRealCapability = true;
                break;
            }
        }

        if ($hasProxy && ! $hasRealCapability) {
            $failures[] = 'proxy_only_objective_without_real_capability';
        }

        if (! $hasRealCapability && $capabilityImprovement === '') {
            $failures[] = 'missing_capability_improvement_statement';
        }

        $passed = $failures === [];

        return [
            'schema_version' => self::SCHEMA,
            'passed' => $passed,
            'failures' => $failures,
            'has_proxy_marker' => $hasProxy,
            'has_real_capability_marker' => $hasRealCapability,
            'objective' => $objective,
        ];
    }
}
