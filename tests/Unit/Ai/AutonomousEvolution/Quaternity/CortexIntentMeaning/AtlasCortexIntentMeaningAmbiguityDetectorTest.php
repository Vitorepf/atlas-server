<?php

declare(strict_types=1);

namespace Tests\Unit\Ai\AutonomousEvolution\Quaternity\CortexIntentMeaning;

use App\Services\Ai\AutonomousEvolution\Quaternity\CortexIntentMeaning\AtlasCortexIntentMeaningAmbiguityDetector;
use Tests\TestCase;

final class AtlasCortexIntentMeaningAmbiguityDetectorTest extends TestCase
{
    private function row(string $symbol, string $file): array
    {
        return ['intent' => 'do thing', 'symbol' => $symbol, 'file' => $file, 'evidence' => 'def', 'matched_token' => $symbol];
    }

    public function test_zero_rows_is_fail_closed_to_clarification(): void
    {
        $v = (new AtlasCortexIntentMeaningAmbiguityDetector)->detect([]);

        $this->assertFalse($v['ambiguous']);
        $this->assertSame(0, $v['site_count']);
        $this->assertTrue($v['clarification_required']);
        $this->assertSame('no_grounded_site', $v['reason']);
    }

    public function test_single_site_is_the_only_path_that_proceeds(): void
    {
        $v = (new AtlasCortexIntentMeaningAmbiguityDetector)->detect([
            $this->row('AtlasLoopComprehensionOriginator', 'app/Services/Ai/AutonomousEvolution/AtlasLoopComprehensionOriginator.php'),
        ]);

        $this->assertFalse($v['ambiguous']);
        $this->assertSame(1, $v['site_count']);
        $this->assertFalse($v['clarification_required']);
        $this->assertSame('unique_grounded_site', $v['reason']);
    }

    public function test_two_distinct_sites_are_ambiguous_and_routed_to_clarification(): void
    {
        $v = (new AtlasCortexIntentMeaningAmbiguityDetector)->detect([
            $this->row('Zeta', 'b.php'),
            $this->row('Alpha', 'a.php'),
        ]);

        $this->assertTrue($v['ambiguous']);
        $this->assertSame(2, $v['site_count']);
        $this->assertTrue($v['clarification_required']);
        $this->assertSame('multiple_grounded_sites', $v['reason']);
        $this->assertSame(['Alpha', 'Zeta'], $v['distinct_symbols'], 'sorted + deduped');
        $this->assertSame(['a.php', 'b.php'], $v['distinct_files']);
    }

    public function test_same_site_mentioned_twice_is_not_ambiguity(): void
    {
        $v = (new AtlasCortexIntentMeaningAmbiguityDetector)->detect([
            $this->row('Alpha', 'a.php'),
            $this->row('Alpha', 'a.php'),
        ]);

        $this->assertFalse($v['ambiguous']);
        $this->assertSame(1, $v['site_count']);
        $this->assertSame(['Alpha'], $v['distinct_symbols']);
    }
}
