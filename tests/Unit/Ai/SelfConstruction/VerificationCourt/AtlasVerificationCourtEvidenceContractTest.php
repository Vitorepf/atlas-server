<?php

declare(strict_types=1);

namespace Tests\Unit\Ai\SelfConstruction\VerificationCourt;

use App\Services\Ai\SelfConstruction\VerificationCourt\AtlasVerificationCourtEvidenceContract;
use PHPUnit\Framework\TestCase;

final class AtlasVerificationCourtEvidenceContractTest extends TestCase
{
    private AtlasVerificationCourtEvidenceContract $contract;

    protected function setUp(): void
    {
        parent::setUp();
        $this->contract = new AtlasVerificationCourtEvidenceContract();
    }

    // AC: missing receipt_chain → rejected
    public function test_missing_receipt_chain_rejected(): void
    {
        $result = $this->contract->verify(
            ['task_packet_id' => 't1', 'lease_id' => 'l1', 'allowed_files_hash' => 'fh1', 'command_hash' => 'ch1'],
            []
        );

        $this->assertFalse($result['accepted']);
        $this->assertContains('missing:receipt_chain', $result['blockers']);
    }

    // AC: chain fields mismatch → rejected with named blockers
    public function test_task_packet_id_mismatch_rejected(): void
    {
        $result = $this->contract->verify(
            ['task_packet_id' => 't1', 'lease_id' => 'l1', 'allowed_files_hash' => 'fh1', 'command_hash' => 'ch1'],
            ['receipt_chain' => ['task_packet_id' => 't2', 'lease_id' => 'l1', 'allowed_files_hash' => 'fh1', 'command_hash' => 'ch1']]
        );

        $this->assertFalse($result['accepted']);
        $this->assertContains('mismatch:task_packet_id', $result['blockers']);
    }

    public function test_lease_id_mismatch_rejected(): void
    {
        $result = $this->contract->verify(
            ['task_packet_id' => 't1', 'lease_id' => 'l1', 'allowed_files_hash' => 'fh1', 'command_hash' => 'ch1'],
            ['receipt_chain' => ['task_packet_id' => 't1', 'lease_id' => 'l2', 'allowed_files_hash' => 'fh1', 'command_hash' => 'ch1']]
        );

        $this->assertFalse($result['accepted']);
        $this->assertContains('mismatch:lease_id', $result['blockers']);
    }

    public function test_allowed_files_hash_mismatch_rejected(): void
    {
        $result = $this->contract->verify(
            ['task_packet_id' => 't1', 'lease_id' => 'l1', 'allowed_files_hash' => 'fh1', 'command_hash' => 'ch1'],
            ['receipt_chain' => ['task_packet_id' => 't1', 'lease_id' => 'l1', 'allowed_files_hash' => 'fh2', 'command_hash' => 'ch1']]
        );

        $this->assertFalse($result['accepted']);
        $this->assertContains('mismatch:allowed_files_hash', $result['blockers']);
    }

    public function test_command_hash_mismatch_rejected(): void
    {
        $result = $this->contract->verify(
            ['task_packet_id' => 't1', 'lease_id' => 'l1', 'allowed_files_hash' => 'fh1', 'command_hash' => 'ch1'],
            ['receipt_chain' => ['task_packet_id' => 't1', 'lease_id' => 'l1', 'allowed_files_hash' => 'fh1', 'command_hash' => 'ch2']]
        );

        $this->assertFalse($result['accepted']);
        $this->assertContains('mismatch:command_hash', $result['blockers']);
    }

    public function test_all_fields_match_accepted(): void
    {
        $result = $this->contract->verify(
            ['task_packet_id' => 't1', 'lease_id' => 'l1', 'allowed_files_hash' => 'fh1', 'command_hash' => 'ch1'],
            ['receipt_chain' => ['task_packet_id' => 't1', 'lease_id' => 'l1', 'allowed_files_hash' => 'fh1', 'command_hash' => 'ch1']]
        );

        $this->assertTrue($result['accepted']);
        $this->assertEmpty($result['blockers']);
    }

    public function test_missing_field_in_chain_is_blocked(): void
    {
        $result = $this->contract->verify(
            ['task_packet_id' => 't1', 'lease_id' => 'l1', 'allowed_files_hash' => 'fh1', 'command_hash' => 'ch1'],
            ['receipt_chain' => ['task_packet_id' => 't1', 'lease_id' => 'l1']]
        );

        $this->assertFalse($result['accepted']);
        $this->assertContains('missing_in_chain:allowed_files_hash', $result['blockers']);
        $this->assertContains('missing_in_chain:command_hash', $result['blockers']);
    }

    // AC: claims_autonomous_execution_quality fails without runtime_owner=atlas_native
    public function test_autonomous_execution_quality_fails_without_atlas_native_runtime_owner(): void
    {
        $result = $this->contract->verify(
            ['task_packet_id' => 't1', 'lease_id' => 'l1', 'allowed_files_hash' => 'fh1', 'command_hash' => 'ch1', 'claims_autonomous_execution_quality' => true],
            [
                'receipt_chain' => ['task_packet_id' => 't1', 'lease_id' => 'l1', 'allowed_files_hash' => 'fh1', 'command_hash' => 'ch1'],
                'runtime_owner' => 'provider_owned',
                'runnable_proof' => true,
                'evidence_age_seconds' => 60,
            ]
        );

        $this->assertFalse($result['accepted']);
        $this->assertContains('blocker:runtime_owner_not_atlas_native', $result['blockers']);
    }

    // AC: runnable proof and freshness both required for court_admissible=true
    public function test_runnable_proof_and_freshness_both_required_for_court_admissible(): void
    {
        $result = $this->contract->verify(
            ['task_packet_id' => 't1', 'lease_id' => 'l1', 'allowed_files_hash' => 'fh1', 'command_hash' => 'ch1', 'claims_autonomous_execution_quality' => true],
            [
                'receipt_chain' => ['task_packet_id' => 't1', 'lease_id' => 'l1', 'allowed_files_hash' => 'fh1', 'command_hash' => 'ch1'],
                'runtime_owner' => 'atlas_native',
                'runnable_proof' => true,
                'evidence_age_seconds' => 60,
            ]
        );

        $this->assertTrue($result['accepted']);
        $this->assertTrue($result['court_admissible']);
    }

    // AC: provider-owned or stale evidence emits blocker reasons
    public function test_provider_owned_or_stale_evidence_emits_blocker_reasons(): void
    {
        $result = $this->contract->verify(
            ['task_packet_id' => 't1', 'lease_id' => 'l1', 'allowed_files_hash' => 'fh1', 'command_hash' => 'ch1', 'claims_autonomous_execution_quality' => true],
            [
                'receipt_chain' => ['task_packet_id' => 't1', 'lease_id' => 'l1', 'allowed_files_hash' => 'fh1', 'command_hash' => 'ch1'],
                'runtime_owner' => 'atlas_native',
                'runnable_proof' => false,
                'evidence_age_seconds' => 7200,
            ]
        );

        $this->assertFalse($result['accepted']);
        $this->assertContains('blocker:missing_runnable_proof', $result['blockers']);
        $this->assertContains('blocker:evidence_stale', $result['blockers']);
        $this->assertFalse($result['court_admissible']);
    }
}
