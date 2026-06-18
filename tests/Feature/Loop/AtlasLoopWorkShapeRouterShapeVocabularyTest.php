<?php

declare(strict_types=1);

namespace Tests\Feature\Loop;

use App\Services\Ai\AutonomousEvolution\Discovery\AtlasLoopWorkShapeRouter;
use Tests\TestCase;

/**
 * SHAPE VOCABULARY BROADENING — frozen proof that the deterministic work-shape selector
 * ({@see AtlasLoopWorkShapeRouter::decideShape}) can now NAME the two HEAVIER refactor shapes the
 * rédea already depends on, not only the smallest single-file shape:
 *
 *   - extract_class : a WIRED, test-backed hub whose worst-method cyclomatic is well above the floor
 *                     (>= extract_class_min_cyclomatic) — the 2-file god-method split lane.
 *   - multi_file    : a detected COUPLED CLUSTER (hub + >=1 covered caller) on the signals packet —
 *                     the >=2-file cluster refactor lane.
 *
 * The load-bearing guard is BYTE-IDENTICAL DEFAULT: with both new shape flags OFF (the default), the
 * very same packets that would now route to a heavier shape produce EXACTLY today's single-file
 * refactor decision — proven by deep-equality against the pre-change output. So the broadening ships
 * inert until the operator arms it, and the default origination path is provably unchanged.
 */
final class AtlasLoopWorkShapeRouterShapeVocabularyTest extends TestCase
{
    private function router(): AtlasLoopWorkShapeRouter
    {
        return new AtlasLoopWorkShapeRouter;
    }

    /** A complex, WIRED, test-backed hub — worst-method cyclomatic 22, above the extract-class floor. */
    private function extractClassPacket(): array
    {
        return [
            'impact_real_callers' => 6,
            'cyclomatic' => 22,
            'has_sibling_test' => true,
            'framework_reach' => 2,
        ];
    }

    /** A WIRED, test-backed hub carrying a detected 3-file coupled cluster (hub + 2 covered callers). */
    private function multiFilePacket(): array
    {
        return [
            'impact_real_callers' => 4,
            'cyclomatic' => 14,
            'has_sibling_test' => true,
            'coupled_cluster' => [
                'files' => [
                    'app/Services/Hub.php',
                    'app/Services/CallerA.php',
                    'app/Services/CallerB.php',
                ],
            ],
        ];
    }

    private function pinNewShapeFlags(bool $on): void
    {
        config([
            'atlas.loop.decision_extract_class_shape_enabled' => $on,
            'atlas.loop.decision_multi_file_shape_enabled' => $on,
            // Pin the floor so the test is env-independent (22 >= 15 is the worst-method trigger).
            'atlas.loop.extract_class_min_cyclomatic' => 15,
        ]);
    }

    // (a) extract_class shape, flag ON.
    public function test_complex_wired_test_backed_hub_routes_to_extract_class_when_armed(): void
    {
        $this->pinNewShapeFlags(true);

        $d = $this->router()->decideShape($this->extractClassPacket());

        $this->assertSame(AtlasLoopWorkShapeRouter::SHAPE_EXTRACT_CLASS, $d['shape']);
        $this->assertSame('extract_class', $d['shape']);
        $this->assertSame('wired_godmethod_test_backed_hub', $d['reason']);
        // The decision is auditable: the floor it cleared is stamped in the log.
        $this->assertSame(15, $d['log']['extract_class_floor']);
    }

    // (b) multi_file shape, flag ON. The cluster of >=2 files is what makes the heavier shape eligible.
    public function test_coupled_cluster_routes_to_multi_file_when_armed(): void
    {
        $this->pinNewShapeFlags(true);

        $d = $this->router()->decideShape($this->multiFilePacket());

        $this->assertSame(AtlasLoopWorkShapeRouter::SHAPE_MULTI_FILE, $d['shape']);
        $this->assertSame('multi_file', $d['shape']);
        $this->assertSame('coupled_cluster_hub_plus_callers', $d['reason']);
        $this->assertGreaterThanOrEqual(2, $d['log']['coupled_cluster_size']);
    }

