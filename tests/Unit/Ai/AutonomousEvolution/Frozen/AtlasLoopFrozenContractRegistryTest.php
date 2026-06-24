<?php

declare(strict_types=1);

namespace Tests\Unit\Ai\AutonomousEvolution\Frozen;

use App\Services\Ai\AutonomousEvolution\Frozen\AtlasLoopFrozenContractRegistry;
use Tests\TestCase;

final class AtlasLoopFrozenContractRegistryTest extends TestCase
{
    public function test_get_returns_seeded_contracts_and_is_byte_deterministic(): void
    {
        $registry = new AtlasLoopFrozenContractRegistry;

        $first = $registry->get('App\\Services\\Ai\\AutonomousEvolution\\AtlasEvolutionFrozenJudge');
        $second = $registry->get('App\\Services\\Ai\\AutonomousEvolution\\AtlasEvolutionFrozenJudge');

        $this->assertSame($first, $second);
        $this->assertSame(
            [
                'assertions_sha256' => '0a4f90901b780e999609257c5479916374ae5bb3b8952ac48a600fafd330249e',
                'registered_at' => '2026-06-24T05:00:00+00:00',
                'test_path' => 'tests/Unit/Ai/AutonomousEvolution/AtlasEvolutionFrozenJudgeTest.php',
            ],
            $first
        );
    }

    public function test_missing_lists_real_autonomous_evolution_classes_without_manifest_entries_deterministically(): void
    {
        $registry = new AtlasLoopFrozenContractRegistry;

        $first = $registry->missing();
        $second = $registry->missing();

        $this->assertSame($first, $second);
        $this->assertContains('App\\Services\\Ai\\AutonomousEvolution\\Telemetry\\AtlasLoopTelemetryFactStreamEmitter', $first);
        $this->assertNotContains('App\\Services\\Ai\\AutonomousEvolution\\AtlasLoopAttemptLedger', $first);
    }

    public function test_manifest_round_trips_to_byte_identical_json_with_sorted_keys(): void
    {
        $registry = new AtlasLoopFrozenContractRegistry;
        $manifestPath = base_path('app/Services/Ai/AutonomousEvolution/Frozen/contracts.manifest.json');
        $expected = (string) file_get_contents($manifestPath);
        $actual = $registry->toJson();

        $this->assertSame($expected, $actual);
        $this->assertSame(hash('sha256', $expected), hash('sha256', $actual));
    }
}
