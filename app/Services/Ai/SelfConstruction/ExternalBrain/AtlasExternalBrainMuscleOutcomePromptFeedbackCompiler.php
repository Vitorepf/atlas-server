<?php

declare(strict_types=1);

namespace App\Services\Ai\SelfConstruction\ExternalBrain;

/**
 * Turns muscle outcome data (success, give_back, retry, model/client, task
 * shape, elapsed time, proof strength, spec pattern) into concrete prompt
 * and spec-writing adjustments for the next brain run, instead of leaving
 * the same mistakes to repeat run after run.
 *
 * Input shape: {outcomes: list<{
 *   family?:string, outcome:string(success|give_back|retry),
 *   give_back_reason?:string, worker_id?:string, model?:string,
 *   task_shape?:string(test_heavy_low_risk|cross_module),
 *   elapsed_seconds?:int, proof_strength?:float, spec_pattern?:string,
 *   template_similarity?:float,
 * }>}
 *
 * OUTPUT: {schema, prompt_patches, routing_hints, promoted_spec_patterns}
 *
 * Pure / deterministic. No I/O.
 */
final class AtlasExternalBrainMuscleOutcomePromptFeedbackCompiler
{
    public const SCHEMA = 'atlas.external_brain.muscle_outcome_prompt_feedback_compiler.v1';

    private const MIN_REPEATS = 2;

    private const FAST_ELAPSED_SECONDS = 300;

    private const STRONG_PROOF_THRESHOLD = 0.70;

    private const TEMPLATE_FARM_THRESHOLD = 0.70;

    private const HIGH_SUCCESS_THRESHOLD = 0.70;

    private const LOW_SUCCESS_THRESHOLD = 0.50;

    /**
     * @param  array{outcomes?: list<array<string,mixed>>}  $input
     * @return array<string,mixed>
     */
    public function compile(array $input): array
    {
        $outcomes = array_values(array_filter((array) ($input['outcomes'] ?? []), 'is_array'));

        return [
            'schema' => self::SCHEMA,
            'prompt_patches' => $this->compilePromptPatches($outcomes),
            'routing_hints' => $this->compileRoutingHints($outcomes),
            'promoted_spec_patterns' => $this->compilePromotedPatterns($outcomes),
        ];
    }

    /** @param  list<array<string,mixed>>  $outcomes */
    private function compilePromptPatches(array $outcomes): array
    {
        $byFamily = [];
        foreach ($outcomes as $o) {
            $family = (string) ($o['family'] ?? '');
            if ($family === '') {
                continue;
            }
            $byFamily[$family][] = $o;
        }

        $patches = [];
        foreach ($byFamily as $family => $list) {
            $narrowGiveBacks = array_values(array_filter(
                $list,
                static fn (array $o): bool => (string) ($o['outcome'] ?? '') === 'give_back'
                    && (string) ($o['give_back_reason'] ?? '') === 'allowed_files_too_narrow',
            ));
            if (count($narrowGiveBacks) < self::MIN_REPEATS) {
                continue;
            }
            $patches[] = [
                'family' => $family,
                'patch' => 'require_implementation_plus_test_scope_and_closure_verification',
                'reason' => 'repeated_give_back:allowed_files_too_narrow',
                'occurrences' => count($narrowGiveBacks),
            ];
        }

        return $patches;
    }

    /** @param  list<array<string,mixed>>  $outcomes */
    private function compileRoutingHints(array $outcomes): array
    {
        $byWorker = [];
        foreach ($outcomes as $o) {
            $worker = (string) ($o['worker_id'] ?? $o['model'] ?? '');
            if ($worker === '') {
                continue;
            }
            $byWorker[$worker][] = $o;
        }

        $hints = [];
        foreach ($byWorker as $worker => $list) {
            $testHeavy = array_values(array_filter(
                $list,
                static fn (array $o): bool => (string) ($o['task_shape'] ?? '') === 'test_heavy_low_risk',
            ));
            $crossModule = array_values(array_filter(
                $list,
                static fn (array $o): bool => (string) ($o['task_shape'] ?? '') === 'cross_module',
            ));

            if ($testHeavy === [] || $crossModule === []) {
                continue;
            }

            $testHeavyRate = $this->successRate($testHeavy);
            $crossModuleRate = $this->successRate($crossModule);

            if ($testHeavyRate >= self::HIGH_SUCCESS_THRESHOLD && $crossModuleRate < self::LOW_SUCCESS_THRESHOLD) {
                $hints[] = [
                    'worker' => $worker,
                    'assign' => 'test_heavy_low_risk',
                    'avoid' => 'cross_module',
                    'reason' => 'skill_fit_test_heavy_low_risk',
                    'test_heavy_success_rate' => $testHeavyRate,
                    'cross_module_success_rate' => $crossModuleRate,
                ];
            }
        }

        return $hints;
    }

    /** @param  list<array<string,mixed>>  $outcomes */
    private function compilePromotedPatterns(array $outcomes): array
    {
        $byPattern = [];
        foreach ($outcomes as $o) {
            $pattern = (string) ($o['spec_pattern'] ?? '');
            if ($pattern === '') {
                continue;
            }
            $byPattern[$pattern][] = $o;
        }

        $promoted = [];
        foreach ($byPattern as $pattern => $list) {
            $successes = array_values(array_filter(
                $list,
                static fn (array $o): bool => (string) ($o['outcome'] ?? '') === 'success',
            ));
            if (count($successes) < self::MIN_REPEATS) {
                continue;
            }

            $allFast = true;
            $proofSum = 0.0;
            $templateFarmWarning = false;
            foreach ($successes as $o) {
                if ((int) ($o['elapsed_seconds'] ?? PHP_INT_MAX) > self::FAST_ELAPSED_SECONDS) {
                    $allFast = false;
                }
                $proofSum += (float) ($o['proof_strength'] ?? 0.0);
                if ((float) ($o['template_similarity'] ?? 0.0) >= self::TEMPLATE_FARM_THRESHOLD) {
                    $templateFarmWarning = true;
                }
            }
            $avgProof = $proofSum / count($successes);

            if (! $allFast || $avgProof < self::STRONG_PROOF_THRESHOLD) {
                continue;
            }

            $entry = [
                'spec_pattern' => $pattern,
                'promoted' => true,
                'occurrences' => count($successes),
                'average_proof_strength' => round($avgProof, 4),
            ];
            if ($templateFarmWarning) {
                $entry['anti_template_farm_warning'] = true;
            }
            $promoted[] = $entry;
        }

        return $promoted;
    }

    /** @param  list<array<string,mixed>>  $list */
    private function successRate(array $list): float
    {
        if ($list === []) {
            return 0.0;
        }
        $successCount = count(array_filter(
            $list,
            static fn (array $o): bool => (string) ($o['outcome'] ?? '') === 'success',
        ));

        return round($successCount / count($list), 4);
    }
}
