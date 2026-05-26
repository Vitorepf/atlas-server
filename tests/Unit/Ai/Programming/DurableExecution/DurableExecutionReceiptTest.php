<?php

declare(strict_types=1);

namespace Tests\Unit\Ai\Programming\DurableExecution;

use App\Services\Ai\Programming\DurableExecution\DurableExecutionReceipt;
use Tests\TestCase;

final class DurableExecutionReceiptTest extends TestCase
{
    private DurableExecutionReceipt $service;

    protected function setUp(): void
    {
        parent::setUp();
        $this->service = new DurableExecutionReceipt;
    }

    private function decisionFixture(): array
    {
        return [
            'decision' => 'execute_durable',
            'actor' => 'operator:vitor',
            'decided_at' => '2026-05-26T00:00:00+00:00',
            'work_item_id' => 'wi-1',
            'plan_hash' => 'sha:plan',
            'spec_hash' => 'sha:spec',
        ];
    }

    public function test_emits_canonical_schema(): void
    {
        $r = $this->service->issue('success', $this->decisionFixture());

        $this->assertSame('atlas.programming.durable_execution_receipt.v1', $r['schema_version']);
        $this->assertSame('AP-285', $r['ap_reference']);
        $this->assertSame('success', $r['outcome']);
    }

    public function test_success_helper(): void
    {
        $r = $this->service->success($this->decisionFixture(), durationMs: 1234, repairAttempts: 1);

        $this->assertSame('success', $r['outcome']);
        $this->assertSame(1234, $r['metrics']['duration_ms']);
        $this->assertSame(1, $r['metrics']['repair_attempts']);
    }

    public function test_failure_helper_carries_signature(): void
    {
        $r = $this->service->failure($this->decisionFixture(), 'phpunit:FooTest::test_bar', 567);

        $this->assertSame('failed', $r['outcome']);
        $this->assertSame('phpunit:FooTest::test_bar', $r['failure_signature']);
        $this->assertSame(567, $r['metrics']['duration_ms']);
    }

    public function test_receipt_hash_is_deterministic_for_same_input(): void
    {
        // Lock timestamp so the hash is comparable across two issues.
        $this->travelTo('2026-05-26T12:00:00+00:00');
        $r1 = $this->service->issue('success', $this->decisionFixture());
        $r2 = $this->service->issue('success', $this->decisionFixture());

        $this->assertSame($r1['receipt_hash'], $r2['receipt_hash']);
    }

    public function test_decision_ref_carries_canonical_links(): void
    {
        $r = $this->service->issue('escalated_to_forge', $this->decisionFixture());

        $this->assertSame('execute_durable', $r['decision_ref']['decision']);
        $this->assertSame('operator:vitor', $r['decision_ref']['actor']);
        $this->assertSame('2026-05-26T00:00:00+00:00', $r['decision_ref']['decided_at']);
    }

    public function test_rejects_unknown_outcome(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('Outcome must be one of');

        $this->service->issue('mostly_ok', $this->decisionFixture());
    }

    public function test_all_canonical_outcomes_accepted(): void
    {
        foreach (DurableExecutionReceipt::ALLOWED_OUTCOMES as $outcome) {
            $r = $this->service->issue($outcome, $this->decisionFixture());
            $this->assertSame($outcome, $r['outcome']);
        }
    }

    public function test_providers_consulted_only_strings(): void
    {
        $r = $this->service->issue('success', $this->decisionFixture(), [
            'providers_consulted' => ['claude_cli', '', 42, null, 'codex_cli'],
        ]);

        $this->assertSame(['claude_cli', 'codex_cli'], $r['metrics']['providers_consulted']);
    }

    public function test_envelope_carries_provider_safe_flag_true(): void
    {
        $r = $this->service->issue('success', $this->decisionFixture());

        $this->assertTrue($r['provider_safe']);
    }
}
