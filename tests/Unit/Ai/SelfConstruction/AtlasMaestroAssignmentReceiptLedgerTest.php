<?php

declare(strict_types=1);

namespace Tests\Unit\Ai\SelfConstruction;

use App\Services\Ai\SelfConstruction\Maestro\MultiProvider\AtlasMaestroAssignmentReceiptLedger;
use App\Services\Ai\SelfConstruction\Maestro\MultiProvider\AtlasMaestroPacketClassifier;
use Tests\TestCase;

final class AtlasMaestroAssignmentReceiptLedgerTest extends TestCase
{
    private string $path;

    protected function setUp(): void
    {
        parent::setUp();
        $this->path = sys_get_temp_dir().'/atlas-maestro-assignment-ledger-'.bin2hex(random_bytes(5)).'.jsonl';
    }

    protected function tearDown(): void
    {
        if (is_file($this->path)) {
            unlink($this->path);
        }
        parent::tearDown();
    }

    public function test_record_appends_valid_json_line_and_returns_deterministic_sha256(): void
    {
        $ledger = $this->ledger();
        $receipt = $this->receipt('packet-1');

        $first = $ledger->record($receipt);
        $second = $ledger->record($receipt);

        $this->assertMatchesRegularExpression('/^[a-f0-9]{64}$/', $first);
        $this->assertSame($first, $second);
        $lines = file($this->path, FILE_IGNORE_NEW_LINES);
        $this->assertCount(2, $lines);
        foreach ($lines as $line) {
            $row = json_decode($line, true);
            $this->assertIsArray($row);
            $this->assertSame($first, $row['receipt_hash']);
        }
    }

    public function test_recent_returns_tail_in_insertion_order_without_rewriting_prior_lines(): void
    {
        $ledger = $this->ledger();
        $ledger->record($this->receipt('packet-1'));
        $ledger->record($this->receipt('packet-2', outcome: 'succeeded'));
        $ledger->record($this->receipt('packet-3', outcome: 'failed_over', fallbackUsed: 'claude-opus'));

        $this->assertCount(3, file($this->path, FILE_IGNORE_NEW_LINES));
        $recent = $ledger->recent(2);

        $this->assertSame(['packet-2', 'packet-3'], array_column($recent, 'task_packet_id'));
        $this->assertSame(['succeeded', 'failed_over'], array_column($recent, 'outcome'));
        $this->assertSame('claude-opus', $recent[1]['fallback_used']);
    }

    private function ledger(): AtlasMaestroAssignmentReceiptLedger
    {
        return new AtlasMaestroAssignmentReceiptLedger($this->path);
    }

    /**
     * @return array<string,mixed>
     */
    private function receipt(string $packetId, string $outcome = 'assigned', ?string $fallbackUsed = null): array
    {
        return [
            'task_packet_id' => $packetId,
            'classified_class' => AtlasMaestroPacketClassifier::GRIND,
            'classifier_rule_id' => AtlasMaestroPacketClassifier::REASON_GRIND,
            'primary_provider' => 'minimax-m3',
            'fallback_chain' => ['glm-5-2'],
            'fallback_used' => $fallbackUsed,
            'outcome' => $outcome,
            'wall_clock_iso8601' => '2026-06-24T12:00:00+00:00',
        ];
    }
}
