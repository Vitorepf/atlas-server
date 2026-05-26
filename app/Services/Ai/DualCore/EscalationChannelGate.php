<?php

declare(strict_types=1);

namespace App\Services\Ai\DualCore;

/**
 * Atlas Dev/Forge — Escalation channel gate.
 *
 * Enforces the canonical rule declared in
 * `docs/engineering-knowledge-base/atlas-dev-forge-escalation-consolidation-plan.md`:
 * all Programming-adjacent HTTP controllers MUST emit
 * `atlas.dual_core.route_decision.v1` via `DualCoreRouteDecisionService`
 * (or its sibling `ForgeIntakeRouteDecisionRecorder`). Phase 2 of the
 * consolidation requires zero controllers escalating Dev→Forge outside
 * this canonical channel.
 *
 * The gate is intentionally read-only and additive:
 *
 *  - It does NOT rewrite controllers.
 *  - It returns a structured report so callers can decide policy.
 *  - It distinguishes `unwired_controllers` (existing baseline) from
 *    `new_unwired_controllers` (a fail-fast set passed in by the caller
 *    from `git diff --diff-filter=A`).
 *
 * Existing 44 unwired controllers are grandfathered until Phase 2 ships
 * per-family APs. The gate's job is to prevent NEW divergence.
 */
final class EscalationChannelGate
{
    public const SCHEMA_VERSION = 'atlas.dual_core.escalation_channel_gate.v1';

    public const CANONICAL_SERVICE = 'DualCoreRouteDecisionService';

    public const CANONICAL_RECORDER = 'ForgeIntakeRouteDecisionRecorder';

    public const CANONICAL_SCHEMA = 'atlas.dual_core.route_decision.v1';

    /**
     * Substrings used to identify Programming-adjacent controllers. The
     * list is intentionally conservative — controllers that touch SDD,
     * Forge or AtlasCode are in scope; everything else (auth, billing,
     * etc.) is out of scope.
     *
     * @var list<string>
     */
    private const SCOPE_SUBSTRINGS = [
        'AtlasCode',
        'AtlasProgramming',
        'AtlasSdd',
        'AtlasForge',
        'AtlasProject',
        'DevToForge',
    ];

    /**
     * @var non-empty-string
     */
    private const CONTROLLERS_ROOT = 'app/Http/Controllers';

    private string $controllersAbsolutePath;

    public function __construct(?string $controllersAbsolutePath = null)
    {
        $this->controllersAbsolutePath = $controllersAbsolutePath
            ?? base_path(self::CONTROLLERS_ROOT);
    }

    /**
     * @param  list<string>  $newControllerRelativePaths  Files to fail-fast on (e.g. from `git diff --diff-filter=A`).
     * @return array{
     *   schema_version: string,
     *   status: 'ok'|'failed',
     *   policy: array{canonical_service: string, canonical_recorder: string, canonical_schema: string},
     *   new_violations: list<array{file: string, controller: string, reason: string, detail: string}>,
     *   existing_unwired: array{count: int, sample: list<string>},
     *   summary: array{
     *     scanned_existing: int,
     *     existing_wired: int,
     *     existing_unwired: int,
     *     new_checked: int,
     *     new_failed: int,
     *     coverage_pct: float
     *   }
     * }
     */
    public function evaluate(array $newControllerRelativePaths = []): array
    {
        $newViolations = [];
        foreach ($newControllerRelativePaths as $relative) {
            $violation = $this->checkPath($relative);
            if ($violation !== null) {
                $newViolations[] = $violation;
            }
        }

        [$scanned, $wired, $unwired] = $this->scanExisting();
        $coverage = $scanned > 0 ? round($wired / $scanned * 100, 1) : 100.0;

        return [
            'schema_version' => self::SCHEMA_VERSION,
            'status' => $newViolations === [] ? 'ok' : 'failed',
            'policy' => [
                'canonical_service' => self::CANONICAL_SERVICE,
                'canonical_recorder' => self::CANONICAL_RECORDER,
                'canonical_schema' => self::CANONICAL_SCHEMA,
            ],
            'new_violations' => $newViolations,
            'existing_unwired' => [
                'count' => count($unwired),
                'sample' => array_slice($unwired, 0, 10),
            ],
            'summary' => [
                'scanned_existing' => $scanned,
                'existing_wired' => $wired,
                'existing_unwired' => count($unwired),
                'new_checked' => count($newControllerRelativePaths),
                'new_failed' => count($newViolations),
                'coverage_pct' => $coverage,
            ],
        ];
    }

