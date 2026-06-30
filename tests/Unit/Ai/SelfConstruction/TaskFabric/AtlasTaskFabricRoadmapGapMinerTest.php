<?php

declare(strict_types=1);

namespace Tests\Unit\Ai\SelfConstruction\TaskFabric;

use App\Services\Ai\SelfConstruction\TaskFabric\AtlasTaskFabricRoadmapGapMiner;
use PHPUnit\Framework\TestCase;

/**
 * Proves AtlasTaskFabricRoadmapGapMiner: unresolved rows become candidates; resolved rows are skipped;
 * cosmetic/proxy rows are skipped; rows without evidence_path are skipped; rows for organs outside the
 * supported allowlist are skipped; output is deterministically ordered by (organ, capability).
 */
final class AtlasTaskFabricRoadmapGapMinerTest extends TestCase
{
    private function row(string $organ, string $capability, array $overrides = []): array
    {
        return array_merge([
            'organ' => $organ,
            'capability' => $capability,
            'current_state' => 'absent',
            'target_state' => 'present',
            'evidence_path' => "docs/{$organ}_{$capability}.md",
            'suggested_files' => ["app/{$organ}/{$capability}.php"],
            'resolved' => false,
            'kind' => 'structural',
        ], $overrides);
    }

    public function test_unresolved_row_becomes_candidate_with_organ_and_capability_tags(): void
    {
        $out = (new AtlasTaskFabricRoadmapGapMiner)->mine([$this->row('Task Fabric', 'dependency_ladder')]);
        $this->assertCount(1, $out);
        $this->assertSame('Task Fabric', $out[0]['organ']);
        $this->assertSame('dependency_ladder', $out[0]['capability']);
        $this->assertContains('organ:Task Fabric', $out[0]['tags']);
        $this->assertContains('capability:dependency_ladder', $out[0]['tags']);
        $this->assertStringContainsString('CURRENT: absent', $out[0]['capability_gap']);
        $this->assertStringContainsString('TARGET: present', $out[0]['capability_gap']);
    }

    public function test_resolved_row_is_skipped(): void
    {
        $out = (new AtlasTaskFabricRoadmapGapMiner)->mine([$this->row('Maestro', 'tiering', ['resolved' => true])]);
        $this->assertSame([], $out);
    }

    public function test_cosmetic_or_proxy_row_is_skipped(): void
    {
        $miner = new AtlasTaskFabricRoadmapGapMiner;
        $this->assertSame([], $miner->mine([$this->row('Worker Swarm', 'whitespace_fix', ['kind' => 'cosmetic'])]));
        $this->assertSame([], $miner->mine([$this->row('Worker Swarm', 'cyclomatic_shrink', ['kind' => 'proxy_metric'])]));
        $this->assertSame([], $miner->mine([$this->row('Worker Swarm', 'comment_polish', ['kind' => 'comment-only'])]));
    }

    public function test_missing_evidence_path_is_skipped(): void
    {
        $out = (new AtlasTaskFabricRoadmapGapMiner)->mine([$this->row('Merge Governor', 'broader_gate', ['evidence_path' => ''])]);
        $this->assertSame([], $out);
    }

    public function test_organ_outside_supported_allowlist_is_skipped(): void
    {
        $out = (new AtlasTaskFabricRoadmapGapMiner)->mine([$this->row('Marketing Domain', 'whatever')]);
        $this->assertSame([], $out);
    }

    public function test_output_is_sorted_by_organ_then_capability(): void
    {
        $out = (new AtlasTaskFabricRoadmapGapMiner)->mine([
            $this->row('Maestro', 'tier_routing'),
            $this->row('Maestro', 'fleet_probe'),
            $this->row('Task Fabric', 'dependency_ladder'),
            $this->row('Worker Swarm', 'execution_envelope'),
        ]);
        $names = array_map(static fn ($c): string => $c['organ'].':'.$c['capability'], $out);
        $this->assertSame([
            'Maestro:fleet_probe',
            'Maestro:tier_routing',
            'Task Fabric:dependency_ladder',
            'Worker Swarm:execution_envelope',
        ], $names);
    }

