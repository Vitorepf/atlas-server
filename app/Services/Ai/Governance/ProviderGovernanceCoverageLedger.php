<?php

declare(strict_types=1);

namespace App\Services\Ai\Governance;

use App\Services\Ai\Support\AppendOnlyJsonlStore;

/**
 * Provider governance COVERAGE ledger — the honest bypass meter.
 *
 * The four governance decorators (ADML routing, cost-guard, compression,
 * response-cache) live in {@see \App\Services\Ai\AiProviderManager::get()}.
 * The muscle paths that actually execute programming work construct their
 * provider DIRECTLY and skip the manager:
 *   - Forge/loop CLI spawns via {@see \App\Services\Ai\Programming\AtlasForgeProviderProcessRunner};
 *   - the Dev claude path (PipelineRunExecutor::executeClaudeProvider → ClaudeCliGateway).
 * So the bulk of programming execution runs blind to cost/route.
 *
 * This ledger records, per provider execution, whether it went THROUGH the
 * governed manager (`covered`) or a muscle path (`bypass`), so the bypass rate
 * becomes a REAL number instead of a guess. It changes NO execution behavior —
 * it only appends a receipt, fail-open: a broken ledger never breaks a call.
 *
 * Grain (stated honestly): `covered` is counted at manager RESOLUTION
 * ({@see \App\Services\Ai\AiProviderManager::get()}, exactly as the goal names
 * it); `bypass` is counted at the muscle SPAWN. Both are ~one record per real
 * execution.
 *
 * Anti-Goodhart: the rate is computed only from actually-recorded executions;
 * an empty ledger reports 0 total / 0.0 rate (honest baseline), never fabricated.
 *
 * Schema: atlas.ai.governance.provider_coverage.v1
 */
final class ProviderGovernanceCoverageLedger
{
    public const SCHEMA = 'atlas.ai.governance.provider_coverage.v1';

    /**
     * ENG-10 — operational false-positive definition (codified, not manual).
     * A would-have-blocked advisory on a consult that later completed green
     * with post-cost at or below the pre-cost estimate is counted as FP.
     */
    public const FP_DEFINITION = 'would_have_blocked=true on a consult that completed green with post_cost_units <= pre_cost_units (within expected pre-cost estimate)';

    /** Default percentile when deriving the candidate hard ceiling from ledger traffic. */
    public const CANDIDATE_DEFAULT_PERCENTILE = 0.99;

    public const REASON_COST_GUARD_CANDIDATE_HARD_EXCEEDED = 'cost_guard_candidate_hard_exceeded';

    public const PATH_COVERED = 'covered';

    public const PATH_BYPASS = 'bypass';

    /**
     * SLICE 2 — a muscle path that spawned its provider DIRECTLY but first
     * consulted the shared governance seam (same cost-guard + ADML the manager
     * runs). Counts toward GOVERNED coverage, not bypass.
     */
    public const PATH_CONSULTED = 'consulted';

    /** P1b.2: consult-skip bookkeeping absorbed from GovernanceConsultSkipCounter. */
    public const PATH_SKIPPED = 'skipped';

    public const SURFACE_MANAGER = 'ai_provider_manager';

    public const SURFACE_RECOMMENDATION = 'ai_provider_manager_recommendation';

    public const SURFACE_FORGE_PROCESS_RUNNER = 'forge_process_runner';

    public const SURFACE_DEV_CLAUDE_GATEWAY = 'dev_claude_gateway';

    private ?string $logPathOverride = null;

    public function setLogPathForTesting(?string $path): void
    {
        $this->logPathOverride = $path;
    }

    public function logPath(): string
    {
        if ($this->logPathOverride !== null) {
            return $this->logPathOverride;
        }
        $base = function_exists('storage_path')
            ? storage_path('atlas/ai/governance')
            : sys_get_temp_dir().'/atlas/ai/governance';

        return $base.DIRECTORY_SEPARATOR.'provider_coverage.jsonl';
    }

