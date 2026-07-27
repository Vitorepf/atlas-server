<?php

declare(strict_types=1);

namespace App\Services\Ai\AutonomousEvolution\Discovery;

final class AtlasLoopWorkShapeRouterSupport
{
    /**
     * @param  array<string,mixed>  $signals
     * @param  array<string,mixed>  $log
     * @return array{shape:string, reason:string, log:array<string,mixed>}
     */
    public function decideRoutableShape(
        array $signals,
        array $log,
        int $minCyclomatic,
        float $leverageFloor,
        ?int $callers,
        int $cyclomatic,
        float $leverage,
        bool $hasSibling,
        int $frameworkReach,
    ): array {
        $wired = is_int($callers) && $callers >= 1;

        // 1.5 HEAVIER SHAPES (default-OFF; flag-gated so the function is byte-identical until armed):
        //     these are strictly higher-leverage REFINEMENTS of the single-file refactor and so are
        //     evaluated BEFORE it. Each requires the SAME wired+test-backed safety the single-file
        //     lane requires (the loop can never edit the frozen sibling test), plus its own structural
        //     trigger. A missing trigger / OFF flag simply falls through to the single-file lane —
        //     the rédea never LOSES a shape, it only GAINS the ability to name a heavier one.

        // multi_file — a detected COUPLED CLUSTER (hub + >=1 covered caller) carried on the signals
        //     packet. The heaviest shape: routes to synthesizeMultiFileRefactor(). Requires a wired,
        //     test-backed hub (never names multi_file for an unmeasured/untested target).
        if ((bool) config('atlas.loop.decision_multi_file_shape_enabled', false)
            && $wired && $hasSibling && $this->hasCoupledCluster($signals)) {
            $log['coupled_cluster_size'] = $this->coupledClusterSize($signals);

            return ['shape' => AtlasLoopWorkShapeRouter::SHAPE_MULTI_FILE, 'reason' => 'coupled_cluster_hub_plus_callers', 'log' => $log];
        }

        // extract_class — a WIRED, test-backed hub whose worst-method cyclomatic is WELL above the
        //     floor (>= extract_class_min_cyclomatic, default ~15): a god-method that wants a 2-file
        //     split, not an in-place nudge. Routes to synthesizeFrameworkRefactor(extractClass:true).
        $extractFloor = max($minCyclomatic, (int) config('atlas.loop.extract_class_min_cyclomatic', 15));
        $log['extract_class_floor'] = $extractFloor;
        if ((bool) config('atlas.loop.decision_extract_class_shape_enabled', false)
            && $wired && $hasSibling && $cyclomatic >= $extractFloor) {
            return ['shape' => AtlasLoopWorkShapeRouter::SHAPE_EXTRACT_CLASS, 'reason' => 'wired_godmethod_test_backed_hub', 'log' => $log];
        }

        // 2. Substantive refactor lane: a WIRED, complex, test-backed hub (the proven-drop lane),
        //    OR a high-leverage framework target. Requires MEASURED callers>=1 (never assumes).
        if ($wired && $cyclomatic >= $minCyclomatic && $hasSibling) {
            return ['shape' => AtlasLoopWorkShapeRouter::SHAPE_REFACTOR, 'reason' => 'wired_complex_test_backed_hub', 'log' => $log];
        }
        if ($leverage >= $leverageFloor && $frameworkReach > 0 && $wired) {
            return ['shape' => AtlasLoopWorkShapeRouter::SHAPE_REFACTOR, 'reason' => 'high_leverage_wired_framework_target', 'log' => $log];
        }

        // 3. Default: the edge-gap fix lane (today's behavior; fail-open destination).
        return ['shape' => AtlasLoopWorkShapeRouter::SHAPE_EDGE_FIX, 'reason' => 'default_edge_fix', 'log' => $log];
    }

    /**
     * Does the signals packet carry a detected COUPLED CLUSTER (a hub + >=1 covered caller)?
     *
     * Discovery stamps the cluster under one of a few provider-safe keys; we accept any of them and
     * require strictly >=2 files (the hub + at least one caller). PURE: a missing/garbled cluster
     * returns false, so the multi_file shape never fires on an unmeasured target (fail-open to the
     * single-file lane). The router only DETECTS coupling here; the synthesizer re-resolves the real
     * cluster + re-runs every sibling test downstream (the authority — the router never bypasses it).
     *
     * @param  array<string,mixed>  $signals
     */
    public function hasCoupledCluster(array $signals): bool
    {
        return $this->coupledClusterSize($signals) >= 2;
    }

    /**
     * The size (file count) of a detected coupled cluster carried on the signals, or 0 when none.
     * Tolerant of the shapes discovery may stamp: an explicit count, a list of cluster files, or a
     * nested {files:[...]} / {callers:[...]} cluster descriptor (hub + callers).
     *
     * @param  array<string,mixed>  $signals
     */
    public function coupledClusterSize(array $signals): int
    {
        $cluster = $signals['coupled_cluster'] ?? null;

        if (is_int($cluster)) {
            return max(0, $cluster);
        }
        if (is_array($cluster)) {
            // {files:[hub, ...callers]} or {callers:[...]} (hub implicit) or a plain list of files.
            if (isset($cluster['files']) && is_array($cluster['files'])) {
                return count($cluster['files']);
            }
            if (isset($cluster['callers']) && is_array($cluster['callers'])) {
                return 1 + count($cluster['callers']); // hub + covered callers
            }
            if (array_is_list($cluster)) {
                return count($cluster);
            }
        }

        // Fallback: an explicit numeric cluster size signal.
        $size = $signals['coupled_cluster_size'] ?? null;

        return is_int($size) ? max(0, $size) : 0;
    }
}
