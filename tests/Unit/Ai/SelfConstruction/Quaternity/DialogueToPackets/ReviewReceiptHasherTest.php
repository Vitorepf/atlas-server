<?php

declare(strict_types=1);

namespace Tests\Unit\Ai\SelfConstruction\Quaternity\DialogueToPackets;

use App\Services\Ai\SelfConstruction\Quaternity\DialogueToPackets\ReviewReceiptHasher;
use PHPUnit\Framework\TestCase;

final class ReviewReceiptHasherTest extends TestCase
{
    public function test_proposal_hash_is_stable_across_associative_key_order(): void
    {
        $a = ReviewReceiptHasher::proposalHash(['task_packet_id' => 't1', 'objective' => 'x', 'allowed_files' => ['a.php']]);
        $b = ReviewReceiptHasher::proposalHash(['allowed_files' => ['a.php'], 'objective' => 'x', 'task_packet_id' => 't1']);

        $this->assertSame($a, $b);
    }

    public function test_proposal_hash_is_stable_across_nested_key_order(): void
    {
        $a = ReviewReceiptHasher::proposalHash(['task_packet_id' => 't1', 'meta' => ['foo' => 'bar', 'baz' => 1]]);
        $b = ReviewReceiptHasher::proposalHash(['task_packet_id' => 't1', 'meta' => ['baz' => 1, 'foo' => 'bar']]);

        $this->assertSame($a, $b);
    }

    public function test_secret_like_fields_are_redacted_before_hashing(): void
    {
        $withSecret = ReviewReceiptHasher::proposalHash([
            'task_packet_id' => 't1',
            'api_key' => 'sk-live-abc123',
        ]);
        $withoutSecret = ReviewReceiptHasher::proposalHash([
            'task_packet_id' => 't1',
        ]);

        $this->assertSame($withoutSecret, $withSecret, 'secret-like fields must be redacted so hash is unaffected by their value');
    }

    public function test_secret_field_value_changes_do_not_alter_the_hash(): void
    {
        $a = ReviewReceiptHasher::proposalHash(['task_packet_id' => 't1', 'auth_token' => 'value-one']);
        $b = ReviewReceiptHasher::proposalHash(['task_packet_id' => 't1', 'auth_token' => 'value-two']);

        $this->assertSame($a, $b, 'redacted secret field values must not leak into the hash');
    }

    public function test_content_change_alters_the_receipt_hash(): void
    {
        $a = ReviewReceiptHasher::proposalHash(['task_packet_id' => 't1', 'objective' => 'do X']);
        $b = ReviewReceiptHasher::proposalHash(['task_packet_id' => 't1', 'objective' => 'do Y']);

        $this->assertNotSame($a, $b);
    }

    public function test_cortex_snapshot_hash_is_order_independent(): void
    {
        $a = ReviewReceiptHasher::cortexSnapshotHash(['sym-a', 'sym-b', 'sym-c']);
        $b = ReviewReceiptHasher::cortexSnapshotHash(['sym-c', 'sym-a', 'sym-b']);

        $this->assertSame($a, $b);
    }

    public function test_approval_hash_changes_when_inputs_change(): void
    {
        $a = ReviewReceiptHasher::approvalHash('proposal-hash', 'sig', 'receipt', '2026-06-25T00:00:00Z');
        $b = ReviewReceiptHasher::approvalHash('proposal-hash', 'sig', 'receipt', '2026-06-25T00:00:01Z');

        $this->assertNotSame($a, $b);
    }
}
