<?php

namespace Tests\Feature\Ai\ToolRuntime;

use App\Models\AiToolDefinition;
use App\Services\Ai\ToolRuntime\ToolDefinitionRegistryService;
use App\Services\Ai\ToolRuntime\ToolSeedDefinitions;
use Tests\Concerns\CreatesToolRuntimeTables;
use Tests\TestCase;

class ToolRuntimeSeedDefaultsTest extends TestCase
{
    use CreatesToolRuntimeTables;

    protected function setUp(): void
    {
        parent::setUp();
        $this->createToolRuntimeTables();
    }

    protected function tearDown(): void
    {
        $this->dropToolRuntimeTables();
        parent::tearDown();
    }

    public function test_seed_creates_all_default_tools_with_capabilities(): void
    {
        $payload = app(ToolDefinitionRegistryService::class)->seedDefaults(ToolSeedDefinitions::all());

        $this->assertTrue($payload['ok']);
        $this->assertSame(10, $payload['summary']['created']);
        $this->assertSame(10, $payload['summary']['total_after']);

        $expected = [
            'filesystem.read', 'command.local_readonly', 'docs.search', 'github.readonly',
            'browser.readonly', 'api.readonly', 'artifact.write_local', 'test.local_command',
            'evidence.attach', 'policy.evaluate',
        ];
        $present = AiToolDefinition::query()->pluck('tool_id')->all();
        foreach ($expected as $toolId) {
            $this->assertContains($toolId, $present);
        }

        foreach (AiToolDefinition::query()->get() as $tool) {
            $this->assertGreaterThan(0, $tool->capabilities()->count(), "tool [{$tool->tool_id}] must have a capability");
        }
    }

    public function test_seed_is_idempotent(): void
    {
        $registry = app(ToolDefinitionRegistryService::class);
        $registry->seedDefaults(ToolSeedDefinitions::all());
        $second = $registry->seedDefaults(ToolSeedDefinitions::all());

        $this->assertSame(0, $second['summary']['created']);
        $this->assertSame(10, $second['summary']['skipped']);
        $this->assertSame(10, AiToolDefinition::query()->count());
    }
}
