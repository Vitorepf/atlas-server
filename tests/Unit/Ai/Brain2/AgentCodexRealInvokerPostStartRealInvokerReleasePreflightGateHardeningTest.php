<?php

declare(strict_types=1);

namespace Tests\Unit\Ai\Brain2;

use App\Services\Ai\SelfConstruction\Support\AgentCodexRealInvokerPostStartRealInvokerReleasePreflightGate;
use App\Services\Ai\SelfConstruction\Support\AgentCodexRealInvokerReleasePreflight;
use PHPUnit\Framework\TestCase;

/**
 * Proves AgentCodexRealInvokerPostStartRealInvokerReleasePreflightGate::recheckRelease
 * marks a proof aged exactly to the TTL as stale.
 */
final class AgentCodexRealInvokerPostStartRealInvokerReleasePreflightGateHardeningTest extends TestCase
{
    private function createGate(): AgentCodexRealInvokerPostStartRealInvokerReleasePreflightGate
    {
        $preflight = $this->createMock(AgentCodexRealInvokerReleasePreflight::class);
        return new AgentCodexRealInvokerPostStartRealInvokerReleasePreflightGate($preflight);
    }

    private function allProofs(int $generatedAt, int $ttlMinutes): array
    {
        $ts = date('Y-m-d H:i:s', $generatedAt);
        return [
            'queue_health'    => ['generated_at' => $ts, 'ttl_minutes' => $ttlMinutes],
            'lease'           => ['generated_at' => $ts, 'ttl_minutes' => $ttlMinutes],
            'scope'           => ['generated_at' => $ts, 'ttl_minutes' => $ttlMinutes],
            'launch_proof'    => ['generated_at' => $ts, 'ttl_minutes' => $ttlMinutes],
            'rollback_proof'  => ['generated_at' => $ts, 'ttl_minutes' => $ttlMinutes],
        ];
    }

    public function test_proof_at_exact_ttl_boundary_is_stale(): void
    {
        $gate = $this->createGate();

        $now = time();
        $generatedAt = $now - 300; // 5 minutes ago

        $result = $gate->recheckRelease([
            'proofs' => $this->allProofs($generatedAt, 5),
            'now' => date('Y-m-d H:i:s', $now),
        ]);

        $this->assertFalse($result['release_ready']);
        $this->assertCount(5, $result['stale_proofs']);
    }

    public function test_proof_just_before_ttl_boundary_is_fresh(): void
    {
        $gate = $this->createGate();

        $now = time();
        $generatedAt = $now - 299; // 4 min 59 sec ago

        $result = $gate->recheckRelease([
            'proofs' => $this->allProofs($generatedAt, 5),
            'now' => date('Y-m-d H:i:s', $now),
        ]);

        $this->assertTrue($result['release_ready']);
        $this->assertEmpty($result['stale_proofs']);
    }

    public function test_proof_well_past_ttl_is_stale(): void
    {
        $gate = $this->createGate();

        $now = time();
        $generatedAt = $now - 600; // 10 minutes ago

        $result = $gate->recheckRelease([
            'proofs' => $this->allProofs($generatedAt, 5),
            'now' => date('Y-m-d H:i:s', $now),
        ]);

        $this->assertFalse($result['release_ready']);
        $this->assertCount(5, $result['stale_proofs']);
    }
}
