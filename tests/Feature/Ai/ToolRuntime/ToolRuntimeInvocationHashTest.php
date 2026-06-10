<?php

namespace Tests\Feature\Ai\ToolRuntime;

use App\Services\Ai\ToolRuntime\ToolDefinitionRegistryService;
use App\Services\Ai\ToolRuntime\ToolInvocationService;
use Tests\Concerns\CreatesToolRuntimeTables;
use Tests\TestCase;

class ToolRuntimeInvocationHashTest extends TestCase
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

    public function test_invocation_records_input_and_output_hash_when_allowed(): void
    {
        $tool = app(ToolDefinitionRegistryService::class)->register(
            ToolRuntimeRegistryUniquenessTest::validToolAttributes('hash_demo'),
        );

        $invocation = app(ToolInvocationService::class)->invoke($tool, ['x' => 1]);

        $this->assertSame('succeeded', $invocation->invocation_status);
        $this->assertSame(64, strlen((string) $invocation->input_hash));
        $this->assertSame(64, strlen((string) $invocation->output_hash));
        $this->assertNotNull($invocation->started_at);
        $this->assertNotNull($invocation->finished_at);
    }

    public function test_invocation_for_external_action_without_policy_is_blocked(): void
    {
        $attrs = ToolRuntimeRegistryUniquenessTest::validToolAttributes('hash_external');
        $attrs['authority_group'] = 'external_action';
        $attrs['risk_level'] = 'high';
        $tool = app(ToolDefinitionRegistryService::class)->register($attrs);

        $invocation = app(ToolInvocationService::class)->invoke($tool, ['x' => 1]);

        $this->assertSame('blocked', $invocation->invocation_status);
        $this->assertNull($invocation->output_hash, 'blocked invocation must not produce an output');
        $this->assertNotNull($invocation->input_hash);
    }
}
