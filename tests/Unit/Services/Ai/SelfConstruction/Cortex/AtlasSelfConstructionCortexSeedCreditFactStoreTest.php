<?php

declare(strict_types=1);

namespace Tests\Unit\Services\Ai\SelfConstruction\Cortex;

use App\Services\Ai\SelfConstruction\Cortex\AtlasSelfConstructionCortexSeedCreditFactStore;
use Tests\TestCase;

final class AtlasSelfConstructionCortexSeedCreditFactStoreTest extends TestCase
{
    private function store(): AtlasSelfConstructionCortexSeedCreditFactStore
    {
        return new AtlasSelfConstructionCortexSeedCreditFactStore;
    }

    // ── AC: credited rounds are stored with distinct reason codes ──

    public function test_credited_round_stored(): void
    {
        $result = $this->store()->store([
            'round_id' => 'r1',
            'originator_id' => 'orig-1',
            'verdict' => 'credited',
            'credits_granted' => 1,
            'emitted_targets' => ['impl', 'test'],
        ]);

        $this->assertSame('credited', $result['fact_type']);
        $this->assertSame('clean_enqueue_healthy_gates', $result['reason_code']);
    }

    // ── AC: rejected rounds are stored with distinct reason codes ──

    public function test_rejected_round_stored(): void
    {
        $result = $this->store()->store([
            'round_id' => 'r2',
            'originator_id' => 'orig-1',
            'verdict' => 'denied',
            'denial_reasons' => ['enqueue_not_successful:rejected'],
            'credits_granted' => 0,
        ]);

        $this->assertSame('rejected', $result['fact_type']);
        $this->assertStringContainsString('enqueue_not_successful', $result['reason_code']);
    }

    // ── AC: operationally blocked rounds are stored with distinct reason codes ──

    public function test_operationally_blocked_round_stored(): void
    {
        $result = $this->store()->store([
            'round_id' => 'r3',
            'originator_id' => 'orig-1',
            'verdict' => 'denied',
            'denial_reasons' => ['post_round_health_not_healthy:degraded'],
            'credits_granted' => 0,
        ]);

        $this->assertSame('operationally_blocked', $result['fact_type']);
    }

    // ── provider-safe ──

    public function test_fact_is_provider_safe(): void
    {
        $result = $this->store()->store([
            'round_id' => 'r4',
            'verdict' => 'credited',
        ]);

        $this->assertTrue($result['provider_safe']);
    }

    // ── output structure ──

    public function test_output_has_required_keys(): void
    {
        $result = $this->store()->store([]);

        $this->assertSame(AtlasSelfConstructionCortexSeedCreditFactStore::SCHEMA, $result['schema_version']);
        $this->assertArrayHasKey('fact_type', $result);
        $this->assertArrayHasKey('reason_code', $result);
        $this->assertArrayHasKey('credits_granted', $result);
    }
}
