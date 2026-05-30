<?php

declare(strict_types=1);

namespace App\Services\Ai\SoftwareCompanyStewardship\AreaFocusLoop;

use App\Services\Ai\Mission\MissionCanonicalHash;
use DateTimeImmutable;
use DateTimeInterface;
use DateTimeZone;

/**
 * FASE 5 · honest observability read-model over the durable AP-790 cycle ledger.
 *
 * This is a pure READ model: it never invokes a provider, never merges, never
 * deletes a branch and never writes the ledger. It replays the append-only cycle
 * receipts that {@see Reliable24hLoopRunnerService} already records and computes
 * the operator-facing metrics for a 24h loop window:
 *
 *  - utilization = merges / token-spending cycles (cycles that actually spent
 *    owner runtime — merged OR blocked — vs no-op progress/repeated cycles);
 *  - product vs self-maintenance merges (so self-maintenance can never silently
 *    crowd out product throughput);
 *  - tokens / merge, when the ledger carries token usage (honest: reported only
 *    when at least one cycle recorded usage, never fabricated);
 *  - gaps discovered vs implemented (distinct findings seen vs distinct findings
 *    merged), so the loop's real conversion rate is visible.
 *
 * Metrics are computed ONLY from what the ledger durably proves; any quantity the
 * ledger does not carry is reported as unavailable rather than invented.
 */
final class Loop24hMetricsReadModelService
{
    public const SCHEMA = 'atlas.software_company_stewardship.ap790_loop_24h_metrics.v1';

    private const OUTCOME_MERGED = 'merged';

    private const OUTCOME_BLOCKED = 'blocked';

    public function __construct(
        private readonly Reliable24hLoopRunnerService $runner,
    ) {}

    /**
     * Compute the FASE 5 metrics for an area/focus loop ledger.
     *
     * @return array<string,mixed>
     */
    public function metrics(string $areaId, string $focus = 'dev_forge'): array
    {
        $records = $this->runner->readLedgerRecords($areaId, $focus);

        return $this->computeFromRecords($areaId, $focus, $records);
    }

    /**
     * Pure computation seam — testable without touching the filesystem.
     *
     * @param  list<array<string,mixed>>  $records
     * @return array<string,mixed>
     */
    public function computeFromRecords(string $areaId, string $focus, array $records): array
    {
        $cycles = 0;
        $merges = 0;
        $blocked = 0;
        $tokenSpendingCycles = 0;
        $productMerges = 0;
        $selfMaintenanceMerges = 0;

        $tokensTotal = 0;
        $cyclesWithTokenUsage = 0;

        /** @var array<string,true> $findingsSeen */
        $findingsSeen = [];
        /** @var array<string,true> $findingsMerged */
        $findingsMerged = [];

        foreach ($records as $record) {
            if (! is_array($record)) {
                continue;
            }
            if ((string) ($record['schema_version'] ?? '') !== Reliable24hLoopRunnerService::LEDGER_SCHEMA) {
                continue;
            }

            $cycles++;
            $outcome = (string) ($record['outcome'] ?? '');
            $merged = (bool) ($record['merge_performed'] ?? false) || $outcome === self::OUTCOME_MERGED;
            $isBlocked = $outcome === self::OUTCOME_BLOCKED;

            // A token-spending cycle is one that actually consumed owner runtime:
            // a merge or a blocked attempt. A no-op progress/repeated cycle did not
            // spend, so it must not dilute utilization.
            if ($merged || $isBlocked) {
                $tokenSpendingCycles++;
            }

            $findingKey = (string) ($record['finding_key'] ?? '');
            if ($findingKey !== '') {
                $findingsSeen[$findingKey] = true;
            }

            if ($merged) {
                $merges++;
                if ($findingKey !== '') {
                    $findingsMerged[$findingKey] = true;
                }
                if ((string) ($record['work_class'] ?? Reliable24hLoopRunnerService::WORK_CLASS_PRODUCT) === Reliable24hLoopRunnerService::WORK_CLASS_SELF_MAINTENANCE) {
                    $selfMaintenanceMerges++;
                } else {
                    $productMerges++;
                }
            } elseif ($isBlocked) {
                $blocked++;
            }

            $tokens = $this->cycleTokens($record);
            if ($tokens !== null) {
                $tokensTotal += $tokens;
                $cyclesWithTokenUsage++;
            }
        }

        $gapsDiscovered = count($findingsSeen);
        $gapsImplemented = count($findingsMerged);

        return [
            'schema_version' => self::SCHEMA,
            'area_id' => $areaId,
            'focus' => $focus,
            'cycles' => $cycles,
            'token_spending_cycles' => $tokenSpendingCycles,
            'merges' => $merges,
            'blocked_cycles' => $blocked,
            'utilization' => $this->ratio($merges, $tokenSpendingCycles),
            'merge_mix' => [
                'product_merges' => $productMerges,
                'self_maintenance_merges' => $selfMaintenanceMerges,
                'self_maintenance_share' => $this->ratio($selfMaintenanceMerges, $merges),
            ],
            'tokens' => [
                'available' => $cyclesWithTokenUsage > 0,
                'total' => $cyclesWithTokenUsage > 0 ? $tokensTotal : null,
                'cycles_with_usage' => $cyclesWithTokenUsage,
                // tokens/merge is honest: null when no token usage was recorded OR
                // when there were zero merges (never divide-by-zero, never fabricate).
                'per_merge' => ($cyclesWithTokenUsage > 0 && $merges > 0)
                    ? round($tokensTotal / $merges, 2)
                    : null,
            ],
            'gaps' => [
                'discovered' => $gapsDiscovered,
                'implemented' => $gapsImplemented,
                'conversion' => $this->ratio($gapsImplemented, $gapsDiscovered),
            ],
            'computed_at' => $this->now(),
            'metrics_hash' => 'sha256:'.MissionCanonicalHash::sha256([
                $areaId, $focus, $cycles, $merges, $blocked, $tokenSpendingCycles,
                $productMerges, $selfMaintenanceMerges, $gapsDiscovered, $gapsImplemented,
            ]),
        ];
    }

    /**
     * Read token usage from a cycle record if the ledger carries it. Returns null
     * when no usage is recorded so the read-model reports `available=false` rather
     * than treating absent telemetry as zero tokens.
     *
     * @param  array<string,mixed>  $record
     */
    private function cycleTokens(array $record): ?int
    {
        foreach (['tokens', 'token_usage', 'total_tokens'] as $key) {
            $value = $record[$key] ?? null;
            if (is_int($value) || (is_string($value) && is_numeric($value))) {
                return (int) $value;
            }
            if (is_array($value) && isset($value['total']) && (is_int($value['total']) || (is_string($value['total']) && is_numeric($value['total'])))) {
                return (int) $value['total'];
            }
        }

        return null;
    }

    private function ratio(int $numerator, int $denominator): float
    {
        if ($denominator <= 0) {
            return 0.0;
        }

        return round($numerator / $denominator, 4);
    }

    private function now(): string
    {
        return (new DateTimeImmutable('now', new DateTimeZone('UTC')))->format(DateTimeInterface::ATOM);
    }
}
