<?php

declare(strict_types=1);

namespace Tests\Unit\Services\Ai\SelfConstruction\RuntimeDaemon;

use App\Services\Ai\SelfConstruction\RuntimeDaemon\AtlasSelfConstructionRuntimeStallToTaskConverter;
use PHPUnit\Framework\TestCase;

final class AtlasSelfConstructionRuntimeStallToTaskConverterTest extends TestCase
{
    private AtlasSelfConstructionRuntimeStallToTaskConverter $converter;

    protected function setUp(): void
    {
        $this->converter = new AtlasSelfConstructionRuntimeStallToTaskConverter;
    }

    // ── AC: each stall class emits one implementable task spec ──

    public function test_lease_leak_emits_implementable_spec(): void
    {
        $result = $this->converter->convert([
            'classification' => 'lease_leak',
            'reasons' => ['leases_match_claimed_false'],
            'originator_id' => 'orig-1',
            'round_id' => 'round-1',
        ]);

        $this->assertTrue($result['runnable']);
        $this->assertNotEmpty($result['spec']['objective']);
        $this->assertNotEmpty($result['spec']['allowed_files']);
        $this->assertNotEmpty($result['spec']['acceptance_criteria']);
        $this->assertSame('lease_leak', $result['spec']['stall_classification']);
    }

    public function test_malformed_queue_emits_implementable_spec(): void
    {
        $result = $this->converter->convert([
            'classification' => 'verification_blocked',
            'reasons' => ['verification_failed_runs'],
            'originator_id' => 'orig-1',
            'round_id' => 'round-1',
        ]);

        $this->assertTrue($result['runnable']);
        $this->assertNotEmpty($result['spec']['allowed_files']);
        $this->assertStringContainsString('VerificationCourt', $result['spec']['allowed_files'][0]);
    }

    public function test_queue_dry_emits_implementable_spec(): void
    {
        $result = $this->converter->convert([
            'classification' => 'queue_dry',
            'reasons' => ['queue_depth_zero'],
            'originator_id' => 'orig-1',
            'round_id' => 'round-1',
        ]);

        $this->assertTrue($result['runnable']);
        $this->assertStringContainsString('QueueTopUpPolicy', $result['spec']['allowed_files'][0]);
    }

    public function test_waiting_on_dependencies_emits_implementable_spec(): void
    {
        $result = $this->converter->convert([
            'classification' => 'waiting_on_dependencies',
            'reasons' => ['queue_has_depth_but_nothing_claimable'],
            'originator_id' => 'orig-1',
            'round_id' => 'round-1',
        ]);

        $this->assertTrue($result['runnable']);
        $this->assertNotEmpty($result['spec']['allowed_files']);
    }

    // ── AC: healthy classification is not runnable ──

    public function test_healthy_is_not_runnable(): void
    {
        $result = $this->converter->convert([
            'classification' => 'healthy',
            'reasons' => [],
            'originator_id' => 'orig-1',
            'round_id' => 'round-1',
        ]);

        $this->assertFalse($result['runnable']);
        $this->assertSame([], $result['spec']['allowed_files']);
    }

    // ── AC: required evidence present ──

    public function test_spec_includes_required_evidence(): void
    {
        $result = $this->converter->convert([
            'classification' => 'worker_unavailable',
            'reasons' => ['native_worker_not_ready'],
            'originator_id' => 'orig-1',
            'round_id' => 'round-1',
        ]);

        $this->assertContains('tests_or_gates_result', $result['spec']['required_evidence']);
        $this->assertContains('implementation_notes', $result['spec']['required_evidence']);
    }

    // ── output structure ──

    public function test_output_has_required_keys(): void
    {
        $result = $this->converter->convert([
            'classification' => 'heartbeat_stale',
            'reasons' => ['heartbeat_stale'],
            'originator_id' => 'orig-1',
            'round_id' => 'round-1',
        ]);

        $this->assertSame(AtlasSelfConstructionRuntimeStallToTaskConverter::SCHEMA, $result['schema_version']);
        $this->assertArrayHasKey('spec', $result);
        $this->assertArrayHasKey('runnable', $result);
    }

    public function test_result_is_deterministic(): void
    {
        $input = [
            'classification' => 'stale_brain_heartbeat',
            'reasons' => ['brain_quota_stall_reason_stale_brain_heartbeat'],
            'originator_id' => 'orig-1',
            'round_id' => 'round-1',
        ];

        $a = $this->converter->convert($input);
        $b = $this->converter->convert($input);

        $this->assertSame(json_encode($a), json_encode($b));
    }
}
