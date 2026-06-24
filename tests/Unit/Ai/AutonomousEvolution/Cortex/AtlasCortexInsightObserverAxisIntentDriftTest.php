<?php

declare(strict_types=1);

namespace Tests\Unit\Ai\AutonomousEvolution\Cortex;

use App\Services\Ai\AutonomousEvolution\Discovery\Cortex\Insights\AtlasCortexInsightObservationFactException;
use App\Services\Ai\AutonomousEvolution\Discovery\Cortex\Insights\Axis\AtlasCortexInsightObserverAxisIntentDrift;
use PHPUnit\Framework\TestCase;

final class AtlasCortexInsightObserverAxisIntentDriftTest extends TestCase
{
    public function test_it_emits_intent_drift_when_gate_block_follows_a_clean_merge(): void
    {
        $observer = new AtlasCortexInsightObserverAxisIntentDrift;

        $observation = $observer->observe([
            'noticed_at' => '2026-06-24T12:00:00-03:00',
            'unit_status_rows' => [
                ['unit' => 'App\\Domain\\Alpha', 'last_merge_clean' => true, 'has_gate_block' => true],
                ['unit' => 'App\\Domain\\Beta', 'last_merge_clean' => true, 'has_gate_block' => false],
            ],
        ]);

        $this->assertSame('intent_drift', $observation['axis_id']);
        $this->assertSame('intent_drift', $observation['observation_kind']);
        $this->assertSame('2026-06-24T15:00:00Z', $observation['noticed_at']);
        $this->assertSame(['App\\Domain\\Alpha'], $observation['witnesses']);
        $this->assertSame(['matched_units' => ['App\\Domain\\Alpha']], $observation['facts']);
    }

    public function test_it_returns_empty_when_no_unit_matches_the_drift_condition(): void
    {
        $observer = new AtlasCortexInsightObserverAxisIntentDrift;

        $observation = $observer->observe([
            'unit_status_rows' => [
                ['unit' => 'App\\Domain\\Alpha', 'last_merge_clean' => true, 'has_gate_block' => false],
                ['unit' => 'App\\Domain\\Beta', 'last_merge_clean' => false, 'has_gate_block' => true],
            ],
        ]);

        $this->assertSame([], $observation);
    }

    public function test_it_throws_a_typed_exception_when_required_facts_are_missing(): void
    {
        $observer = new AtlasCortexInsightObserverAxisIntentDrift;

        $this->expectException(AtlasCortexInsightObservationFactException::class);
        $this->expectExceptionMessage('unit_status_rows');

        $observer->observe([]);
    }
}
