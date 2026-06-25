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
}
