<?php

declare(strict_types=1);

namespace App\Services\Ai\Cognition\Watchdog\Checks;

use App\Services\Ai\Cognition\Watchdog\AtlasWatchdogCheck;
use App\Services\Ai\Cognition\Watchdog\AtlasWatchdogCheckResult;
use App\Services\Ai\Context\AtlasCanonicalContextRef;
use App\Services\Ai\Context\AtlasDeliveredPackLedger;
use App\Services\Ai\Context\LocalRagBenchmarkService;
use App\Services\Ai\Support\AiValueNormalizer;
use Carbon\CarbonImmutable;
use Throwable;

/**
 * MAXG-06 — Daily canary: replay-by-refs (no raw query).
 *
 * Reads the last N delivered-pack-ledger entries within a rolling window and
 * measures ref stability + one golden vN recall run. The check is provider-safe
 * by CONSTRUCTION: only refs, hashes, counters, floats. It never reads or emits
 * the raw query, packed markdown, or memory bodies.
 *
 * Stage-1 (default): read-only over the already-persisted ledger + a golden
 * fixture report. Stage-2 (documented in the frontier plan; NOT wired here) is
 * a default-OFF ring-buffer of queries that only the operator can flip. This
 * class implements Stage-1 and asserts the invariant.
 */
final class DailyCanaryReplayByRefsWatchdogCheck implements AtlasWatchdogCheck
{
    public const SCHEMA_VERSION = 'atlas.acos.watchdog.daily_canary_replay_by_refs.v1';

    public const DEFAULT_WINDOW_HOURS = 24;

    public const DEFAULT_TOP_N_FLOWS = 25;

    public const REF_STABILITY_ALERT_FLOOR = 0.95;

    public const GOLDEN_RECALL_AT_5_ALERT_FLOOR = 0.40;

    public const IMPROPER_FLOOR_DISCARD_ALERT_CEILING = 0;

    /** Provider-safe guard: any evidence key matching this regex is forbidden. */
    public const FORBIDDEN_EVIDENCE_KEY_PATTERN = '/(^|_)(query|prompt|context|body|markdown|text|raw)(_|$)/i';

    /** @var callable|null */
    private $goldenReportProvider;

    /** @var callable|null */
    private $nowProvider;

    /**
     * @param  callable():array<string,mixed>|null  $goldenReportProvider Injectable seam for tests; defaults to the LocalRagBenchmarkService report.
     * @param  callable():CarbonImmutable|null  $nowProvider Injectable clock for tests.
     */
    public function __construct(
        private readonly AtlasDeliveredPackLedger $deliveredPackLedger,
        private readonly ?LocalRagBenchmarkService $benchmark = null,
        private readonly int $windowHours = self::DEFAULT_WINDOW_HOURS,
        private readonly int $topNFlows = self::DEFAULT_TOP_N_FLOWS,
        ?callable $goldenReportProvider = null,
        ?callable $nowProvider = null,
    ) {
        $this->goldenReportProvider = $goldenReportProvider;
        $this->nowProvider = $nowProvider;
    }

    public function id(): string
    {
        return 'wdg-01.daily_canary_replay_by_refs';
    }

