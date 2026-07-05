<?php

declare(strict_types=1);

namespace Tests\Unit\Services\Ai\SelfConstruction\Autonomy;

use App\Services\Ai\SelfConstruction\Autonomy\AtlasSelfConstructionAutonomyRecoveryPriorityRouter;
use PHPUnit\Framework\TestCase;

final class AtlasSelfConstructionAutonomyRecoveryPriorityRouterTest extends TestCase
{
    private AtlasSelfConstructionAutonomyRecoveryPriorityRouter $router;

    protected function setUp(): void
    {
        $this->router = new AtlasSelfConstructionAutonomyRecoveryPriorityRouter;
    }

    private function cleanInput(): array
    {
        return [
            'originator_id' => 'orig-1',
            'round_id' => 'round-1',
            'autonomy_health' => [
                'lease_leak_detected' => false,
                'malformed_count' => 0,
                'stale_proof_detected' => false,
                'queue_dry' => false,
                'sensing_degraded' => false,
            ],
        ];
    }

    // ── AC: lease leaks map to a recovery priority ──

    public function test_lease_leak_maps_to_recovery_priority(): void
    {
        $input = $this->cleanInput();
        $input['autonomy_health']['lease_leak_detected'] = true;

        $result = $this->router->route($input);

        $this->assertTrue($result['recovery_needed']);
        $this->assertSame(AtlasSelfConstructionAutonomyRecoveryPriorityRouter::RECOVERY_LEASE_LEAK, $result['top_recovery']['recovery_kind']);
        $this->assertNotEmpty($result['top_recovery']['allowed_files']);
    }

    // ── AC: malformed blockers map to a recovery priority ──

    public function test_malformed_blockers_maps_to_recovery_priority(): void
    {
        $input = $this->cleanInput();
        $input['autonomy_health']['malformed_count'] = 5;

        $result = $this->router->route($input);

        $this->assertTrue($result['recovery_needed']);
        $this->assertSame(AtlasSelfConstructionAutonomyRecoveryPriorityRouter::RECOVERY_MALFORMED_BLOCKERS, $result['top_recovery']['recovery_kind']);
    }

    // ── AC: stale proof maps to a recovery priority ──

    public function test_stale_proof_maps_to_recovery_priority(): void
    {
        $input = $this->cleanInput();
        $input['autonomy_health']['stale_proof_detected'] = true;

        $result = $this->router->route($input);

        $this->assertTrue($result['recovery_needed']);
        $this->assertSame(AtlasSelfConstructionAutonomyRecoveryPriorityRouter::RECOVERY_STALE_PROOF, $result['top_recovery']['recovery_kind']);
    }

    // ── AC: queue dry maps to a recovery priority ──

    public function test_queue_dry_maps_to_recovery_priority(): void
    {
        $input = $this->cleanInput();
        $input['autonomy_health']['queue_dry'] = true;

        $result = $this->router->route($input);

        $this->assertTrue($result['recovery_needed']);
        $this->assertSame(AtlasSelfConstructionAutonomyRecoveryPriorityRouter::RECOVERY_QUEUE_DRY, $result['top_recovery']['recovery_kind']);
    }

    // ── priority ordering: lease leak beats malformed ──

    public function test_lease_leak_beats_malformed(): void
    {
        $input = $this->cleanInput();
        $input['autonomy_health']['lease_leak_detected'] = true;
        $input['autonomy_health']['malformed_count'] = 5;

        $result = $this->router->route($input);

        $this->assertSame(AtlasSelfConstructionAutonomyRecoveryPriorityRouter::RECOVERY_LEASE_LEAK, $result['top_recovery']['recovery_kind']);
    }

    // ── clean health returns no recovery needed ──

    public function test_clean_health_returns_no_recovery(): void
    {
        $result = $this->router->route($this->cleanInput());

        $this->assertFalse($result['recovery_needed']);
        $this->assertFalse($result['blocks_expansion']);
        $this->assertSame(AtlasSelfConstructionAutonomyRecoveryPriorityRouter::RECOVERY_NONE, $result['top_recovery']['recovery_kind']);
    }

    // ── output structure ──

    public function test_output_has_required_keys(): void
    {
        $result = $this->router->route($this->cleanInput());

        $this->assertSame(AtlasSelfConstructionAutonomyRecoveryPriorityRouter::SCHEMA, $result['schema_version']);
        $this->assertArrayHasKey('recovery_needed', $result);
        $this->assertArrayHasKey('top_recovery', $result);
        $this->assertArrayHasKey('recoveries', $result);
        $this->assertArrayHasKey('blocks_expansion', $result);
    }

    public function test_result_is_deterministic(): void
    {
        $input = $this->cleanInput();
        $input['autonomy_health']['malformed_count'] = 2;

        $a = $this->router->route($input);
        $b = $this->router->route($input);

        $this->assertSame(json_encode($a), json_encode($b));
    }
}
