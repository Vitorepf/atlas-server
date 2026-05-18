<?php

namespace Tests\Feature\Ai\ToolRuntime;

use App\Services\Ai\ToolRuntime\ToolCapabilityCatalogService;
use App\Services\Ai\ToolRuntime\ToolDefinitionRegistryService;
use App\Services\Ai\ToolRuntime\ToolRuntimeException;
use Tests\Concerns\CreatesToolRuntimeTables;
use Tests\TestCase;

class ToolRuntimeCapabilityCatalogTest extends TestCase
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

    public function test_capability_persists_with_tool_link_and_schemas(): void
    {
        $tool = app(ToolDefinitionRegistryService::class)->register(
            ToolRuntimeRegistryUniquenessTest::validToolAttributes('catalog_demo'),
        );
        $catalog = app(ToolCapabilityCatalogService::class);
        $capability = $catalog->register($tool, [
            'capability_id' => 'demo.do_thing',
            'name' => 'Do Thing',
            'input_schema' => ['type' => 'object', 'required' => ['x']],
            'output_schema' => ['type' => 'object', 'required' => ['y']],
            'required_policy_gates' => ['workspace_read'],
            'required_evidence' => ['receipt'],
            'maturity_level' => 2,
        ]);

        $this->assertSame('demo.do_thing', $capability->capability_id);
        $this->assertSame($tool->id, $capability->tool_definition_id);
        $this->assertSame(['workspace_read'], $capability->required_policy_gates);
        $this->assertSame(['receipt'], $capability->required_evidence);
    }

    public function test_capability_register_rejects_duplicate(): void
    {
        $tool = app(ToolDefinitionRegistryService::class)->register(
            ToolRuntimeRegistryUniquenessTest::validToolAttributes('dup_cap'),
        );
        $catalog = app(ToolCapabilityCatalogService::class);
        $attrs = [
            'capability_id' => 'demo.same',
            'input_schema' => ['type' => 'object'],
            'output_schema' => ['type' => 'object'],
            'required_policy_gates' => [],
            'required_evidence' => ['receipt'],
            'maturity_level' => 1,
        ];
        $catalog->register($tool, $attrs);

        $this->expectException(ToolRuntimeException::class);
        $this->expectExceptionMessageMatches('/already exists for tool/');
        $catalog->register($tool, $attrs);
    }
}
