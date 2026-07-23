<?php

declare(strict_types=1);

namespace Tests\Unit\Ai\SelfConstruction\ExternalBrain;

use App\Services\Ai\SelfConstruction\ExternalBrain\AtlasExternalBrainStalledCapabilityRescuePlanner;
use Tests\TestCase;

final class AtlasExternalBrainStalledCapabilityRescuePlannerTest extends TestCase
{
    private function svc(): AtlasExternalBrainStalledCapabilityRescuePlanner
    {
        return new AtlasExternalBrainStalledCapabilityRescuePlanner;
    }

    private function cap(string $id, string $state, int $stallCount = 0, string $owner = '', bool $ownerIntegrated = false): array
    {
        return [
            'id' => $id,
            'state' => $state,
            'stall_count' => $stallCount,
            'replacement_owner_id' => $owner,
            'replacement_owner_integrated' => $ownerIntegrated,
        ];
    }

    private function entryFor(array $result, string $id): ?array
    {
        foreach ($result['entries'] as $e) {
            if ($e['capability_id'] === $id) {
                return $e;
            }
        }

        return null;
    }

    // ── rescue ────────────────────────────────────────────────────────────────

    public function test_implemented_not_integrated_is_rescue(): void
    {
        $r = $this->svc()->plan([$this->cap('cap-1', 'implemented')]);

        $e = $this->entryFor($r, 'cap-1');
        $this->assertNotNull($e);
        $this->assertSame(AtlasExternalBrainStalledCapabilityRescuePlanner::ACTION_RESCUE, $e['action']);
        $this->assertContains('wiring_proof', $e['evidence_requirements']);
        $this->assertContains('integration_test', $e['evidence_requirements']);
        $this->assertArrayHasKey('next_action', $e);
        $this->assertNotEmpty($e['next_action']);
    }

    public function test_high_impact_rescueable_capability_has_first_safe_task(): void
    {
        $cap = array_merge($this->cap('cap-rescue-1', 'implemented'), [
            'impact_level' => 'high',
            'estimated_rescue_cost' => 0.2,
            'owning_file_paths' => ['app/Services/Ai/Foo.php'],
        ]);
        $r = $this->svc()->plan([$cap]);
        $e = $this->entryFor($r, 'cap-rescue-1');

        $this->assertArrayHasKey('first_safe_task', $e);
        foreach (['objective_hint', 'allowed_file_hints', 'proof_requirements', 'expected_capability_delta'] as $k) {
            $this->assertArrayHasKey($k, $e['first_safe_task'], "missing {$k}");
        }
        $this->assertNotEmpty($e['first_safe_task']['objective_hint']);
        $this->assertContains('app/Services/Ai/Foo.php', $e['first_safe_task']['allowed_file_hints']);
        $this->assertNotEmpty($e['first_safe_task']['proof_requirements']);
        $this->assertNotEmpty($e['first_safe_task']['expected_capability_delta']);
    }

    public function test_every_entry_has_unblock_cause_rescue_priority_and_next_action(): void
    {
        $r = $this->svc()->plan([
            $this->cap('cap-rescue',  'implemented'),
            array_merge($this->cap('cap-collapse', 'implemented'), [
                'replacement_owner_id' => 'owner-2', 'replacement_owner_integrated' => true,
            ]),
            array_merge($this->cap('cap-retire', 'planned'), ['stall_count' => 5]),
            $this->cap('cap-monitor', 'planned'),
        ]);

        foreach (['cap-rescue', 'cap-collapse', 'cap-retire', 'cap-monitor'] as $id) {
            $e = $this->entryFor($r, $id);
            $this->assertNotNull($e, "missing entry for {$id}");
            foreach (['unblock_cause', 'rescue_priority', 'next_action', 'evidence_requirements'] as $k) {
                $this->assertArrayHasKey($k, $e, "{$id} missing key {$k}");
            }
            $this->assertNotEmpty($e['next_action']);
        }
    }

    public function test_tested_not_integrated_is_rescue(): void
    {
        $r = $this->svc()->plan([$this->cap('cap-2', 'tested')]);

        $this->assertSame(
            AtlasExternalBrainStalledCapabilityRescuePlanner::ACTION_RESCUE,
            $this->entryFor($r, 'cap-2')['action'],
        );
    }

    // ── collapse ──────────────────────────────────────────────────────────────