    /**
     * A provider resolved THROUGH the governed manager (cost-guard/ADML/etc. apply).
     *
     * @param  array<string,mixed>  $context
     */
    public function recordCovered(string $provider, string $surface = self::SURFACE_MANAGER, array $context = []): void
    {
        $this->record(self::PATH_COVERED, $provider, $surface, $context);
    }

    /**
     * A provider executed by a muscle path that skipped the manager (runs blind).
     *
     * @param  array<string,mixed>  $context
     */
    public function recordBypass(string $provider, string $surface, array $context = []): void
    {
        $this->record(self::PATH_BYPASS, $provider, $surface, $context);
    }

    /**
     * A muscle path that spawned directly but consulted the shared governance
     * seam first (SLICE 2) — governed, not blind. Context carries the advisory
     * (ADML verdict + cost-guard result) for audit.
     *
     * @param  array<string,mixed>  $context
     */
    public function recordConsulted(string $provider, string $surface, array $context = []): void
    {
        $this->record(self::PATH_CONSULTED, $provider, $surface, $context);
    }

    /**
     * P1b.2: dual-write consult skips into the coverage ledger so skip_counter
     * is not a second unreconciled writer forever.
     *
     * @param  array<string,mixed>  $context
     */
    public function recordSkipped(string $provider, string $surface, array $context = []): void
    {
        $this->record(self::PATH_SKIPPED, $provider, $surface, $context);
    }

    /**
     * Ingest one GovernanceConsultSkipCounter row into the coverage ledger.
     *
     * @param  array<string,mixed>  $skipRow
     */
    public function ingestConsultSkip(array $skipRow): void
    {
        $this->recordSkipped(
            (string) ($skipRow['provider'] ?? 'unknown'),
            (string) ($skipRow['surface'] ?? 'governance_consult_skip'),
            [
                'executor' => (string) ($skipRow['executor'] ?? ''),
                'reason' => (string) ($skipRow['reason'] ?? ''),
                'source' => 'governance_consult_skip_counter',
                'ts' => (string) ($skipRow['ts'] ?? ''),
            ],
        );
    }

    /**
     * @param  array<string,mixed>  $context
     */
    private function record(string $path, string $provider, string $surface, array $context): void
    {
        try {
            $recordedAt = function_exists('now')
                ? now()->toIso8601String()
                : (new \DateTimeImmutable('now', new \DateTimeZone('UTC')))->format(\DateTimeInterface::ATOM);
            $context['recorded_at'] ??= $recordedAt;
            $executor = $this->executorFromSurface($surface);
            if (! isset($context['executor']) && $executor !== null) {
                $context['executor'] = $executor;
            }
            if (! isset($context['actor']) && isset($context['executor'])) {
                $context['actor'] = $context['executor'];
            }

            AppendOnlyJsonlStore::appendSilently($this->logPath(), [
                'schema_version' => self::SCHEMA,
                'recorded_at' => $recordedAt,
                'path' => $path,
                'covered' => $path === self::PATH_COVERED,
                // governed = manager-resolved OR consulted-the-shared-seam.
                'governed' => $path === self::PATH_COVERED || $path === self::PATH_CONSULTED,
                'skipped' => $path === self::PATH_SKIPPED,
                'provider' => $provider !== '' ? $provider : 'unknown',
                'surface' => $surface !== '' ? $surface : 'unknown',
                'context' => $context,
            ], AppendOnlyJsonlStore::DEFAULT_JSON_FLAGS);
        } catch (\Throwable) {
            // Measurement is best-effort — a ledger error must never break a
            // provider call. ponytail: swallow, the meter is not a gate.
        }
    }

    private function executorFromSurface(string $surface): ?string
    {
        $surface = strtolower($surface);
        foreach (['dev', 'forge', 'autonomos'] as $executor) {
            if (str_contains($surface, $executor)) {
                return $executor;
            }
        }

        return null;
    }

