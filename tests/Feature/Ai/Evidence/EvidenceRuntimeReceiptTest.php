<?php

namespace Tests\Feature\Ai\Evidence;

use App\Services\Ai\Evidence\EvidenceCanonicalHash;
use App\Services\Ai\Evidence\EvidencePackService;
use App\Services\Ai\Evidence\ReceiptService;
use Tests\Concerns\CreatesEvidenceRuntimeTables;
use Tests\TestCase;

class EvidenceRuntimeReceiptTest extends TestCase
{
    use CreatesEvidenceRuntimeTables;

    protected function setUp(): void
    {
        parent::setUp();
        $this->createEvidenceRuntimeTables();
    }

    protected function tearDown(): void
    {
        $this->dropEvidenceRuntimeTables();
        parent::tearDown();
    }

    public function test_receipt_hash_is_deterministic_for_same_canonical_input(): void
    {
        /** @var ReceiptService $svc */
        $svc = app(ReceiptService::class);

        $first = $svc->emit([
            'receipt_type' => ReceiptService::TYPE_TOOL_CALL,
            'action' => 'shell.exec',
            'target_type' => EvidencePackService::TARGET_TOOL_RUN,
            'target_id' => 'tool-run-A',
            'input_hash' => 'aaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa',
            'output_hash' => 'bbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbb',
            'status' => ReceiptService::STATUS_OK,
        ]);
        $second = $svc->emit([
            'receipt_type' => ReceiptService::TYPE_TOOL_CALL,
            'action' => 'shell.exec',
            'target_type' => EvidencePackService::TARGET_TOOL_RUN,
            'target_id' => 'tool-run-B',
            'input_hash' => 'aaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa',
            'output_hash' => 'bbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbb',
            'status' => ReceiptService::STATUS_OK,
        ]);

        $this->assertNotEmpty($first->receipt_hash);
        $this->assertNotEmpty($second->receipt_hash);
        $this->assertNotSame(
            $first->receipt_hash,
            $second->receipt_hash,
            'distinct canonical input (different target_id) must produce distinct hashes',
        );

        // Determinism: re-hashing the exact same canonical structure must reproduce the hash.
        $reproduced = EvidenceCanonicalHash::sha256([
            'receipt_type' => ReceiptService::TYPE_TOOL_CALL,
            'target_type' => EvidencePackService::TARGET_TOOL_RUN,
            'target_id' => 'tool-run-A',
            'mission_id' => null,
            'work_order_id' => null,
            'actor_type' => 'system',
            'action' => 'shell.exec',
            'input_hash' => 'aaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa',
            'output_hash' => 'bbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbb',
            'evidence_refs' => [],
            'status' => ReceiptService::STATUS_OK,
        ]);
        $this->assertSame($first->receipt_hash, $reproduced, 'receipt_hash must equal canonical sha256 of the same input.');
    }

    public function test_invalid_receipt_type_throws(): void
    {
        /** @var ReceiptService $svc */
        $svc = app(ReceiptService::class);
        $this->expectException(\InvalidArgumentException::class);

        $svc->emit([
            'receipt_type' => 'bogus',
            'action' => 'whatever',
        ]);
    }
}