    // multi_file dominates extract_class when BOTH triggers are present (the heaviest shape wins).
    public function test_multi_file_dominates_extract_class_when_both_apply(): void
    {
        $this->pinNewShapeFlags(true);

        $packet = $this->extractClassPacket();
        $packet['coupled_cluster'] = ['files' => ['a.php', 'b.php']]; // also a cluster

        $d = $this->router()->decideShape($packet);

        $this->assertSame(AtlasLoopWorkShapeRouter::SHAPE_MULTI_FILE, $d['shape']);
    }

    // (c) BYTE-IDENTICAL guard: with both flags OFF (default), the SAME two packets produce exactly
    //     today's single-file refactor decision. Deep-equality against the pre-change expected output.
    public function test_default_off_is_byte_identical_single_file_refactor(): void
    {
        $this->pinNewShapeFlags(false);

        // The pre-change behavior: BOTH packets are wired + complex + test-backed hubs, which the
        // ORIGINAL selector classifies as single_file_refactor with the wired-hub reason.
        $expectedExtract = [
            'shape' => AtlasLoopWorkShapeRouter::SHAPE_REFACTOR,
            'reason' => 'wired_complex_test_backed_hub',
        ];
        $expectedMulti = [
            'shape' => AtlasLoopWorkShapeRouter::SHAPE_REFACTOR,
            'reason' => 'wired_complex_test_backed_hub',
        ];

        $extract = $this->router()->decideShape($this->extractClassPacket());
        $multi = $this->router()->decideShape($this->multiFilePacket());

        $this->assertSame($expectedExtract['shape'], $extract['shape']);
        $this->assertSame($expectedExtract['reason'], $extract['reason']);
        $this->assertSame($expectedMulti['shape'], $multi['shape']);
        $this->assertSame($expectedMulti['reason'], $multi['reason']);

        // Neither default decision exposes the new heavier shapes — the vocabulary is truly inert.
        $this->assertNotSame(AtlasLoopWorkShapeRouter::SHAPE_EXTRACT_CLASS, $extract['shape']);
        $this->assertNotSame(AtlasLoopWorkShapeRouter::SHAPE_MULTI_FILE, $multi['shape']);
    }

    // Fail-open safety: with the flags ON, an UNMEASURED (null callers) or UNTESTED hub never gets a
    // heavier shape — the heavier shapes require the same wired + sibling-test safety as today.
    public function test_armed_but_unmeasured_or_untested_never_routes_heavier(): void
    {
        $this->pinNewShapeFlags(true);

        // No impact_real_callers => unmeasured => not wired => fail-open to edge_fix.
        $unmeasured = $this->router()->decideShape(['cyclomatic' => 22, 'has_sibling_test' => true, 'coupled_cluster' => ['files' => ['a.php', 'b.php']]]);
        $this->assertSame(AtlasLoopWorkShapeRouter::SHAPE_EDGE_FIX, $unmeasured['shape']);

        // Wired + complex but NO sibling test => cannot prove behavior preserved => never heavier.
        $untested = $this->router()->decideShape(['impact_real_callers' => 6, 'cyclomatic' => 22, 'has_sibling_test' => false, 'coupled_cluster' => ['files' => ['a.php', 'b.php']]]);
        $this->assertNotSame(AtlasLoopWorkShapeRouter::SHAPE_EXTRACT_CLASS, $untested['shape']);
        $this->assertNotSame(AtlasLoopWorkShapeRouter::SHAPE_MULTI_FILE, $untested['shape']);
    }

    // A confirmed orphan still skips even with the new shapes armed (the skip decision is unchanged).
    public function test_confirmed_orphan_still_skips_with_new_shapes_armed(): void
    {
        $this->pinNewShapeFlags(true);

        $d = $this->router()->decideShape(['orphan' => true, 'impact_real_callers' => 0, 'cyclomatic' => 22, 'has_sibling_test' => true]);
        $this->assertSame(AtlasLoopWorkShapeRouter::SHAPE_SKIP, $d['shape']);
    }
}
