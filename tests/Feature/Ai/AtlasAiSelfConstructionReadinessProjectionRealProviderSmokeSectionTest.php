<?php

declare(strict_types=1);

namespace Tests\Feature\Ai;

use App\Services\Ai\SelfConstruction\AtlasSelfConstructionReadinessService;
use App\Services\Ai\SelfConstruction\ReadinessProjectionRealProviderSmokeSection;
use Tests\TestCase;

/**
 * Locks the contract of ReadinessProjectionRealProviderSmokeSection —
 * the atlasSelfConstructionRealProviderSmoke* projection concern extracted
 * from the god-class AtlasSelfConstructionReadinessService.
 */
final class AtlasAiSelfConstructionReadinessProjectionRealProviderSmokeSectionTest extends TestCase
{
    public function test_section_class_is_resolvable(): void
    {
        $section = new ReadinessProjectionRealProviderSmokeSection(
            fn (array $payload): string => 'hash:'.md5((string) json_encode($payload))
        );
        $this->assertInstanceOf(ReadinessProjectionRealProviderSmokeSection::class, $section);
    }

    public function test_all_32_real_provider_smoke_methods_exist_on_section(): void
    {
        $section = new ReadinessProjectionRealProviderSmokeSection(
            fn (array $payload): string => 'hash'
        );

        $ref = new \ReflectionClass($section);
        $publicMethods = array_filter(
            $ref->getMethods(\ReflectionMethod::IS_PUBLIC),
            fn (\ReflectionMethod $m): bool => str_starts_with($m->getName(), 'atlasSelfConstructionRealProviderSmoke')
        );
        $this->assertGreaterThanOrEqual(
            32,
            count($publicMethods),
            'Section must expose at least 32 atlasSelfConstructionRealProviderSmoke* public methods'
        );

        foreach ([
            'atlasSelfConstructionRealProviderSmokeEvidenceDossierContract',
            'atlasSelfConstructionRealProviderSmokeOperatorRunbookExporterStatus',
        ] as $name) {
            $this->assertTrue(
                method_exists($section, $name),
                "ReadinessProjectionRealProviderSmokeSection::{$name} must exist"
            );
        }
    }

    public function test_runtime_service_delegates_to_section(): void
    {
        $runtime = app(AtlasSelfConstructionReadinessService::class);
        foreach ([
            'atlasSelfConstructionRealProviderSmokeEvidenceDossierContract',
            'atlasSelfConstructionRealProviderSmokeOperatorRunbookExporterStatus',
        ] as $method) {
            $this->assertTrue(
                method_exists($runtime, $method),
                "AtlasSelfConstructionReadinessService::{$method} must exist as a delegator"
            );
        }
    }

    public function test_section_can_be_resolved_via_runtime_lazy_resolver(): void
    {
        $runtime = app(AtlasSelfConstructionReadinessService::class);
        $ref = new \ReflectionMethod($runtime, 'realProviderSmokeSection');
        $ref->setAccessible(true);
        $section = $ref->invoke($runtime);

        $this->assertInstanceOf(ReadinessProjectionRealProviderSmokeSection::class, $section);
    }
}