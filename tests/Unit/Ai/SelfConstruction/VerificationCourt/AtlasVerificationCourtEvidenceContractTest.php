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
}
