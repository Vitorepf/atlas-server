<?php

declare(strict_types=1);

namespace Tests\Unit\Ai\SelfConstruction\Maestro\MultiProvider;

use App\Services\Ai\SelfConstruction\Maestro\MultiProvider\AtlasMaestroAssignmentReceiptLedger;
use App\Services\Ai\SelfConstruction\Maestro\MultiProvider\AtlasMaestroPacketClassifier;
use Tests\TestCase;

final class AtlasMaestroAssignmentReceiptLedgerTest extends TestCase
{
    private string $path;

    protected function setUp(): void
    {
        parent::setUp();
        $this->path = sys_get_temp_dir().'/atlas-maestro-assignment-ledger-new-'.bin2hex(random_bytes(5)).'.jsonl';
    }

    protected function tearDown(): void
    {
        if (is_file($this->path)) {
            unlink($this->path);
        }
        parent::tearDown();
    }

    public function test_receipt_includes_worker_id_task_family_routing_reason_and_outcome(): void
    {
        $ledger = $this->ledger();
        $ledger->record($this->receipt('p1', workerId: 'worker-a', taskFamily: 'refactor', routingReason: 'lowest_cost_eligible'));

        $row = $ledger->recent(1)[0];

        $this->assertSame('worker-a', $row['worker_id']);
        $this->assertSame('refactor', $row['task_family']);
        $this->assertSame('lowest_cost_eligible', $row['routing_reason']);
        $this->assertSame('assigned', $row['outcome']);
    }

    public function test_recent_returns_newest_assignment_receipts_first(): void
    {
        $ledger = $this->ledger();
        $ledger->record($this->receipt('p1', workerId: 'worker-a'));
        $ledger->record($this->receipt('p2', workerId: 'worker-b', outcome: 'succeeded'));
        $ledger->record($this->receipt('p3', workerId: 'worker-c', outcome: 'failed_over'));

        $recent = $ledger->recentNewestFirst(2);

        $this->assertSame(['p3', 'p2'], array_column($recent, 'task_packet_id'));
    }

    public function test_verify_chain_fails_after_receipt_tampering(): void
    {
        $ledger = $this->ledger();
        $ledger->record($this->receipt('p1', workerId: 'worker-a'));
        $ledger->record($this->receipt('p2', workerId: 'worker-b', outcome: 'succeeded'));

        $this->assertTrue($ledger->verifyChain());

        $lines = file($this->path, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
        $second = json_decode($lines[1], true);
        $second['worker_id'] = 'tampered-worker'; // mutate payload without recomputing hash
        file_put_contents($this->path, $lines[0].PHP_EOL.json_encode($second).PHP_EOL);

        $this->assertFalse($ledger->verifyChain(), 'tampered receipt content must break chain verification');
    }

    private function ledger(): AtlasMaestroAssignmentReceiptLedger
    {
        return new AtlasMaestroAssignmentReceiptLedger($this->path);
    }

    /**
     * @return array<string,mixed>
     */
    private function receipt(
        string $packetId,
        string $outcome = 'assigned',
        ?string $workerId = null,
        ?string $taskFamily = null,
        ?string $routingReason = null,
    ): array {
        return [
            'task_packet_id' => $packetId,
            'classified_class' => AtlasMaestroPacketClassifier::GRIND,
            'classifier_rule_id' => AtlasMaestroPacketClassifier::REASON_GRIND,
            'primary_provider' => 'minimax-m3',
            'fallback_chain' => ['glm-5-2'],
            'outcome' => $outcome,
            'wall_clock_iso8601' => '2026-06-24T12:00:00+00:00',
            'worker_id' => $workerId,
            'task_family' => $taskFamily,
            'routing_reason' => $routingReason,
        ];
    }
}
