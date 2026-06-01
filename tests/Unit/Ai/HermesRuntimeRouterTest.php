<?php

namespace Tests\Unit\Ai;

use App\Services\Ai\Hermes\HermesRuntimeRouter;
use Tests\TestCase;

class HermesRuntimeRouterTest extends TestCase
{
    /**
     * @var array<string,mixed>
     */
    private array $policyAllow = ['providers' => ['hermes_cli' => ['allow_auto' => true]]];

    /**
     * @var array<string,mixed>
     */
    private array $policyBlock = ['providers' => ['hermes_cli' => ['allow_auto' => false]]];

    protected function setUp(): void
    {
        parent::setUp();

        config(['atlas.ai.hermes_runtime_router.compatible_tasks' => ['ops', 'gateway', 'long_running', 'tool_heavy', 'research']]);
    }

    public function test_allow_auto_false_is_not_a_candidate(): void
    {
        $router = app(HermesRuntimeRouter::class);
        $options = ['payload' => ['routing_task' => 'ops']];

        $this->assertFalse($router->isAutoRoutingCandidate($options, $this->policyBlock));
        $this->assertSame('auto_disabled_by_policy', $router->blockReason($options, $this->policyBlock));
    }

    public function test_allow_auto_true_plus_compatible_task_is_candidate(): void
    {
        $router = app(HermesRuntimeRouter::class);
        $options = ['payload' => ['routing_task' => 'ops']];

        $this->assertTrue($router->isAutoRoutingCandidate($options, $this->policyAllow));
        $this->assertNull($router->blockReason($options, $this->policyAllow));

        $receipt = $router->buildReceipt($options, $this->policyAllow, true, null);

        $this->assertSame('auto_routed_to_hermes', data_get($receipt, 'status'));
        $this->assertTrue(data_get($receipt, 'auto_routing_allowed_now'));
        $this->assertSame('executive_runtime', data_get($receipt, 'runtime_role'));
        $this->assertSame('atlas_decide', data_get($receipt, 'authority'));
        $this->assertSame('hermes_runtime_router', data_get($receipt, 'router'));
        $this->assertSame('hermes_cli', data_get($receipt, 'selected_provider'));
        $this->assertNull(data_get($receipt, 'fallback_provider'));
        $this->assertTrue(data_get($receipt, 'policy_allow_auto'));
        $this->assertTrue(data_get($receipt, 'compatible_task'));
        $this->assertFalse(data_get($receipt, 'blocked'));
        $this->assertNotEmpty(data_get($receipt, 'reason'));
    }

    public function test_sensitive_privacy_blocks_even_when_allow_auto_true(): void
    {
        $router = app(HermesRuntimeRouter::class);
        $options = ['payload' => ['routing_task' => 'ops', 'privacy' => ['sensitivity' => 'sensitive']]];

        $this->assertSame('sensitive_or_secret_privacy_blocks_external_runtime', $router->blockReason($options, $this->policyAllow));
        $this->assertFalse($router->isAutoRoutingCandidate($options, $this->policyAllow));

        $receipt = $router->buildReceipt($options, $this->policyAllow, false, 'privacy_blocked');
        $this->assertTrue(data_get($receipt, 'blocked'));
        $this->assertSame('auto_routing_blocked', data_get($receipt, 'status'));
        $this->assertFalse(data_get($receipt, 'auto_routing_allowed_now'));
    }

    public function test_secret_privacy_blocks(): void
    {
        $router = app(HermesRuntimeRouter::class);
        $options = ['payload' => ['routing_task' => 'ops', 'privacy' => ['sensitivity' => 'secret']]];

        $this->assertSame('sensitive_or_secret_privacy_blocks_external_runtime', $router->blockReason($options, $this->policyAllow));
        $this->assertFalse($router->isAutoRoutingCandidate($options, $this->policyAllow));
    }

    public function test_unsafe_gateway_or_memory_policy_blocks(): void
    {
        $router = app(HermesRuntimeRouter::class);

        $gatewayOptions = ['payload' => ['routing_task' => 'ops', 'hermes' => ['gateway_allowed' => true]]];
        $this->assertSame('unsafe_memory_or_gateway_policy', $router->blockReason($gatewayOptions, $this->policyAllow));
        $this->assertFalse($router->isAutoRoutingCandidate($gatewayOptions, $this->policyAllow));

        $memoryOptions = ['payload' => ['routing_task' => 'ops', 'hermes' => ['memory_policy' => 'wild']]];
        $this->assertSame('unsafe_memory_or_gateway_policy', $router->blockReason($memoryOptions, $this->policyAllow));
        $this->assertFalse($router->isAutoRoutingCandidate($memoryOptions, $this->policyAllow));
    }

    public function test_incompatible_task_is_not_candidate(): void
    {
        $router = app(HermesRuntimeRouter::class);
        $options = ['payload' => ['task_type' => 'dev']];

        $this->assertSame('task_not_hermes_compatible', $router->blockReason($options, $this->policyAllow));
        $this->assertFalse($router->isAutoRoutingCandidate($options, $this->policyAllow));
        $this->assertFalse($router->compatibleTask($options));
    }

    public function test_receipt_is_sealed_with_receipt_hash(): void
    {
        $router = app(HermesRuntimeRouter::class);
        $options = ['payload' => ['routing_task' => 'ops']];

        $receipt = $router->buildReceipt($options, $this->policyAllow, true, null);

        $this->assertArrayHasKey('receipt_hash', $receipt);

        $unsealed = $receipt;
        unset($unsealed['receipt_hash']);
        $expected = hash('sha256', json_encode($unsealed, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR));

        $this->assertSame($expected, $receipt['receipt_hash']);
    }

    public function test_not_selected_receipt_has_allowed_now_false(): void
    {
        $router = app(HermesRuntimeRouter::class);
        $options = ['payload' => ['routing_task' => 'ops']];

        $receipt = $router->buildReceipt($options, $this->policyBlock, false, 'candidate_auto_disabled');

        $this->assertFalse(data_get($receipt, 'auto_routing_allowed_now'));
        $this->assertContains(data_get($receipt, 'status'), ['auto_routing_disabled_by_policy', 'not_a_candidate']);
        $this->assertSame('candidate_auto_disabled', data_get($receipt, 'fallback_reason'));
    }
}
