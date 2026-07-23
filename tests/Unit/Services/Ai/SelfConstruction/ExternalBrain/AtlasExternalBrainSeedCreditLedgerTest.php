<?php

declare(strict_types=1);

namespace Tests\Unit\Services\Ai\SelfConstruction\ExternalBrain;

use App\Services\Ai\SelfConstruction\ExternalBrain\AtlasExternalBrainSeedCreditLedger;
use Tests\TestCase;

final class AtlasExternalBrainSeedCreditLedgerTest extends TestCase
{
    private function ledger(): AtlasExternalBrainSeedCreditLedger
    {
        return new AtlasExternalBrainSeedCreditLedger;
    }

    private function validRecord(): array
    {
        return [
            'originator_id' => 'orig-1',
            'round_id' => 'round-1',
            'enqueue_status' => 'success',
            'post_round_health' => 'healthy',
            'malformed_blockers' => [],
            'target_collisions' => [],
            'emitted_targets' => ['implementation', 'testing'],
        ];
    }

    // ── AC: credits granted only for successful enqueue with healthy task health ──

    public function test_credits_granted_for_successful_enqueue_with_healthy_health(): void
    {
        $result = $this->ledger()->evaluate($this->validRecord());

        $this->assertSame('granted', $result['credit_status']);
        $this->assertSame(1, $result['credits_granted']);
        $this->assertSame([], $result['denial_reasons']);
    }

    public function test_credits_denied_for_failed_enqueue(): void
    {
        $result = $this->ledger()->evaluate(array_merge($this->validRecord(), [
            'enqueue_status' => 'rejected',
        ]));

        $this->assertSame('denied', $result['credit_status']);
        $this->assertSame(0, $result['credits_granted']);
        $this->assertNotEmpty($result['denial_reasons']);
    }

    public function test_credits_denied_for_unhealthy_post_round_health(): void
    {
        $result = $this->ledger()->evaluate(array_merge($this->validRecord(), [
            'post_round_health' => 'degraded',
        ]));

        $this->assertSame('denied', $result['credit_status']);
        $this->assertNotEmpty($result['denial_reasons']);
    }

    // ── AC: denied for malformed blockers ──

    public function test_credits_denied_for_malformed_blockers(): void
    {
        $result = $this->ledger()->evaluate(array_merge($this->validRecord(), [
            'malformed_blockers' => ['missing_scope'],
        ]));

        $this->assertSame('denied', $result['credit_status']);
        $this->assertNotEmpty($result['denial_reasons']);
    }

    // ── AC: denied for new target collisions ──

    public function test_credits_denied_for_new_target_collision(): void
    {
        $result = $this->ledger()->evaluate(array_merge($this->validRecord(), [
            'target_collisions' => [
                ['target_family' => 'implementation', 'severity' => 'critical'],
            ],
        ]));

        $this->assertSame('denied', $result['credit_status']);
        $this->assertContains('implementation', $result['colliding_targets']);
    }

    public function test_credits_granted_when_collision_on_unrelated_target(): void
    {
        $result = $this->ledger()->evaluate(array_merge($this->validRecord(), [
            'target_collisions' => [
                ['target_family' => 'unrelated_family', 'severity' => 'critical'],
            ],
        ]));

        $this->assertSame('granted', $result['credit_status']);
    }

    // ── output structure ──

    public function test_output_has_required_keys(): void
    {
        $result = $this->ledger()->evaluate($this->validRecord());

        $this->assertSame(AtlasExternalBrainSeedCreditLedger::SCHEMA, $result['schema_version']);
        $this->assertArrayHasKey('credit_status', $result);
        $this->assertArrayHasKey('credits_granted', $result);
        $this->assertArrayHasKey('denial_reasons', $result);
    }

    public function test_batch_evaluation(): void
    {
        $result = $this->ledger()->evaluateBatch([
            $this->validRecord(),
            array_merge($this->validRecord(), ['enqueue_status' => 'failed']),
        ]);

        $this->assertSame(2, $result['total_records']);
        $this->assertSame(1, $result['total_credits_granted']);
    }

    public function test_result_is_deterministic(): void
    {
        $record = $this->validRecord();
        $a = $this->ledger()->evaluate($record);
        $b = $this->ledger()->evaluate($record);

        $this->assertSame(json_encode($a), json_encode($b));
    }
}
