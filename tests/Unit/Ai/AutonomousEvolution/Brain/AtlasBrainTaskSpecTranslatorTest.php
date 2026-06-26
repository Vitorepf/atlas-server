<?php

declare(strict_types=1);

namespace Tests\Unit\Ai\AutonomousEvolution\Brain;

use App\Services\Ai\AutonomousEvolution\Brain\AtlasBrainTaskSpecTranslator;
use Tests\TestCase;

/**
 * Proves the spec translator maps an origination output to a valid seed-gov-lanes
 * packet spec, with allowed_files coming ONLY from target_path + obligations.
 */
final class AtlasBrainTaskSpecTranslatorTest extends TestCase
{
    public function test_translate_maps_origination_to_valid_spec(): void
    {
        $translator = new AtlasBrainTaskSpecTranslator;

        $origination = [
            'objective' => 'Wire the orphan AtlasFooService into the bar pipeline',
            'target_path' => 'app/Services/Ai/AutonomousEvolution/AtlasFooService.php',
            'obligations' => [
                [
                    'kind' => 'consumer_protection',
                    'target_symbol' => 'App\\Services\\Ai\\AutonomousEvolution\\AtlasBarPipeline::consume',
                    'assertion_ref' => 'php artisan test --filter=AtlasBarPipelineTest passes green',
                    'target_file' => 'app/Services/Ai/AutonomousEvolution/AtlasBarPipeline.php',
                ],
                [
                    'kind' => 'characterization_test',
                    'target_symbol' => 'AtlasFooService::execute',
                    'assertion_ref' => 'php artisan test --filter=AtlasFooServiceTest passes green',
                    'file_path' => 'tests/Unit/Ai/AutonomousEvolution/AtlasFooServiceTest.php',
                ],
            ],
            'snapshot_id' => 'snap_abc123',
        ];

        $spec = $translator->translate($origination);

        // task_packet_id is deterministic sha1(objective|snapshotId).
        self::assertSame('brain:'.sha1('Wire the orphan AtlasFooService into the bar pipeline|snap_abc123'), $spec['task_packet_id']);
        self::assertSame('Wire the orphan AtlasFooService into the bar pipeline', $spec['objective']);

        // allowed_files = target_path + obligation files ONLY — no invented paths.
        $expectedAllowed = [
            'app/Services/Ai/AutonomousEvolution/AtlasBarPipeline.php',
            'app/Services/Ai/AutonomousEvolution/AtlasFooService.php',
            'tests/Unit/Ai/AutonomousEvolution/AtlasFooServiceTest.php',
        ];
        sort($expectedAllowed);
        self::assertSame($expectedAllowed, $spec['allowed_files']);
        self::assertSame($spec['allowed_files'], $spec['scope_in']);

        // No allowed_file outside target_path + obligations.
        foreach ($spec['allowed_files'] as $file) {
            self::assertContains($file, $expectedAllowed, "Invented path detected: $file");
        }

        // Acceptance criteria derived from obligations.
        self::assertNotEmpty($spec['acceptance_criteria']);
        self::assertContains('php artisan test --filter=AtlasBarPipelineTest passes green', $spec['acceptance_criteria']);
        self::assertContains('php artisan test --filter=AtlasFooServiceTest passes green', $spec['acceptance_criteria']);

        // Evidence requirements derived.
        self::assertNotEmpty($spec['evidence_requirements']);

        // Standard fields.
        self::assertSame([], $spec['depends_on']);
        self::assertSame(1, $spec['wave']);
        self::assertSame('medium', $spec['risk_level']);
    }

    public function test_translate_with_no_obligations_produces_minimal_spec(): void
    {
        $translator = new AtlasBrainTaskSpecTranslator;

        $spec = $translator->translate([
            'objective' => 'Simple objective',
            'target_path' => 'app/Services/Foo.php',
            'obligations' => [],
            'snapshot_id' => 'snap_1',
        ]);

        self::assertSame(['app/Services/Foo.php'], $spec['allowed_files']);
        self::assertContains('php -l app/Services/Foo.php passes (no syntax error)', $spec['acceptance_criteria']);
    }

    public function test_translate_never_invents_file_paths(): void
    {
        $translator = new AtlasBrainTaskSpecTranslator;

        $spec = $translator->translate([
            'objective' => 'Objective with non-file references',
            'target_path' => 'app/Http/Controllers/FooController.php',
            'obligations' => [
                [
                    'kind' => 'review',
                    'target_symbol' => 'App\\Models\\Foo',
                    'assertion_ref' => 'code review approved',
                    // NO file path — this obligation must not contribute to allowed_files
                ],
            ],
            'snapshot_id' => 'snap_2',
        ]);

        // Only the target_path, nothing invented.
        self::assertSame(['app/Http/Controllers/FooController.php'], $spec['allowed_files']);
    }

    public function test_translate_is_deterministic(): void
    {
        $translator = new AtlasBrainTaskSpecTranslator;
        $origination = [
            'objective' => 'Deterministic test objective',
            'target_path' => 'app/Foo.php',
            'obligations' => [],
            'snapshot_id' => 'snap_det',
        ];

        $a = $translator->translate($origination);
        $b = $translator->translate($origination);

        self::assertSame($a, $b);
    }

    public function test_obligation_without_assertion_ref_uses_kind_and_symbol(): void
    {
        $translator = new AtlasBrainTaskSpecTranslator;

        $spec = $translator->translate([
            'objective' => 'Test obligation derivation',
            'target_path' => 'app/Bar.php',
            'obligations' => [
                [
                    'kind' => 'mutation_testing',
                    'target_symbol' => 'App\\Bar::execute',
                    // no assertion_ref — should derive from kind + symbol
                ],
            ],
            'snapshot_id' => 'snap_3',
        ]);

        self::assertContains('mutation_testing obligation on App\\Bar::execute is satisfied', $spec['acceptance_criteria']);
    }
}
