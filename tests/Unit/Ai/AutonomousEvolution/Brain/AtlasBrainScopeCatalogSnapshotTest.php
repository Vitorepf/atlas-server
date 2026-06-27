<?php

declare(strict_types=1);

namespace Tests\Unit\Ai\AutonomousEvolution\Brain;

use App\Services\Ai\AutonomousEvolution\AtlasLoopHarnessGuard;
use App\Services\Ai\AutonomousEvolution\Brain\AtlasBrainScopeCatalogSnapshot;
use Tests\TestCase;

final class AtlasBrainScopeCatalogSnapshotTest extends TestCase
{
    public function test_snapshot_enumerates_configured_scopes_sorted(): void
    {
        config()->set('atlas.brain.scopes', [
            'muscle' => ['label' => 'Muscle', 'roots' => ['app/Models', 'app/Http'], 'docs_roots' => [], 'meta_harness' => false],
            'loop' => ['label' => 'Loop', 'roots' => ['app/Services/Ai'], 'docs_roots' => ['docs/loop'], 'meta_harness' => true],
        ]);
        config()->set('atlas.brain.default_scope', 'loop');

        $r = (new AtlasBrainScopeCatalogSnapshot)->snapshot();
        self::assertSame(2, $r['count']);
        self::assertSame('loop', $r['default_scope']);
        // sorted by slug asc → loop before muscle.
        self::assertSame('loop', $r['scopes'][0]['slug']);
        self::assertTrue($r['scopes'][0]['meta_harness']);
        self::assertSame(1, $r['scopes'][0]['docs_roots_count']);
        self::assertSame('muscle', $r['scopes'][1]['slug']);
        self::assertSame(2, $r['scopes'][1]['roots_count']);
    }

    public function test_organ_is_a_petreo_forbidden_self_target(): void
    {
        $verdict = app(AtlasLoopHarnessGuard::class)->admit(
            'app/Services/Ai/AutonomousEvolution/Brain/AtlasBrainScopeCatalogSnapshot.php',
            true
        );
        self::assertSame('forbidden', $verdict);
    }
}
