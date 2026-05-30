<?php

declare(strict_types=1);

namespace App\Services\Ai\SoftwareCompanyStewardship\AreaFocusLoop;

use App\Services\Ai\Mission\MissionCanonicalHash;
use DateTimeImmutable;
use DateTimeInterface;
use DateTimeZone;

/**
 * AP-810 / LHL-08 — Loop Resource Governor (AP-808/809).
 *
 * A read-only / deterministic / input-seam driven governor that answers exactly
 * one question for the long-horizon loop:
 *
 *   > Has this run consumed enough resource that the next cycle must PAUSE or STOP?
 *
 * It NEVER invokes a provider, NEVER runs the loop, NEVER merges, NEVER deletes a
 * branch/worktree, NEVER mutates code. It only DIAGNOSES resource usage against
 * configurable ceilings and emits an honest verdict + a resource_summary for the
 * final run report.
 *
 * Tracks (all from input seams; honest approximation when a real measure is null):
 *   - provider_calls           number of provider calls spent this run
 *   - token_estimate           real tokens when present, otherwise a deterministic
 *                              honest estimate derived from provider_calls + diff size
 *   - wall_time_seconds        run wall-clock seconds
 *   - memory_mb                observable RSS (input seam; never probed live here)
 *   - disk_growth_mb           how much disk the run has grown
 *   - ledger_bytes             append-only ledger size on disk
 *   - provider_process_count   live provider processes attributed to the loop
 *   - worktree_count           git worktrees in play
 *   - branch_count             sandbox/lane branches in play
 *   - blocked_streak           consecutive blocked cycles
 *   - retries_per_finding      max retries seen for a single finding
 *   - retries_per_packet       max retries seen for a single packet
 *
 * Ceiling semantics (operator does not accept silent burn):
 *   - exceeding any HARD ceiling => status=stop with a receipt (stop_receipt) and a
 *     recorded breach. A run that hits a hard ceiling is NEVER reported as ok.
 *   - exceeding any SOFT ceiling (and no hard breach) => status=pause with a recorded
 *     breach. pause is a real outcome, never dressed as ok.
 *   - under all ceilings => status=ok.
 *
 * Determinism: same input => identical report_hash (volatile fields stripped).
 *
 * Contract: AP-808/809; AP-810 build contract slice LHL-08.
 */
final class LoopResourceGovernorService
{
    public const REPORT_SCHEMA = 'atlas.software_company_stewardship.loop_resource_governor.v1';

    public const STATUS_OK = 'ok';

    public const STATUS_PAUSE = 'pause';

    public const STATUS_STOP = 'stop';

    /** Breach severities. A hard breach forces stop; a soft breach forces pause. */
    public const SEVERITY_HARD = 'hard';

    public const SEVERITY_SOFT = 'soft';

    /**
     * Honest token-per-provider-call approximation used only when real tokens are
     * absent. A deterministic constant, never a random/time-based guess.
     */
    public const TOKENS_PER_CALL_ESTIMATE = 12000;

    /** Honest extra-token approximation per changed file in the run's diff. */
    public const TOKENS_PER_CHANGED_FILE_ESTIMATE = 250;

    /**
     * Canonical HARD ceilings (exceeding any => stop with receipt). Every value is
     * overridable via $input['ceilings'][<metric>] and $input['hard_ceilings'].
     *
     * @var array<string,int>
     */
    private const DEFAULT_HARD_CEILINGS = [
        'provider_calls' => 240,
        'token_estimate' => 6000000,
        'wall_time_seconds' => 86400,
        'memory_mb' => 8192,
        'disk_growth_mb' => 4096,
        'ledger_bytes' => 268435456,
        'provider_process_count' => 4,
        'worktree_count' => 12,
        'branch_count' => 40,
        'blocked_streak' => 14,
        'retries_per_finding' => 6,
        'retries_per_packet' => 6,
    ];

