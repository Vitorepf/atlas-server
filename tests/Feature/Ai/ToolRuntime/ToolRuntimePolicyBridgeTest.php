<?php

namespace Tests\Feature\Ai\ToolRuntime;

use App\Services\Ai\ToolRuntime\ToolDefinitionRegistryService;
use App\Services\Ai\ToolRuntime\ToolPolicyBridgeService;
use Tests\Concerns\CreatesToolRuntimeTables;
use Tests\TestCase;

class ToolRuntimePolicyBridgeTest extends TestCase
{
    use CreatesToolRuntimeTables;

    protected function setUp(): void
    {
        parent::setUp();
        config()->set('atlas_ai.tool_runtime.strict_mode', false);
        $this->createToolRuntimeTables();
    }

    protected function tearDown(): void
    {
        $this->dropToolRuntimeTables();
        parent::tearDown();
    }

    public function test_bridge_falls_back_to_allow_for_read_only_when_policy_runtime_absent(): void
    {
        $tool = app(ToolDefinitionRegistryService::class)->register(
            ToolRuntimeRegistryUniquenessTest::validToolAttributes('bridge_readonly'),
        );

        $decision = app(ToolPolicyBridgeService::class)->evaluate($tool);

        $this->assertSame('allow', $decision['decision']);
        $this->assertSame('tool_runtime_fallback', $decision['source']);
        $this->assertSame(64, strlen((string) $decision['receipt_hash']));
    }

    public function test_bridge_falls_back_to_require_approval_for_high_risk_authority_when_policy_runtime_absent(): void
    {
        $attrs = ToolRuntimeRegistryUniquenessTest::validToolAttributes('bridge_external');
        $attrs['authority_group'] = 'external_action';
        $attrs['risk_level'] = 'high';
        $tool = app(ToolDefinitionRegistryService::class)->register($attrs);

        $decision = app(ToolPolicyBridgeService::class)->evaluate($tool);

        $this->assertSame('require_approval', $decision['decision']);
        $this->assertSame('tool_runtime_fallback', $decision['source']);
    }
}
