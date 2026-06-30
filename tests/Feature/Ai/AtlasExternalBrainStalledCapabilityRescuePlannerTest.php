<?php

declare(strict_types=1);

namespace Tests\Feature\Ai;

use App\Services\Ai\SelfConstruction\ExternalBrain\AtlasExternalBrainStalledCapabilityRescuePlanner;
use PHPUnit\Framework\TestCase;

final class AtlasExternalBrainStalledCapabilityRescuePlannerTest extends TestCase
{
    private AtlasExternalBrainStalledCapabilityRescuePlanner $planner;

    protected function setUp(): void
    {
        $this->planner = new AtlasExternalBrainStalledCapabilityRescuePlanner;
    }

    private function plan(array $caps): array
    {
        return $this->planner->plan($caps);
    }

    private function cap(array $overrides = []): array
    {
        return array_merge([
            'id'                          => 'cap-1',
            'state'                       => 'implemented',
            'stall_count'                 => 1,
            'replacement_owner_id'        => '',
            'replacement_owner_integrated' => false,
            'consumer_count'              => 0,
            'duplicate_owner_count'       => 0,
            'last_green_commit_age_days'  => 5,
            'outstanding_give_back_count' => 0,
            'implementation_completeness' => 0.7,
            'estimated_rescue_cost'       => 0.3,
            'impact_level'               => 'high',
            'is_progressing'             => false,
            'has_missing_file'           => false,
            'has_missing_test'           => false,
            'has_scope_gap'              => false,
            'has_contradiction'          => false,
        ], $overrides);
    }

    // ── AC1: stalled caps emit unblock actions with typed causes ──────────────

    public function test_stalled_cap_with_missing_file_emits_rescue_with_missing_file_cause(): void
    {
        $result = $this->plan([$this->cap(['has_missing_file' => true])]);

        $entry = $result['entries'][0];
        $this->assertSame(AtlasExternalBrainStalledCapabilityRescuePlanner::ACTION_RESCUE, $entry['action']);
        $this->assertSame('missing_file', $entry['unblock_cause']);
    }

    public function test_stalled_cap_with_missing_test_emits_rescue_with_missing_test_cause(): void
    {
        $result = $this->plan([$this->cap(['has_missing_test' => true])]);

        $entry = $result['entries'][0];
        $this->assertSame(AtlasExternalBrainStalledCapabilityRescuePlanner::ACTION_RESCUE, $entry['action']);
        $this->assertSame('missing_test', $entry['unblock_cause']);
    }

    public function test_stalled_cap_with_scope_gap_emits_rescue_with_scope_gap_cause(): void
    {
        $result = $this->plan([$this->cap(['has_scope_gap' => true])]);

        $entry = $result['entries'][0];
        $this->assertSame(AtlasExternalBrainStalledCapabilityRescuePlanner::ACTION_RESCUE, $entry['action']);
        $this->assertSame('scope_gap', $entry['unblock_cause']);
    }

    public function test_stalled_cap_with_contradiction_emits_rescue_with_contradiction_cause(): void
    {
        $result = $this->plan([$this->cap(['has_contradiction' => true])]);

        $entry = $result['entries'][0];
        $this->assertSame(AtlasExternalBrainStalledCapabilityRescuePlanner::ACTION_RESCUE, $entry['action']);
        $this->assertSame('contradiction', $entry['unblock_cause']);
    }

    public function test_contradiction_takes_priority_over_missing_file(): void
    {
        $result = $this->plan([$this->cap(['has_contradiction' => true, 'has_missing_file' => true])]);

        $this->assertSame('contradiction', $result['entries'][0]['unblock_cause']);
    }

    public function test_rescue_entry_always_includes_evidence_requirements(): void
    {
        $result = $this->plan([$this->cap(['has_missing_file' => true])]);

        $this->assertNotEmpty($result['entries'][0]['evidence_requirements']);
    }

