<?php

declare(strict_types=1);

namespace App\Services\Ai\SoftwareCompanyStewardship\AreaFocusLoop\OwnerFlow;

/**
 * FASE 2 — zero-provider pre-flight admission gate.
 *
 * A cheap, pure (no I/O, no provider spend) gate evaluated in the AP-786
 * owner-flow cycle BEFORE the atlas_dev owner command (senior-loop /
 * minimax-worker) is ever invoked. Its only job is to decide, for free, whether
 * a slice is qualified enough to be worth a token-spending provider cycle.
 *
 * A cycle is admitted only when ALL of these hold:
 *   1. allowed_files resolved        — at least one concrete allowed file;
 *   2. runnable validation command   — at least one non-empty validation cmd;
 *   3. risk <= R3                     — risk tier within the autonomous ceiling;
 *   4. deps satisfied                — no unmet declared dependencies;
 *   5. scope <= N files / 1 layer    — allowed files within the file budget AND
 *                                       confined to a single architectural layer.
 *
 * When any condition fails the gate returns an honest CHEAP SKIP: no provider is
 * invoked, and the cycle is explicitly marked as NOT token-spending so the loop
 * metric `merges / token-spending-cycles` stays truthful. The gate never weakens
 * any downstream gate — it only refuses to spend tokens on an unqualified slice.
 */
final class ZeroProviderPreflightGate
{
    public const SCHEMA = 'atlas.software_company_stewardship.zero_provider_preflight_gate.v1';

    /** Maximum allowed files for a single autonomous owner-runtime slice. */
    public const MAX_SCOPE_FILES = 5;

    /** Highest risk tier admitted for autonomous owner-runtime execution. */
    public const MAX_RISK_TIER = 3;

    public const REASON_NO_ALLOWED_FILES = 'preflight_no_allowed_files';

    public const REASON_NO_VALIDATION_COMMAND = 'preflight_no_runnable_validation_command';

    public const REASON_RISK_ABOVE_CEILING = 'preflight_risk_above_r3';

    public const REASON_DEPS_UNSATISFIED = 'preflight_dependencies_unsatisfied';

    public const REASON_SCOPE_TOO_LARGE = 'preflight_scope_exceeds_file_budget';

    public const REASON_SCOPE_MULTIPLE_LAYERS = 'preflight_scope_spans_multiple_layers';

    /**
     * Evaluate the gate. Pure: never invokes a provider, never shells out.
     *
     * @param  list<string>  $allowedFiles
     * @param  list<string>  $validationCommands
     * @param  array<string,mixed>  $finding
     * @return array{
     *     schema_version:string,
     *     admitted:bool,
     *     token_spending_cycle:bool,
     *     blockers:list<string>,
     *     risk_tier:int,
     *     scope_file_count:int,
     *     scope_layers:list<string>,
     *     deps_unsatisfied:list<string>,
     *     evaluated_at:string
     * }
     */
    public function evaluate(array $allowedFiles, array $validationCommands, array $finding): array
    {
        $allowedFiles = $this->stringList($allowedFiles);
        $validationCommands = $this->stringList($validationCommands);

        $blockers = [];

        if ($allowedFiles === []) {
            $blockers[] = self::REASON_NO_ALLOWED_FILES;
        }

        if (! $this->hasRunnableValidationCommand($validationCommands)) {
            $blockers[] = self::REASON_NO_VALIDATION_COMMAND;
        }

        $riskTier = $this->riskTier($finding);
        if ($riskTier > self::MAX_RISK_TIER) {
            $blockers[] = self::REASON_RISK_ABOVE_CEILING;
        }

        $depsUnsatisfied = $this->unsatisfiedDependencies($finding);
        if ($depsUnsatisfied !== []) {
            $blockers[] = self::REASON_DEPS_UNSATISFIED;
        }

        $fileCount = count($allowedFiles);
        if ($fileCount > self::MAX_SCOPE_FILES) {
            $blockers[] = self::REASON_SCOPE_TOO_LARGE;
        }

        $layers = $this->layers($allowedFiles);
        if (count($layers) > 1) {
            $blockers[] = self::REASON_SCOPE_MULTIPLE_LAYERS;
        }

        $admitted = $blockers === [];

        return [
            'schema_version' => self::SCHEMA,
            'admitted' => $admitted,
            // A skipped cycle never reaches a provider, so it must NOT count
            // toward token-spending cycles in the merges/token-spending metric.
            'token_spending_cycle' => $admitted,
            'blockers' => array_values($blockers),
            'risk_tier' => $riskTier,
            'scope_file_count' => $fileCount,
            'scope_layers' => array_values($layers),
            'deps_unsatisfied' => $depsUnsatisfied,
            'evaluated_at' => gmdate('c'),
        ];
    }

