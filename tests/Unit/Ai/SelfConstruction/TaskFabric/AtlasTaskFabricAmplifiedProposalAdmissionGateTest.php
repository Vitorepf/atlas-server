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
        $id = (string) ($overrides['id'] ?? 'p1');

        return array_merge([
            'id'                        => $id,
            'is_compliance_compliant'   => true,
            'is_normalized'             => true,
            'replay_cleared'            => true,
            'is_duplicate'              => false,
            'task_fabric_ready'         => true,
            'structural_value_proof'    => "closes a real structural gap for {$id}",
            'implementation_target'     => "App\\Services\\Foo\\{$id}.php",
            'runnable_acceptance_proof' => "php artisan test --filter={$id}Test",
            'template_signature'        => "sig-{$id}",
            'impact_class'              => 'general',
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
        $this->assertArrayHasKey('value_blockers',     $r);
        $this->assertArrayHasKey('diversity_blockers', $r);
        $this->assertArrayHasKey('batch_action',       $r);
    }

    // ── new AC: value-proof checks reject weak-model scaffolds ────────────────

    public function test_missing_structural_value_proof_rejected_even_with_five_checks_passing(): void
    {
        $r = $this->gate()->evaluate(['proposals' => [
            $this->good(['structural_value_proof' => '']),
        ]]);

        $this->assertFalse($r['admit']);
        $this->assertContains('structural_value_check', $r['rejected_proposals'][0]['failed_checks']);
        $this->assertContains('structural_value_check', $r['value_blockers']);
    }

    public function test_missing_implementation_target_rejected(): void
    {
        $r = $this->gate()->evaluate(['proposals' => [
            $this->good(['implementation_target' => '']),
        ]]);

        $this->assertFalse($r['admit']);
        $this->assertContains('implementability_check', $r['rejected_proposals'][0]['failed_checks']);
        $this->assertContains('implementability_check', $r['value_blockers']);
    }

    public function test_missing_runnable_acceptance_proof_rejected(): void
    {
        $r = $this->gate()->evaluate(['proposals' => [
            $this->good(['runnable_acceptance_proof' => '']),
        ]]);

        $this->assertFalse($r['admit']);
        $this->assertContains('runnable_acceptance_check', $r['rejected_proposals'][0]['failed_checks']);
        $this->assertContains('runnable_acceptance_check', $r['value_blockers']);
    }

    public function test_value_blockers_empty_when_all_value_facts_present(): void
    {
        $r = $this->gate()->evaluate(['proposals' => [$this->good()]]);

        $this->assertTrue($r['admit']);
        $this->assertSame([], $r['value_blockers']);
    }

    // ── new AC: anti-template-flood diversity checks ──────────────────────────

    public function test_too_many_shared_template_signature_holds_overflow_for_repair(): void
    {
        // max_same_template_signature defaults to 2; a third proposal sharing the signature
        // is diversity-demoted even though it individually passes every other check.
        $r = $this->gate()->evaluate(['proposals' => [
            $this->good(['id' => 'p1', 'template_signature' => 'shared-sig']),
            $this->good(['id' => 'p2', 'template_signature' => 'shared-sig']),
            $this->good(['id' => 'p3', 'template_signature' => 'shared-sig']),
        ]]);

        $this->assertContains('p1', $r['admitted_proposals']);
        $this->assertContains('p2', $r['admitted_proposals']);
        $this->assertNotContains('p3', $r['admitted_proposals']);
        $rejectedIds = array_column($r['rejected_proposals'], 'id');
        $this->assertContains('p3', $rejectedIds);
        $this->assertContains('template_diversity_check', $r['diversity_blockers']);
    }

    public function test_too_many_shared_impact_class_holds_overflow_for_repair(): void
    {
        $r = $this->gate()->evaluate([
            'proposals' => [
                $this->good(['id' => 'p1', 'impact_class' => 'perf']),
                $this->good(['id' => 'p2', 'impact_class' => 'perf']),
            ],
            'max_same_impact_class' => 1,
        ]);

        $this->assertContains('p1', $r['admitted_proposals']);
        $this->assertNotContains('p2', $r['admitted_proposals']);
        $this->assertContains('impact_class_diversity_check', $r['diversity_blockers']);
    }

    public function test_diverse_template_signatures_all_admitted(): void
    {
        $r = $this->gate()->evaluate(['proposals' => [
            $this->good(['id' => 'p1', 'template_signature' => 'sig-a']),
            $this->good(['id' => 'p2', 'template_signature' => 'sig-b']),
            $this->good(['id' => 'p3', 'template_signature' => 'sig-c']),
        ]]);

        $this->assertTrue($r['admit']);
        $this->assertCount(3, $r['admitted_proposals']);
        $this->assertSame([], $r['diversity_blockers']);
    }

    // ── new AC: existing happy-path proposals still admit with new facts ─────

    public function test_happy_path_proposal_with_new_value_and_diversity_facts_still_admitted(): void
    {
        $r = $this->gate()->evaluate(['proposals' => [$this->good()]]);

        $this->assertTrue($r['admit']);
        $this->assertContains('p1', $r['admitted_proposals']);
        $this->assertSame('submit', $r['batch_action']);
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
