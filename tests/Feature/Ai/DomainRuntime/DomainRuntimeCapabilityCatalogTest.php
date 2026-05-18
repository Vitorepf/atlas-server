<?php

namespace Tests\Feature\Ai\DomainRuntime;

use App\Services\Ai\DomainRuntime\DomainCapabilityCatalogService;
use App\Services\Ai\DomainRuntime\DomainManifestRegistryService;
use App\Services\Ai\DomainRuntime\DomainRuntimeException;
use Tests\Concerns\CreatesDomainRuntimeTables;
use Tests\TestCase;

class DomainRuntimeCapabilityCatalogTest extends TestCase
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

    public function test_capability_register_persists_required_schemas_and_gates(): void
    {
        $manifest = app(DomainManifestRegistryService::class)->register(
            DomainRuntimeManifestRegistryTest::validManifestAttributes('catalog_demo'),
        );

        $capability = app(DomainCapabilityCatalogService::class)->register($manifest, [
            'capability_id' => 'demo.do_thing',
            'name' => 'Do Thing',
            'input_schema' => ['type' => 'object', 'required' => ['x']],
            'output_schema' => ['type' => 'object', 'required' => ['y']],
            'allowed_tools' => ['tool.a'],
            'required_gates' => ['gate.a'],
            'evidence_required' => ['doc'],
            'risk_level' => 'medium',
            'maturity_level' => 2,
        ]);

        $this->assertSame('demo.do_thing', $capability->capability_id);
        $this->assertSame(['gate.a'], $capability->required_gates);
        $this->assertSame(['doc'], $capability->evidence_required);
    }

    public function test_capability_register_rejects_critical_without_department_maturity(): void
    {
        $manifest = app(DomainManifestRegistryService::class)->register(
            DomainRuntimeManifestRegistryTest::validManifestAttributes('critical_demo'),
        );

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessageMatches('/critical capability .* requires maturity_level >= Department/');
        app(DomainCapabilityCatalogService::class)->register($manifest, [
            'capability_id' => 'demo.danger',
            'input_schema' => ['type' => 'object'],
            'output_schema' => ['type' => 'object'],
            'allowed_tools' => ['tool.a'],
            'required_gates' => ['gate.a'],
            'evidence_required' => ['doc'],
            'risk_level' => 'critical',
            'maturity_level' => 1,
        ]);
    }

    public function test_capability_register_rejects_duplicate(): void
    {
        $manifest = app(DomainManifestRegistryService::class)->register(
            DomainRuntimeManifestRegistryTest::validManifestAttributes('dup_demo'),
        );
        $catalog = app(DomainCapabilityCatalogService::class);
        $attrs = [
            'capability_id' => 'demo.same',
            'input_schema' => ['type' => 'object'],
            'output_schema' => ['type' => 'object'],
            'allowed_tools' => ['tool.a'],
            'required_gates' => ['gate.a'],
            'evidence_required' => ['doc'],
            'risk_level' => 'low',
            'maturity_level' => 1,
        ];
        $catalog->register($manifest, $attrs);

        $this->expectException(DomainRuntimeException::class);
        $this->expectExceptionMessageMatches('/already exists in domain/');
        $catalog->register($manifest, $attrs);
    }
}