    public function test_with_integrated_replacement_is_collapse(): void
    {
        $r = $this->svc()->plan([$this->cap('cap-3', 'implemented', 0, 'cap-owner', true)]);

        $e = $this->entryFor($r, 'cap-3');
        $this->assertSame(AtlasExternalBrainStalledCapabilityRescuePlanner::ACTION_COLLAPSE, $e['action']);
        $this->assertSame('cap-owner', $e['collapse_into']);
        $this->assertContains('overlap_proof', $e['evidence_requirements']);
        $this->assertContains('canonical_owner_confirmation', $e['evidence_requirements']);
        $this->assertArrayHasKey('first_safe_task', $e);
        $this->assertSame('cap-owner', $e['first_safe_task']['replacement_owner']);
        $this->assertNotEmpty($e['first_safe_task']['consolidation_proof']);
        $this->assertStringContainsString('cap-owner', $e['first_safe_task']['objective_hint']);
    }

    public function test_collapse_takes_priority_over_rescue(): void
    {
        // state=implemented (would be rescue) but has integrated owner → collapse
        $r = $this->svc()->plan([$this->cap('cap-4', 'implemented', 0, 'owner-x', true)]);

        $this->assertSame(
            AtlasExternalBrainStalledCapabilityRescuePlanner::ACTION_COLLAPSE,
            $this->entryFor($r, 'cap-4')['action'],
        );
    }

    public function test_non_integrated_replacement_does_not_collapse(): void
    {
        // replacement_owner_integrated=false → does not trigger collapse
        $r = $this->svc()->plan([$this->cap('cap-5', 'implemented', 0, 'owner-y', false)]);

        $this->assertSame(
            AtlasExternalBrainStalledCapabilityRescuePlanner::ACTION_RESCUE,
            $this->entryFor($r, 'cap-5')['action'],
        );
    }

    // ── retire ────────────────────────────────────────────────────────────────

    public function test_high_stall_count_planned_is_retire(): void
    {
        $threshold = AtlasExternalBrainStalledCapabilityRescuePlanner::STALL_RETIRE_THRESHOLD;
        $r = $this->svc()->plan([
            $this->cap('cap-6', 'planned', $threshold),
        ]);

        $this->assertSame(
            AtlasExternalBrainStalledCapabilityRescuePlanner::ACTION_RETIRE,
            $this->entryFor($r, 'cap-6')['action'],
        );
    }

    public function test_retire_decision_has_retire_conditions_and_no_first_safe_task(): void
    {
        $threshold = AtlasExternalBrainStalledCapabilityRescuePlanner::STALL_RETIRE_THRESHOLD;
        $r = $this->svc()->plan([$this->cap('cap-retire-1', 'planned', $threshold)]);
        $e = $this->entryFor($r, 'cap-retire-1');

        $this->assertArrayHasKey('retire_conditions', $e);
        $this->assertArrayHasKey('no_active_consumer', $e['retire_conditions']);
        $this->assertArrayNotHasKey('first_safe_task', $e);
    }

    public function test_high_stall_queued_is_retire(): void
    {
        $threshold = AtlasExternalBrainStalledCapabilityRescuePlanner::STALL_RETIRE_THRESHOLD;
        $r = $this->svc()->plan([$this->cap('cap-7', 'queued', $threshold)]);

        $this->assertSame(
            AtlasExternalBrainStalledCapabilityRescuePlanner::ACTION_RETIRE,
            $this->entryFor($r, 'cap-7')['action'],
        );
    }

    public function test_retire_evidence_requirements(): void
    {
        $threshold = AtlasExternalBrainStalledCapabilityRescuePlanner::STALL_RETIRE_THRESHOLD;
        $r = $this->svc()->plan([$this->cap('cap-8', 'queued', $threshold)]);

        $e = $this->entryFor($r, 'cap-8');
        $this->assertContains('stall_history', $e['evidence_requirements']);
        $this->assertContains('no_active_consumer', $e['evidence_requirements']);
    }

    // ── monitor ───────────────────────────────────────────────────────────────

    public function test_low_stall_count_planned_is_monitor(): void
    {
        $r = $this->svc()->plan([$this->cap('cap-9', 'planned', 1)]);

        $e = $this->entryFor($r, 'cap-9');
        $this->assertSame(AtlasExternalBrainStalledCapabilityRescuePlanner::ACTION_MONITOR, $e['action']);
        $this->assertSame([], $e['evidence_requirements']);
    }

    // ── skip integrated ───────────────────────────────────────────────────────

    public function test_already_integrated_is_skipped(): void
    {
        $r = $this->svc()->plan([$this->cap('cap-10', 'integrated')]);

        $this->assertSame(0, $r['total_evaluated']);
        $this->assertSame([], $r['entries']);
    }

    public function test_already_used_is_skipped(): void
    {
        $r = $this->svc()->plan([$this->cap('cap-11', 'used')]);

        $this->assertNull($this->entryFor($r, 'cap-11'));
    }

