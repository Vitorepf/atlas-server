<?php

declare(strict_types=1);

namespace Tests\Feature\Ai;

use App\Services\Ai\SelfConstruction\AtlasSelfConstructionReadinessService;
use App\Services\Ai\SelfConstruction\ReadinessProjectionRuntimePromotionSection;
use Tests\TestCase;

/**
 * Locks the contract of ReadinessProjectionRuntimePromotionSection —
 * the atlasSelfConstructionRuntimePromotion* projection concern extracted
 * from the god-class AtlasSelfConstructionReadinessService.
 */
final class AtlasAiSelfConstructionReadinessProjectionRuntimePromotionSectionTest extends TestCase
{
    public function test_section_class_is_resolvable(): void
    {
        $section = new ReadinessProjectionRuntimePromotionSection(
            fn (array $payload): string => 'hash:'.md5((string) json_encode($payload))
        );
        $this->assertInstanceOf(ReadinessProjectionRuntimePromotionSection::class, $section);
    }

    public function test_all_36_runtime_promotion_methods_exist_on_section(): void
    {
        $section = new ReadinessProjectionRuntimePromotionSection(
            fn (array $payload): string => 'hash'
        );

        $ref = new \ReflectionClass($section);
        $publicMethods = array_filter(
            $ref->getMethods(\ReflectionMethod::IS_PUBLIC),
            fn (\ReflectionMethod $m): bool => str_starts_with($m->getName(), 'atlasSelfConstructionRuntimePromotion')
        );
        $this->assertGreaterThanOrEqual(
            36,
            count($publicMethods),
            'Section must expose at least 36 atlasSelfConstructionRuntimePromotion* public methods'
        );

        // Spot-check first and last.
        foreach ([
            'atlasSelfConstructionRuntimePromotionReceiptDraftContract',
            'atlasSelfConstructionRuntimePromotionOperatorRunbookExporterStatus',
        ] as $name) {
            $this->assertTrue(
                method_exists($section, $name),
                "ReadinessProjectionRuntimePromotionSection::{$name} must exist"
            );
        }
    }

    public function test_runtime_service_delegates_to_section(): void
    {
        $runtime = app(AtlasSelfConstructionReadinessService::class);
        foreach ([
            'atlasSelfConstructionRuntimePromotionReceiptDraftContract',
            'atlasSelfConstructionRuntimePromotionOperatorRunbookExporterStatus',
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
        $ref = new \ReflectionMethod($runtime, 'runtimePromotionSection');
        $ref->setAccessible(true);
        $section = $ref->invoke($runtime);

        $this->assertInstanceOf(ReadinessProjectionRuntimePromotionSection::class, $section);
    }
}