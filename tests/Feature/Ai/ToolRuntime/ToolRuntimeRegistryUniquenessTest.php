<?php

namespace Tests\Feature\Ai\ToolRuntime;

use App\Services\Ai\ToolRuntime\ToolDefinitionRegistryService;
use App\Services\Ai\ToolRuntime\ToolRuntimeException;
use Tests\Concerns\CreatesToolRuntimeTables;
use Tests\TestCase;

class ToolRuntimeRegistryUniquenessTest extends TestCase
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

    public function test_tool_id_must_be_unique(): void
    {
        $registry = app(ToolDefinitionRegistryService::class);
        $attrs = self::validToolAttributes('unique_demo');
        $registry->register($attrs);

        $this->expectException(ToolRuntimeException::class);
        $this->expectExceptionMessageMatches('/already exists; tool_id is globally unique/');
        $registry->register($attrs);
    }

    public function test_invalid_tool_id_format_is_rejected(): void
    {
        $registry = app(ToolDefinitionRegistryService::class);
        $attrs = self::validToolAttributes('Bad ID!');

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessageMatches('/tool_id .* must match/');
        $registry->register($attrs);
    }

    /**
     * @return array<string,mixed>
     */
    public static function validToolAttributes(string $toolId): array
    {
        return [
            'tool_id' => $toolId,
            'name' => 'Demo Tool',
            'tool_type' => 'internal',
            'authority_group' => 'read_only',
            'risk_level' => 'low',
            'input_schema' => ['type' => 'object', 'required' => ['x']],
            'output_schema' => ['type' => 'object', 'required' => ['y']],
            'side_effects' => ['mutates' => false, 'external_network' => false],
            'evidence_emitted' => ['receipt'],
        ];
    }
}