    // ── aggregates ────────────────────────────────────────────────────────────

    public function test_by_action_groups_correctly(): void
    {
        $threshold = AtlasExternalBrainStalledCapabilityRescuePlanner::STALL_RETIRE_THRESHOLD;
        $r = $this->svc()->plan([
            $this->cap('r1', 'implemented'),
            $this->cap('c1', 'planned', 0, 'owner', true),
            $this->cap('e1', 'queued', $threshold),
            $this->cap('m1', 'planned', 1),
            $this->cap('skip', 'integrated'),
        ]);

        $this->assertContains('r1', $r['by_action']['rescue']);
        $this->assertContains('c1', $r['by_action']['collapse']);
        $this->assertContains('e1', $r['by_action']['retire']);
        $this->assertContains('m1', $r['by_action']['monitor']);
        $this->assertNotContains('skip', array_merge(
            $r['by_action']['rescue'],
            $r['by_action']['collapse'],
            $r['by_action']['retire'],
            $r['by_action']['monitor'],
        ));
        $this->assertSame(4, $r['total_evaluated']);
    }

    public function test_schema_version_present(): void
    {
        $r = $this->svc()->plan([]);

        $this->assertSame(AtlasExternalBrainStalledCapabilityRescuePlanner::SCHEMA, $r['schema_version']);
    }

    // ── model-amplifier rescue policy (AC2 + AC3) ─────────────────────────────

    public function test_stale_duplicated_unused_high_cost_is_not_rescue(): void
    {
        $r = $this->svc()->plan([array_merge(
            $this->cap('cap-bad', 'implemented'),
            [
                'consumer_count'             => 0,
                'duplicate_owner_count'      => 1,
                'last_green_commit_age_days' => 31,
                'estimated_rescue_cost'      => 2.0,
            ],
        )]);

        $e = $this->entryFor($r, 'cap-bad');
        $this->assertNotNull($e);
        $this->assertNotSame(AtlasExternalBrainStalledCapabilityRescuePlanner::ACTION_RESCUE, $e['action']);
    }

    public function test_stale_duplicated_unused_high_cost_collapses_when_has_duplicate_owner(): void
    {
        $r = $this->svc()->plan([array_merge(
            $this->cap('cap-dup', 'implemented'),
            [
                'consumer_count'             => 0,
                'duplicate_owner_count'      => 2,
                'last_green_commit_age_days' => 35,
                'estimated_rescue_cost'      => 3.0,
            ],
        )]);

        $this->assertSame(
            AtlasExternalBrainStalledCapabilityRescuePlanner::ACTION_COLLAPSE,
            $this->entryFor($r, 'cap-dup')['action'],
        );
    }

    public function test_implemented_with_consumers_recent_green_low_cost_is_rescue(): void
    {
        $r = $this->svc()->plan([array_merge(
            $this->cap('cap-good', 'implemented'),
            [
                'consumer_count'             => 3,
                'last_green_commit_age_days' => 5,
                'estimated_rescue_cost'      => 0.5,
            ],
        )]);

        $e = $this->entryFor($r, 'cap-good');
        $this->assertSame(AtlasExternalBrainStalledCapabilityRescuePlanner::ACTION_RESCUE, $e['action']);
        $this->assertContains('integration_test', $e['evidence_requirements']);
        $this->assertContains('wiring_proof', $e['evidence_requirements']);
    }

    public function test_stale_unused_high_cost_without_dup_owner_still_rescues(): void
    {
        // All four AC2 conditions must hold; without duplicate_owner → primary check fails → still viable
        $r = $this->svc()->plan([array_merge(
            $this->cap('cap-solo', 'implemented'),
            [
                'consumer_count'             => 0,
                'duplicate_owner_count'      => 0,
                'last_green_commit_age_days' => 60,
                'estimated_rescue_cost'      => 5.0,
            ],
        )]);

        $this->assertSame(
            AtlasExternalBrainStalledCapabilityRescuePlanner::ACTION_RESCUE,
            $this->entryFor($r, 'cap-solo')['action'],
        );
    }

    public function test_high_give_backs_unused_stale_low_completeness_retires(): void
    {
        $r = $this->svc()->plan([array_merge(
            $this->cap('cap-gb', 'implemented'),
            [
                'consumer_count'              => 0,
                'duplicate_owner_count'       => 0,
                'last_green_commit_age_days'  => 31,
                'outstanding_give_back_count' => 2,
                'implementation_completeness' => 0.3,
            ],
        )]);

        $this->assertSame(
            AtlasExternalBrainStalledCapabilityRescuePlanner::ACTION_RETIRE,
            $this->entryFor($r, 'cap-gb')['action'],
        );
    }

