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

        // External Brain core commands + its seed quality gate — AtlasLoopHarnessGuard already
        // lists these as FORBIDDEN_SELF_TARGETS (the réu never edits its own seed harness), but
        // they live outside AutonomousEvolution/ (app/Console/Commands/) so PROPERTY_GATED_PATTERNS
        // never caught them and they fell through to ordinary. Align with the harness guard so
        // preflight and the seed harness never disagree on these targets.
        'app/Console/Commands/AtlasBrainNextCommand.php',
        'app/Console/Commands/AtlasBrainWorkerPromptCommand.php',
        'app/Console/Commands/AtlasBrainAuditCommand.php',
        'app/Console/Commands/AtlasBrainSummaryCommand.php',
        'app/Services/Ai/AutonomousEvolution/Brain/AtlasBrainSeedQualityGate.php',

        // SEV-1: a própria denylist classificava a si mesma como ordinary — um commit
        // autônomo podia reescrever a lista dos próprios alvos proibidos e, no ciclo
        // seguinte, tudo virava ordinary. 'Constitution/' acima só casa o cadáver ACDE
        // em AutonomousEvolution/Constitution/; a constituição VIVA mora em Governance/.
        'AtlasTaskPropertyGatedTargetPolicy', // a própria denylist — o réu nunca edita a lista
        'app/Services/Ai/Governance/',        // constituição viva (Kernel · Admissão · Vault · TrustBudget)
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

    public const PACKET_FORBIDDEN_SELF_TARGET = 'forbidden_self_target';

    public const PACKET_OPERATOR_ONLY = 'operator_only';

    public const PACKET_TEST_ONLY_SCOPE = 'test_only_scope';

    public const PACKET_CROSS_SCOPE = 'cross_scope';

    public const PACKET_SAFE_AUTONOMOUS = 'safe_autonomous';

    /**
     * Classifies an entire task packet (not a single path) into one of five states before it may
     * enter autonomous serving. Checked in this priority order — each earlier check wins
     * unconditionally over a later one, matching classify()'s forbidden-first invariant:
     *   1. forbidden_self_target — any allowed_file is on the pétreo list (never a valid target)
     *   2. operator_only         — any allowed_file needs a governing property/operator sign-off
     *   3. test_only_scope       — allowed_files are ALL test paths, no implementation target
     *   4. cross_scope           — an allowed_file falls outside every declared scope_in root
     *   5. safe_autonomous       — none of the above; safe for unattended serving
     *
     * @param  array{allowed_files?: list<string>, scope_in?: list<string>}  $packet
     * @return array{classification:string, blocking_reason:string|null, repair_hint:string|null}
     */
    public function classifyPacket(array $packet): array
    {
        $allowedFiles = array_values(array_filter(array_map('strval', (array) ($packet['allowed_files'] ?? []))));
        $scopeIn = array_values(array_filter(array_map('strval', (array) ($packet['scope_in'] ?? []))));

        $forbiddenMatches = array_values(array_filter(
            $allowedFiles,
            fn (string $f): bool => $this->classify($f) === self::CLASSIFICATION_FORBIDDEN,
        ));
        if ($forbiddenMatches !== []) {
            return [
                'classification' => self::PACKET_FORBIDDEN_SELF_TARGET,
                'blocking_reason' => 'forbidden_petreo_target:'.implode(',', $forbiddenMatches),
                'repair_hint' => 'remove the forbidden/pétreo file(s) from allowed_files; this target can never be edited by an autonomous task',
            ];
        }

        $operatorOnlyMatches = array_values(array_filter(
            $allowedFiles,
            fn (string $f): bool => $this->classify($f) === self::CLASSIFICATION_PROPERTY_GATED,
        ));
        if ($operatorOnlyMatches !== []) {
            return [
                'classification' => self::PACKET_OPERATOR_ONLY,
                'blocking_reason' => 'property_gated_target_requires_operator_enablement:'.implode(',', $operatorOnlyMatches),
                'repair_hint' => 'obtain explicit operator sign-off / enable the governing property before this target can be autonomously served',
            ];
        }

        if ($allowedFiles !== [] && array_values(array_filter($allowedFiles, fn (string $f): bool => ! $this->isTestPath($f))) === []) {
            return [
                'classification' => self::PACKET_TEST_ONLY_SCOPE,
                'blocking_reason' => 'allowed_files_contains_only_test_paths_no_implementation_target',
                'repair_hint' => 'add the implementation file(s) this test proves so the task produces real behavior, not just test scaffolding',
            ];
        }

        if ($scopeIn !== []) {
            $outOfScope = array_values(array_filter(
                $allowedFiles,
                fn (string $f): bool => ! $this->withinAnyScopeRoot($f, $scopeIn),
            ));
            if ($outOfScope !== []) {
                return [
                    'classification' => self::PACKET_CROSS_SCOPE,
                    'blocking_reason' => 'allowed_files_outside_declared_scope_in:'.implode(',', $outOfScope),
                    'repair_hint' => 'narrow allowed_files to paths under the declared scope_in root(s), or split into separate scoped tasks',
                ];
            }
        }

        return [
            'classification' => self::PACKET_SAFE_AUTONOMOUS,
            'blocking_reason' => null,
            'repair_hint' => null,
        ];
    }

    private function isTestPath(string $path): bool
    {
        return str_starts_with($path, 'tests/')
            || str_ends_with($path, 'Test.php')
            || str_ends_with($path, 'Spec.php');
    }

    /** @param list<string> $scopeRoots */
    private function withinAnyScopeRoot(string $path, array $scopeRoots): bool
    {
        foreach ($scopeRoots as $root) {
            if (str_starts_with($path, $root)) {
                return true;
            }
        }

        return false;
    }
}
