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

    public const PATH_COVERED = 'covered';

    public const PATH_BYPASS = 'bypass';

    /**
     * SLICE 2 — a muscle path that spawned its provider DIRECTLY but first
     * consulted the shared governance seam (same cost-guard + ADML the manager
     * runs). Counts toward GOVERNED coverage, not bypass.
     */
    public const PATH_CONSULTED = 'consulted';

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
     * @param  array<string,mixed>  $context
     */
    private function record(string $path, string $provider, string $surface, array $context): void
    {
        try {
            AppendOnlyJsonlStore::appendSilently($this->logPath(), [
                'schema_version' => self::SCHEMA,
                'path' => $path,
                'covered' => $path === self::PATH_COVERED,
                // governed = manager-resolved OR consulted-the-shared-seam.
                'governed' => $path === self::PATH_COVERED || $path === self::PATH_CONSULTED,
                'provider' => $provider !== '' ? $provider : 'unknown',
                'surface' => $surface !== '' ? $surface : 'unknown',
                'context' => $context,
            ], AppendOnlyJsonlStore::DEFAULT_JSON_FLAGS);
        } catch (\Throwable) {
            // Measurement is best-effort — a ledger error must never break a
            // provider call. ponytail: swallow, the meter is not a gate.
        }
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
        foreach ($rows as $row) {
            $path = (string) ($row['path'] ?? '');
            if (! array_key_exists($path, $counts)) {
                continue;
            }
            $surface = (string) ($row['surface'] ?? 'unknown');
            $provider = (string) ($row['provider'] ?? 'unknown');
            $bySurface[$surface] ??= ['covered' => 0, 'consulted' => 0, 'bypass' => 0];
            $byProvider[$provider] ??= ['covered' => 0, 'consulted' => 0, 'bypass' => 0];
            $counts[$path]++;
            $bySurface[$surface][$path]++;
            $byProvider[$provider][$path]++;
        }

        $covered = $counts[self::PATH_COVERED];
        $consulted = $counts[self::PATH_CONSULTED];
        $bypass = $counts[self::PATH_BYPASS];
        $governed = $covered + $consulted;
        $total = $governed + $bypass;

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
            'by_surface' => $bySurface,
            'by_provider' => $byProvider,
        ];
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
