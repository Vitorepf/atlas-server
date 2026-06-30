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

    // ---------- mineRanked — ranked real gaps, blocked-family, dedup, proxy rejection ----------

    public function test_mine_ranked_output_has_leverage_score(): void
    {
        $out = (new AtlasTaskFabricRoadmapGapMiner)->mineRanked([$this->row('Task Fabric', 'dependency_ladder')]);
        $this->assertCount(1, $out['candidates']);
        $this->assertArrayHasKey('leverage_score', $out['candidates'][0]);
        $this->assertIsInt($out['candidates'][0]['leverage_score']);
        $this->assertGreaterThan(0, $out['candidates'][0]['leverage_score']);
    }

    public function test_mine_ranked_sorts_by_leverage_score_descending(): void
    {
        // Task Fabric priority=10, 1 file  → score 11
        // Multi Project priority=4, 3 files → score 7
        $rows = [
            $this->row('Multi Project', 'isolation_sentinel', ['suggested_files' => ['a.php', 'b.php', 'c.php']]),
            $this->row('Task Fabric', 'dependency_ladder', ['suggested_files' => ['x.php']]),
        ];
        $out = (new AtlasTaskFabricRoadmapGapMiner)->mineRanked($rows);

        $this->assertCount(2, $out['candidates']);
        $this->assertSame('Task Fabric', $out['candidates'][0]['organ']);
        $this->assertGreaterThan($out['candidates'][1]['leverage_score'], $out['candidates'][0]['leverage_score']);
    }

    public function test_mine_ranked_tie_broken_by_organ_then_capability(): void
    {
        // Both Maestro with 0 files → same score; sort by capability ASC
        $rows = [
            $this->row('Maestro', 'z_cap', ['suggested_files' => []]),
            $this->row('Maestro', 'a_cap', ['suggested_files' => []]),
        ];
        $out = (new AtlasTaskFabricRoadmapGapMiner)->mineRanked($rows);

        $this->assertSame('a_cap', $out['candidates'][0]['capability']);
        $this->assertSame('z_cap', $out['candidates'][1]['capability']);
    }

    public function test_mine_ranked_skips_blocked_family(): void
    {
        $rows = [
            $this->row('Task Fabric', 'dep_ladder', ['family' => 'fabric-alpha']),
            $this->row('Maestro', 'tier_routing'),
        ];
        $out = (new AtlasTaskFabricRoadmapGapMiner)->mineRanked($rows, ['fabric-alpha']);

        $this->assertCount(1, $out['candidates']);
        $this->assertSame('Maestro', $out['candidates'][0]['organ']);
    }

    public function test_mine_ranked_passes_through_row_with_no_family_field(): void
    {
        $row = $this->row('Maestro', 'fleet_probe'); // no 'family' key
        $out = (new AtlasTaskFabricRoadmapGapMiner)->mineRanked([$row], ['fabric-alpha']);

        $this->assertCount(1, $out['candidates'], 'row without a family field must not be blocked');
    }

    public function test_mine_ranked_deduplicates_live_targets(): void
    {
        $rows = [
            $this->row('Task Fabric', 'dep_ladder'),
            $this->row('Maestro', 'tier_routing'),
        ];
        $out = (new AtlasTaskFabricRoadmapGapMiner)->mineRanked($rows, [], ['Task Fabric:dep_ladder']);

        $this->assertCount(1, $out['candidates']);
        $this->assertSame('Maestro', $out['candidates'][0]['organ']);
    }

    public function test_mine_ranked_rejects_proxy_gaps(): void
    {
        $out = (new AtlasTaskFabricRoadmapGapMiner)->mineRanked([
            $this->row('Task Fabric', 'cyclomatic_shrink', ['kind' => 'proxy']),
            $this->row('Worker Swarm', 'comment_polish', ['kind' => 'cosmetic']),
        ]);

        $this->assertSame([], $out['candidates']);
    }

    public function test_mine_ranked_caps_file_bonus_at_five(): void
    {
        // 10 files → bonus capped at 5; Task Fabric priority=10 → max score = 15
        $row = $this->row('Task Fabric', 'dep_ladder', [
            'suggested_files' => ['a.php', 'b.php', 'c.php', 'd.php', 'e.php', 'f.php', 'g.php', 'h.php', 'i.php', 'j.php'],
        ]);
        $out = (new AtlasTaskFabricRoadmapGapMiner)->mineRanked([$row]);

        $this->assertSame(15, $out['candidates'][0]['leverage_score']);
    }

    public function test_mine_ranked_rejection_reasons_are_deterministic_for_all_six_classes(): void
    {
        $miner = new AtlasTaskFabricRoadmapGapMiner;
        $rows = [
            $this->row('Task Fabric', 'resolved_cap', ['resolved' => true]),
            $this->row('Task Fabric', 'proxy_cap', ['kind' => 'cosmetic']),
            $this->row('Task Fabric', 'no_evidence', ['evidence_path' => '']),
            $this->row('Marketing Domain', 'unsupported_organ_cap'),    // unsupported organ
            $this->row('Maestro', 'blocked_cap', ['family' => 'beta']),
            $this->row('Worker Swarm', 'live_dup'),                      // live target duplicate
            $this->row('Verification Court', 'accepted_cap'),           // should be accepted
        ];
        $out = $miner->mineRanked($rows, ['beta'], ['Worker Swarm:live_dup']);

        // One candidate survives
        $this->assertCount(1, $out['candidates']);
        $this->assertSame('Verification Court', $out['candidates'][0]['organ']);

        // Six rejections, sorted by reason asc
        $this->assertCount(6, $out['rejections']);
        $reasons = array_column($out['rejections'], 'reason');

        // All six rejection classes present
        $this->assertContains('resolved', $reasons);
        $this->assertContains('cosmetic_or_proxy', $reasons);
        $this->assertContains('missing_evidence', $reasons);
        $this->assertContains('unsupported_organ', $reasons);
        $this->assertContains('blocked_family', $reasons);
        $this->assertContains('live_target_duplicate', $reasons);

        // Rejections are sorted deterministically (reason asc is primary)
        $sorted = $reasons;
        sort($sorted, SORT_STRING);
        $this->assertSame($sorted, $reasons, 'rejections must be sorted by reason asc');
    }

    // --- chain / unlock hint field tests ---

    public function test_mine_by_lane_emits_chain_key_when_row_provides_it(): void
    {
        $row = $this->row('Task Fabric', 'chain_source', [
            'lane'      => 'self-recovery',
            'chain_key' => 'fabric-unlock-chain-1',
        ]);

        $out = (new AtlasTaskFabricRoadmapGapMiner)->mineByLane([$row]);

        $this->assertCount(1, $out);
        $this->assertArrayHasKey('chain_key', $out[0]);
        $this->assertSame('fabric-unlock-chain-1', $out[0]['chain_key']);
    }

    public function test_mine_by_lane_emits_unlocks_capabilities_when_supplied(): void
    {
        $row = $this->row('Maestro', 'adaptive_routing', [
            'lane'                 => 'task-repair',
            'unlocks_capabilities' => ['score_routing', 'weighted_dispatch'],
        ]);

        $out = (new AtlasTaskFabricRoadmapGapMiner)->mineByLane([$row]);

        $this->assertArrayHasKey('unlocks_capabilities', $out[0]);
        $this->assertSame(['score_routing', 'weighted_dispatch'], $out[0]['unlocks_capabilities']);
    }

    public function test_mine_by_lane_emits_prerequisite_gap_refs_when_supplied(): void
    {
        $row = $this->row('Worker Swarm', 'lease_repair', [
            'lane'                  => 'task-repair',
            'prerequisite_gap_refs' => ['gap-001', 'gap-002'],
        ]);

        $out = (new AtlasTaskFabricRoadmapGapMiner)->mineByLane([$row]);

        $this->assertArrayHasKey('prerequisite_gap_refs', $out[0]);
        $this->assertSame(['gap-001', 'gap-002'], $out[0]['prerequisite_gap_refs']);
    }

    public function test_mine_by_lane_emits_next_unblock_hint_when_supplied(): void
    {
        $row = $this->row('Verification Court', 'replay_proof', [
            'lane'              => 'completion-certification',
            'next_unblock_hint' => 'Wire the CertificationService into the runtime loop',
        ]);

        $out = (new AtlasTaskFabricRoadmapGapMiner)->mineByLane([$row]);

        $this->assertArrayHasKey('next_unblock_hint', $out[0]);
        $this->assertSame('Wire the CertificationService into the runtime loop', $out[0]['next_unblock_hint']);
    }

    public function test_mine_ranked_emits_chain_fields_when_row_provides_them(): void
    {
        $row = $this->row('Task Fabric', 'ranked_chain', [
            'chain_key'             => 'fabric-chain-A',
            'unlocks_capabilities'  => ['ranked_dispatch'],
            'prerequisite_gap_refs' => ['gap-ranked-001'],
            'next_unblock_hint'     => 'Implement the ranked chain solver first',
        ]);

        $result    = (new AtlasTaskFabricRoadmapGapMiner)->mineRanked([$row]);
        $candidate = $result['candidates'][0];

        $this->assertSame('fabric-chain-A', $candidate['chain_key']);
        $this->assertSame(['ranked_dispatch'], $candidate['unlocks_capabilities']);
        $this->assertSame(['gap-ranked-001'], $candidate['prerequisite_gap_refs']);
        $this->assertSame('Implement the ranked chain solver first', $candidate['next_unblock_hint']);
    }

    public function test_chain_fields_absent_when_row_does_not_supply_them(): void
    {
        $out = (new AtlasTaskFabricRoadmapGapMiner)->mineByLane([$this->row('Merge Governor', 'auto_merge')]);

        $this->assertArrayNotHasKey('chain_key',             $out[0]);
        $this->assertArrayNotHasKey('unlocks_capabilities',  $out[0]);
        $this->assertArrayNotHasKey('prerequisite_gap_refs', $out[0]);
        $this->assertArrayNotHasKey('next_unblock_hint',     $out[0]);
    }

    public function test_resolved_row_with_chain_metadata_is_still_skipped(): void
    {
        $row = $this->row('Task Fabric', 'resolved_chain', [
            'resolved'  => true,
            'chain_key' => 'should-not-emit',
        ]);

        $out = (new AtlasTaskFabricRoadmapGapMiner)->mineByLane([$row]);

        $this->assertSame([], $out);
    }

    public function test_live_target_duplicate_with_chain_metadata_is_still_skipped(): void
    {
        $row = $this->row('Maestro', 'duplicate_chain', [
            'lane'      => 'self-recovery',
            'chain_key' => 'should-not-emit',
        ]);

        $out = (new AtlasTaskFabricRoadmapGapMiner)->mineByLane([$row], ['Maestro:duplicate_chain']);

        $this->assertSame([], $out);
    }

    public function test_blank_chain_fields_are_omitted_not_emitted_empty(): void
    {
        $row = $this->row('Learning Transfer', 'blank_chain', [
            'chain_key'            => '',
            'unlocks_capabilities' => [''],
            'next_unblock_hint'    => '  ',
        ]);

        $out = (new AtlasTaskFabricRoadmapGapMiner)->mineByLane([$row]);

        $this->assertCount(1, $out);
        $this->assertArrayNotHasKey('chain_key',            $out[0]);
        $this->assertArrayNotHasKey('unlocks_capabilities', $out[0]);
        $this->assertArrayNotHasKey('next_unblock_hint',    $out[0]);
    }

    // ── final-95 gap-index fields ─────────────────────────────────────────────

    public function test_mine_by_lane_emits_maturity_level_when_supplied(): void
    {
        $row = $this->row('Task Fabric', 'maturity_cap', ['lane' => 'compounding', 'maturity_level' => 'emerging']);
        $out = (new AtlasTaskFabricRoadmapGapMiner)->mineByLane([$row]);

        $this->assertCount(1, $out);
        $this->assertArrayHasKey('maturity_level', $out[0]);
        $this->assertSame('emerging', $out[0]['maturity_level']);
    }

    public function test_mine_by_lane_emits_blocked_state_when_supplied(): void
    {
        $row = $this->row('Maestro', 'blocked_cap', [
            'lane'          => 'task-repair',
            'blocked_state' => 'dependency_wait',
        ]);
        $out = (new AtlasTaskFabricRoadmapGapMiner)->mineByLane([$row]);

        $this->assertCount(1, $out);
        $this->assertSame('dependency_wait', $out[0]['blocked_state']);
    }

    public function test_mine_by_lane_emits_next_proof_required_when_supplied(): void
    {
        $row = $this->row('Worker Swarm', 'proof_cap', [
            'lane'                => 'muscle-feedback',
            'next_proof_required' => 'implement_green_gate',
        ]);
        $out = (new AtlasTaskFabricRoadmapGapMiner)->mineByLane([$row]);

        $this->assertArrayHasKey('next_proof_required', $out[0]);
        $this->assertSame('implement_green_gate', $out[0]['next_proof_required']);
    }

    public function test_mine_by_lane_emits_lane_unlock_impact_when_supplied(): void
    {
        $row = $this->row('Verification Court', 'impact_cap', [
            'lane'               => 'completion-certification',
            'lane_unlock_impact' => 'unblocks_completion_lane',
        ]);
        $out = (new AtlasTaskFabricRoadmapGapMiner)->mineByLane([$row]);

        $this->assertArrayHasKey('lane_unlock_impact', $out[0]);
        $this->assertSame('unblocks_completion_lane', $out[0]['lane_unlock_impact']);
    }

    public function test_new_final95_fields_absent_when_not_in_row(): void
    {
        $out = (new AtlasTaskFabricRoadmapGapMiner)->mineByLane([$this->row('Merge Governor', 'no_meta')]);

        $this->assertCount(1, $out);
        $this->assertArrayNotHasKey('maturity_level',      $out[0]);
        $this->assertArrayNotHasKey('blocked_state',       $out[0]);
        $this->assertArrayNotHasKey('next_proof_required', $out[0]);
        $this->assertArrayNotHasKey('lane_unlock_impact',  $out[0]);
    }

    // ── poison_quarantined filter ─────────────────────────────────────────────

    public function test_poison_quarantined_row_is_omitted_by_default(): void
    {
        $row = $this->row('Task Fabric', 'poisoned_cap', ['blocked_state' => 'poison_quarantined']);
        $out = (new AtlasTaskFabricRoadmapGapMiner)->mineByLane([$row]);

        $this->assertSame([], $out, 'poison_quarantined row must be omitted when not repairable');
    }

    public function test_poison_quarantined_row_included_when_repairable_with_evidence(): void
    {
        $row = $this->row('Task Fabric', 'repairable_cap', [
            'blocked_state'       => 'poison_quarantined',
            'repairable'          => true,
            'repair_evidence_path'=> 'docs/repair/repairable_cap.md',
        ]);
        $out = (new AtlasTaskFabricRoadmapGapMiner)->mineByLane([$row]);

        $this->assertCount(1, $out, 'repairable poison_quarantined row with evidence must be included');
        $this->assertSame('poison_quarantined', $out[0]['blocked_state']);
    }

    public function test_poison_quarantined_repairable_without_evidence_is_still_omitted(): void
    {
        $row = $this->row('Maestro', 'no_evidence_repair', [
            'blocked_state' => 'poison_quarantined',
            'repairable'    => true,
            // no repair_evidence_path
        ]);
        $out = (new AtlasTaskFabricRoadmapGapMiner)->mineByLane([$row]);

        $this->assertSame([], $out, 'repairable=true without repair_evidence_path must still be omitted');
    }

    public function test_non_poison_blocked_state_is_not_filtered(): void
    {
        // blocked_state with a non-poison value must not be filtered out
        $row = $this->row('Worker Swarm', 'dep_wait_cap', ['blocked_state' => 'dependency_wait']);
        $out = (new AtlasTaskFabricRoadmapGapMiner)->mineByLane([$row]);

        $this->assertCount(1, $out, 'non-poison blocked_state must not cause omission');
    }

    public function test_live_target_deduplication_still_works_with_final95_fields(): void
    {
        $row = $this->row('Task Fabric', 'dup_cap', [
            'lane'           => 'compounding',
            'maturity_level' => 'stable',
        ]);
        $out = (new AtlasTaskFabricRoadmapGapMiner)->mineByLane([$row], ['Task Fabric:dup_cap']);

        $this->assertSame([], $out, 'live-target deduplication must still drop the candidate even with final-95 fields');
    }
}
