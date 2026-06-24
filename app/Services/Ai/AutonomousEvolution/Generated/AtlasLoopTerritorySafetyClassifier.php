<?php

declare(strict_types=1);

namespace App\Services\Ai\AutonomousEvolution\Generated;

final class AtlasLoopTerritorySafetyClassifier
{
    public const SCHEMA_VERSION = 'atlas.loop.territory_safety_classifier.v1';

    public const MODE = 'read_only_generated_docgap_capability';

    /** @return array<string, mixed> */
    public function describe(): array
    {
        $signals = [
            'behavioral_revert_flips_battery',
            'frozen_marker_declared',
            'safety_keyword_path_match',
        ];

        return [
            'schema_version' => self::SCHEMA_VERSION,
            'mode' => self::MODE,
            'status' => 'available',
            'promotion_behavior' => 'add_only_forbidden_expansion',
            'determinism' => 'frozen_classifier_loop_uneditable',
            'signals' => $signals,
            'signal_count' => count($signals),
            'execution_allowed' => false,
            'dispatch_allowed' => false,
            'provider_call_allowed' => false,
            'workspace_mutation_allowed' => false,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function classify(
        string $path,
        bool $revertFlipsBattery,
        bool $hasFrozenMarker = false,
    ): array {
        $matchedSignals = [];

        if ($revertFlipsBattery) {
            $matchedSignals[] = 'behavioral_revert_flips_battery';
        }

        if ($hasFrozenMarker) {
            $matchedSignals[] = 'frozen_marker_declared';
        }

        if ($this->looksLikeSafetyPath($path)) {
            $matchedSignals[] = 'safety_keyword_path_match';
        }

        $isSafetyCritical = in_array('behavioral_revert_flips_battery', $matchedSignals, true)
            || in_array('frozen_marker_declared', $matchedSignals, true);

        return [
            'schema_version' => self::SCHEMA_VERSION,
            'path' => $path,
            'classification' => $isSafetyCritical ? 'safety_critical' : 'non_safety_critical',
            'forbidden_action' => $isSafetyCritical ? 'add_to_forbidden' : 'no_change',
            'matched_signals' => $matchedSignals,
            'matched_signal_count' => count($matchedSignals),
            'revert_flips_battery' => $revertFlipsBattery,
            'execution_allowed' => false,
            'dispatch_allowed' => false,
            'provider_call_allowed' => false,
            'workspace_mutation_allowed' => false,
        ];
    }

    /**
     * @param  array<int, array{classification:string}>  $classifiedFiles
     * @return array<string, mixed>
     */
    public function validatePromotionInvariant(array $classifiedFiles, int $robustnessCaseCount): array
    {
        $hasSafetyCritical = count(array_filter(
            $classifiedFiles,
            static fn (array $file): bool => ($file['classification'] ?? null) === 'safety_critical',
        )) > 0;

        $passes = $hasSafetyCritical && $robustnessCaseCount >= 1;

        return [
            'schema_version' => self::SCHEMA_VERSION,
            'passes' => $passes,
            'requires_forbidden_freeze' => true,
            'requires_robustness_case' => $robustnessCaseCount >= 1,
            'safety_critical_file_present' => $hasSafetyCritical,
        ];
    }

    private function looksLikeSafetyPath(string $path): bool
    {
        $normalized = strtolower($path);

        return str_contains($normalized, 'safety')
            || str_contains($normalized, 'constitution')
            || str_contains($normalized, 'frozen');
    }
}
