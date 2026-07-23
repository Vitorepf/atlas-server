<?php

declare(strict_types=1);

namespace Tests\Unit\Ai\Aaeos;

use App\Services\Ai\AgenticEngineeringOs\Maturity\AtlasPhaseRouterService;
use Tests\TestCase;

final class AtlasAaeosPhaseRouterServiceTest extends TestCase
{
    public function test_default_phase_is_legacy_and_inactive(): void
    {
        config(['atlas.aaeos.http_path_phase' => 'legacy']);

        $router = new AtlasPhaseRouterService;

        self::assertSame('legacy', $router->configuredPhase());
        self::assertTrue($router->isValid());
        self::assertTrue($router->isLegacy());
        self::assertFalse($router->isActive());
        self::assertFalse($router->atLeastPhase1());
    }

    public function test_active_phase_helpers_are_strict(): void
    {
        self::assertFalse(AtlasPhaseRouterService::isActivePhase('legacy'));
        self::assertTrue(AtlasPhaseRouterService::isActivePhase('1'));
        self::assertTrue(AtlasPhaseRouterService::isActivePhase('2'));
        self::assertTrue(AtlasPhaseRouterService::isActivePhase('3'));
        self::assertTrue(AtlasPhaseRouterService::isActivePhase('4'));
        self::assertFalse(AtlasPhaseRouterService::isActivePhase(''));
        self::assertFalse(AtlasPhaseRouterService::isActivePhase('0'));
        self::assertFalse(AtlasPhaseRouterService::isActivePhase('5'));
    }

    public function test_phase_ordering_never_treats_invalid_values_as_zero(): void
    {
        self::assertFalse(AtlasPhaseRouterService::phaseAtLeast('legacy', '1'));
        self::assertFalse(AtlasPhaseRouterService::phaseAtLeast('0', '1'));
        self::assertFalse(AtlasPhaseRouterService::phaseAtLeast('99', '1'));
        self::assertFalse(AtlasPhaseRouterService::phaseAtLeast('2', '99'));

        self::assertTrue(AtlasPhaseRouterService::phaseAtLeast('2', '1'));
        self::assertTrue(AtlasPhaseRouterService::phaseAtLeast('2', '2'));
        self::assertFalse(AtlasPhaseRouterService::phaseAtLeast('2', '3'));
        self::assertFalse(AtlasPhaseRouterService::phaseAtLeast('2', '4'));
    }

    public function test_status_snapshot_reports_capabilities_for_phase_four(): void
    {
        $snapshot = (new AtlasPhaseRouterService('4'))->statusSnapshot();

        self::assertSame('atlas.aaeos.phase_router.v1', $snapshot['schema_version']);
        self::assertSame('4', $snapshot['configured_phase']);
        self::assertTrue($snapshot['is_valid']);
        self::assertTrue($snapshot['is_active']);
        self::assertFalse($snapshot['is_legacy']);

        foreach (['intent_capture', 'disambiguation', 'placement', 'classification', 'policy_gate', 'topology', 'routing', 'spec', 'tasks', 'receipt'] as $capability) {
            self::assertTrue($snapshot['phase_capabilities'][$capability], "capability {$capability} must be active at phase 4");
        }
    }

    public function test_status_snapshot_reports_invalid_phase_honestly(): void
    {
        $snapshot = (new AtlasPhaseRouterService('99'))->statusSnapshot();

        self::assertSame('99', $snapshot['configured_phase']);
        self::assertFalse($snapshot['is_valid']);
        self::assertFalse($snapshot['is_active']);
        self::assertFalse($snapshot['is_legacy']);
        self::assertSame('Invalid AAEOS HTTP path phase.', $snapshot['description']);

        foreach ($snapshot['phase_capabilities'] as $capability => $enabled) {
            self::assertFalse($enabled, "capability {$capability} must stay inactive for invalid phases");
        }
    }
}
