<?php

declare(strict_types=1);

namespace Tests\Unit\Ai\SelfConstruction\TaskFabric;

use App\Services\Ai\SelfConstruction\TaskFabric\AtlasTaskFabricAmplifiedProposalAdmissionGate;
use PHPUnit\Framework\TestCase;

final class AtlasTaskFabricAmplifiedProposalAdmissionGateTest extends TestCase
{
    private function gate(): AtlasTaskFabricAmplifiedProposalAdmissionGate
    {
        return new AtlasTaskFabricAmplifiedProposalAdmissionGate;
    }

    private function good(array $overrides = []): array
    {
        return array_merge([
            'id'                       => 'p1',
            'is_compliance_compliant'  => true,
            'is_normalized'            => true,
            'replay_cleared'           => true,
            'is_duplicate'             => false,
            'task_fabric_ready'        => true,
        ], $overrides);
    }

    // ── AC4: output shape ─────────────────────────────────────────────────────

    public function test_output_has_required_keys(): void
    {
        $r = $this->gate()->evaluate([]);
        $this->assertSame(AtlasTaskFabricAmplifiedProposalAdmissionGate::SCHEMA, $r['schema_version']);
        $this->assertArrayHasKey('admit', $r);
        $this->assertArrayHasKey('admitted_proposals', $r);
        $this->assertArrayHasKey('rejected_proposals', $r);
        $this->assertArrayHasKey('blocking_checks',    $r);
        $this->assertArrayHasKey('batch_action',       $r);
    }

    // ── AC3: admit=true path ──────────────────────────────────────────────────

    public function test_all_passing_proposals_admitted(): void
    {
        $r = $this->gate()->evaluate(['proposals' => [$this->good()]]);
        $this->assertTrue($r['admit']);
        $this->assertContains('p1', $r['admitted_proposals']);
        $this->assertEmpty($r['rejected_proposals']);
        $this->assertSame('submit', $r['batch_action']);
    }

    // ── AC2: compliance check ─────────────────────────────────────────────────

    public function test_non_compliant_proposal_rejected(): void
    {
        $r = $this->gate()->evaluate(['proposals' => [
            $this->good(['is_compliance_compliant' => false]),
        ]]);
        $this->assertFalse($r['admit']);
        $this->assertContains('compliance_check', $r['rejected_proposals'][0]['failed_checks']);
        $this->assertContains('compliance_check', $r['blocking_checks']);
    }

    // ── AC2: normalization check ──────────────────────────────────────────────

    public function test_non_normalized_proposal_rejected(): void
    {
        $r = $this->gate()->evaluate(['proposals' => [
            $this->good(['is_normalized' => false]),
        ]]);
        $this->assertContains('normalization_check', $r['rejected_proposals'][0]['failed_checks']);
    }

    // ── AC2: replay check ─────────────────────────────────────────────────────

    public function test_replay_not_cleared_rejected(): void
    {
        $r = $this->gate()->evaluate(['proposals' => [
            $this->good(['replay_cleared' => false]),
        ]]);
        $this->assertContains('replay_check', $r['rejected_proposals'][0]['failed_checks']);
    }

    // ── AC2: deduplication check ──────────────────────────────────────────────

    public function test_duplicate_proposal_rejected(): void
    {
        $r = $this->gate()->evaluate(['proposals' => [
            $this->good(['is_duplicate' => true]),
        ]]);
        $this->assertContains('deduplication_check', $r['rejected_proposals'][0]['failed_checks']);
        $this->assertContains('deduplication_check', $r['blocking_checks']);
    }

    // ── AC2: task-fabric check ────────────────────────────────────────────────

    public function test_task_fabric_not_ready_rejected(): void
    {
        $r = $this->gate()->evaluate(['proposals' => [
            $this->good(['task_fabric_ready' => false]),
        ]]);
        $this->assertContains('task_fabric_check', $r['rejected_proposals'][0]['failed_checks']);
    }

    // ── AC2: multiple failures reported ──────────────────────────────────────

    public function test_multiple_failures_all_reported(): void
    {
        $r = $this->gate()->evaluate(['proposals' => [
            $this->good(['is_normalized' => false, 'replay_cleared' => false]),
        ]]);
        $failed = $r['rejected_proposals'][0]['failed_checks'];
        $this->assertContains('normalization_check', $failed);
        $this->assertContains('replay_check',        $failed);
    }

    // ── batch checks (AC3) ────────────────────────────────────────────────────

    public function test_batch_below_min_size_yields_no_admit(): void
    {
        // batch_min_size=3 but only 1 proposal passes → admit=false.
        $r = $this->gate()->evaluate([
            'proposals'      => [$this->good()],
            'batch_min_size' => 3,
        ]);
        $this->assertFalse($r['admit']);
        $this->assertSame('hold_for_repair', $r['batch_action']);
        $this->assertNotEmpty($r['admitted_proposals']); // proposal itself is OK
    }

    public function test_batch_exceeds_max_size_yields_no_admit(): void
    {
        $proposals = array_map(fn ($i) => $this->good(['id' => "p$i"]), range(1, 5));
        $r = $this->gate()->evaluate([
            'proposals'      => $proposals,
            'batch_max_size' => 3,
        ]);
        $this->assertFalse($r['admit']);
        $this->assertSame('discard', $r['batch_action']);
    }

    // ── blocking_checks union ─────────────────────────────────────────────────

    public function test_blocking_checks_union_across_rejected(): void
    {
        $r = $this->gate()->evaluate(['proposals' => [
            $this->good(['is_compliance_compliant' => false]),
            $this->good(['id' => 'p2', 'is_duplicate' => true]),
        ]]);
        $this->assertContains('compliance_check',   $r['blocking_checks']);
        $this->assertContains('deduplication_check', $r['blocking_checks']);
    }

    // ── batch_action ──────────────────────────────────────────────────────────

    public function test_all_rejected_yields_discard(): void
    {
        $r = $this->gate()->evaluate(['proposals' => [
            $this->good(['is_compliance_compliant' => false]),
        ]]);
        $this->assertSame('discard', $r['batch_action']);
    }

    // ── Determinism ───────────────────────────────────────────────────────────

    public function test_output_is_deterministic(): void
    {
        $facts = ['proposals' => [
            $this->good(['id' => 'a']),
            $this->good(['id' => 'b', 'replay_cleared' => false]),
        ]];
        $a = $this->gate()->evaluate($facts);
        $b = $this->gate()->evaluate($facts);
        $this->assertSame(json_encode($a), json_encode($b));
    }
}