    public function test_capability_gap_uses_unspecified_placeholders_when_states_empty(): void
    {
        $row = $this->row('Multi Project', 'isolation_sentinel', ['current_state' => '', 'target_state' => '']);
        $out = (new AtlasTaskFabricRoadmapGapMiner)->mine([$row]);
        $this->assertStringContainsString('(unspecified)', $out[0]['capability_gap']);
    }

    // ---------- mineByLane — lane tagging + deduplication ----------

    public function test_mine_by_lane_emits_lane_tag_for_known_final_brain_lane(): void
    {
        $row = $this->row('Task Fabric', 'self_recovery_probe', ['lane' => 'self-recovery']);
        $out = (new AtlasTaskFabricRoadmapGapMiner)->mineByLane([$row]);

        $this->assertCount(1, $out);
        $this->assertSame('self-recovery', $out[0]['lane']);
        $this->assertContains('lane:self-recovery', $out[0]['tags']);
    }

    public function test_mine_by_lane_emits_all_seven_final_brain_lanes(): void
    {
        $miner = new AtlasTaskFabricRoadmapGapMiner;
        $rows  = [];
        foreach (AtlasTaskFabricRoadmapGapMiner::FINAL_BRAIN_LANES as $i => $lane) {
            $rows[] = $this->row('Task Fabric', 'cap_'.$i, ['lane' => $lane]);
        }
        $out = $miner->mineByLane($rows);

        $this->assertCount(7, $out, 'all 7 final-brain lanes must produce candidates');
        $emittedLanes = array_column($out, 'lane');
        foreach (AtlasTaskFabricRoadmapGapMiner::FINAL_BRAIN_LANES as $lane) {
            $this->assertContains($lane, $emittedLanes, "lane {$lane} must appear in output");
        }
    }

    public function test_mine_by_lane_drops_duplicate_when_live_target_exists(): void
    {
        $row = $this->row('Maestro', 'fleet_probe', ['lane' => 'compounding']);
        $liveTargets = ['Maestro:fleet_probe'];
        $out = (new AtlasTaskFabricRoadmapGapMiner)->mineByLane([$row], $liveTargets);

        $this->assertSame([], $out, 'candidate matching a live-target key must be deduplicated');
    }

    public function test_mine_by_lane_emits_non_duplicate_alongside_duplicate(): void
    {
        $rows = [
            $this->row('Maestro', 'fleet_probe',   ['lane' => 'compounding']),
            $this->row('Maestro', 'tier_routing',  ['lane' => 'compounding']),
        ];
        $out = (new AtlasTaskFabricRoadmapGapMiner)->mineByLane($rows, ['Maestro:fleet_probe']);

        $this->assertCount(1, $out, 'only the non-duplicate must survive');
        $this->assertSame('tier_routing', $out[0]['capability']);
    }

    public function test_mine_by_lane_omits_lane_key_when_row_has_no_lane(): void
    {
        $row = $this->row('Worker Swarm', 'execution_envelope');
        $out = (new AtlasTaskFabricRoadmapGapMiner)->mineByLane([$row]);

        $this->assertCount(1, $out);
        $this->assertArrayNotHasKey('lane', $out[0]);
        $this->assertEmpty(array_filter($out[0]['tags'], static fn (string $t): bool => str_starts_with($t, 'lane:')));
    }

    public function test_mine_by_lane_sorted_by_lane_then_organ_then_capability(): void
    {
        $rows = [
            $this->row('Task Fabric',  'b_cap', ['lane' => 'compounding']),
            $this->row('Task Fabric',  'a_cap', ['lane' => 'compounding']),
            $this->row('Maestro',      'z_cap', ['lane' => 'self-recovery']),
        ];
        $out = (new AtlasTaskFabricRoadmapGapMiner)->mineByLane($rows);

        $keys = array_map(static fn (array $c): string => ($c['lane'] ?? '').':'.$c['organ'].':'.$c['capability'], $out);
        $this->assertSame([
            'compounding:Task Fabric:a_cap',
            'compounding:Task Fabric:b_cap',
            'self-recovery:Maestro:z_cap',
        ], $keys);
    }
}
