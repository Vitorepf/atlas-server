<?php

declare(strict_types=1);

namespace Tests\Unit\Ai\AutonomousEvolution;

use App\Services\Ai\AutonomousEvolution\AtlasLoopAttemptLedger;
use App\Services\Ai\AutonomousEvolution\AtlasLoopLeapReceiptLedger;
use PHPUnit\Framework\TestCase;

final class AtlasLoopLeapReceiptLedgerTest extends TestCase
{
    public function test_record_then_read_links_task_packets_and_resolves_certified_outcome(): void
    {
        $attempts = new AtlasLoopAttemptLedger;
        $attempts->record('implement leap packet', 'codex', true, 'passed', 'sig');
        $ledger = new AtlasLoopLeapReceiptLedger;

        $result = $ledger->record($this->receipt(), $attempts);

        $this->assertTrue($result['recorded']);
        $stored = $ledger->all()[0];
        $this->assertSame('LeapReceipt', $stored['record_type']);
        $this->assertSame(['packet-a', 'packet-b'], $stored['task_packet_ids']);
        $this->assertSame('certified', $stored['outcome']);
        $this->assertSame(1, $stored['outcome_sources']['attempt_rounds']);
    }

    public function test_record_is_append_only_and_duplicate_leap_id_is_rejected(): void
    {
        $ledger = new AtlasLoopLeapReceiptLedger;
        $first = $ledger->record($this->receipt(['task_packet_ids' => ['packet-a']]));
        $second = $ledger->record($this->receipt(['task_packet_ids' => ['packet-mutated']]));

        $this->assertTrue($first['recorded']);
        $this->assertFalse($second['recorded']);
        $this->assertSame('already_recorded', $second['reason']);
        $this->assertSame(['packet-a'], $ledger->all()[0]['task_packet_ids']);
    }

    public function test_provider_safe_contract_strips_provider_keys_prompts_and_traces(): void
    {
        $ledger = new AtlasLoopLeapReceiptLedger;
        $ledger->record($this->receipt([
            'provider_key' => 'secret',
            'prompt' => 'raw prompt',
            'trace_id' => 'trace',
            'ambition_leap' => [
                'leap_id' => 'ambition_leap:loop',
                'gap_id' => 'frontier_gap:loop',
                'provider_key' => 'nested-secret',
                'prompt_text' => 'nested prompt',
            ],
        ]));

        $json = json_encode($ledger->all(), JSON_THROW_ON_ERROR);
        $this->assertStringNotContainsString('secret', $json);
        $this->assertStringNotContainsString('raw prompt', $json);
        $this->assertStringNotContainsString('trace', $json);
        $this->assertStringNotContainsString('nested prompt', $json);
    }

    public function test_read_api_queries_by_gap_id_and_outcome_status(): void
    {
        $ledger = new AtlasLoopLeapReceiptLedger;
        $passed = new AtlasLoopAttemptLedger;
        $passed->record('packet', 'codex', true);
        $failed = new AtlasLoopAttemptLedger;
        $failed->record('packet', 'codex', false, 'same');
        $failed->record('packet', 'codex', false, 'same');
        $failed->record('packet', 'codex', false, 'same');

        $ledger->record($this->receipt(['leap_id' => 'ambition_leap:a', 'gap_id' => 'frontier_gap:a']), $passed);
        $ledger->record($this->receipt(['leap_id' => 'ambition_leap:b', 'gap_id' => 'frontier_gap:b']), $failed);

        $this->assertCount(1, $ledger->byGapId('frontier_gap:a'));
        $this->assertSame('ambition_leap:a', $ledger->byOutcome('certified')[0]['leap_id']);
        $this->assertSame('ambition_leap:b', $ledger->byOutcome('abandoned')[0]['leap_id']);
    }

    /**
     * @param  array<string,mixed>  $overrides
     * @return array<string,mixed>
     */
    private function receipt(array $overrides = []): array
    {
        return array_merge([
            'leap_id' => 'ambition_leap:loop',
            'gap_id' => 'frontier_gap:loop',
            'ambition_leap' => [
                'leap_id' => 'ambition_leap:loop',
                'gap_id' => 'frontier_gap:loop',
                'target_path' => 'app/Services/Ai/AutonomousEvolution/AtlasLoopFrontierGapModel.php',
            ],
            'risk_verdict' => [
                'leap_id' => 'ambition_leap:loop',
                'status' => 'pass',
                'reasons' => [],
            ],
            'task_packet_ids' => ['packet-b', 'packet-a'],
            'target_path' => 'app/Services/Ai/AutonomousEvolution/AtlasLoopFrontierGapModel.php',
            'acceptance_criteria' => ['criterion one', 'criterion two'],
        ], $overrides);
    }
}
