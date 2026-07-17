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
    public const STATUS_UNAVAILABLE = 'unavailable';

    public const STATUS_UNKNOWN = 'unknown';

    public const REASON_CANARY_DRIFT = 'canary_drift';

    public const REASON_CANARY_WITHIN_FLOORS = 'canary_within_floors';

    public const REASON_INSUFFICIENT_SIGNAL = 'insufficient_signal';
    public const FIELD_RECALL_AT_5 = 'recall_at_5';
    public const FIELD_IMPROPER_FLOOR_DISCARDS = 'improper_floor_discards';
    public const FIELD_STATUS = 'status';
    public const FIELD_REFS_TOTAL = 'refs_total';
    public const FIELD_REFS_CANONICAL = 'refs_canonical';
    public const FIELD_FLOWS_CHECKED = 'flows_checked';
    public const FIELD_REASON = 'reason';
    public const FIELD_OK = 'ok';


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
        $refStability = $refCounts[self::FIELD_REFS_TOTAL] > 0
            ? round($refCounts[self::FIELD_REFS_CANONICAL] / $refCounts[self::FIELD_REFS_TOTAL], 4)
            : null;

        $golden = $this->goldenSnapshot();

        $evidence = [
            'schema_version' => self::SCHEMA_VERSION,
            'as_of' => $now->toIso8601String(),
            'window_hours' => max(1, $this->windowHours),
            'top_n_flows' => max(1, $this->topNFlows),
            self::FIELD_FLOWS_CHECKED => $window[self::FIELD_FLOWS_CHECKED],
            'flows_available_in_window' => $window['flows_available'],
            self::FIELD_REFS_TOTAL => $refCounts[self::FIELD_REFS_TOTAL],
            self::FIELD_REFS_CANONICAL => $refCounts[self::FIELD_REFS_CANONICAL],
            'refs_by_kind' => $refCounts['by_kind'],
            'ref_stability' => $refStability,
            'ref_stability_floor' => self::REF_STABILITY_ALERT_FLOOR,
            'golden_version' => $golden['version'],
            'golden_recall_at_5' => $golden[self::FIELD_RECALL_AT_5],
            self::FIELD_IMPROPER_FLOOR_DISCARDS => $golden[self::FIELD_IMPROPER_FLOOR_DISCARDS],
            'golden_status' => $golden[self::FIELD_STATUS],
            'golden_recall_at_5_floor' => self::GOLDEN_RECALL_AT_5_ALERT_FLOOR,
            'provider_safe_invariant' => 'no_raw_query_or_context_in_report_or_ledger',
        ];

        $this->assertProviderSafeEvidence($evidence);

        if ($window[self::FIELD_FLOWS_CHECKED] === 0 && $golden[self::FIELD_RECALL_AT_5] === null) {
            return AtlasWatchdogCheckResult::skipped($evidence + [self::FIELD_REASON => self::REASON_INSUFFICIENT_SIGNAL]);
        }

        $violations = $this->violations($refStability, $golden);
        if ($violations !== []) {
            return AtlasWatchdogCheckResult::alert(
                $evidence + [self::FIELD_REASON => self::REASON_CANARY_DRIFT],
                [
                    'code' => 'daily_canary_drift',
                    'message' => 'MAXG-06 daily canary detected drift above frozen thresholds.',
                    'violations' => $violations,
                ],
            );
        }

        return AtlasWatchdogCheckResult::ok($evidence + [self::FIELD_REASON => self::REASON_CANARY_WITHIN_FLOORS]);
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
            self::FIELD_FLOWS_CHECKED => count($entries),
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
            foreach (AiValueNormalizer::arrayOrEmpty($entry['delivered_refs'] ?? null) as $ref) {
                $ref = AiValueNormalizer::trimmedStringOrNull($ref);
                if ($ref === null) {
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
            self::FIELD_REFS_TOTAL => $total,
            self::FIELD_REFS_CANONICAL => $canonical,
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
                self::FIELD_RECALL_AT_5 => null,
                self::FIELD_IMPROPER_FLOOR_DISCARDS => null,
                self::FIELD_STATUS => self::STATUS_UNAVAILABLE,
            ];
        }

        if (! is_array($report)) {
            return [
                'version' => null,
                self::FIELD_RECALL_AT_5 => null,
                self::FIELD_IMPROPER_FLOOR_DISCARDS => null,
                self::FIELD_STATUS => self::STATUS_UNAVAILABLE,
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
                self::FIELD_RECALL_AT_5 => null,
                self::FIELD_IMPROPER_FLOOR_DISCARDS => null,
                self::FIELD_STATUS => self::STATUS_UNAVAILABLE,
            ];
        }

        $chosen = $versions[$chosenKey];
        $recall = $chosen[self::FIELD_RECALL_AT_5] ?? $chosen['r5'] ?? null;
        $discards = $chosen[self::FIELD_IMPROPER_FLOOR_DISCARDS] ?? $chosen['fd'] ?? null;

        $recallNumeric = AiValueNormalizer::finiteFloatOrNull($recall);
        $discardsNumeric = AiValueNormalizer::finiteFloatOrNull($discards);

        return [
            'version' => $chosenKey,
            self::FIELD_RECALL_AT_5 => $recallNumeric === null ? null : round($recallNumeric, 4),
            self::FIELD_IMPROPER_FLOOR_DISCARDS => $discardsNumeric === null ? null : (int) $discardsNumeric,
            self::FIELD_STATUS => (AiValueNormalizer::trimmedStringOrNull($chosen[self::FIELD_STATUS] ?? null) ?? self::STATUS_UNKNOWN),
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

        if ($golden[self::FIELD_RECALL_AT_5] !== null && $golden[self::FIELD_RECALL_AT_5] < self::GOLDEN_RECALL_AT_5_ALERT_FLOOR) {
            $violations[] = [
                'metric' => 'golden_recall_at_5',
                'value' => $golden[self::FIELD_RECALL_AT_5],
                'floor' => self::GOLDEN_RECALL_AT_5_ALERT_FLOOR,
                'version' => $golden['version'],
            ];
        }

        if ($golden[self::FIELD_IMPROPER_FLOOR_DISCARDS] !== null && $golden[self::FIELD_IMPROPER_FLOOR_DISCARDS] > self::IMPROPER_FLOOR_DISCARD_ALERT_CEILING) {
            $violations[] = [
                'metric' => 'improper_floor_discards',
                'value' => $golden[self::FIELD_IMPROPER_FLOOR_DISCARDS],
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
