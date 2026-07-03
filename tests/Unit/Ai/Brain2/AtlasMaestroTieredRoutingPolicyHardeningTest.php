<?php

declare(strict_types=1);

namespace Tests\Unit\Ai\Brain2;

use App\Services\Ai\SelfConstruction\Maestro\Tiering\AtlasMaestroTaskTierClassifier;
use App\Services\Ai\SelfConstruction\Maestro\Tiering\AtlasMaestroTieredRoutingPolicy;
use App\Services\Ai\SelfConstruction\Maestro\Tiering\AtlasMaestroWorkerTierRegistry;
use PHPUnit\Framework\TestCase;

/**
 * Proves AtlasMaestroTieredRoutingPolicy::evaluate does not silently rank an unknown
 * worker tier as 0 (lowest).
 */
final class AtlasMaestroTieredRoutingPolicyHardeningTest extends TestCase
{
    private string $snapshotPath;
    private AtlasMaestroWorkerTierRegistry $registry;
    private AtlasMaestroTieredRoutingPolicy $policy;

    protected function setUp(): void
    {
        parent::setUp();
        $this->snapshotPath = sys_get_temp_dir().'/atlas_routing_hardening_'.bin2hex(random_bytes(6)).'.json';
        $this->registry = new AtlasMaestroWorkerTierRegistry($this->snapshotPath);
        $this->policy = new AtlasMaestroTieredRoutingPolicy(new AtlasMaestroTaskTierClassifier, $this->registry);
    }

    protected function tearDown(): void
    {
        parent::tearDown();
        @unlink($this->snapshotPath);
    }

    private function easyPacket(): array
    {
        return [
            'packet_id' => 'p-easy',
            'objective' => 'fix typo',
            'allowed_files' => ['app/Foo.php'],
            'acceptance_criteria' => ['ok'],
        ];
    }

    /**
     * Write an unknown tier directly into the snapshot file, bypassing the validation gate.
     */
    private function injectUnknownTier(string $clientId, string $unknownTier): void
    {
        $snapshot = file_exists($this->snapshotPath)
            ? json_decode((string) file_get_contents($this->snapshotPath), true) ?: []
            : [];
        $snapshot[$clientId] = [
            'client_id' => $clientId,
            'declared_max_tier' => $unknownTier,
            'meta' => [],
            'observed_max_tier' => '',
            'evidence_recorded_at' => '',
        ];
        file_put_contents($this->snapshotPath, json_encode($snapshot));
    }

    public function test_unknown_worker_tier_is_not_silently_ranked_as_zero(): void
    {
        $this->injectUnknownTier('unknown-worker', 'totally-fake-tier');

        $v = $this->policy->evaluate('unknown-worker', $this->easyPacket());

        // With the fix, unknown tier defaults to PHP_INT_MAX (highest rank), meaning
        // the worker can't handle anything. The verdict should be REFUSE, not ALLOW.
        $this->assertSame('refuse_tier_mismatch', $v['verdict']);
    }

    public function test_known_tier_still_works_normally(): void
    {
        $this->registry->register('known-worker', 'easy');

        $v = $this->policy->evaluate('known-worker', $this->easyPacket());

        $this->assertSame('allow', $v['verdict']);
    }
}
