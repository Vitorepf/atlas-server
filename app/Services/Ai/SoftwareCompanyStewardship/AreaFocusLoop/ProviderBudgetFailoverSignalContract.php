<?php

declare(strict_types=1);

namespace App\Services\Ai\SoftwareCompanyStewardship\AreaFocusLoop;

/**
 * Data contract for the provider budget failover signal in
 * {@see LoopResourceGovernorService}. Step 3/3: consumed by the governor
 * evaluate() decision path via {@see self::governorInputFrom()} and
 * {@see self::signalTriggersFailover()}.
 */
final class ProviderBudgetFailoverSignalContract
{
    public const SCHEMA = 'atlas.software_company_stewardship.provider_budget_failover_signal.v1';

    public const GAP_MATRIX_CANONICAL = 'docs/engineering-knowledge-base/atlas-agentic-engineering-os-runtime-gap-matrix.md';

    public const SIGNAL_ID = 'provider_budget_exhausted';

    /** Failover triggers when remaining budget pct falls strictly below this floor. */
    public const FAILOVER_THRESHOLD_PCT = 20;

    /** Matches {@see LoopResourceGovernorService} default hard ceiling for provider_calls. */
    public const DEFAULT_PROVIDER_CALLS_HARD_CEILING = 240;

    private function __construct(
        public readonly string $areaId,
        public readonly string $focus,
        public readonly string $runId,
        public readonly int $providerCalls,
        public readonly int $providerCallsHardCeiling,
        public readonly ?int $remainingProviderBudgetPct,
    ) {}

    public static function defaults(
        string $areaId = 'agentic_engineering_os',
        string $focus = 'dev_forge',
    ): self {
        return new self(
            areaId: $areaId,
            focus: $focus,
            runId: '',
            providerCalls: 0,
            providerCallsHardCeiling: self::DEFAULT_PROVIDER_CALLS_HARD_CEILING,
            remainingProviderBudgetPct: null,
        );
    }

    /**
     * @param  array<string,mixed>  $input
     */
    public static function fromArray(array $input): self
    {
        $areaId = trim((string) ($input['area_id'] ?? 'agentic_engineering_os'));
        $focus = trim((string) ($input['focus'] ?? 'dev_forge'));
        $explicitRemaining = $input['remaining_provider_budget_pct'] ?? null;
        $normalizedRemaining = is_numeric($explicitRemaining)
            ? self::clampPct((int) $explicitRemaining)
            : null;

        return new self(
            areaId: $areaId !== '' ? $areaId : 'agentic_engineering_os',
            focus: $focus !== '' ? $focus : 'dev_forge',
            runId: trim((string) ($input['run_id'] ?? '')),
            providerCalls: max(0, (int) ($input['provider_calls'] ?? 0)),
            providerCallsHardCeiling: max(
                1,
                is_numeric($input['provider_calls_hard_ceiling'] ?? null)
                    ? (int) $input['provider_calls_hard_ceiling']
                    : self::DEFAULT_PROVIDER_CALLS_HARD_CEILING,
            ),
            remainingProviderBudgetPct: $normalizedRemaining,
        );
    }

    /**
     * Map governor usage context into the contract input seam.
     *
     * @return array<string,mixed>
     */
    public static function governorInputFrom(
        string $areaId,
        string $focus,
        string $runId,
        int $providerCalls,
        int $providerCallsHardCeiling,
        ?int $remainingProviderBudgetPct = null,
    ): array {
        return [
            'area_id' => $areaId,
            'focus' => $focus,
            'run_id' => $runId,
            'provider_calls' => $providerCalls,
            'provider_calls_hard_ceiling' => $providerCallsHardCeiling,
            'remaining_provider_budget_pct' => $remainingProviderBudgetPct,
        ];
    }

    /**
     * @param  array<string,mixed>  $signal
     */
    public static function signalTriggersFailover(array $signal): bool
    {
        return ($signal['schema_version'] ?? null) === self::SCHEMA
            && ($signal['signal_id'] ?? null) === self::SIGNAL_ID
            && ($signal['outputs']['signal_id'] ?? null) === self::SIGNAL_ID
            && ($signal['outputs']['triggers_provider_failover'] ?? false) === true;
    }

    /**
     * @return array<string,mixed>
     */
    public function toArray(): array
    {
        $remainingPct = $this->remainingProviderBudgetPct
            ?? self::computeRemainingPct($this->providerCalls, $this->providerCallsHardCeiling);
        $triggersFailover = $remainingPct < self::FAILOVER_THRESHOLD_PCT;

        return [
            'schema_version' => self::SCHEMA,
            'signal_id' => self::SIGNAL_ID,
            'failover_threshold_pct' => self::FAILOVER_THRESHOLD_PCT,
            'gap_matrix_canonical' => self::GAP_MATRIX_CANONICAL,
            'area_id' => $this->areaId,
            'focus' => $this->focus,
            'inputs' => [
                'run_id' => $this->runId,
                'provider_calls' => $this->providerCalls,
                'provider_calls_hard_ceiling' => $this->providerCallsHardCeiling,
                'remaining_provider_budget_pct' => $this->remainingProviderBudgetPct,
            ],
            'outputs' => [
                'remaining_provider_budget_pct' => $remainingPct,
                'triggers_provider_failover' => $triggersFailover,
                'signal_id' => $triggersFailover ? self::SIGNAL_ID : null,
            ],
        ];
    }

    private static function computeRemainingPct(int $providerCalls, int $hardCeiling): int
    {
        if ($hardCeiling <= 0) {
            return 0;
        }

        $remainingCalls = max(0, $hardCeiling - $providerCalls);

        return self::clampPct((int) floor(($remainingCalls / $hardCeiling) * 100));
    }

    private static function clampPct(int $pct): int
    {
        return max(0, min(100, $pct));
    }
}
