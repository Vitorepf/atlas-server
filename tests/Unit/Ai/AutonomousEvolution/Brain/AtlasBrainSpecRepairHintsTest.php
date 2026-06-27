<?php

declare(strict_types=1);

namespace Tests\Unit\Ai\AutonomousEvolution\Brain;

use App\Services\Ai\AutonomousEvolution\AtlasLoopHarnessGuard;
use App\Services\Ai\AutonomousEvolution\Brain\AtlasBrainSpecRepairHints;
use Tests\TestCase;

final class AtlasBrainSpecRepairHintsTest extends TestCase
{
    public function test_known_deficiencies_get_concrete_repair_hints(): void
    {
        $r = (new AtlasBrainSpecRepairHints)->repair([
            'missing_objective',
            'missing_acceptance_criteria',
            'vague_objective',
        ]);

        self::assertCount(3, $r['hints']);
        self::assertSame('missing_objective', $r['hints'][0]['deficiency']);
        self::assertNotEmpty($r['hints'][0]['repair']);
        self::assertSame([], $r['unknown']);
    }

    public function test_unknown_deficiencies_land_in_unknown_bucket(): void
    {
        $r = (new AtlasBrainSpecRepairHints)->repair(['something_brand_new_inspector_added']);

        self::assertSame([], $r['hints']);
        self::assertContains('something_brand_new_inspector_added', $r['unknown']);
    }

    public function test_empty_input_returns_empty(): void
    {
        $r = (new AtlasBrainSpecRepairHints)->repair([]);
        self::assertSame([], $r['hints']);
        self::assertSame([], $r['unknown']);
    }

    public function test_repair_hints_organ_is_a_petreo_forbidden_self_target(): void
    {
        $verdict = app(AtlasLoopHarnessGuard::class)->admit(
            'app/Services/Ai/AutonomousEvolution/Brain/AtlasBrainSpecRepairHints.php',
            true
        );
        self::assertSame('forbidden', $verdict);
    }
}