    public function test_high_completeness_saves_give_back_from_retire(): void
    {
        $r = $this->svc()->plan([array_merge(
            $this->cap('cap-done', 'implemented'),
            [
                'consumer_count'              => 0,
                'duplicate_owner_count'       => 0,
                'last_green_commit_age_days'  => 31,
                'outstanding_give_back_count' => 2,
                'implementation_completeness' => 0.8,
            ],
        )]);

        $this->assertSame(
            AtlasExternalBrainStalledCapabilityRescuePlanner::ACTION_RESCUE,
            $this->entryFor($r, 'cap-done')['action'],
        );
    }

    public function test_rescue_viability_constants_are_public(): void
    {
        $this->assertSame(30, AtlasExternalBrainStalledCapabilityRescuePlanner::RESCUE_STALE_DAYS_THRESHOLD);
        $this->assertSame(1.0, AtlasExternalBrainStalledCapabilityRescuePlanner::RESCUE_MAX_COST);
    }

    // ── AC: planUnblock() emits a concrete unblock_plan for genuinely stalled capabilities ──

    private function unblockEntryFor(array $result, string $id): array
    {
        foreach ($result['entries'] as $entry) {
            if ($entry['capability_id'] === $id) {
                return $entry;
            }
        }

        return [];
    }

    public function test_repeated_give_back_is_detected_as_stalled_with_unblock_plan(): void
    {
        $result = (new AtlasExternalBrainStalledCapabilityRescuePlanner)->planUnblock([
            ['id' => 'cap-giveback', 'give_back_count' => 3],
        ]);

        $entry = $this->unblockEntryFor($result, 'cap-giveback');
        $this->assertTrue($entry['is_stalled']);
        $this->assertSame('repeated_give_back', $entry['unblock_plan']['root_cause']);
        $this->assertNotEmpty($entry['unblock_plan']['first_safe_task']);
        $this->assertNotEmpty($entry['unblock_plan']['required_evidence']);
        $this->assertTrue($entry['unblock_plan']['stop_creating_adjacent_features']);
    }

    public function test_stale_proof_is_detected_as_stalled(): void
    {
        $result = (new AtlasExternalBrainStalledCapabilityRescuePlanner)->planUnblock([
            ['id' => 'cap-stale', 'last_proof_age_days' => 45],
        ]);

        $entry = $this->unblockEntryFor($result, 'cap-stale');
        $this->assertTrue($entry['is_stalled']);
        $this->assertSame('stale_proof', $entry['unblock_plan']['root_cause']);
    }

    public function test_blocked_dependency_is_detected_as_stalled(): void
    {
        $result = (new AtlasExternalBrainStalledCapabilityRescuePlanner)->planUnblock([
            ['id' => 'cap-blocked', 'blocked_dependencies' => ['AtlasFooService']],
        ]);

        $entry = $this->unblockEntryFor($result, 'cap-blocked');
        $this->assertTrue($entry['is_stalled']);
        $this->assertSame('blocked_dependency', $entry['unblock_plan']['root_cause']);
    }

    public function test_no_impact_commit_streak_is_detected_as_stalled(): void
    {
        $result = (new AtlasExternalBrainStalledCapabilityRescuePlanner)->planUnblock([
            ['id' => 'cap-no-impact', 'no_impact_commit_streak' => 4],
        ]);

        $entry = $this->unblockEntryFor($result, 'cap-no-impact');
        $this->assertTrue($entry['is_stalled']);
        $this->assertSame('no_impact_commits', $entry['unblock_plan']['root_cause']);
    }

    public function test_low_priority_capability_without_stall_signals_is_not_stalled(): void
    {
        $result = (new AtlasExternalBrainStalledCapabilityRescuePlanner)->planUnblock([
            ['id' => 'cap-low-priority', 'priority' => 'low'],
        ]);

        $entry = $this->unblockEntryFor($result, 'cap-low-priority');
        $this->assertFalse($entry['is_stalled']);
        $this->assertNull($entry['unblock_plan']);
    }

    // ── AC: repeated give_back produces a prerequisite or scope repair rescue ──

    public function test_repeated_give_back_with_missing_prerequisite_names_prerequisite_task(): void
    {
        $result = (new AtlasExternalBrainStalledCapabilityRescuePlanner)->planUnblock([
            ['id' => 'cap-prereq', 'give_back_count' => 3, 'has_missing_prerequisite' => true],
        ]);

        $entry = $this->unblockEntryFor($result, 'cap-prereq');
        $this->assertSame('repeated_give_back', $entry['unblock_plan']['root_cause']);
        $this->assertSame('resolve_missing_prerequisite_before_retry', $entry['unblock_plan']['first_safe_task']);
    }

