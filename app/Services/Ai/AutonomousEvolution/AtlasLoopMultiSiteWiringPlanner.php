<?php

declare(strict_types=1);

namespace App\Services\Ai\AutonomousEvolution;

use Closure;

/**
 * MULTI-SITE WIRING PLANNER — reads the {@see AtlasLoopCrossLeverageRegistry} manifest and, for every
 * (primitive, intended-consumer) pair, emits a deterministic wiring-INTENT record proving — by a grep-of-record
 * against the LIVE tree, never from memory — whether the primitive is actually wired into that consumer site.
 *
 * This is the answer to the loop-architecture-debt-wiring-gap: many primitives are BUILT but UNWIRED across
 * their N consumers. The planner makes that fact explicit per site: status='wired' iff the consumer file
 * mentions the primitive's class, else 'intended'.
 *
 * It NEVER edits code, certifies, or merges — the wiring action stays a downstream human/loop task. ANTI-
 * GOODHART: no scalar score, no completeness percentage — only the per-site facts. Flag
 * ATLAS_LOOP_MULTI_SITE_WIRING_PLANNER_ENABLED default OFF ⇒ plan() returns [] (byte-identical no-op). Output is
 * JSON-encodable so the Intent Ledger persists it verbatim. Zero-IO beyond reading the declared consumer files.
 */
final class AtlasLoopMultiSiteWiringPlanner
{
    public const SCHEMA = 'atlas.loop.multi_site_wiring_plan.v1';

    /**
     * @param  Closure():list<array<string,mixed>>|null  $primitivesResolver  test seam: a stub manifest source
     *                                                                         (defaults to the real registry)
     */
    public function __construct(
        private readonly ?AtlasLoopCrossLeverageRegistry $registry = null,
        private readonly ?Closure $primitivesResolver = null,
        private readonly ?string $repoRoot = null,
    ) {}

    /**
     * @return list<array{primitive_id:string, consumer_path:string, seam_anchor:string, status:string}>
     */
    public function plan(): array
    {
        if (! (bool) config('atlas.loop.multi_site_wiring_planner_enabled', false)) {
            return []; // flag OFF ⇒ byte-identical no-op
        }

        $primitives = $this->primitivesResolver !== null
            ? (array) ($this->primitivesResolver)()
            : ($this->registry ?? new AtlasLoopCrossLeverageRegistry)->primitives();

        $repoRoot = rtrim($this->repoRoot ?? base_path(), '/');

        $records = [];
        foreach ($primitives as $primitive) {
            if (! is_array($primitive)) {
                continue;
            }
            $primitiveId = (string) ($primitive['primitive_id'] ?? '');
            $filePath = (string) ($primitive['file_path'] ?? '');
            $className = $filePath !== '' ? pathinfo($filePath, PATHINFO_FILENAME) : '';

            foreach ((array) ($primitive['intended_consumer_paths'] ?? []) as $consumerPath) {
                $consumerPath = (string) $consumerPath;
                $source = (string) @file_get_contents($repoRoot.'/'.ltrim($consumerPath, '/'));
                $wired = $className !== '' && $source !== '' && str_contains($source, $className);

                $records[] = [
                    'primitive_id' => $primitiveId,
                    'consumer_path' => $consumerPath,
                    'seam_anchor' => $this->seamAnchor($source),
                    'status' => $wired ? 'wired' : 'intended',
                ];
            }
        }

        usort($records, static fn (array $a, array $b): int => [$a['primitive_id'], $a['consumer_path']] <=> [$b['primitive_id'], $b['consumer_path']]);

        return $records;
    }

    /** The seam where a primitive is injected: the constructor (DI), else handle(), else the first public method. */
    private function seamAnchor(string $source): string
    {
        if (str_contains($source, 'function __construct')) {
            return '__construct';
        }
        if (str_contains($source, 'function handle')) {
            return 'handle';
        }
        if (preg_match('/public function (\w+)/', $source, $m) === 1) {
            return $m[1];
        }

        return 'unknown';
    }
}
