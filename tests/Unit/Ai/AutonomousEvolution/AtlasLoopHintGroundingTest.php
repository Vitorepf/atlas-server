<?php

declare(strict_types=1);

namespace Tests\Unit\Ai\AutonomousEvolution;

use App\Services\Ai\AutonomousEvolution\AtlasLoopObraExecutionAdapter;
use PHPUnit\Framework\TestCase;

/**
 * ACDE P2 — hint-grounding strips fictional *.php references from a spec's decomposition_hint while preserving
 * real ones, so the planner cannot chase a file the weak engine invented. Pure transform => hang-free.
 */
final class AtlasLoopHintGroundingTest extends TestCase
{
    private function adapter(): AtlasLoopObraExecutionAdapter
    {
        return new AtlasLoopObraExecutionAdapter;
    }

    public function test_strips_fictional_file_and_keeps_real_one(): void
    {
        $spec = ['decomposition_hint' => 'extract Foo into src/Real.php and move it from src/Fake.php'];

        $out = $this->adapter()->groundDecompositionHint($spec, ['src/Real.php']);

        $this->assertStringContainsString('src/Real.php', $out['decomposition_hint']);
        $this->assertStringNotContainsString('src/Fake.php', $out['decomposition_hint']);
        $this->assertTrue($out['decomposition_hint_grounded']);
    }

    public function test_leaves_an_all_real_hint_untouched(): void
    {
        $spec = ['decomposition_hint' => 'split src/A.php and src/B.php'];

        $out = $this->adapter()->groundDecompositionHint($spec, ['src/A.php', 'src/B.php']);

        $this->assertStringContainsString('src/A.php', $out['decomposition_hint']);
        $this->assertStringContainsString('src/B.php', $out['decomposition_hint']);
        $this->assertArrayNotHasKey('decomposition_hint_grounded', $out, 'nothing fictional => no grounding flag');
    }

    public function test_no_hint_is_a_passthrough(): void
    {
        $spec = ['summary' => 'do a thing'];

        $this->assertSame($spec, $this->adapter()->groundDecompositionHint($spec, ['src/A.php']));
    }

    public function test_blank_hint_is_a_passthrough(): void
    {
        $spec = ['decomposition_hint' => '   '];

        $this->assertSame($spec, $this->adapter()->groundDecompositionHint($spec, ['src/A.php']));
    }
}
