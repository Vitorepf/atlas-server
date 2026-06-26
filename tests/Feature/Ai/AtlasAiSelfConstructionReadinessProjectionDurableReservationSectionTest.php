<?php

declare(strict_types=1);

namespace Tests\Feature\Ai;

use App\Services\Ai\SelfConstruction\AtlasSelfConstructionReadinessService;
use App\Services\Ai\SelfConstruction\ReadinessProjectionDurableReservationSection;
use Tests\TestCase;

/**
 * Locks the contract of ReadinessProjectionDurableReservationSection —
 * the durableReservation* projection concern extracted from the god-class
 * AtlasSelfConstructionReadinessService.
 */
final class AtlasAiSelfConstructionReadinessProjectionDurableReservationSectionTest extends TestCase
{
    public function test_section_class_is_resolvable(): void
    {
        $section = new ReadinessProjectionDurableReservationSection(
            fn (array $payload): string => 'hash:'.md5((string) json_encode($payload))
        );
        $this->assertInstanceOf(ReadinessProjectionDurableReservationSection::class, $section);
    }

    public function test_all_18_durable_reservation_methods_exist_on_section(): void
    {
        $section = new ReadinessProjectionDurableReservationSection(
            fn (array $payload): string => 'hash'
        );

        $ref = new \ReflectionClass($section);
        $publicMethods = array_filter(
            $ref->getMethods(\ReflectionMethod::IS_PUBLIC),
            fn (\ReflectionMethod $m): bool => str_starts_with($m->getName(), 'durableReservation')
        );
        $this->assertGreaterThanOrEqual(
            18,
            count($publicMethods),
            'Section must expose at least 18 durableReservation* public methods'
        );

        // Spot-check well-known methods.
        foreach ([
            'durableReservationLedgerImplementationPlan',
            'durableReservationRuntimeBuildPacket',
        ] as $name) {
            $this->assertTrue(
                method_exists($section, $name),
                "ReadinessProjectionDurableReservationSection::{$name} must exist"
            );
        }
    }

    public function test_runtime_service_delegates_to_section(): void
    {
        $runtime = app(AtlasSelfConstructionReadinessService::class);
        foreach ([
            'durableReservationLedgerImplementationPlan',
            'durableReservationRuntimeBuildPacket',
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
        $ref = new \ReflectionMethod($runtime, 'durableReservationSection');
        $ref->setAccessible(true);
        $section = $ref->invoke($runtime);

        $this->assertInstanceOf(ReadinessProjectionDurableReservationSection::class, $section);
    }
}