<?php

declare(strict_types=1);

namespace Tests\Unit\Ai\SelfConstruction\NativeWorker;

use App\Services\Ai\SelfConstruction\NativeWorker\AtlasNativeWorkerCapabilityRegistry;
use App\Services\Ai\SelfConstruction\NativeWorker\AtlasNativeWorkerExecutionEnvelopeBuilder;
use App\Services\Ai\SelfConstruction\NativeWorker\AtlasNativeWorkerReadinessGate;
use PHPUnit\Framework\TestCase;

/**
 * Proves the AtlasNativeWorkerReadinessGate: ready=true only with atlas_native runtime owner + server-side
 * verification + rollback available + every required component present-and-verified; missing patch_applier
 * yields a precise blocker; missing rollback yields rollback_unavailable; external-only worker yields
 * runtime_owner_not_atlas_native; deterministic envelope across calls.
 */
final class AtlasNativeWorkerReadinessGateTest extends TestCase
{
    private function gate(): AtlasNativeWorkerReadinessGate
    {
        return new AtlasNativeWorkerReadinessGate(new AtlasNativeWorkerCapabilityRegistry);
    }

    private function allReady(): array
    {
        return [
            'runtime_owner' => AtlasNativeWorkerExecutionEnvelopeBuilder::RUNTIME_OWNER,
            'server_side_verification_available' => true,
            'rollback_available' => true,
            'components' => [
                'patch_planner' => ['present' => true, 'verified' => true],
                'scoped_patch_applier' => ['present' => true, 'verified' => true],
                'gate_runner' => ['present' => true, 'verified' => true],
                'evidence_writer' => ['present' => true, 'verified' => true],
                'rollback_runner' => ['present' => true, 'verified' => true],
                'learning_receipt_writer' => ['present' => true, 'verified' => true],
            ],
        ];
    }

    public function test_all_ready_yields_ready_true_with_empty_blockers(): void
    {
        $v = $this->gate()->evaluate($this->allReady());
        $this->assertTrue($v['ready']);
        $this->assertSame([], $v['blockers']);
        $this->assertSame(AtlasNativeWorkerReadinessGate::REQUIRED_COMPONENTS, $v['components_verified']);
    }

    public function test_missing_patch_applier_yields_precise_blocker(): void
    {
        $o = $this->allReady();
        unset($o['components']['scoped_patch_applier']);
        $v = $this->gate()->evaluate($o);
        $this->assertFalse($v['ready']);
        $this->assertContains('component_missing:scoped_patch_applier', $v['blockers']);
    }

    public function test_unverified_component_yields_component_unverified_blocker(): void
    {
        $o = $this->allReady();
        $o['components']['patch_planner']['verified'] = false;
        $v = $this->gate()->evaluate($o);
        $this->assertContains('component_unverified:patch_planner', $v['blockers']);
        $this->assertFalse($v['ready']);
    }

    public function test_missing_rollback_yields_rollback_unavailable_blocker(): void
    {
        $o = $this->allReady();
        $o['rollback_available'] = false;
        $v = $this->gate()->evaluate($o);
        $this->assertContains('rollback_unavailable', $v['blockers']);
        $this->assertFalse($v['ready']);
    }

    public function test_external_only_runtime_owner_yields_runtime_owner_blocker(): void
    {
        $o = $this->allReady();
        $o['runtime_owner'] = 'external_provider';
        $v = $this->gate()->evaluate($o);
        $this->assertContains('runtime_owner_not_atlas_native:external_provider', $v['blockers']);
        $this->assertFalse($v['ready']);
    }

    public function test_two_evaluations_with_same_input_return_byte_identical_envelope(): void
    {
        $g = $this->gate();
        $o = $this->allReady();
        $a = json_encode($g->evaluate($o), JSON_UNESCAPED_SLASHES);
        $b = json_encode($g->evaluate($o), JSON_UNESCAPED_SLASHES);
        $this->assertSame($a, $b);
    }

    public function test_no_scalar_score_field_in_envelope(): void
    {
        $v = $this->gate()->evaluate($this->allReady());
        $json = (string) json_encode($v);
        $this->assertDoesNotMatchRegularExpression('/score|grade|percent|hype/i', $json);
    }
}