    /**
     * Canonical SOFT ceilings (exceeding any, with no hard breach => pause).
     * Overridable via $input['soft_ceilings'][<metric>].
     *
     * @var array<string,int>
     */
    private const DEFAULT_SOFT_CEILINGS = [
        'provider_calls' => 180,
        'token_estimate' => 4500000,
        'wall_time_seconds' => 64800,
        'memory_mb' => 6144,
        'disk_growth_mb' => 3072,
        'ledger_bytes' => 134217728,
        'provider_process_count' => 2,
        'worktree_count' => 8,
        'branch_count' => 24,
        'blocked_streak' => 8,
        'retries_per_finding' => 4,
        'retries_per_packet' => 4,
    ];

    /** Metrics governed, in stable order (used for usage + breach iteration). */
    private const METRICS = [
        'provider_calls',
        'token_estimate',
        'wall_time_seconds',
        'memory_mb',
        'disk_growth_mb',
        'ledger_bytes',
        'provider_process_count',
        'worktree_count',
        'branch_count',
        'blocked_streak',
        'retries_per_finding',
        'retries_per_packet',
    ];

    /**
     * Single entrypoint. Every key is optional; the diagnostic default analyzes a
     * clean/empty run (zero usage) and never crashes.
     *
     * @param  array<string,mixed>  $input
     * @return array<string,mixed>
     */
    public function evaluate(array $input = []): array
    {
        // A wiring-phase `fixture` (from --fixture-file) may carry the whole usage
        // record; merge it under the explicit input so direct keys still win.
        $input = $this->mergeFixture($input);

        $area = trim((string) ($input['area'] ?? 'agentic_engineering_os')) ?: 'agentic_engineering_os';
        $focus = trim((string) ($input['focus'] ?? 'dev_forge')) ?: 'dev_forge';
        $runId = trim((string) ($input['run_id'] ?? ''));
        $cycleIndex = (int) ($input['cycle_index'] ?? 0);

        // Usage is taken purely from input seams (no live probing in this read model).
        $usageSource = is_array($input['usage'] ?? null) ? $input['usage'] : [];

        $tokenEstimate = $this->resolveTokenEstimate($input, $usageSource);

        $usage = [
            'provider_calls' => $this->nonNegInt($usageSource['provider_calls'] ?? ($input['provider_calls'] ?? 0)),
            'token_estimate' => $tokenEstimate['value'],
            'token_estimate_is_approximation' => $tokenEstimate['is_approximation'],
            'wall_time_seconds' => $this->nonNegInt($usageSource['wall_time_seconds'] ?? ($input['wall_time_seconds'] ?? 0)),
            'memory_mb' => $this->nonNegInt($usageSource['memory_mb'] ?? ($input['memory_mb'] ?? 0)),
            'disk_growth_mb' => $this->nonNegInt($usageSource['disk_growth_mb'] ?? ($input['disk_growth_mb'] ?? 0)),
            'ledger_bytes' => $this->nonNegInt($usageSource['ledger_bytes'] ?? ($input['ledger_bytes'] ?? 0)),
            'provider_process_count' => $this->nonNegInt($usageSource['provider_process_count'] ?? ($input['provider_process_count'] ?? 0)),
            'worktree_count' => $this->nonNegInt($usageSource['worktree_count'] ?? ($input['worktree_count'] ?? 0)),
            'branch_count' => $this->nonNegInt($usageSource['branch_count'] ?? ($input['branch_count'] ?? 0)),
            'blocked_streak' => $this->nonNegInt($usageSource['blocked_streak'] ?? ($input['blocked_streak'] ?? 0)),
            'retries_per_finding' => $this->resolveMaxRetries($usageSource, $input, 'retries_per_finding'),
            'retries_per_packet' => $this->resolveMaxRetries($usageSource, $input, 'retries_per_packet'),
        ];

        $hardCeilings = $this->resolveCeilings($input, self::DEFAULT_HARD_CEILINGS, 'hard_ceilings');
        $softCeilings = $this->resolveCeilings($input, self::DEFAULT_SOFT_CEILINGS, 'soft_ceilings');

        /** @var list<array<string,mixed>> $breaches */
        $breaches = [];
        $blockers = [];
        $warnings = [];

        foreach (self::METRICS as $metric) {
            $value = (int) $usage[$metric];

            // HARD breach takes precedence and forces a stop.
            if (isset($hardCeilings[$metric]) && $value > $hardCeilings[$metric]) {
                $breaches[] = [
                    'metric' => $metric,
                    'severity' => self::SEVERITY_HARD,
                    'value' => $value,
                    'ceiling' => $hardCeilings[$metric],
                    'action' => self::STATUS_STOP,
                ];
                $blockers[] = 'hard_ceiling_exceeded:'.$metric;

                continue;
            }

            // SOFT breach forces a pause (only matters if no hard breach wins).
            if (isset($softCeilings[$metric]) && $value > $softCeilings[$metric]) {
                $breaches[] = [
                    'metric' => $metric,
                    'severity' => self::SEVERITY_SOFT,
                    'value' => $value,
                    'ceiling' => $softCeilings[$metric],
                    'action' => self::STATUS_PAUSE,
                ];
                $warnings[] = 'soft_ceiling_exceeded:'.$metric;
            }
        }

        $hasHard = $this->hasSeverity($breaches, self::SEVERITY_HARD);
        $hasSoft = $this->hasSeverity($breaches, self::SEVERITY_SOFT);

        // NEGATIVE INVARIANT: a hard breach is NEVER reported as ok; a soft breach is
        // NEVER dressed as ok either. Only an entirely clean run is ok.
        $status = $hasHard
            ? self::STATUS_STOP
            : ($hasSoft ? self::STATUS_PAUSE : self::STATUS_OK);

        // A stop carries a receipt so the operator has an auditable reason the run halted.
        $stopReceipt = null;
        if ($status === self::STATUS_STOP) {
            $hardBreaches = array_values(array_filter(
                $breaches,
                static fn (array $b): bool => ($b['severity'] ?? '') === self::SEVERITY_HARD,
            ));
            $stopReceipt = [
                'reason' => 'hard_resource_ceiling_exceeded',
                'breached_metrics' => array_values(array_map(
                    static fn (array $b): string => (string) ($b['metric'] ?? ''),
                    $hardBreaches,
                )),
                'receipt_id' => 'lrgstop_'.substr(MissionCanonicalHash::sha256([
                    $area,
                    $focus,
                    $runId,
                    $cycleIndex,
                    $hardBreaches,
                ]), 0, 16),
                'requires_operator_or_maintenance' => true,
            ];
        }

        $nextAction = match ($status) {
            self::STATUS_STOP => 'stop_resource_ceiling',
            self::STATUS_PAUSE => 'pause_resource_pressure',
            default => 'continue',
        };

        $providerBudgetFailoverSignal = $this->evaluateProviderBudgetFailoverSignal(
            ProviderBudgetFailoverSignalContract::governorInputFrom(
                areaId: $area,
                focus: $focus,
                runId: $runId,
                providerCalls: (int) $usage['provider_calls'],
                providerCallsHardCeiling: $hardCeilings['provider_calls'],
            ),
        );

        if (ProviderBudgetFailoverSignalContract::signalTriggersFailover($providerBudgetFailoverSignal)) {
            $warnings[] = 'provider_budget_failover:'.ProviderBudgetFailoverSignalContract::SIGNAL_ID;
            if ($status === self::STATUS_OK) {
                $nextAction = 'prepare_provider_failover';
            }
        }

        $resourceSummary = $this->buildResourceSummary($usage, $hardCeilings, $softCeilings, $breaches, $status);
        $resourceSummary['remaining_provider_budget_pct'] = $providerBudgetFailoverSignal['outputs']['remaining_provider_budget_pct'] ?? null;
        $resourceSummary['triggers_provider_failover'] = ProviderBudgetFailoverSignalContract::signalTriggersFailover($providerBudgetFailoverSignal);

        $payload = [
            'schema_version' => self::REPORT_SCHEMA,
            'ap_contract' => 'AP-808',
            'slice_id' => 'LHL-08',
            'status' => $status,
            'governor_id' => 'lrg_'.substr(MissionCanonicalHash::sha256([
                $area,
                $focus,
                $runId,
                $cycleIndex,
            ]), 0, 16),
            'run_id' => $runId,
            'cycle_index' => $cycleIndex,
            'area' => $area,
            'focus' => $focus,
            'checked_at' => (new DateTimeImmutable('now', new DateTimeZone('UTC')))->format(DateTimeInterface::ATOM),
            'usage' => $usage,
            'ceilings' => [
                'hard' => $hardCeilings,
                'soft' => $softCeilings,
            ],
            'breaches' => $breaches,
            'stop_receipt' => $stopReceipt,
            'resource_summary' => $resourceSummary,
            'next_action' => $nextAction,
            'blockers' => array_values(array_unique($blockers)),
            'warnings' => array_values(array_unique($warnings)),
            'provider_budget_failover_signal' => $providerBudgetFailoverSignal,
            'claim_policy' => [
                'read_only' => true,
                'runs_provider' => false,
                'runs_loop' => false,
                'runs_merge' => false,
                'deletes_branches' => false,
                'blocked_never_dressed_as_ready' => true,
            ],
        ];

        $payload['report_hash'] = 'sha256:'.MissionCanonicalHash::sha256($this->withoutVolatile($payload));

        return $payload;
    }