    /**
     * The REAL coverage numbers off the ledger.
     *
     * ponytail: reads the whole ledger each call (like the sibling gateway-
     * consultation ledger). Add windowing/rotation if the 24/7 loop grows it.
     *
     * `governed` = covered (manager-resolved) + consulted (shared-seam). The
     * bypass rate falls as muscles move from blind bypass to consulted.
     *
     * @return array{
     *   total:int, covered:int, consulted:int, bypass:int, governed:int,
     *   bypass_rate:float, covered_rate:float, governed_rate:float,
     *   would_have_blocked_total:int, would_have_blocked_rate:float,
     *   false_positive_total:int, false_positive_rate:float,
     *   fp_definition:string,
     *   candidate_hard_units:?float, candidate_derivation:array<string,mixed>,
     *   by_surface:array<string,array{covered:int,consulted:int,bypass:int}>,
     *   by_provider:array<string,array{covered:int,consulted:int,bypass:int}>
     * }
     */
    public function summary(): array
    {
        $rows = AppendOnlyJsonlStore::read($this->logPath());

        $counts = [self::PATH_COVERED => 0, self::PATH_CONSULTED => 0, self::PATH_BYPASS => 0];
        $bySurface = [];
        $byProvider = [];
        $wouldHaveBlockedTotal = 0;
        $falsePositiveTotal = 0;
        $observedPreCosts = [];
        foreach ($rows as $row) {
            $path = (string) ($row['path'] ?? '');
            if (! array_key_exists($path, $counts)) {
                continue;
            }
            $surface = (string) ($row['surface'] ?? 'unknown');
            $provider = (string) ($row['provider'] ?? 'unknown');
            $context = is_array($row['context'] ?? null) ? $row['context'] : [];
            $bySurface[$surface] ??= ['covered' => 0, 'consulted' => 0, 'bypass' => 0];
            $byProvider[$provider] ??= ['covered' => 0, 'consulted' => 0, 'bypass' => 0];
            $counts[$path]++;
            $bySurface[$surface][$path]++;
            $byProvider[$provider][$path]++;

            if (isset($context['pre_cost_units']) && is_numeric($context['pre_cost_units'])) {
                $observedPreCosts[] = (float) $context['pre_cost_units'];
            }

            if (($context['would_have_blocked'] ?? false) === true) {
                $wouldHaveBlockedTotal++;
                if ($this->isFalsePositive($context)) {
                    $falsePositiveTotal++;
                }
            }
        }

        $covered = $counts[self::PATH_COVERED];
        $consulted = $counts[self::PATH_CONSULTED];
        $bypass = $counts[self::PATH_BYPASS];
        $governed = $covered + $consulted;
        $total = $governed + $bypass;
        $candidateDerivation = $this->candidateDerivationSnapshot($observedPreCosts);

        return [
            'total' => $total,
            'covered' => $covered,
            'consulted' => $consulted,
            'bypass' => $bypass,
            'governed' => $governed,
            // Honest 0-baseline: no data => 0.0, never fabricated.
            'bypass_rate' => $total > 0 ? round($bypass / $total, 4) : 0.0,
            'covered_rate' => $total > 0 ? round($covered / $total, 4) : 0.0,
            'governed_rate' => $total > 0 ? round($governed / $total, 4) : 0.0,
            'would_have_blocked_total' => $wouldHaveBlockedTotal,
            'would_have_blocked_rate' => $consulted > 0
                ? round($wouldHaveBlockedTotal / $consulted, 4)
                : 0.0,
            'false_positive_total' => $falsePositiveTotal,
            'false_positive_rate' => $wouldHaveBlockedTotal > 0
                ? round($falsePositiveTotal / $wouldHaveBlockedTotal, 4)
                : 0.0,
            'fp_definition' => self::FP_DEFINITION,
            'candidate_hard_units' => $candidateDerivation['candidate_hard_units'],
            'candidate_derivation' => $candidateDerivation,
            'by_surface' => $bySurface,
            'by_provider' => $byProvider,
        ];
    }

    /**
     * ENG-10 — derive the candidate hard ceiling from observed pre-cost traffic.
     * Returns null when the ledger has no cost samples (honest: no invented threshold).
     *
     * @param  list<float>  $observedPreCosts  Optional pre-collected samples; when
     *                                         empty, reads the ledger.
     */
    public function deriveCandidateHardUnits(array $observedPreCosts = []): ?float
    {
        if ($observedPreCosts === []) {
            $observedPreCosts = $this->observedPreCostUnits();
        }

        return $this->percentile($observedPreCosts, $this->candidatePercentile());
    }