    /**
     * A runnable validation command exists when the slice declares at least one
     * non-empty command the owner runtime can execute to prove the change. The
     * gate refuses a slice with NO declared validation (the diff could never be
     * verified, so spending a provider call on it is waste); it does not second-
     * guess which command — that is the senior-loop / verification gate's job.
     *
     * @param  list<string>  $validationCommands
     */
    private function hasRunnableValidationCommand(array $validationCommands): bool
    {
        return $validationCommands !== [];
    }

    /**
     * Resolve the risk tier as an integer 0..5. Accepts the canonical R-tier
     * notation (`R3`, `r3`, `R3+`) and the worded scale (low/medium/high/...).
     * Unknown values default to the ceiling (R3) so they are admitted rather
     * than silently blocked, while explicit high tiers (R4/R5) are refused.
     *
     * @param  array<string,mixed>  $finding
     */
    private function riskTier(array $finding): int
    {
        $raw = $finding['risk_tier'] ?? $finding['risk_level'] ?? $finding['risk'] ?? $finding['risk_band'] ?? null;
        if (is_int($raw)) {
            return max(0, min(5, $raw));
        }
        if (! is_string($raw)) {
            return self::MAX_RISK_TIER;
        }
        $value = strtolower(trim($raw));
        if ($value === '') {
            return self::MAX_RISK_TIER;
        }
        if (preg_match('/^r([0-5])/', $value, $m) === 1) {
            return (int) $m[1];
        }

        return match ($value) {
            'trivial', 'none' => 0,
            'very_low', 'minimal' => 1,
            'low' => 2,
            'medium', 'moderate' => 3,
            'high' => 4,
            'very_high', 'critical', 'severe' => 5,
            default => self::MAX_RISK_TIER,
        };
    }

    /**
     * Declared dependencies that are not yet satisfied. A dependency is a string
     * id; satisfied ids come from the finding's `satisfied_dependencies` (or
     * `deps_satisfied`) list. Any declared dep not present there is unsatisfied.
     *
     * @param  array<string,mixed>  $finding
     * @return list<string>
     */
    private function unsatisfiedDependencies(array $finding): array
    {
        $declared = $this->stringList($finding['dependencies'] ?? $finding['depends_on'] ?? []);
        if ($declared === []) {
            return [];
        }
        $satisfied = $this->stringList($finding['satisfied_dependencies'] ?? $finding['deps_satisfied'] ?? []);

        return array_values(array_filter(
            $declared,
            static fn (string $dep): bool => ! in_array($dep, $satisfied, true),
        ));
    }

    /**
     * Distinct production-code layers touched by the slice. "1 layer" means the
     * production files all share one layer (e.g. app/Services) so the slice does
     * not straddle unrelated subsystems in a single provider call. Accompanying
     * test files (the TDD mirror) are NOT counted as a separate layer — a code
     * file plus its *Test.php is one logical slice, not two layers.
     *
     * @param  list<string>  $allowedFiles
     * @return list<string>
     */
    private function layers(array $allowedFiles): array
    {
        $layers = [];
        foreach ($allowedFiles as $file) {
            $layer = $this->layerOf($file);
            if ($layer === null) {
                continue; // test mirror — pairs with code, not its own layer.
            }
            $layers[$layer] = true;
        }

        // A test-only slice is still a single (test) layer.
        if ($layers === [] && $allowedFiles !== []) {
            return ['tests'];
        }

        return array_keys($layers);
    }

    /**
     * Production layer of a file, keyed by its first two path segments (e.g.
     * app/Services, app/Console are distinct). Returns null for test files so
     * they never inflate the layer count of a code+test slice.
     */
    private function layerOf(string $file): ?string
    {
        $normalized = ltrim(str_replace('\\', '/', trim($file)), '/');
        $segments = array_values(array_filter(explode('/', $normalized), static fn (string $s): bool => $s !== ''));
        if ($segments === []) {
            return 'root';
        }
        $first = $segments[0];
        if (in_array($first, ['tests', 'test'], true) || str_ends_with($normalized, 'Test.php')) {
            return null;
        }
        if (isset($segments[1])) {
            return $first.'/'.$segments[1];
        }

        return $first;
    }

    /**
     * @return list<string>
     */
    private function stringList(mixed $value): array
    {
        if (! is_array($value)) {
            return [];
        }

        return array_values(array_filter(array_map(
            static fn (mixed $item): string => is_string($item) ? trim($item) : '',
            $value,
        ), static fn (string $item): bool => $item !== ''));
    }
}
