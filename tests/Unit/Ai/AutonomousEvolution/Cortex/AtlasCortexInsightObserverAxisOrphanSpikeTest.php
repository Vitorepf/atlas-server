<?php

declare(strict_types=1);

namespace Tests\Unit\Ai\AutonomousEvolution\Cortex;

use App\Services\Ai\AutonomousEvolution\Discovery\Cortex\Insights\AtlasCortexInsightObservationFactException;
use App\Services\Ai\AutonomousEvolution\Discovery\Cortex\Insights\Axis\AtlasCortexInsightObserverAxisOrphanSpike;
use PHPUnit\Framework\TestCase;

final class AtlasCortexInsightObserverAxisOrphanSpikeTest extends TestCase
{
    public function test_it_emits_orphan_spike_for_a_strict_superset_with_new_witnesses(): void
    {
        $observer = new AtlasCortexInsightObserverAxisOrphanSpike;

        $observation = $observer->observe([
            'noticed_at' => '2026-06-24T12:00:00Z',
            'prior_orphan_fqcns' => ['App\\Legacy\\One'],
            'current_orphan_fqcns' => ['App\\Legacy\\One', 'App\\Legacy\\Two', 'App\\Legacy\\Two'],
        ]);

        $this->assertSame('orphan_spike', $observation['axis_id']);
        $this->assertSame('orphan_spike', $observation['observation_kind']);
        $this->assertSame('2026-06-24T12:00:00Z', $observation['noticed_at']);
        $this->assertSame(['App\\Legacy\\Two'], $observation['witnesses']);
        $this->assertSame(
            [
                'current_orphan_fqcns' => ['App\\Legacy\\One', 'App\\Legacy\\Two'],
                'prior_orphan_fqcns' => ['App\\Legacy\\One'],
                'new_orphan_fqcns' => ['App\\Legacy\\Two'],
            ],
            $observation['facts']
        );
    }

    public function test_it_returns_empty_when_current_set_is_not_a_strict_superset(): void
    {
        $observer = new AtlasCortexInsightObserverAxisOrphanSpike;

        $observation = $observer->observe([
            'prior_orphan_fqcns' => ['App\\Legacy\\One', 'App\\Legacy\\Two'],
            'current_orphan_fqcns' => ['App\\Legacy\\One'],
        ]);

        $this->assertSame([], $observation);
    }

    public function test_it_throws_a_typed_exception_when_required_facts_are_missing(): void
    {
        $observer = new AtlasCortexInsightObserverAxisOrphanSpike;

        $this->expectException(AtlasCortexInsightObservationFactException::class);
        $this->expectExceptionMessage('current_orphan_fqcns');

        $observer->observe(['prior_orphan_fqcns' => []]);
    }
}
