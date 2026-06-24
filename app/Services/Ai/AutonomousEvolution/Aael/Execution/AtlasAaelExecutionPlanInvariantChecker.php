<?php

declare(strict_types=1);

namespace App\Services\Ai\AutonomousEvolution\Aael\Execution;

final class AtlasAaelExecutionPlanInvariantChecker
{
    public const SCHEMA = 'atlas.aael.execution.plan_invariant_check.v1';

    /**
     * @param  array<string,mixed>|list<mixed>  $plan
     * @param  list<string>  $declaredInvariants
     * @return array{
     *     schema_version:string,
     *     invariants_preserved:list<string>,
     *     invariants_violated:list<string>,
     *     steps_missing_acceptance:list<int>
     * }
     */
    public function check(array $plan, array $declaredInvariants): array
    {
        $steps = $this->extractSteps($plan);
        $declaredInvariants = array_values(array_filter($declaredInvariants, 'is_string'));

        if ($declaredInvariants === []) {
            return [
                'schema_version' => self::SCHEMA,
                'invariants_preserved' => [],
                'invariants_violated' => [],
                'steps_missing_acceptance' => [],
            ];
        }

        $violationsByInvariant = [];
        $stepsMissingAcceptance = [];

        foreach ($steps as $index => $step) {
            foreach ($declaredInvariants as $invariant) {
                foreach ($this->violationsForInvariant($invariant, $step, $index) as $violation) {
                    $violationsByInvariant[$invariant][] = $violation;
                }
            }

            if ($this->acceptanceMissing($step)) {
                $stepsMissingAcceptance[] = $index;
            }
        }

        $preserved = [];
        $violated = [];

        foreach ($declaredInvariants as $invariant) {
            $evidence = array_values(array_unique($violationsByInvariant[$invariant] ?? []));
            if ($evidence === []) {
                if ($this->supportsInvariant($invariant)) {
                    $preserved[] = $invariant;
                }

                continue;
            }

            foreach ($evidence as $item) {
                $violated[] = $item;
            }
        }

        return [
            'schema_version' => self::SCHEMA,
            'invariants_preserved' => $preserved,
            'invariants_violated' => $violated,
            'steps_missing_acceptance' => array_values(array_unique($stepsMissingAcceptance)),
        ];
    }

    /**
     * @param  array<string,mixed>|list<mixed>  $plan
     * @return list<array<string,mixed>>
     */
    private function extractSteps(array $plan): array
    {
        if (isset($plan['tasks']) && is_array($plan['tasks'])) {
            return array_values(array_filter($plan['tasks'], 'is_array'));
        }

        if (isset($plan['objective']) && is_string($plan['objective'])) {
            return [$plan];
        }

        if (! array_is_list($plan)) {
            return [];
        }

        return array_values(array_filter($plan, 'is_array'));
    }

    /**
     * @param  array<string,mixed>  $step
     * @return list<string>
     */
    private function violationsForInvariant(string $invariant, array $step, int $index): array
    {
        return match ($invariant) {
            'acceptance_present' => $this->acceptanceMissing($step)
                ? [sprintf('acceptance_present: step[%d] missing acceptance.commands', $index)]
                : [],
            'forbidden_files_untouched' => $this->forbiddenFilesTouched($step, $index),
            'no_provider_call' => $this->providerCallViolations($step, $index),
            'flag_off_byte_identical' => $this->flagOffViolations($step, $index),
            default => [],
        };
    }

    private function supportsInvariant(string $invariant): bool
    {
        return in_array($invariant, [
            'acceptance_present',
            'flag_off_byte_identical',
            'forbidden_files_untouched',
            'no_provider_call',
        ], true);
    }

    /**
     * @param  array<string,mixed>  $step
     */
    private function acceptanceMissing(array $step): bool
    {
        $commands = data_get($step, 'acceptance.commands');

        if (! is_array($commands) || $commands === []) {
            return true;
        }

        foreach ($commands as $command) {
            if (! is_string($command) || trim($command) === '') {
                return true;
            }
        }

        return false;
    }

    /**
     * @param  array<string,mixed>  $step
     * @return list<string>
     */
    private function forbiddenFilesTouched(array $step, int $index): array
    {
        $violations = [];
        $allowedFiles = array_values(array_filter((array) ($step['allowed_files'] ?? []), 'is_string'));

        foreach ($allowedFiles as $path) {
            if (str_starts_with($path, 'app/Services/Ai/AutonomousEvolution/Constitution/Frozen/')) {
                $violations[] = sprintf('forbidden_files_untouched: step[%d] %s', $index, $path);
            }
        }

        return $violations;
    }

    /**
     * @param  array<string,mixed>  $step
     * @return list<string>
     */
    private function providerCallViolations(array $step, int $index): array
    {
        $violations = [];
        $commands = array_values(array_filter((array) data_get($step, 'acceptance.commands', []), 'is_string'));

        foreach ($commands as $command) {
            if (preg_match('/\b(curl|http|openai|anthropic|gemini|provider)\b/i', $command) === 1) {
                $violations[] = sprintf('no_provider_call: step[%d] %s', $index, $command);
            }
        }

        return $violations;
    }

    /**
     * @param  array<string,mixed>  $step
     * @return list<string>
     */
    private function flagOffViolations(array $step, int $index): array
    {
        $violations = [];
        $objective = (string) ($step['objective'] ?? '');
        if (preg_match('/flag[_ -]?off.+(change|mutate|rewrite)/i', $objective) === 1) {
            $violations[] = sprintf('flag_off_byte_identical: step[%d] %s', $index, $objective);
        }

        return $violations;
    }
}
