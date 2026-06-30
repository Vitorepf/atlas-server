<?php

declare(strict_types=1);

namespace App\Services\Ai\AutonomousEvolution;

use Closure;

/**
 * ARMED-COVERAGE REPORTER — the honest read-model over {@see AtlasLoopCrossLeverageRegistry} (the intended
 * wiring) + the live tree (the actual wiring), the historical companion of {@see AtlasLoopWiringIntentLedger}.
 * For each cross-leverage primitive it reports how many of its INTENDED consumer sites are grep-positive for
 * the primitive RIGHT NOW (wired) and which are still dark (missing).
 *
 * This is the fact-driven answer to the BUILT≫ARMED finding (loop-complete-gap-map) and the "22 primitivos
 * PARKED 0-prod" diagnosis (loop-architecture-debt-wiring-gap): it surfaces, per site, where leverage is dark.
 *
 * It NEVER edits, merges, or proposes wiring; it cannot mask or SCORE a gap. ANTI-GOODHART: no historical
 * coverage average is stored — every figure is RECOMPUTED from the live tree each call, so it can never drift
 * to a stale rosy number. Flag ATLAS_LOOP_ARMED_COVERAGE_REPORTER_ENABLED default OFF ⇒ report() returns []
 * (byte-identical no-op). Pure read: zero writes, zero DB/provider.
 */
final class AtlasLoopArmedCoverageReporter
{
    public const SCHEMA = 'atlas.loop.armed_coverage_report.v1';

    /**
     * @param  Closure():list<array<string,mixed>>|null  $primitivesResolver  test seam: stub manifest source
     */
    public function __construct(
        private readonly ?AtlasLoopCrossLeverageRegistry $registry = null,
        private readonly ?Closure $primitivesResolver = null,
        private readonly ?string $repoRoot = null,
    ) {}

    /**
     * @return array<string, array{intended:int, wired:int, missing:list<string>}>  keyed by primitive_id
     */
    public function report(): array
    {
        if (! (bool) config('atlas.loop.armed_coverage_reporter_enabled', false)) {
            return []; // flag OFF ⇒ byte-identical no-op
        }

        $primitives = $this->primitivesResolver !== null
            ? (array) ($this->primitivesResolver)()
            : ($this->registry ?? new AtlasLoopCrossLeverageRegistry)->primitives();

        $repoRoot = rtrim($this->repoRoot ?? base_path(), '/');

        $report = [];
        foreach ($primitives as $primitive) {
            if (! is_array($primitive)) {
                continue;
            }
            $primitiveId = (string) ($primitive['primitive_id'] ?? '');
            if ($primitiveId === '') {
                continue;
            }
            $className = ($filePath = (string) ($primitive['file_path'] ?? '')) !== ''
                ? pathinfo($filePath, PATHINFO_FILENAME)
                : '';
            $consumers = array_values(array_filter(
                array_map(static fn ($c): string => (string) $c, (array) ($primitive['intended_consumer_paths'] ?? [])),
                static fn (string $c): bool => $c !== '',
            ));

            $wired = 0;
            $missing = [];
            foreach ($consumers as $consumer) {
                $source = (string) @file_get_contents($repoRoot.'/'.ltrim($consumer, '/'));
                // Word-boundary match: a superstring sibling (e.g. AtlasLoopLeverageSelectorAdvanced)
                // must NOT count as wired — str_contains would match the primitive name as a bare substring.
                if ($className !== '' && $source !== '' && preg_match('/\b'.preg_quote($className, '/').'\b/', $source) === 1) {
                    $wired++;
                } else {
                    $missing[] = $consumer;
                }
            }
            sort($missing, SORT_STRING);

            $report[$primitiveId] = [
                'intended' => count($consumers),
                'wired' => $wired,
                'missing' => $missing,
            ];
        }

        ksort($report, SORT_STRING);

        return $report;
    }
}