    /**
     * Provider budget failover signal entry seam (step 3/3).
     *
     * Empty input returns the step-1 default contract. A non-empty seam is
     * normalized through {@see ProviderBudgetFailoverSignalContract} so one
     * concrete usage record produces one concrete signal output (first wiring rule).
     *
     * @param  array<string,mixed>  $input
     * @return array<string,mixed>
     */
    public function evaluateProviderBudgetFailoverSignal(array $input = []): array
    {
        if ($input === []) {
            return ProviderBudgetFailoverSignalContract::defaults()->toArray();
        }

        return ProviderBudgetFailoverSignalContract::fromArray($input)->toArray();
    }

    // ---------------------------------------------------------------- helpers

    /**
     * Resolve the token estimate. Use real tokens when present; otherwise produce an
     * honest deterministic approximation flagged as such (never a silent guess).
     *
     * @param  array<string,mixed>  $input
     * @param  array<string,mixed>  $usageSource
     * @return array{value:int,is_approximation:bool}
     */
    private function resolveTokenEstimate(array $input, array $usageSource): array
    {
        $realTokens = $usageSource['token_estimate']
            ?? $usageSource['tokens']
            ?? $input['token_estimate']
            ?? $input['tokens']
            ?? null;

        if ($realTokens !== null && is_numeric($realTokens)) {
            return ['value' => $this->nonNegInt($realTokens), 'is_approximation' => false];
        }

        // Honest approximation: provider calls * per-call estimate + changed files * per-file.
        $calls = $this->nonNegInt($usageSource['provider_calls'] ?? ($input['provider_calls'] ?? 0));
        $changedFiles = $this->nonNegInt(
            $usageSource['changed_files'] ?? ($input['changed_files'] ?? ($usageSource['diff_files'] ?? ($input['diff_files'] ?? 0)))
        );

        $estimate = ($calls * self::TOKENS_PER_CALL_ESTIMATE) + ($changedFiles * self::TOKENS_PER_CHANGED_FILE_ESTIMATE);

        return ['value' => $estimate, 'is_approximation' => true];
    }

