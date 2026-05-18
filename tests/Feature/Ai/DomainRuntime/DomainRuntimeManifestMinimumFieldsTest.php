<?php

namespace Tests\Feature\Ai\DomainRuntime;

use App\Services\Ai\DomainRuntime\DomainManifestRegistryService;
use App\Services\Ai\DomainRuntime\DomainRuntimeException;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Concerns\CreatesDomainRuntimeTables;
use Tests\TestCase;

class DomainRuntimeManifestMinimumFieldsTest extends TestCase
{
    use CreatesDomainRuntimeTables;

    protected function setUp(): void
    {
        parent::setUp();
        $this->createDomainRuntimeTables();
    }

    protected function tearDown(): void
    {
        $this->dropDomainRuntimeTables();
        parent::tearDown();
    }

    /**
     * @return array<string,array<int,string>>
     */
    public static function requiredFieldProvider(): array
    {
        return [
            'charter' => ['charter'],
            'ontology' => ['ontology'],
            'departments' => ['departments'],
            'flow_profiles' => ['flow_profiles'],
            'tools_allowed' => ['tools_allowed'],
            'evidence_schema' => ['evidence_schema'],
            'quality_gates' => ['quality_gates'],
            'handoff_rules' => ['handoff_rules'],
            'delivery_types' => ['delivery_types'],
            'metrics' => ['metrics'],
            'forbidden_actions' => ['forbidden_actions'],
        ];
    }

    #[DataProvider('requiredFieldProvider')]
    public function test_manifest_register_rejects_when_required_field_is_missing(string $field): void
    {
        $attrs = DomainRuntimeManifestRegistryTest::validManifestAttributes('min_demo');
        unset($attrs[$field]);

        $this->expectException(DomainRuntimeException::class);
        $this->expectExceptionMessageMatches('/missing required field \['.preg_quote($field, '/').'\]/');
        app(DomainManifestRegistryService::class)->register($attrs);
    }
}
