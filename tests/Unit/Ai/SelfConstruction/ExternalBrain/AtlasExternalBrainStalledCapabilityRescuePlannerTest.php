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
}