    /**
     * Max retries seen for a single finding/packet. Accepts either a scalar max, or a
     * map/list of per-id retry counts (we take the highest).
     *
     * @param  array<string,mixed>  $usageSource
     * @param  array<string,mixed>  $input
     */
    private function resolveMaxRetries(array $usageSource, array $input, string $key): int
    {
        $value = $usageSource[$key] ?? ($input[$key] ?? 0);

        if (is_array($value)) {
            $max = 0;
            foreach ($value as $count) {
                if (is_numeric($count)) {
                    $max = max($max, (int) $count);
                }
            }

            return $max;
        }

        return $this->nonNegInt($value);
    }

    /**
     * Merge operator-overridden ceilings over the canonical defaults. Both the
     * generic `ceilings` map and the specific `hard_ceilings`/`soft_ceilings` map are
     * honored; the specific key wins.
     *
     * @param  array<string,mixed>  $input
     * @param  array<string,int>  $defaults
     * @return array<string,int>
     */
    private function resolveCeilings(array $input, array $defaults, string $specificKey): array
    {
        $ceilings = $defaults;

        $generic = is_array($input['ceilings'] ?? null) ? $input['ceilings'] : [];
        foreach ($generic as $metric => $value) {
            if (is_string($metric) && is_numeric($value)) {
                $ceilings[$metric] = (int) $value;
            }
        }

        $specific = is_array($input[$specificKey] ?? null) ? $input[$specificKey] : [];
        foreach ($specific as $metric => $value) {
            if (is_string($metric) && is_numeric($value)) {
                $ceilings[$metric] = (int) $value;
            }
        }

        return $ceilings;
    }

