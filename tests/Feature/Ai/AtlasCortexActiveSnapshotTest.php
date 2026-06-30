<?php

declare(strict_types=1);

namespace Tests\Feature\Ai;

use App\Services\Ai\AutonomousEvolution\Discovery\Cortex\Active\AtlasCortexActiveSnapshot;
use App\Services\Ai\AutonomousEvolution\Discovery\Cortex\Active\AtlasCortexCallGraphProjector;
use App\Services\Ai\AutonomousEvolution\Discovery\Cortex\Active\AtlasCortexCriticalPathDetector;
use App\Services\Ai\AutonomousEvolution\Discovery\Cortex\Active\CortexPassiveImmutabilityViolation;
use PHPUnit\Framework\TestCase;

final class AtlasCortexActiveSnapshotTest extends TestCase
{
    private function makeSnapshot(array $passive = [], array $activeProbes = []): AtlasCortexActiveSnapshot
    {
        return new AtlasCortexActiveSnapshot($passive, $activeProbes);
    }

    // ── AC2: passive and active in separate namespaces + immutability exception ─

    public function test_passive_and_active_are_under_separate_keys(): void
    {
        $snap = $this->makeSnapshot(['scope' => 'main'], []);
        $arr  = $snap->toArray();

        $this->assertArrayHasKey('passive', $arr);
        $this->assertArrayHasKey('active', $arr);
        $this->assertSame('main', $arr['passive']['scope']);
    }

    public function test_mutate_passive_throws_typed_exception(): void
    {
        $snap = $this->makeSnapshot(['k' => 'v'], []);

        $this->expectException(CortexPassiveImmutabilityViolation::class);
        $snap->mutatePassive(['k' => 'overwritten']);
    }

    public function test_passive_accessor_returns_original_passive(): void
    {
        $passive = ['fact' => 'original'];
        $snap    = $this->makeSnapshot($passive, []);

        $this->assertSame($passive, $snap->passive());
    }

    public function test_active_probes_do_not_appear_under_passive(): void
    {
        $snap = $this->makeSnapshot(
            ['scope' => 'main'],
            ['critical_paths' => [['path' => 'foo']]],
        );

        $arr = $snap->toArray();
        $this->assertArrayNotHasKey('critical_paths', $arr['passive']);
    }

    // ── AC3: blind spots aggregated into active.unknown_regions ───────────────

    public function test_unknown_region_fact_is_aggregated(): void
    {
        $snap = $this->makeSnapshot([], [
            'critical_paths' => [
                ['fact' => AtlasCortexCriticalPathDetector::FACT_UNKNOWN_REGION, 'path' => 'ServiceA'],
            ],
        ]);

        $unknowns = $snap->active()['unknown_regions'];
        $this->assertNotEmpty($unknowns);
        $this->assertSame(AtlasCortexCriticalPathDetector::FACT_UNKNOWN_REGION, $unknowns[0]['sentinel']);
    }

    public function test_depth_truncated_sentinel_is_aggregated(): void
    {
        $snap = $this->makeSnapshot([], [
            'call_graph_projections' => [
                ['sentinel' => AtlasCortexCallGraphProjector::DEPTH_TRUNCATED, 'node' => 'Foo'],
            ],
        ]);

        $unknowns = $snap->active()['unknown_regions'];
        $this->assertNotEmpty($unknowns);
        $this->assertSame(AtlasCortexCallGraphProjector::DEPTH_TRUNCATED, $unknowns[0]['sentinel']);
    }

    public function test_unresolved_target_sentinel_is_aggregated(): void
    {
        $snap = $this->makeSnapshot([], [
            'hypothetical_changes' => [
                ['reason' => 'UNRESOLVED_TARGET', 'target' => 'Bar'],
            ],
        ]);

        $unknowns = $snap->active()['unknown_regions'];
        $this->assertNotEmpty($unknowns);
        $this->assertSame('UNRESOLVED_TARGET', $unknowns[0]['sentinel']);
    }

    public function test_normal_facts_do_not_appear_in_unknown_regions(): void
    {
        $snap = $this->makeSnapshot([], [
            'critical_paths' => [
                ['path' => 'ServiceA->ServiceB', 'cost' => 10],
            ],
        ]);

        $this->assertSame([], $snap->active()['unknown_regions']);
    }

    public function test_multiple_blind_spots_across_probes_all_aggregated(): void
    {
        $snap = $this->makeSnapshot([], [
            'critical_paths'         => [['fact' => 'UNKNOWN_REGION', 'p' => 'x']],
            'call_graph_projections' => [['sentinel' => 'DEPTH_TRUNCATED', 'n' => 'y']],
        ]);

        $this->assertCount(2, $snap->active()['unknown_regions']);
    }

    // ── AC4: toJson is byte-deterministic and has no forbidden fields ─────────

    public function test_to_json_is_byte_deterministic(): void
    {
        $passive = ['z' => 2, 'a' => 1, 'nested' => ['b' => 1, 'a' => 2]];
        $probes  = ['critical_paths' => [['cost' => 5, 'path' => 'X->Y']]];

        $a = (new AtlasCortexActiveSnapshot($passive, $probes))->toJson();
        $b = (new AtlasCortexActiveSnapshot($passive, $probes))->toJson();

        $this->assertSame($a, $b);
        $this->assertNotEmpty($a);
    }

    public function test_to_json_does_not_emit_score_or_rank_fields(): void
    {
        $snap = $this->makeSnapshot(['scope' => 'main'], []);
        $json = $snap->toJson();

        foreach (['score', 'rank', 'comprehension_score'] as $forbidden) {
            $this->assertStringNotContainsString('"'.$forbidden.'"', $json, "Forbidden field present: {$forbidden}");
        }
    }
}
