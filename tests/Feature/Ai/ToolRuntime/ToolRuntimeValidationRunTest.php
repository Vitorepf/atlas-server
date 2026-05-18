<?php

namespace Tests\Feature\Ai\ToolRuntime;

use App\Services\Ai\ToolRuntime\ToolDefinitionRegistryService;
use App\Services\Ai\ToolRuntime\ToolValidationService;
use Tests\Concerns\CreatesToolRuntimeTables;
use Tests\TestCase;

class ToolRuntimeValidationRunTest extends TestCase
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

    public function test_schema_validation_passes_when_schemas_are_well_formed(): void
    {
        $tool = app(ToolDefinitionRegistryService::class)->register(
            ToolRuntimeRegistryUniquenessTest::validToolAttributes('validate_demo'),
        );
        $run = app(ToolValidationService::class)->validate($tool, 'schema');

        $this->assertSame('passed', $run->status);
        $this->assertSame(64, strlen((string) $run->validation_hash));
    }

    public function test_fixture_validation_skipped_when_no_fixture_provided(): void
    {
        $tool = app(ToolDefinitionRegistryService::class)->register(
            ToolRuntimeRegistryUniquenessTest::validToolAttributes('validate_fixture_demo'),
        );
        $run = app(ToolValidationService::class)->validate($tool, 'fixture');

        $this->assertSame('skipped', $run->status);
    }

    public function test_smoke_validation_for_high_risk_authority_is_blocked(): void
    {
        $attrs = ToolRuntimeRegistryUniquenessTest::validToolAttributes('validate_external_demo');
        $attrs['authority_group'] = 'external_action';
        $attrs['risk_level'] = 'high';
        $tool = app(ToolDefinitionRegistryService::class)->register($attrs);

        $run = app(ToolValidationService::class)->validate($tool, 'smoke');

        $this->assertSame('blocked', $run->status);
    }
}
