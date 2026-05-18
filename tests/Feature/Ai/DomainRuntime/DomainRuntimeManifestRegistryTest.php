<?php

namespace Tests\Feature\Ai\DomainRuntime;

use App\Services\Ai\DomainRuntime\DomainManifestRegistryService;
use App\Services\Ai\DomainRuntime\DomainRuntimeException;
use Tests\Concerns\CreatesDomainRuntimeTables;
use Tests\TestCase;

class DomainRuntimeManifestRegistryTest extends TestCase
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

    public function test_register_persists_manifest_with_hash(): void
    {
        $manifest = app(DomainManifestRegistryService::class)->register($this->validManifestAttributes('demo'));

        $this->assertSame('demo', $manifest->domain_id);
        $this->assertSame(64, strlen((string) $manifest->manifest_hash));
        $this->assertSame('scaffold', $manifest->status);
    }

    public function test_register_rejects_manifest_without_required_field(): void
    {
        $attrs = $this->validManifestAttributes('broken');
        unset($attrs['charter']);

        $this->expectException(DomainRuntimeException::class);
        $this->expectExceptionMessageMatches('/missing required field \[charter\]/');
        app(DomainManifestRegistryService::class)->register($attrs);
    }

    public function test_register_rejects_invalid_status(): void
    {
        $attrs = $this->validManifestAttributes('demo');
        $attrs['status'] = 'banana';

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessageMatches('/status \[banana\] not allowed/');
        app(DomainManifestRegistryService::class)->register($attrs);
    }

    /**
     * @return array<string,mixed>
     */
    public static function validManifestAttributes(string $domainId): array
    {
        return [
            'domain_id' => $domainId,
            'name' => 'Demo Domain',
            'status' => 'scaffold',
            'charter' => ['mission' => 'demo', 'outcomes' => ['x']],
            'ontology' => ['x'],
            'departments' => ['a'],
            'flow_profiles' => ['flow.a'],
            'tools_allowed' => ['tool.a'],
            'evidence_schema' => ['doc'],
            'quality_gates' => ['gate.a'],
            'handoff_rules' => ['allowed' => [], 'forbidden' => []],
            'delivery_types' => ['artifact'],
            'metrics' => ['metric.a'],
            'forbidden_actions' => ['forbidden.a'],
            'maturity_stage' => 1,
            'owner' => 'qa',
        ];
    }
}