    public function test_repeated_give_back_with_scope_gap_names_scope_repair_task(): void
    {
        $result = (new AtlasExternalBrainStalledCapabilityRescuePlanner)->planUnblock([
            ['id' => 'cap-scope', 'give_back_count' => 3, 'has_scope_gap' => true],
        ]);

        $entry = $this->unblockEntryFor($result, 'cap-scope');
        $this->assertSame('repeated_give_back', $entry['unblock_plan']['root_cause']);
        $this->assertSame('repair_scope_gap_before_retry', $entry['unblock_plan']['first_safe_task']);
    }

    public function test_repeated_give_back_without_specific_signal_falls_back_to_generic_diagnosis(): void
    {
        $result = (new AtlasExternalBrainStalledCapabilityRescuePlanner)->planUnblock([
            ['id' => 'cap-generic', 'give_back_count' => 3],
        ]);

        $entry = $this->unblockEntryFor($result, 'cap-generic');
        $this->assertSame('diagnose_repeated_give_back_root_cause', $entry['unblock_plan']['first_safe_task']);
    }

    // ── AC: low-yield repeated attempts recommend simplification or research ──

    public function test_low_yield_structurally_complex_recommends_simplification(): void
    {
        $result = (new AtlasExternalBrainStalledCapabilityRescuePlanner)->planUnblock([
            ['id' => 'cap-complex', 'yield_score' => 0.1, 'attempt_count' => 3, 'structurally_complex' => true],
        ]);

        $entry = $this->unblockEntryFor($result, 'cap-complex');
        $this->assertTrue($entry['is_stalled']);
        $this->assertSame('low_yield', $entry['unblock_plan']['root_cause']);
        $this->assertSame('simplify_before_retry', $entry['unblock_plan']['first_safe_task']);
    }

    public function test_low_yield_not_structurally_complex_recommends_research(): void
    {
        $result = (new AtlasExternalBrainStalledCapabilityRescuePlanner)->planUnblock([
            ['id' => 'cap-unclear', 'yield_score' => 0.1, 'attempt_count' => 3],
        ]);

        $entry = $this->unblockEntryFor($result, 'cap-unclear');
        $this->assertSame('low_yield', $entry['unblock_plan']['root_cause']);
        $this->assertSame('research_alternative_approach_before_retry', $entry['unblock_plan']['first_safe_task']);
    }

    public function test_low_yield_requires_minimum_attempt_count(): void
    {
        $result = (new AtlasExternalBrainStalledCapabilityRescuePlanner)->planUnblock([
            ['id' => 'cap-first-try', 'yield_score' => 0.1, 'attempt_count' => 1],
        ]);

        $entry = $this->unblockEntryFor($result, 'cap-first-try');
        $this->assertFalse($entry['is_stalled']);
    }

    // ── AC: rescue output includes next_worker_ready_task_hint and do_not_repeat_packet ids ──

    public function test_stalled_entry_includes_next_worker_ready_task_hint(): void
    {
        $result = (new AtlasExternalBrainStalledCapabilityRescuePlanner)->planUnblock([
            ['id' => 'cap-x', 'give_back_count' => 3],
        ]);

        $entry = $this->unblockEntryFor($result, 'cap-x');
        $this->assertArrayHasKey('next_worker_ready_task_hint', $entry['unblock_plan']);
        $this->assertNotEmpty($entry['unblock_plan']['next_worker_ready_task_hint']);
        $this->assertStringContainsString('cap-x', $entry['unblock_plan']['next_worker_ready_task_hint']);
    }

    public function test_entry_includes_do_not_repeat_packet_ids(): void
    {
        $result = (new AtlasExternalBrainStalledCapabilityRescuePlanner)->planUnblock([
            ['id' => 'cap-y', 'give_back_count' => 3, 'prior_packet_ids' => ['op-1', 'op-2']],
        ]);

        $entry = $this->unblockEntryFor($result, 'cap-y');
        $this->assertSame(['op-1', 'op-2'], $entry['do_not_repeat_packet_ids']);
    }

    public function test_do_not_repeat_packet_ids_defaults_to_empty(): void
    {
        $result = (new AtlasExternalBrainStalledCapabilityRescuePlanner)->planUnblock([
            ['id' => 'cap-z'],
        ]);

        $entry = $this->unblockEntryFor($result, 'cap-z');
        $this->assertSame([], $entry['do_not_repeat_packet_ids']);
    }
}
