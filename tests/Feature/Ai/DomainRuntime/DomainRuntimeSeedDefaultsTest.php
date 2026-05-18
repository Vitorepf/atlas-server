<?php

namespace Tests\Feature\Ai\DomainRuntime;

use App\Models\AiDomainManifest;
use App\Services\Ai\DomainRuntime\DomainManifestRegistryService;
use App\Services\Ai\DomainRuntime\DomainSeedManifests;
use Tests\Concerns\CreatesDomainRuntimeTables;
use Tests\TestCase;

class DomainRuntimeSeedDefaultsTest extends TestCase
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

    public function test_seed_creates_all_default_domains(): void
    {
        $payload = app(DomainManifestRegistryService::class)->seedDefaults(DomainSeedManifests::all());

        $this->assertTrue($payload['ok']);
        $this->assertSame(9, $payload['summary']['created']);
        $this->assertSame(9, $payload['summary']['total_after']);

        $domains = AiDomainManifest::query()->pluck('domain_id')->all();
        foreach ([
            'software', 'research', 'strategy', 'finance', 'marketing',
            'cyber', 'personal_development', 'automation', 'operations',
        ] as $expected) {
            $this->assertContains($expected, $domains, "expected seed domain [{$expected}]");
        }
    }

    public function test_seed_is_idempotent_and_does_not_duplicate(): void
    {
        $registry = app(DomainManifestRegistryService::class);
        $registry->seedDefaults(DomainSeedManifests::all());
        $second = $registry->seedDefaults(DomainSeedManifests::all());

        $this->assertSame(0, $second['summary']['created']);
        $this->assertSame(9, $second['summary']['skipped']);
        $this->assertSame(9, AiDomainManifest::query()->count());
    }

    public function test_each_seeded_manifest_has_at_least_one_capability(): void
    {
        app(DomainManifestRegistryService::class)->seedDefaults(DomainSeedManifests::all());

        foreach (AiDomainManifest::query()->get() as $manifest) {
            $this->assertGreaterThan(
                0,
                $manifest->capabilities()->count(),
                "manifest [{$manifest->domain_id}] must have at least one seeded capability",
            );
        }
    }
}