    // ── AC2: progressing caps are not rescued just because they are old ────────

    public function test_progressing_cap_without_blocker_is_monitored_not_rescued(): void
    {
        $result = $this->plan([$this->cap([
            'is_progressing'            => true,
            'last_green_commit_age_days' => 45,  // old, but actively progressing
            'has_missing_file'          => false,
            'has_missing_test'          => false,
            'has_scope_gap'             => false,
            'has_contradiction'         => false,
        ])]);

        $this->assertSame(AtlasExternalBrainStalledCapabilityRescuePlanner::ACTION_MONITOR, $result['entries'][0]['action']);
    }

    public function test_progressing_cap_with_explicit_blocker_is_still_rescued(): void
    {
        $result = $this->plan([$this->cap([
            'is_progressing'  => true,
            'has_missing_file' => true,
        ])]);

        $this->assertSame(AtlasExternalBrainStalledCapabilityRescuePlanner::ACTION_RESCUE, $result['entries'][0]['action']);
    }

    public function test_non_progressing_cap_is_rescued_regardless_of_age(): void
    {
        $result = $this->plan([$this->cap([
            'is_progressing'            => false,
            'last_green_commit_age_days' => 5,
        ])]);

        $this->assertSame(AtlasExternalBrainStalledCapabilityRescuePlanner::ACTION_RESCUE, $result['entries'][0]['action']);
    }

    // ── AC3: rescue priority outranks new-task creation for high-impact/low-cost ─

    public function test_high_impact_low_cost_rescue_has_high_priority(): void
    {
        $result = $this->plan([$this->cap([
            'impact_level'        => 'high',
            'estimated_rescue_cost' => 0.3,
        ])]);

        $this->assertSame('high', $result['entries'][0]['rescue_priority']);
    }

    public function test_high_impact_high_cost_rescue_has_normal_priority(): void
    {
        $result = $this->plan([$this->cap([
            'impact_level'        => 'high',
            'estimated_rescue_cost' => 0.8,
        ])]);

        $this->assertSame('normal', $result['entries'][0]['rescue_priority']);
    }

    public function test_medium_impact_low_cost_rescue_has_normal_priority(): void
    {
        $result = $this->plan([$this->cap([
            'impact_level'        => 'medium',
            'estimated_rescue_cost' => 0.2,
        ])]);

        $this->assertSame('normal', $result['entries'][0]['rescue_priority']);
    }

    public function test_high_priority_rescue_count_increments_correctly(): void
    {
        $result = $this->plan([
            $this->cap(['id' => 'a', 'impact_level' => 'high', 'estimated_rescue_cost' => 0.1]),
            $this->cap(['id' => 'b', 'impact_level' => 'high', 'estimated_rescue_cost' => 0.1]),
            $this->cap(['id' => 'c', 'impact_level' => 'medium', 'estimated_rescue_cost' => 0.1]),
        ]);

        $this->assertSame(2, $result['high_priority_rescue_count']);
    }

    // ── AC4: pure and deterministic ───────────────────────────────────────────

    public function test_output_is_deterministic(): void
    {
        $caps = [$this->cap(['has_missing_file' => true])];

        $this->assertSame(json_encode($this->plan($caps)), json_encode($this->plan($caps)));
    }

    public function test_integrated_capabilities_are_skipped(): void
    {
        $result = $this->plan([$this->cap(['state' => 'integrated'])]);

        $this->assertSame(0, $result['total_evaluated']);
    }

    public function test_output_has_required_keys(): void
    {
        $result = $this->plan([$this->cap()]);

        foreach (['schema_version', 'total_evaluated', 'high_priority_rescue_count', 'entries', 'by_action'] as $key) {
            $this->assertArrayHasKey($key, $result, "Missing key: {$key}");
        }
        $this->assertSame(AtlasExternalBrainStalledCapabilityRescuePlanner::SCHEMA, $result['schema_version']);
    }
}
