<?php

declare(strict_types=1);

namespace Tests\Unit\Ai\AutonomousEvolution\Cortex;

use App\Services\Ai\AutonomousEvolution\Discovery\Cortex\Insights\AtlasCortexInsightObservationFactException;
use App\Services\Ai\AutonomousEvolution\Discovery\Cortex\Insights\Axis\AtlasCortexInsightObserverAxisRecurrenceCluster;
use PHPUnit\Framework\TestCase;

final class AtlasCortexInsightObserverAxisRecurrenceClusterTest extends TestCase
{
    public function test_it_emits_recurrence_cluster_when_units_repeat_across_three_history_snapshots(): void
    {
        $observer = new AtlasCortexInsightObserverAxisRecurrenceCluster;

        $observation = $observer->observe([
            'noticed_at' => '2026-06-24T12:00:00Z',
            'history_orphan_snapshots' => [
                ['orphan_fqcns' => ['App\\Unit\\Recurring', 'App\\Unit\\Solo']],
                ['orphan_fqcns' => ['App\\Unit\\Recurring']],
                ['orphan_fqcns' => ['App\\Unit\\Recurring', 'App\\Unit\\Extra']],
                ['orphan_fqcns' => ['App\\Unit\\Extra']],
            ],
        ]);

        $this->assertSame('recurrence_cluster', $observation['axis_id']);
        $this->assertSame('recurrence_cluster', $observation['observation_kind']);
        $this->assertSame('2026-06-24T12:00:00Z', $observation['noticed_at']);
        $this->assertSame(['App\\Unit\\Recurring'], $observation['witnesses']);
        $this->assertSame(
            [
                'history_window_snapshots' => 4,
                'recurring_units' => ['App\\Unit\\Recurring' => 3],
            ],
            $observation['facts']
        );
    }

    public function test_it_returns_empty_when_no_unit_repeats_three_times(): void
    {
        $observer = new AtlasCortexInsightObserverAxisRecurrenceCluster;

        $observation = $observer->observe([
            'history_orphan_snapshots' => [
                ['orphan_fqcns' => ['App\\Unit\\A']],
                ['orphan_fqcns' => ['App\\Unit\\A']],
                ['orphan_fqcns' => ['App\\Unit\\B']],
            ],
        ]);

        $this->assertSame([], $observation);
    }

    public function test_it_throws_a_typed_exception_when_required_facts_are_missing(): void
    {
        $observer = new AtlasCortexInsightObserverAxisRecurrenceCluster;

        $this->expectException(AtlasCortexInsightObservationFactException::class);
        $this->expectExceptionMessage('history_orphan_snapshots');

        $observer->observe([]);
    }
}