    public function run(): AtlasWatchdogCheckResult
    {
        $now = $this->now();
        $window = $this->deliveredWindow($now);

        $refCounts = $this->refCounts($window['entries']);
        $refStability = $refCounts['refs_total'] > 0
            ? round($refCounts['refs_canonical'] / $refCounts['refs_total'], 4)
            : null;

        $golden = $this->goldenSnapshot();

        $evidence = [
            'schema_version' => self::SCHEMA_VERSION,
            'as_of' => $now->toIso8601String(),
            'window_hours' => max(1, $this->windowHours),
            'top_n_flows' => max(1, $this->topNFlows),
            'flows_checked' => $window['flows_checked'],
            'flows_available_in_window' => $window['flows_available'],
            'refs_total' => $refCounts['refs_total'],
            'refs_canonical' => $refCounts['refs_canonical'],
            'refs_by_kind' => $refCounts['by_kind'],
            'ref_stability' => $refStability,
            'ref_stability_floor' => self::REF_STABILITY_ALERT_FLOOR,
            'golden_version' => $golden['version'],
            'golden_recall_at_5' => $golden['recall_at_5'],
            'improper_floor_discards' => $golden['improper_floor_discards'],
            'golden_status' => $golden['status'],
            'golden_recall_at_5_floor' => self::GOLDEN_RECALL_AT_5_ALERT_FLOOR,
            'provider_safe_invariant' => 'no_raw_query_or_context_in_report_or_ledger',
        ];

        $this->assertProviderSafeEvidence($evidence);

        if ($window['flows_checked'] === 0 && $golden['recall_at_5'] === null) {
            return AtlasWatchdogCheckResult::skipped($evidence + ['reason' => 'insufficient_signal']);
        }

        $violations = $this->violations($refStability, $golden);
        if ($violations !== []) {
            return AtlasWatchdogCheckResult::alert(
                $evidence + ['reason' => 'canary_drift'],
                [
                    'code' => 'daily_canary_drift',
                    'message' => 'MAXG-06 daily canary detected drift above frozen thresholds.',
                    'violations' => $violations,
                ],
            );
        }

        return AtlasWatchdogCheckResult::ok($evidence + ['reason' => 'canary_within_floors']);
    }

    /**
     * @return array{flows_checked:int,flows_available:int,entries:list<array<string,mixed>>}
     */
    private function deliveredWindow(CarbonImmutable $now): array
    {
        $windowHours = max(1, $this->windowHours);
        $topN = max(1, $this->topNFlows);
        $cutoffIso = $now->subHours($windowHours)->toIso8601String();

        // Get bounded top-N in window (already sorted most-recent first).
        $entries = $this->deliveredPackLedger->entriesSince($cutoffIso, $topN);

        // We report only the sampled count as flows_available: the ledger method
        // caps at topN, so we do NOT fabricate a larger denominator; the acceptance
        // is on ref stability + golden — the top-N sample is honest by contract.
        return [
            'flows_checked' => count($entries),
            'flows_available' => count($entries),
            'entries' => $entries,
        ];
    }

    /**
     * @param  list<array<string,mixed>>  $entries
     * @return array{refs_total:int,refs_canonical:int,by_kind:array<string,int>}
     */
    private function refCounts(array $entries): array
    {
        $total = 0;
        $canonical = 0;
        $byKind = ['code' => 0, 'memory' => 0, 'graph' => 0, 'non_canonical' => 0];

        foreach ($entries as $entry) {
            foreach ((array) ($entry['delivered_refs'] ?? []) as $ref) {
                if (! is_string($ref)) {
                    continue;
                }
                $ref = trim($ref);
                if ($ref === '') {
                    continue;
                }
                $total++;
                if (! AtlasCanonicalContextRef::isCanonical($ref)) {
                    $byKind['non_canonical']++;

                    continue;
                }
                $canonical++;
                [$kind] = explode(':', $ref, 2);
                if (isset($byKind[$kind])) {
                    $byKind[$kind]++;
                }
            }
        }

        return [
            'refs_total' => $total,
            'refs_canonical' => $canonical,
            'by_kind' => $byKind,
        ];
    }

