<?php

declare(strict_types=1);

namespace App\Services\Ai\SelfConstruction\ExternalBrain;

/**
 * Pure discoverer that finds under-covered implementation surfaces from existing
 * SelfConstruction organs and proposes non-colliding service-plus-test task targets.
 *
 * - Saturated surfaces (already have both service and test files) are skipped.
 * - Missing service-plus-test pairs are proposed as new task targets.
 * - Forbidden targets (in the forbidden list) are excluded.
 *
 * Pure: no I/O, no side effects.
 */
final class AtlasExternalBrainImplementationSurfaceDiscoverer
{
    public const SCHEMA = 'atlas.external_brain.implementation_surface_discoverer.v1';

    /**
     * @param  array<string, mixed>  $input
     * @return array<string, mixed>
     */
    public function discover(array $input): array
    {
        $surfaces = (array) ($input['surfaces'] ?? []);
        $forbiddenTargets = array_flip(array_map('strval', (array) ($input['forbidden_targets'] ?? [])));
        $queuedTargets = array_flip(array_map('strval', (array) ($input['queued_targets'] ?? [])));

        $proposed = [];
        $skipped = [];
        $excluded = [];

        foreach ($surfaces as $surface) {
            if (! is_array($surface)) {
                continue;
            }

            $surfaceName = (string) ($surface['name'] ?? '');
            $hasService = (bool) ($surface['has_service_file'] ?? false);
            $hasTest = (bool) ($surface['has_test_file'] ?? false);
            $servicePath = (string) ($surface['service_path'] ?? '');
            $testPath = (string) ($surface['test_path'] ?? '');

            if ($surfaceName === '') {
                continue;
            }

            // Skip forbidden targets.
            if (isset($forbiddenTargets[$surfaceName])) {
                $excluded[] = [
                    'surface' => $surfaceName,
                    'reason' => 'forbidden_target',
                ];
                continue;
            }

            // Skip already-queued targets.
            if (isset($queuedTargets[$surfaceName])) {
                $excluded[] = [
                    'surface' => $surfaceName,
                    'reason' => 'already_queued',
                ];
                continue;
            }

            // Saturated: has both service and test → skip.
            if ($hasService && $hasTest) {
                $skipped[] = [
                    'surface' => $surfaceName,
                    'reason' => 'saturated',
                ];
                continue;
            }

            // Missing service or test → propose.
            $proposed[] = [
                'surface' => $surfaceName,
                'service_path' => $servicePath,
                'test_path' => $testPath,
                'missing' => array_filter([
                    $hasService ? null : 'service_file',
                    $hasTest ? null : 'test_file',
                ]),
            ];
        }

        // Sort deterministically by surface name.
        usort($proposed, static fn (array $a, array $b): int => strcmp($a['surface'], $b['surface']));
        usort($skipped, static fn (array $a, array $b): int => strcmp($a['surface'], $b['surface']));
        usort($excluded, static fn (array $a, array $b): int => strcmp($a['surface'], $b['surface']));

        return [
            'schema_version' => self::SCHEMA,
            'proposed' => $proposed,
            'skipped' => $skipped,
            'excluded' => $excluded,
            'total_proposed' => count($proposed),
            'total_skipped' => count($skipped),
            'total_excluded' => count($excluded),
        ];
    }
}
