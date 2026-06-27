<?php

declare(strict_types=1);

namespace Tests\Unit\Ai\AutonomousEvolution;

use App\Services\Ai\AutonomousEvolution\AtlasLoopTaskGrinder;
use PHPUnit\Framework\TestCase;

/**
 * S216 — the grinder arms the S214 source-class independence floor by appending an operator-configured
 * INDEPENDENT-engine judge command to the semantic refuter set. Pure helper; the spawn only happens when
 * configured, so the default (empty) path is byte-identical.
 */
final class AtlasLoopTaskGrinderIndependentJudgeTest extends TestCase
{
    public function test_empty_cmd_is_byte_identical(): void
    {
        $base = ['existing_refuter.sh'];
        self::assertSame($base, AtlasLoopTaskGrinder::withIndependentJudge($base, ''));
        self::assertSame($base, AtlasLoopTaskGrinder::withIndependentJudge($base, '   '));
    }

    public function test_configured_cmd_is_appended_as_a_refuter(): void
    {
        $out = AtlasLoopTaskGrinder::withIndependentJudge(['a.sh'], 'independent_engine_judge.sh');
        self::assertSame(['a.sh', 'independent_engine_judge.sh'], $out);
    }

    public function test_duplicate_cmd_is_not_double_appended(): void
    {
        $base = ['judge.sh'];
        self::assertSame($base, AtlasLoopTaskGrinder::withIndependentJudge($base, 'judge.sh'));
        self::assertSame($base, AtlasLoopTaskGrinder::withIndependentJudge($base, '  judge.sh  '));
    }

    public function test_appends_to_an_empty_refuter_set(): void
    {
        self::assertSame(['solo_judge.sh'], AtlasLoopTaskGrinder::withIndependentJudge([], 'solo_judge.sh'));
    }
}
