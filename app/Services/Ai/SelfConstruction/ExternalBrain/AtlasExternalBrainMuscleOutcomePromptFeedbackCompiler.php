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

    /** Average proof_strength below this marks a worker for suppression. */
    private const WEAK_PROOF_THRESHOLD = 0.30;

    /** give_back / total ratio at or above this marks a worker for suppression. */
    private const HIGH_GIVE_BACK_RATE = 0.50;

    /** give_back_reason values treated as test-only/proxy anti-patterns. */
    private const TEST_ONLY_OR_PROXY_REASONS = ['test_only_change', 'proxy_implementation', 'proxy_only_change'];

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
            'worker_routing_updates' => $this->compileWorkerRoutingUpdates($outcomes),
            'prompt_hardening_updates' => $this->compilePromptHardeningUpdates($outcomes),
        ];
    }

    /**
     * AC1: promote workers whose successes are consistently fast and strong-proof;
     * suppress workers with repeated retries, a high give_back rate, or weak proof
     * strength. Suppression takes priority over promotion when both signals fire.
     *
     * @param  list<array<string,mixed>>  $outcomes
     */
    private function compileWorkerRoutingUpdates(array $outcomes): array
    {
        $byWorker = [];
        foreach ($outcomes as $o) {
            $worker = (string) ($o['worker_id'] ?? $o['model'] ?? '');
            if ($worker === '') {
                continue;
            }
            $byWorker[$worker][] = $o;
        }

        $updates = [];
        foreach ($byWorker as $worker => $list) {
            $total = count($list);
            $retries = array_values(array_filter(
                $list,
                static fn (array $o): bool => (string) ($o['outcome'] ?? '') === 'retry',
            ));
            $giveBacks = array_values(array_filter(
                $list,
                static fn (array $o): bool => (string) ($o['outcome'] ?? '') === 'give_back',
            ));
            $giveBackRate = $total > 0 ? count($giveBacks) / $total : 0.0;

            $proofSum = 0.0;
            foreach ($list as $o) {
                $proofSum += (float) ($o['proof_strength'] ?? 0.0);
            }
            $avgProof = $total > 0 ? $proofSum / $total : 0.0;

            $suppressReasons = [];
            if (count($retries) >= self::MIN_REPEATS) {
                $suppressReasons[] = 'repeated_retries';
            }
            if ($giveBackRate >= self::HIGH_GIVE_BACK_RATE) {
                $suppressReasons[] = 'high_give_back_rate';
            }
            if ($avgProof < self::WEAK_PROOF_THRESHOLD) {
                $suppressReasons[] = 'weak_proof_strength';
            }

            if ($suppressReasons !== []) {
                $updates[] = [
                    'worker' => $worker,
                    'action' => 'suppress',
                    'reasons' => $suppressReasons,
                    'retry_count' => count($retries),
                    'give_back_rate' => round($giveBackRate, 4),
                    'average_proof_strength' => round($avgProof, 4),
                ];
                continue;
            }

            $fastStrongSuccesses = array_values(array_filter(
                $list,
                static fn (array $o): bool => (string) ($o['outcome'] ?? '') === 'success'
                    && (int) ($o['elapsed_seconds'] ?? PHP_INT_MAX) <= self::FAST_ELAPSED_SECONDS
                    && (float) ($o['proof_strength'] ?? 0.0) >= self::STRONG_PROOF_THRESHOLD,
            ));

            if (count($fastStrongSuccesses) >= self::MIN_REPEATS) {
                $updates[] = [
                    'worker' => $worker,
                    'action' => 'promote',
                    'reason' => 'fast_strong_proof_success',
                    'occurrences' => count($fastStrongSuccesses),
                ];
            }
        }

        return $updates;
    }

    /**
     * AC2: deterministic prompt-hardening signals per family — high template
     * similarity, repeated allowed_files_too_narrow give-backs, and repeated
     * test-only/proxy give-backs — each with an occurrence count and a
     * recommended_patch_id.
     *
     * @param  list<array<string,mixed>>  $outcomes
     */
    private function compilePromptHardeningUpdates(array $outcomes): array
    {
        $byFamily = [];
        foreach ($outcomes as $o) {
            $family = (string) ($o['family'] ?? '');
            if ($family === '') {
                continue;
            }
            $byFamily[$family][] = $o;
        }

        $updates = [];
        foreach ($byFamily as $family => $list) {
            $narrowGiveBacks = array_values(array_filter(
                $list,
                static fn (array $o): bool => (string) ($o['outcome'] ?? '') === 'give_back'
                    && (string) ($o['give_back_reason'] ?? '') === 'allowed_files_too_narrow',
            ));
            if (count($narrowGiveBacks) >= self::MIN_REPEATS) {
                $updates[] = [
                    'family' => $family,
                    'signal' => 'allowed_files_too_narrow',
                    'occurrences' => count($narrowGiveBacks),
                    'recommended_patch_id' => 'require_implementation_plus_test_scope_and_closure_verification',
                ];
            }

            $proxyLikeGiveBacks = array_values(array_filter(
                $list,
                static fn (array $o): bool => (string) ($o['outcome'] ?? '') === 'give_back'
                    && in_array((string) ($o['give_back_reason'] ?? ''), self::TEST_ONLY_OR_PROXY_REASONS, true),
            ));
            if (count($proxyLikeGiveBacks) >= self::MIN_REPEATS) {
                $updates[] = [
                    'family' => $family,
                    'signal' => 'test_only_or_proxy_pattern',
                    'occurrences' => count($proxyLikeGiveBacks),
                    'recommended_patch_id' => 'require_real_behavior_change_not_test_only_or_proxy',
                ];
            }

            $highSimilarity = array_values(array_filter(
                $list,
                static fn (array $o): bool => (float) ($o['template_similarity'] ?? 0.0) >= self::TEMPLATE_FARM_THRESHOLD,
            ));
            if (count($highSimilarity) >= self::MIN_REPEATS) {
                $updates[] = [
                    'family' => $family,
                    'signal' => 'high_template_similarity',
                    'occurrences' => count($highSimilarity),
                    'recommended_patch_id' => 'diversify_spec_pattern_away_from_template_farm',
                ];
            }
        }

        return $updates;
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

    /**
     * Negative pattern patches: repeated weak_green, proxy_success and high template_similarity
     * successes produce prompt hardening patches that prevent future muscles from reporting shallow green.
     *
     * @param  list<array{outcome?:string, proof_strength?:float, template_similarity?:float, behavior_delta?:float}>  $outcomes
     * @return list<array{pattern:string, recommended_patch_id:string, occurrences:int}>
     */
    public function negativePatternPatches(array $outcomes): array
    {
        $weakGreenCount = 0;
        $proxySuccessCount = 0;
        $highTemplateCount = 0;

        foreach ($outcomes as $o) {
            $outcome = (string) ($o['outcome'] ?? '');
            $proofStrength = (float) ($o['proof_strength'] ?? 0.0);
            $templateSimilarity = (float) ($o['template_similarity'] ?? 0.0);
            $behaviorDelta = (float) ($o['behavior_delta'] ?? 0.0);

            // weak_green: success with low proof strength
            if ($outcome === 'success' && $proofStrength < 0.5) {
                $weakGreenCount++;
            }

            // proxy_success: success without behavior delta
            if ($outcome === 'success' && $behaviorDelta < 0.1) {
                $proxySuccessCount++;
            }

            // high template similarity without strong proof and behavior delta
            if ($templateSimilarity >= 0.8 && $proofStrength < 0.8 && $behaviorDelta < 0.5) {
                $highTemplateCount++;
            }
        }

        $patches = [];

        if ($weakGreenCount >= 2) {
            $patches[] = [
                'pattern' => 'repeated_weak_green',
                'recommended_patch_id' => 'require_concrete_command_path_and_behavior_delta',
                'occurrences' => $weakGreenCount,
            ];
        }

        if ($proxySuccessCount >= 2) {
            $patches[] = [
                'pattern' => 'repeated_proxy_success',
                'recommended_patch_id' => 'reject_proxy_or_wrapper_success_without_capability_delta',
                'occurrences' => $proxySuccessCount,
            ];
        }

        if ($highTemplateCount >= 2) {
            $patches[] = [
                'pattern' => 'high_template_similarity_without_proof',
                'recommended_patch_id' => 'require_proof_strength_and_behavior_delta_before_promotion',
                'occurrences' => $highTemplateCount,
            ];
        }

        return $patches;
    }
}