    /**
     * @param  list<float>  $observedPreCosts
     * @return array{
     *   source:string, percentile:float, samples:int,
     *   env_override:bool, candidate_hard_units:?float
     * }
     */
    public function candidateDerivationSnapshot(array $observedPreCosts = []): array
    {
        $envOverride = $this->envCandidateHardUnits();
        if ($envOverride > 0.0) {
            return [
                'source' => 'env_override',
                'percentile' => $this->candidatePercentile(),
                'samples' => count($observedPreCosts !== [] ? $observedPreCosts : $this->observedPreCostUnits()),
                'env_override' => true,
                'candidate_hard_units' => $envOverride,
            ];
        }

        if ($observedPreCosts === []) {
            $observedPreCosts = $this->observedPreCostUnits();
        }

        $derived = $this->percentile($observedPreCosts, $this->candidatePercentile());

        return [
            'source' => $derived !== null ? 'ledger_percentile' : 'insufficient_samples',
            'percentile' => $this->candidatePercentile(),
            'samples' => count($observedPreCosts),
            'env_override' => false,
            'candidate_hard_units' => $derived,
        ];
    }

    /**
     * Resolve the effective candidate hard ceiling for would-have-blocked simulation.
     */
    public function resolveCandidateHardUnits(): float
    {
        $envOverride = $this->envCandidateHardUnits();
        if ($envOverride > 0.0) {
            return $envOverride;
        }

        return $this->deriveCandidateHardUnits() ?? 0.0;
    }

    /**
     * ENG-10 — codified false-positive check for a single consult context payload.
     *
     * @param  array<string,mixed>  $context
     */
    public function isFalsePositive(array $context): bool
    {
        if (($context['would_have_blocked'] ?? false) !== true) {
            return false;
        }

        if (($context['completion_outcome'] ?? null) !== 'green') {
            return false;
        }

        if (! isset($context['pre_cost_units'], $context['post_cost_units'])
            || ! is_numeric($context['pre_cost_units'])
            || ! is_numeric($context['post_cost_units'])) {
            return false;
        }

        return (float) $context['post_cost_units'] <= (float) $context['pre_cost_units'];
    }

    /** @return list<float> */
    private function observedPreCostUnits(): array
    {
        $costs = [];
        foreach (AppendOnlyJsonlStore::read($this->logPath()) as $row) {
            $context = is_array($row['context'] ?? null) ? $row['context'] : [];
            if (isset($context['pre_cost_units']) && is_numeric($context['pre_cost_units'])) {
                $costs[] = (float) $context['pre_cost_units'];
            }
        }

        return $costs;
    }

    private function envCandidateHardUnits(): float
    {
        $guard = function_exists('config') ? config('atlas.ai.cache.cost_guard', []) : [];
        $guard = is_array($guard) ? $guard : [];

        return (float) ($guard['hard_units_candidate'] ?? 0);
    }

    private function candidatePercentile(): float
    {
        $guard = function_exists('config') ? config('atlas.ai.cache.cost_guard', []) : [];
        $guard = is_array($guard) ? $guard : [];
        $percentile = (float) ($guard['hard_units_candidate_percentile'] ?? self::CANDIDATE_DEFAULT_PERCENTILE);

        return max(0.0, min(1.0, $percentile));
    }

    /**
     * Linear-interpolated percentile.
     *
     * @param  list<float>  $values
     */
    private function percentile(array $values, float $q): ?float
    {
        if ($values === []) {
            return null;
        }

        sort($values);
        $n = count($values);
        if ($n === 1) {
            return $values[0];
        }

        $rank = $q * ($n - 1);
        $low = (int) floor($rank);
        $high = (int) ceil($rank);
        if ($low === $high) {
            return $values[$low];
        }

        return $values[$low] + ($values[$high] - $values[$low]) * ($rank - $low);
    }

    /** Clear the ledger to start a fresh measurement window. */
    public function reset(): void
    {
        $path = $this->logPath();
        if (is_file($path)) {
            @unlink($path);
        }
    }
}
