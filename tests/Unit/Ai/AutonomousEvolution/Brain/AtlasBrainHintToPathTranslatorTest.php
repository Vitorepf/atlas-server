<?php

declare(strict_types=1);

namespace Tests\Unit\Ai\AutonomousEvolution\Brain;

use App\Services\Ai\AutonomousEvolution\AtlasLoopHarnessGuard;
use App\Services\Ai\AutonomousEvolution\Brain\AtlasBrainHintToPathTranslator;
use Tests\TestCase;

final class AtlasBrainHintToPathTranslatorTest extends TestCase
{
    public function test_path_for_known_hints(): void
    {
        $t = new AtlasBrainHintToPathTranslator;
        self::assertSame('frontier-harvest', $t->pathFor('harvest_frontier'));
        self::assertSame('pattern-design', $t->pathFor('use_drafted_candidate'));
        self::assertSame('compounding', $t->pathFor('compound'));
        self::assertNull($t->pathFor('unknown_hint'));
    }

    public function test_attribute_adds_path_to_known_hint_rows(): void
    {
        $rows = (new AtlasBrainHintToPathTranslator)->attribute([
            ['hint' => 'use_drafted_candidate', 'count' => 5, 'served' => 3, 'refused' => 2, 'total' => 5, 'served_rate_pct' => 60],
            ['hint' => 'unknown_thing', 'count' => 2],
        ]);
        self::assertCount(1, $rows);
        self::assertSame('pattern-design', $rows[0]['path']);
        self::assertSame(60, $rows[0]['served_rate_pct']);
    }

    public function test_translator_organ_is_a_petreo_forbidden_self_target(): void
    {
        $verdict = app(AtlasLoopHarnessGuard::class)->admit(
            'app/Services/Ai/AutonomousEvolution/Brain/AtlasBrainHintToPathTranslator.php',
            true
        );
        self::assertSame('forbidden', $verdict);
    }
}
