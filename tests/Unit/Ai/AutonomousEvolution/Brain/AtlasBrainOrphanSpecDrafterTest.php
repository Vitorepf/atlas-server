<?php

declare(strict_types=1);

namespace Tests\Unit\Ai\AutonomousEvolution\Brain;

use App\Services\Ai\AutonomousEvolution\AtlasLoopHarnessGuard;
use App\Services\Ai\AutonomousEvolution\Brain\AtlasBrainOrphanSpecDrafter;
use App\Services\Ai\SelfConstruction\AtlasTaskPacketQualityInspector;
use Tests\TestCase;

/**
 * FROZEN proof of the orphan spec drafter — the S4 brain-as-author embryo. Proves the draft maps an App\
 * FQCN to the canonical paths + objective + acceptance, that the resulting spec actually PASSES the live
 * inspector (no blocking deficiencies — passes_clean or advisory_only), that malformed inputs are refused
 * fail-closed, that output is deterministic, and the organ is pétreo.
 */
final class AtlasBrainOrphanSpecDrafterTest extends TestCase
{
    public function test_draft_maps_an_app_fqcn_to_canonical_paths_and_acceptance(): void
    {
        $spec = (new AtlasBrainOrphanSpecDrafter)->draft('App\\Services\\Foo\\Bar', 'loop');

        self::assertIsArray($spec);
        self::assertContains('app/Services/Foo/Bar.php', $spec['allowed_files']);
        self::assertContains('tests/Unit/Services/Foo/BarTest.php', $spec['allowed_files']);
        self::assertSame(['php artisan test --filter=BarTest passes'], $spec['acceptance_criteria']);
        self::assertStringContainsString('App\\Services\\Foo\\Bar', $spec['objective']);
        self::assertStringContainsString('php artisan', $spec['objective']);
        self::assertSame('brain:orphan_spec_drafter', $spec['source']);
    }

    public function test_drafted_spec_passes_the_live_inspector_with_no_blocking_deficiencies(): void
    {
        $spec = (new AtlasBrainOrphanSpecDrafter)->draft('App\\Services\\Foo\\Bar', 'loop');
        $report = (new AtlasTaskPacketQualityInspector)->inspect($spec);

        self::assertSame([], $report['blocking_deficiencies'], 'a drafted spec must clear every BLOCKING deficiency by construction');
        self::assertTrue($report['self_sufficient']);
    }

    public function test_draft_refuses_a_non_app_fqcn(): void
    {
        self::assertNull((new AtlasBrainOrphanSpecDrafter)->draft('Vendor\\Foo\\Bar', 'loop'));
    }

    public function test_draft_refuses_empty_or_path_traversal_input(): void
    {
        $drafter = new AtlasBrainOrphanSpecDrafter;
        self::assertNull($drafter->draft('', 'loop'));
        self::assertNull($drafter->draft('App\\..\\X', 'loop'));
        self::assertNull($drafter->draft('App\\...\\X', 'loop'));
    }

    public function test_draft_is_deterministic_same_input_same_output(): void
    {
        $d = new AtlasBrainOrphanSpecDrafter;
        self::assertSame($d->draft('App\\X\\Foo', 'loop'), $d->draft('App\\X\\Foo', 'loop'));
    }

    public function test_drafter_organ_is_a_petreo_forbidden_self_target(): void
    {
        $verdict = app(AtlasLoopHarnessGuard::class)->admit(
            'app/Services/Ai/AutonomousEvolution/Brain/AtlasBrainOrphanSpecDrafter.php',
            true
        );

        self::assertSame('forbidden', $verdict);
    }
}
