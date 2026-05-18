<?php

namespace Tests\Feature\Ai\DomainRuntime;

use App\Services\Ai\DomainRuntime\DomainManifestRegistryService;
use App\Services\Ai\DomainRuntime\DomainRuntimeException;
use Tests\Concerns\CreatesDomainRuntimeTables;
use Tests\TestCase;

class DomainRuntimeDuplicateManifestTest extends TestCase
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

    public function test_register_rejects_duplicate_domain_id(): void
    {
        $registry = app(DomainManifestRegistryService::class);
        $registry->register(DomainRuntimeManifestRegistryTest::validManifestAttributes('dup_domain'));

        $this->expectException(DomainRuntimeException::class);
        $this->expectExceptionMessageMatches('/already exists; manifests are unique by domain_id/');
        $registry->register(DomainRuntimeManifestRegistryTest::validManifestAttributes('dup_domain'));
    }
}
