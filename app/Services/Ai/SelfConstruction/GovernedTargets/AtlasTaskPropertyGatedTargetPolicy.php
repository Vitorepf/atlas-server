<?php

declare(strict_types=1);

namespace App\Services\Ai\SelfConstruction\GovernedTargets;

/**
 * Task-serving classification policy for allowed_files paths.
 *
 * Classifies each path into one of three classes:
 *   - ordinary        : any file outside the pétreo and property-gated zones
 *   - property_gated  : Brain / AutonomousEvolution organs that are NOT on the pétreo list —
 *                       they can be targeted once the governing property is enabled
 *   - forbidden       : pétreo set — the lock itself, master switches, constitution spine,
 *                       watchdog, and guard/committer safety files; NEVER a valid target
 *
 * Pure and deterministic: no shell, no provider, no queue writes, no git, no mutation.
 * Forbidden check always runs first so a pétreo path inside AutonomousEvolution/ is never
 * mis-classified as property_gated.
 */
final class AtlasTaskPropertyGatedTargetPolicy
{
    public const CLASSIFICATION_ORDINARY = 'ordinary';

    public const CLASSIFICATION_PROPERTY_GATED = 'property_gated';

    public const CLASSIFICATION_FORBIDDEN = 'forbidden';

    /**
     * Substring patterns (repo-relative paths) for the pétreo forbidden set.
     * Forbidden check runs before property-gated, so AutonomousEvolution/ pétreo files
     * are never mis-classified. Só-adiciona: shrinking this list requires a deliberate
     * decision and the frozen test will fail if anyone tries.
     *
     * @var list<string>
     */
    private const FORBIDDEN_PATTERNS = [
        'AtlasLoopHarnessGuard',           // the guard itself — réu never edits the lock
        'AtlasLoopMasterSwitch',           // loop master switch — operator-only
        'AtlasBrainMasterSwitch',          // brain master switch — operator-only
        'Constitution/',                   // constitution spine — governs all gates
        'config/atlas.php',               // atlas global config — operator-only
        'atlas-loop-watchdog',            // watchdog script — keep-alive safety
        'AtlasTaskScopedCommitter',        // committer safety — scoped-commit guarantor
        'AtlasTaskPacketQualityInspector', // inspector safety — quality gate
    ];

    /**
     * Substring patterns for files that need a governing property enabled before they can
     * be targeted. Any path that matches here (and is not forbidden) is property_gated.
     *
     * @var list<string>
     */
    private const PROPERTY_GATED_PATTERNS = [
        'AutonomousEvolution/', // covers Brain/ (sub-path) and all evolution organs
    ];

    public function classify(string $path): string
    {
        // Forbidden is pétreo — checked first, wins unconditionally.
        foreach (self::FORBIDDEN_PATTERNS as $pattern) {
            if (str_contains($path, $pattern)) {
                return self::CLASSIFICATION_FORBIDDEN;
            }
        }

        foreach (self::PROPERTY_GATED_PATTERNS as $pattern) {
            if (str_contains($path, $pattern)) {
                return self::CLASSIFICATION_PROPERTY_GATED;
            }
        }

        return self::CLASSIFICATION_ORDINARY;
    }

    /**
     * Classify a list of paths and group them by classification.
     *
     * @param  list<string>  $paths
     * @return array{ordinary:list<string>, property_gated:list<string>, forbidden:list<string>}
     */
    public function classifyAll(array $paths): array
    {
        $result = [
            self::CLASSIFICATION_ORDINARY => [],
            self::CLASSIFICATION_PROPERTY_GATED => [],
            self::CLASSIFICATION_FORBIDDEN => [],
        ];
        foreach ($paths as $path) {
            $result[$this->classify($path)][] = $path;
        }

        return $result;
    }
}
