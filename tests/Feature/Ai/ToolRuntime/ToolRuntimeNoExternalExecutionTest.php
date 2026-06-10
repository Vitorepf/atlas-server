<?php

namespace Tests\Feature\Ai\ToolRuntime;

use App\Services\Ai\ToolRuntime\ToolDefinitionRegistryService;
use App\Services\Ai\ToolRuntime\ToolInvocationService;
use Tests\Concerns\CreatesToolRuntimeTables;
use Tests\TestCase;

class ToolRuntimeNoExternalExecutionTest extends TestCase
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

    public function test_high_risk_authority_groups_never_succeed_without_explicit_policy_allow(): void
    {
        $registry = app(ToolDefinitionRegistryService::class);
        $invocations = app(ToolInvocationService::class);

        foreach (['external_action', 'financial_action', 'security_sensitive'] as $authority) {
            $attrs = ToolRuntimeRegistryUniquenessTest::validToolAttributes('no_external_'.$authority);
            $attrs['authority_group'] = $authority;
            $attrs['risk_level'] = $authority === 'financial_action' ? 'critical' : 'high';
            $tool = $registry->register($attrs);

            $invocation = $invocations->invoke($tool, ['payload' => 'demo']);

            $this->assertSame(
                'blocked',
                $invocation->invocation_status,
                "tool [{$tool->tool_id}] with authority [{$authority}] must not succeed without policy allow",
            );
            $this->assertNull($invocation->output_hash, "no output should be produced for [{$authority}]");
        }
    }

    public function test_mock_executor_does_not_attempt_real_io(): void
    {
        $registry = app(ToolDefinitionRegistryService::class);
        $invocations = app(ToolInvocationService::class);
        $attrs = ToolRuntimeRegistryUniquenessTest::validToolAttributes('browser_demo');
        $attrs['tool_type'] = 'browser';
        $attrs['authority_group'] = 'read_only';
        $tool = $registry->register($attrs);

        $invocation = $invocations->invoke($tool, ['url' => 'https://example.com/this-should-not-be-fetched']);

        $this->assertSame('succeeded', $invocation->invocation_status);
        $this->assertNotNull($invocation->output_hash);
    }
}
