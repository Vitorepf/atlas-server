<?php

declare(strict_types=1);

namespace Tests\Unit\Services\Ai\SelfConstruction\Maestro\Health;

use App\Services\Ai\SelfConstruction\Maestro\Health\AtlasMaestroHealthGateSeedCreditBridge;
use Tests\TestCase;

final class AtlasMaestroHealthGateSeedCreditBridgeTest extends TestCase
{
    private function bridge(): AtlasMaestroHealthGateSeedCreditBridge
    {
        return new AtlasMaestroHealthGateSeedCreditBridge;
    }

    private function cleanInput(): array
    {
        return [
            'originator_id' => 'orig-1',
            'round_id' => 'round-1',
            'health' => ['healthy' => true, 'status' => 'healthy'],
            'malformed_blockers' => [],
            'emitted_targets' => ['implementation', 'testing'],
            'target_collisions' => [],
        ];
    }

    // ── AC: healthy clean rounds grant credit ──

    public function test_healthy_clean_round_grants_credit(): void
    {
        $result = $this->bridge()->bridge($this->cleanInput());

        $this->assertSame('credited', $result['verdict']);
        $this->assertSame(1, $result['credits_granted']);
        $this->assertSame([], $result['denial_reasons']);
    }

    // ── AC: unhealthy health denies credit ──

    public function test_unhealthy_health_denies_credit(): void
    {
        $result = $this->bridge()->bridge(array_merge($this->cleanInput(), [
            'health' => ['healthy' => false, 'status' => 'degraded'],
        ]));

        $this->assertSame('denied', $result['verdict']);
        $this->assertSame(0, $result['credits_granted']);
        $this->assertNotEmpty($result['denial_reasons']);
    }

    // ── AC: malformed blockers deny credit ──

    public function test_malformed_blockers_deny_credit(): void
    {
        $result = $this->bridge()->bridge(array_merge($this->cleanInput(), [
            'malformed_blockers' => ['missing_scope'],
        ]));

        $this->assertSame('denied', $result['verdict']);
        $this->assertSame(0, $result['credits_granted']);
    }

    // ── AC: emitted-target collisions deny credit ──

    public function test_emitted_target_collision_denies_credit(): void
    {
        $result = $this->bridge()->bridge(array_merge($this->cleanInput(), [
            'target_collisions' => [
                ['target_family' => 'implementation', 'severity' => 'critical'],
            ],
        ]));

        $this->assertSame('denied', $result['verdict']);
        $this->assertContains('implementation', $result['colliding_targets']);
    }

    public function test_collision_on_unrelated_target_does_not_deny(): void
    {
        $result = $this->bridge()->bridge(array_merge($this->cleanInput(), [
            'target_collisions' => [
                ['target_family' => 'unrelated', 'severity' => 'critical'],
            ],
        ]));

        $this->assertSame('credited', $result['verdict']);
    }

    // ── output structure ──

    public function test_output_has_required_keys(): void
    {
        $result = $this->bridge()->bridge($this->cleanInput());

        $this->assertSame(AtlasMaestroHealthGateSeedCreditBridge::SCHEMA, $result['schema_version']);
        $this->assertArrayHasKey('verdict', $result);
        $this->assertArrayHasKey('credits_granted', $result);
        $this->assertArrayHasKey('denial_reasons', $result);
        $this->assertArrayHasKey('health_healthy', $result);
    }

    public function test_result_is_deterministic(): void
    {
        $input = $this->cleanInput();
        $a = $this->bridge()->bridge($input);
        $b = $this->bridge()->bridge($input);

        $this->assertSame(json_encode($a), json_encode($b));
    }
}
