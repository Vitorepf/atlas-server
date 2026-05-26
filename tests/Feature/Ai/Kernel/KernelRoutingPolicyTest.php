<?php

declare(strict_types=1);

namespace Tests\Feature\Ai\Kernel;

use App\Services\Ai\Kernel\Architecture\KernelRoutingPolicy;
use Tests\TestCase;

/**
 * Gap1.F4 — KernelRoutingPolicy contract tests.
 *
 *   - Default flag = false → safe legacy routing.
 *   - Flag = true → kernel routing.
 *   - Decision envelope carries canonical schema + flag source + safe_default.
 */
class KernelRoutingPolicyTest extends TestCase
{
    public function test_default_flag_is_false(): void
    {
        config()->set('atlas_ai.aiworker_kernel_routed', false);

        $policy = new KernelRoutingPolicy;

        $this->assertFalse($policy->shouldRouteKernel());
    }

    public function test_default_route_is_legacy(): void
    {
        config()->set('atlas_ai.aiworker_kernel_routed', false);

        $policy = new KernelRoutingPolicy;
        $decision = $policy->decision();

        $this->assertSame('atlas.ai.kernel_routing_policy.v1', $decision['schema_version']);
        $this->assertSame('legacy', $decision['route']);
        $this->assertFalse($decision['flag_enabled']);
        $this->assertTrue($decision['safe_default']);
    }

    public function test_flag_on_routes_kernel(): void
    {
        config()->set('atlas_ai.aiworker_kernel_routed', true);

        $policy = new KernelRoutingPolicy;
        $decision = $policy->decision();

        $this->assertSame('kernel', $decision['route']);
        $this->assertTrue($decision['flag_enabled']);
        $this->assertFalse($decision['safe_default']);
    }

    public function test_decision_envelope_shape_is_stable(): void
    {
        config()->set('atlas_ai.aiworker_kernel_routed', false);

        $decision = (new KernelRoutingPolicy)->decision();

        $this->assertSame([
            'schema_version',
            'flag_enabled',
            'route',
            'flag_source',
            'detail',
            'safe_default',
        ], array_keys($decision));
    }

    public function test_detail_documents_flag_source(): void
    {
        $decision = (new KernelRoutingPolicy)->decision();

        $this->assertStringContainsString('ATLAS_AIWORKER_KERNEL_ROUTED', $decision['flag_source']);
        $this->assertStringContainsString('config(atlas_ai.aiworker_kernel_routed)', $decision['flag_source']);
    }
}
