<?php

declare(strict_types=1);

namespace App\Services\Ai\SelfConstruction\Autonomy;

/**
 * Identifies manual steady-state dependencies in self-construction surfaces
 * and converts them into burn-down tasks with local runtime alternatives.
 *
 * Pure: no I/O, no side effects.
 */
final class AtlasSelfConstructionManualDependencyBurnDownPlanner
{
    public const SCHEMA = 'atlas.self_construction.manual_dependency_burn_down_planner.v1';

    public const KIND_MANUAL_ONLY = 'manual_only';
    public const KIND_BOOTSTRAP_ONLY = 'bootstrap_only';
    public const KIND_RUNTIME_ALTERNATIVE = 'runtime_alternative';

    /**
     * @param  array<string, mixed>  $input
     * @return array<string, mixed>
     */
    public function plan(array $input): array
    {
        $dependencies = is_array($input['dependencies'] ?? null) ? $input['dependencies'] : [];
        $originatorId = (string) ($input['originator_id'] ?? '');
        $roundId = (string) ($input['round_id'] ?? '');

        $burnDownSpecs = [];
        $flagged = [];
        $allowed = [];

        foreach ($dependencies as $dep) {
            if (! is_array($dep)) {
                continue;
            }

            $name = (string) ($dep['name'] ?? '');
            if ($name === '') {
                continue;
            }

            $isManual = (bool) ($dep['requires_manual_step'] ?? false);
            $isBootstrap = (bool) ($dep['bootstrap_only'] ?? false);
            $hasRuntimeAlternative = (bool) ($dep['has_runtime_alternative'] ?? false);
            $surface = (string) ($dep['surface'] ?? 'unknown');

            if ($isBootstrap) {
                $allowed[] = [
                    'name' => $name,
                    'surface' => $surface,
                    'reason' => 'bootstrap-only dependency is permitted',
                ];

                continue;
            }

            if ($isManual && ! $hasRuntimeAlternative) {
                $spec = $this->buildBurnDownSpec($dep);
                $burnDownSpecs[] = $spec;
                $flagged[] = [
                    'name' => $name,
                    'surface' => $surface,
                    'kind' => self::KIND_MANUAL_ONLY,
                    'reason' => 'manual-only steady-state dependency must be burned down',
                ];
            } elseif ($isManual && $hasRuntimeAlternative) {
                $allowed[] = [
                    'name' => $name,
                    'surface' => $surface,
                    'reason' => 'manual dependency has a local runtime alternative',
                ];
            }
        }

        usort($burnDownSpecs, static fn (array $a, array $b): int => strcmp($a['dependency_name'], $b['dependency_name']));
        usort($flagged, static fn (array $a, array $b): int => strcmp($a['name'], $b['name']));
        usort($allowed, static fn (array $a, array $b): int => strcmp($a['name'], $b['name']));

        return [
            'schema_version' => self::SCHEMA,
            'originator_id' => $originatorId,
            'round_id' => $roundId,
            'burn_down_specs' => $burnDownSpecs,
            'flagged_dependencies' => $flagged,
            'allowed_dependencies' => $allowed,
            'burn_down_count' => count($burnDownSpecs),
            'has_manual_only_dependencies' => $burnDownSpecs !== [],
        ];
    }

    /**
     * @param  array<string, mixed>  $dep
     * @return array<string, mixed>
     */
    private function buildBurnDownSpec(array $dep): array
    {
        $name = (string) ($dep['name'] ?? '');
        $surface = (string) ($dep['surface'] ?? 'unknown');
        $currentStep = (string) ($dep['current_manual_step'] ?? 'manual intervention');
        $proposedAlternative = (string) ($dep['proposed_runtime_alternative'] ?? 'implement local runtime replacement');

        $serviceClass = 'Atlas'.str_replace(' ', '', ucwords(str_replace(['_', '-'], ' ', $name))).'Automation';
        $testClass = $serviceClass.'Test';

        return [
            'dependency_name' => $name,
            'surface' => $surface,
            'objective' => "Burn down manual dependency '{$name}' on surface '{$surface}' by replacing '{$currentStep}' with '{$proposedAlternative}'.",
            'allowed_files' => [
                "app/Services/Ai/SelfConstruction/Automation/{$serviceClass}.php",
                "tests/Unit/Services/Ai/SelfConstruction/Automation/{$testClass}.php",
            ],
            'acceptance_criteria' => [
                "Implement {$serviceClass} to perform '{$currentStep}' without manual intervention.",
                "Write {$testClass} proving the automation matches the manual step output.",
            ],
            'required_evidence' => ['tests_or_gates_result', 'implementation_notes'],
            'current_manual_step' => $currentStep,
            'proposed_runtime_alternative' => $proposedAlternative,
        ];
    }
}