    /**
     * @return array{version:?string,recall_at_5:?float,improper_floor_discards:?int,status:string}
     */
    private function goldenSnapshot(): array
    {
        try {
            $report = $this->fetchGoldenReport();
        } catch (Throwable) {
            return [
                'version' => null,
                'recall_at_5' => null,
                'improper_floor_discards' => null,
                'status' => 'unavailable',
            ];
        }

        if (! is_array($report)) {
            return [
                'version' => null,
                'recall_at_5' => null,
                'improper_floor_discards' => null,
                'status' => 'unavailable',
            ];
        }

        $versions = is_array($report['memory_recall_golden_versions'] ?? null)
            ? $report['memory_recall_golden_versions']
            : $report;

        // Prefer the highest version available (v2 > v1); fall back to v1 for legacy.
        $chosenKey = null;
        foreach (['v2', 'v3', 'v4'] as $candidate) {
            if (isset($versions[$candidate]) && is_array($versions[$candidate])) {
                $chosenKey = $candidate;
                break;
            }
        }
        if ($chosenKey === null && isset($versions['v1']) && is_array($versions['v1'])) {
            $chosenKey = 'v1';
        }
        if ($chosenKey === null) {
            return [
                'version' => null,
                'recall_at_5' => null,
                'improper_floor_discards' => null,
                'status' => 'unavailable',
            ];
        }

        $chosen = $versions[$chosenKey];
        $recall = $chosen['recall_at_5'] ?? $chosen['r5'] ?? null;
        $discards = $chosen['improper_floor_discards'] ?? $chosen['fd'] ?? null;

        $recallNumeric = AiValueNormalizer::finiteFloatOrNull($recall);
        $discardsNumeric = AiValueNormalizer::finiteFloatOrNull($discards);

        return [
            'version' => $chosenKey,
            'recall_at_5' => $recallNumeric === null ? null : round($recallNumeric, 4),
            'improper_floor_discards' => $discardsNumeric === null ? null : (int) $discardsNumeric,
            'status' => (string) ($chosen['status'] ?? 'unknown'),
        ];
    }

    /**
     * @return array<string,mixed>|null
     */
    private function fetchGoldenReport(): ?array
    {
        if ($this->goldenReportProvider !== null) {
            $result = ($this->goldenReportProvider)();

            return is_array($result) ? $result : null;
        }
        if ($this->benchmark instanceof LocalRagBenchmarkService) {
            $report = $this->benchmark->report();

            return is_array($report) ? $report : null;
        }

        return null;
    }

    /**
     * @param  array{version:?string,recall_at_5:?float,improper_floor_discards:?int,status:string}  $golden
     * @return list<array<string,mixed>>
     */
    private function violations(?float $refStability, array $golden): array
    {
        $violations = [];

        if ($refStability !== null && $refStability < self::REF_STABILITY_ALERT_FLOOR) {
            $violations[] = [
                'metric' => 'ref_stability',
                'value' => $refStability,
                'floor' => self::REF_STABILITY_ALERT_FLOOR,
            ];
        }

        if ($golden['recall_at_5'] !== null && $golden['recall_at_5'] < self::GOLDEN_RECALL_AT_5_ALERT_FLOOR) {
            $violations[] = [
                'metric' => 'golden_recall_at_5',
                'value' => $golden['recall_at_5'],
                'floor' => self::GOLDEN_RECALL_AT_5_ALERT_FLOOR,
                'version' => $golden['version'],
            ];
        }

        if ($golden['improper_floor_discards'] !== null && $golden['improper_floor_discards'] > self::IMPROPER_FLOOR_DISCARD_ALERT_CEILING) {
            $violations[] = [
                'metric' => 'improper_floor_discards',
                'value' => $golden['improper_floor_discards'],
                'ceiling' => self::IMPROPER_FLOOR_DISCARD_ALERT_CEILING,
                'version' => $golden['version'],
            ];
        }

        return $violations;
    }

    /**
     * @param  array<string,mixed>  $evidence
     */
    private function assertProviderSafeEvidence(array $evidence): void
    {
        foreach ($evidence as $key => $value) {
            if (is_string($key) && preg_match(self::FORBIDDEN_EVIDENCE_KEY_PATTERN, $key) === 1) {
                throw new \LogicException('MAXG-06 evidence key would leak raw content: '.$key);
            }
            if (is_array($value)) {
                $this->assertProviderSafeEvidence($value);
            }
        }
    }

    private function now(): CarbonImmutable
    {
        if ($this->nowProvider !== null) {
            $result = ($this->nowProvider)();
            if ($result instanceof CarbonImmutable) {
                return $result;
            }
        }

        return CarbonImmutable::now();
    }
}