    /**
     * @param  list<array<string,mixed>>  $breaches
     */
    private function hasSeverity(array $breaches, string $severity): bool
    {
        foreach ($breaches as $breach) {
            if (($breach['severity'] ?? '') === $severity) {
                return true;
            }
        }

        return false;
    }

    /**
     * The compact, operator-facing resource summary for the final run report.
     *
     * @param  array<string,mixed>  $usage
     * @param  array<string,int>  $hardCeilings
     * @param  array<string,int>  $softCeilings
     * @param  list<array<string,mixed>>  $breaches
     * @return array<string,mixed>
     */
    private function buildResourceSummary(array $usage, array $hardCeilings, array $softCeilings, array $breaches, string $status): array
    {
        $headroom = [];
        foreach (self::METRICS as $metric) {
            $value = (int) $usage[$metric];
            $hard = $hardCeilings[$metric] ?? null;
            $headroom[$metric] = [
                'value' => $value,
                'hard_ceiling' => $hard,
                'soft_ceiling' => $softCeilings[$metric] ?? null,
                'remaining_to_hard' => $hard !== null ? max(0, $hard - $value) : null,
            ];
        }

        return [
            'status' => $status,
            'provider_calls' => (int) $usage['provider_calls'],
            'token_estimate' => (int) $usage['token_estimate'],
            'token_estimate_is_approximation' => (bool) $usage['token_estimate_is_approximation'],
            'wall_time_seconds' => (int) $usage['wall_time_seconds'],
            'memory_mb' => (int) $usage['memory_mb'],
            'disk_growth_mb' => (int) $usage['disk_growth_mb'],
            'ledger_bytes' => (int) $usage['ledger_bytes'],
            'live_provider_processes' => (int) $usage['provider_process_count'],
            'worktrees' => (int) $usage['worktree_count'],
            'branches' => (int) $usage['branch_count'],
            'blocked_streak' => (int) $usage['blocked_streak'],
            'breach_count' => count($breaches),
            'hard_breach_count' => count(array_filter($breaches, static fn (array $b): bool => ($b['severity'] ?? '') === self::SEVERITY_HARD)),
            'soft_breach_count' => count(array_filter($breaches, static fn (array $b): bool => ($b['severity'] ?? '') === self::SEVERITY_SOFT)),
            'headroom' => $headroom,
        ];
    }

    private function nonNegInt(mixed $value): int
    {
        if (! is_numeric($value)) {
            return 0;
        }

        return max(0, (int) $value);
    }

    /**
     * A wiring-phase `fixture` may be a single usage record; fold it under the
     * explicit input so direct keys still take precedence (input-seam composition).
     *
     * @param  array<string,mixed>  $input
     * @return array<string,mixed>
     */
    private function mergeFixture(array $input): array
    {
        $fixture = $input['fixture'] ?? null;
        if (! is_array($fixture) || $fixture === []) {
            return $input;
        }
        unset($input['fixture']);

        return array_merge($fixture, $input);
    }

    /**
     * @param  array<string,mixed>  $payload
     * @return array<string,mixed>
     */
    private function withoutVolatile(array $payload): array
    {
        unset($payload['checked_at'], $payload['report_hash']);

        return $payload;
    }
}