    /**
     * @return array{file: string, controller: string, reason: string, detail: string}|null
     */
    public function checkPath(string $relativePath): ?array
    {
        if (! $this->isProgrammingAdjacent($relativePath)) {
            return null;
        }

        $absolute = $this->resolveAbsolute($relativePath);
        if (! is_file($absolute)) {
            // Caller asked us to fail-fast on a file we can't read.
            // Conservatively flag it — operator must commit the file
            // already wired or remove the path from the fail-fast set.
            return [
                'file' => $relativePath,
                'controller' => basename($relativePath, '.php'),
                'reason' => 'file_not_found',
                'detail' => 'Controller path is in scope but does not exist on disk; cannot verify route_decision.v1 emission.',
            ];
        }

        if ($this->emitsCanonicalChannel($absolute)) {
            return null;
        }

        return [
            'file' => $relativePath,
            'controller' => basename($relativePath, '.php'),
            'reason' => 'no_canonical_channel_emission',
            'detail' => sprintf(
                'controller does not reference %s, %s or schema literal %s. Phase 2 requires every Programming-adjacent controller to emit %s.',
                self::CANONICAL_SERVICE,
                self::CANONICAL_RECORDER,
                self::CANONICAL_SCHEMA,
                self::CANONICAL_SCHEMA,
            ),
        ];
    }

    /**
     * @return array{0:int,1:int,2:list<string>} [scanned, wired, unwired_list]
     */
    private function scanExisting(): array
    {
        if (! is_dir($this->controllersAbsolutePath)) {
            return [0, 0, []];
        }

        $items = scandir($this->controllersAbsolutePath);
        if ($items === false) {
            return [0, 0, []];
        }

        $scanned = 0;
        $wired = 0;
        $unwired = [];

        foreach ($items as $item) {
            if ($item === '.' || $item === '..' || ! str_ends_with($item, '.php')) {
                continue;
            }
            $relative = self::CONTROLLERS_ROOT.'/'.$item;
            if (! $this->isProgrammingAdjacent($relative)) {
                continue;
            }
            $scanned++;
            $absolute = $this->controllersAbsolutePath.'/'.$item;
            if ($this->emitsCanonicalChannel($absolute)) {
                $wired++;
            } else {
                $unwired[] = $relative;
            }
        }

        sort($unwired);

        return [$scanned, $wired, $unwired];
    }

    private function isProgrammingAdjacent(string $relativePath): bool
    {
        if (! str_ends_with($relativePath, '.php')) {
            return false;
        }
        $basename = basename($relativePath);
        foreach (self::SCOPE_SUBSTRINGS as $needle) {
            if (str_contains($basename, $needle)) {
                return true;
            }
        }

        return false;
    }

    private function emitsCanonicalChannel(string $absolute): bool
    {
        $contents = file_get_contents($absolute);
        if ($contents === false) {
            return false;
        }

        return str_contains($contents, self::CANONICAL_SERVICE)
            || str_contains($contents, self::CANONICAL_RECORDER)
            || str_contains($contents, self::CANONICAL_SCHEMA);
    }

    private function resolveAbsolute(string $relativePath): string
    {
        // Controllers in this project all live flat under
        // `app/Http/Controllers/`, so resolving by basename against the
        // configured root works for both production and tests (where
        // the root is overridden to a sandbox).
        return $this->controllersAbsolutePath.'/'.basename($relativePath);
    }
}
