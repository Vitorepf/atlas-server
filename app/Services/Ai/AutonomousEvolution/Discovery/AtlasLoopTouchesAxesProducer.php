<?php

declare(strict_types=1);

namespace App\Services\Ai\AutonomousEvolution\Discovery;

/**
 * LOOP-OS · FASE 4 · SLICE 7.5 — the `touches_axes` producer (makes bottleneck_relief MACHINE-computable).
 *
 * The {@see AtlasLoopExpectedValueDecider} weights each candidate by how much it relieves the BINDING
 * system axis — but only if it knows WHICH axes an UNBUILT candidate would move. Verified: that producer
 * did not exist; `touches_axes` arrived empty, so `bottleneck_relief` silently collapsed to a constant and
 * the EV math could not pivot. This fills the gap WITHOUT a provider call: it derives the axes
 * deterministically from the target path + the measured caller count + the candidate's cyclomatic/verifiable
 * signals, using the SAME axis definitions {@see AtlasLoopUtilityGradeService} re-resolves at grade time.
 *
 * HONEST BOUNDARY (the canon's anti-Goodhart line): an axis is emitted as a machine claim ONLY when it can
 * be ASSERTED from deterministic signals. The `safety` axis (1 − incidents) needs to know the work is a
 * bug-fix/regression-defense — that is a model judgement pre-build — so it is NEVER machine-claimed here; it
 * is reported under `advisory_axes`. `non_trivial` is claimed ONLY through the deterministic refactor door
 * (a sibling-test-verifiable change on a genuinely complex file), never the model-bound category door. So a
 * candidate's machine `touches_axes` is a set the cert chain could later confirm — not an asserted hope.
 */
final class AtlasLoopTouchesAxesProducer
{
    public const SCHEMA_VERSION = 'atlas.loop.touches_axes.v1';

    /** Axes whose pre-build value needs a model judgement — excluded from the machine claim. */
    private const ADVISORY_AXES = ['safety'];

    /**
     * Machine-claimable axes an unbuilt candidate would move. Pure: no DB, no git, no provider — given the
     * same signals it always yields the same set (the property that makes an EV-plateau meaningful).
     *
     * @return array{schema_version:string, target_kind:string, touches_axes:list<string>,
     *               advisory_axes:list<string>}
     */
    public function produce(
        string $path,
        int $callerCount,
        int $cyclomatic,
        bool $verifiable,
        ?int $hubThreshold = null,
        ?int $refactorCyclomatic = null,
    ): array {
        $hubThreshold = $hubThreshold ?? max(2, (int) config('atlas.ai.loop.utility_grade_hub_callers', 3));
        $refactorCyclomatic = $refactorCyclomatic ?? max(1, (int) config('atlas.loop.decision_min_refactor_cyclomatic', 10));

        $kind = $this->targetKind($path);
        $real = $kind === 'real';

        $axes = [];
        if ($real) {
            // REAL_TARGET — a non-generated/test/docs production file moves the real-target share.
            $axes[] = 'real_target';
            // WIRED — at least one measured production caller. callerCount 0 (orphan OR unmeasured) is
            // NOT claimed: we only assert wired-relief when a caller is provably present (fail-closed).
            if ($callerCount >= 1) {
                $axes[] = 'wired';
            }
            // COMPOUNDING — a HUB (fan-in ≥ threshold): hardening it protects many callers.
            if ($callerCount >= $hubThreshold) {
                $axes[] = 'compounding';
            }
            // NON_TRIVIAL — the deterministic refactor door only: a sibling-test-verifiable change on a
            // genuinely complex file. The category door (bug/perf/edge) is model-bound ⇒ not machine-claimed.
            if ($verifiable && $cyclomatic >= $refactorCyclomatic) {
                $axes[] = 'non_trivial';
            }
        }

        return [
            'schema_version' => self::SCHEMA_VERSION,
            'target_kind' => $kind,
            'touches_axes' => array_values($axes),
            'advisory_axes' => self::ADVISORY_AXES,
        ];
    }

    /**
     * Convenience over a {@see AtlasLoopObjectiveProducer::gather()} packet — the producer reads the exact
     * fields gather() already measured (path, caller_count, cyclomatic, verifiable).
     *
     * @param  array<string,mixed>  $packet
     * @return list<string>
     */
    public function forPacket(array $packet): array
    {
        return $this->produce(
            (string) ($packet['path'] ?? ''),
            (int) ($packet['caller_count'] ?? 0),
            (int) ($packet['cyclomatic'] ?? 0),
            (bool) ($packet['verifiable'] ?? false),
        )['touches_axes'];
    }

    /**
     * Classify a target path exactly as {@see AtlasLoopUtilityGradeService} does (generated/test/docs/real)
     * — replicated as PURE path logic so the producer never edits or depends on the frozen grade service.
     */
    private function targetKind(string $path): string
    {
        $path = str_replace('\\', '/', ltrim($path, '/'));
        if (preg_match('#(^|/)Generated(/|$)#', $path) === 1) {
            return 'generated';
        }
        if (str_contains($path, '/tests/') || str_starts_with($path, 'tests/') || str_ends_with($path, 'Test.php')) {
            return 'test';
        }
        if (str_starts_with($path, 'docs/') || str_ends_with($path, '.md')) {
            return 'docs';
        }

        return 'real';
    }
}
