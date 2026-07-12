<?php

declare(strict_types=1);

namespace Tests\Unit\Ai\EngineeringKernel;

use App\Services\Ai\EngineeringKernel\AcceptanceBundle;

/**
 * Test-only builder. Two canonical bundles: a fully-honest one that MUST promote, and the
 * fake-green kernel's exact evidence shape that MUST be refused. Not a test class.
 */
final class AcceptanceBundleFactory
{
    /**
     * A bundle that passes every invariant — the promote happy-path.
     *
     * @param  array<string,mixed>  $overrides  each top-level key REPLACES its honest default
     *                                          (pass a full nested object when overriding one)
     */
    public static function honest(array $overrides = []): AcceptanceBundle
    {
        $base = [
            'criteria_hash' => 'frozen-hash-001',
            'frozen_hash' => 'frozen-hash-001',
            'changed_files' => ['app/Services/Ai/EngineeringKernel/SovereignHonestyFloor.php'],
            'changed_public_symbols' => [
                ['symbol' => 'SovereignHonestyFloor::certify', 'has_criterion' => true, 'has_test' => true],
            ],
            'execution' => [
                'commands' => ['php artisan test tests/Unit/Ai/EngineeringKernel/SovereignFloorTest.php'],
                'claimed_status' => 'passed',
                'tests_run' => 6,
                'assertions_executed' => 21,
                'selected_tests' => ['tests/Unit/Ai/EngineeringKernel/SovereignFloorTest.php'],
                'artifacts' => [],
            ],
            'mutation_report' => ['kill_ratio' => 0.82, 'mutants_generated' => 17, 'decision_surface_added' => true],
            'security_scan' => ['ran' => true, 'secret_free' => true, 'critical_sast' => 0, 'critical_cve' => 0],
            'judges' => [
                ['name' => 'judge_a', 'provider_family' => 'anthropic', 'approved' => true],
                ['name' => 'judge_b', 'provider_family' => 'openai', 'approved' => true],
            ],
            'context_sufficiency' => 88,
        ];

        return AcceptanceBundle::fromArray(array_replace($base, $overrides));
    }

    /**
     * The exact evidence the fake-green kernel (AtlasRealEngineeringExecutionKernelService) emits:
     * a php -l on one file, presented as if a suite passed. MUST be refused.
     */
    public static function fakeGreenStub(): AcceptanceBundle
    {
        return AcceptanceBundle::fromArray([
            'criteria_hash' => '',
            'frozen_hash' => '',
            'changed_files' => ['runtime/atlas_real_execution_smoke.php'],
            'changed_public_symbols' => [],
            'execution' => [
                'commands' => ['php -l /abs/storage/app/atlas-real-execution/x/runtime/atlas_real_execution_smoke.php'],
                'claimed_status' => 'passed',
                'tests_run' => 0,
                'assertions_executed' => 0,
                // the fake-green kernel's selected_tests LIE: it claims artisan suites it never ran
                'selected_tests' => [
                    'php -l /abs/.../atlas_real_execution_smoke.php',
                    'php artisan test tests/Unit/Ai/RealExecution',
                    'php artisan test tests/Feature/Ai/AtlasRealEngineeringExecutionKernelTest.php',
                ],
                'artifacts' => ['runtime/atlas_real_execution_smoke.php'],
            ],
            'mutation_report' => [],
            'security_scan' => [],
            'judges' => [],
            'context_sufficiency' => 84,
        ]);
    }
}
