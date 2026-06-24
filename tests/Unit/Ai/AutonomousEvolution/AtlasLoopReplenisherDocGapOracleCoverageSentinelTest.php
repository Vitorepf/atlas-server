<?php

declare(strict_types=1);

namespace Tests\Unit\Ai\AutonomousEvolution;

use App\Services\Ai\AutonomousEvolution\Sentinels\AtlasLoopReplenisherDocGapOracleCoverageSentinel;
use Tests\TestCase;

final class AtlasLoopReplenisherDocGapOracleCoverageSentinelTest extends TestCase
{
    public function test_both_halves_packet_is_doc_gap_resolved(): void
    {
        $facts = (new AtlasLoopReplenisherDocGapOracleCoverageSentinel)->check();

        $this->assertFalse(
            $facts['both_halves']['doc_gap_unresolved'],
            'a packet granting BOTH app/ and tests/ paths must NOT be flagged — the test-half of the oracle still fires'
        );
        $this->assertNotContains('doc_gap_unresolved', $facts['both_halves']['deficiencies']);
    }

    public function test_app_only_packet_is_flagged_doc_gap_unresolved_exactly_once(): void
    {
        $facts = (new AtlasLoopReplenisherDocGapOracleCoverageSentinel)->check();

        $this->assertTrue(
            $facts['app_only']['doc_gap_unresolved'],
            'an app-only packet that asks for a test must be flagged — proves the oracle is not a silent no-op'
        );
        $this->assertSame(
            1,
            count(array_filter($facts['app_only']['deficiencies'], static fn (string $d): bool => $d === 'doc_gap_unresolved')),
            'the doc_gap_unresolved deficiency fires exactly once'
        );
    }

    public function test_overall_oracle_is_conformant(): void
    {
        $facts = (new AtlasLoopReplenisherDocGapOracleCoverageSentinel)->check();

        $this->assertSame('atlas.loop.replenisher_docgap_oracle_coverage.v1', $facts['schema']);
        $this->assertTrue($facts['conformant'], 'oracle resolves the both-halves packet AND fires on the app-only one');
    }
}
