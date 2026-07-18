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
    public const FIELD_VERSION = 'version';
    public const FIELD_METRIC = 'metric';
    public const FIELD_VALUE = 'value';
    public const FIELD_FLOOR = 'floor';
    public const FIELD_CODE = 'code';
    public const FIELD_SCHEMA_VERSION = 'schema_version';
    public const FIELD_AS_OF = 'as_of';
    public const FIELD_WINDOW_HOURS = 'window_hours';
    public const FIELD_TOP_N_FLOWS = 'top_n_flows';
    public const FIELD_FLOWS_AVAILABLE_IN_WINDOW = 'flows_available_in_window';
    public const FIELD_REFS_BY_KIND = 'refs_by_kind';
    public const FIELD_REF_STABILITY = 'ref_stability';
    public const FIELD_FLOWS_AVAILABLE = 'flows_available';
    public const FIELD_ENTRIES = 'entries';
    public const FIELD_NON_CANONICAL = 'non_canonical';
    public const FIELD_BY_KIND = 'by_kind';
    public const FIELD_MEMORY_RECALL_GOLDEN_VERSIONS = 'memory_recall_golden_versions';
    public const FIELD_REF_STABILITY_FLOOR = 'ref_stability_floor';
    public const FIELD_GOLDEN_VERSION = 'golden_version';
    public const FIELD_GOLDEN_RECALL_AT_5 = 'golden_recall_at_5';
    public const FIELD_V1 = 'v1';
    public const FIELD_VIOLATIONS = 'violations';
    public const FIELD_CEILING = 'ceiling';
    public const FIELD_DELIVERED_REFS = 'delivered_refs';
    public const FIELD_FD = 'fd';
    public const FIELD_GOLDEN_RECALL_AT_5_FLOOR = 'golden_recall_at_5_floor';
    public const FIELD_GOLDEN_STATUS = 'golden_status';
    public const FIELD_GRAPH = 'graph';
    public const FIELD_MEMORY = 'memory';
    public const FIELD_MESSAGE = 'message';
    public const FIELD_PROVIDER_SAFE_INVARIANT = 'provider_safe_invariant';
    public const FIELD_R5 = 'r5';


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

        $refCounts = $this->refCounts($window[self::FIELD_ENTRIES]);
        $refStability = $refCounts[self::FIELD_REFS_TOTAL] > 0
            ? round($refCounts[self::FIELD_REFS_CANONICAL] / $refCounts[self::FIELD_REFS_TOTAL], 4)
            : null;

        $golden = $this->goldenSnapshot();

        $evidence = [
            self::FIELD_SCHEMA_VERSION => self::SCHEMA_VERSION,
            self::FIELD_AS_OF => $now->toIso8601String(),
            self::FIELD_WINDOW_HOURS => max(1, $this->windowHours),
            self::FIELD_TOP_N_FLOWS => max(1, $this->topNFlows),
            self::FIELD_FLOWS_CHECKED => $window[self::FIELD_FLOWS_CHECKED],
            self::FIELD_FLOWS_AVAILABLE_IN_WINDOW => $window[self::FIELD_FLOWS_AVAILABLE],
            self::FIELD_REFS_TOTAL => $refCounts[self::FIELD_REFS_TOTAL],
            self::FIELD_REFS_CANONICAL => $refCounts[self::FIELD_REFS_CANONICAL],
            self::FIELD_REFS_BY_KIND => $refCounts[self::FIELD_BY_KIND],
            self::FIELD_REF_STABILITY => $refStability,
            self::FIELD_REF_STABILITY_FLOOR => self::REF_STABILITY_ALERT_FLOOR,
            self::FIELD_GOLDEN_VERSION => $golden[self::FIELD_VERSION],
            self::FIELD_GOLDEN_RECALL_AT_5 => $golden[self::FIELD_RECALL_AT_5],
            self::FIELD_IMPROPER_FLOOR_DISCARDS => $golden[self::FIELD_IMPROPER_FLOOR_DISCARDS],
            self::FIELD_GOLDEN_STATUS => $golden[self::FIELD_STATUS],
            self::FIELD_GOLDEN_RECALL_AT_5_FLOOR => self::GOLDEN_RECALL_AT_5_ALERT_FLOOR,
            self::FIELD_PROVIDER_SAFE_INVARIANT => 'no_raw_query_or_context_in_report_or_ledger',
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
                    self::FIELD_CODE => 'daily_canary_drift',
                    self::FIELD_MESSAGE => 'MAXG-06 daily canary detected drift above frozen thresholds.',
                    self::FIELD_VIOLATIONS => $violations,
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
            self::FIELD_FLOWS_AVAILABLE => count($entries),
            self::FIELD_ENTRIES => $entries,
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
        $byKind = [self::FIELD_CODE => 0, self::FIELD_MEMORY => 0, self::FIELD_GRAPH => 0, self::FIELD_NON_CANONICAL => 0];

        foreach ($entries as $entry) {
            foreach (AiValueNormalizer::arrayOrEmpty($entry[self::FIELD_DELIVERED_REFS] ?? null) as $ref) {
                $ref = AiValueNormalizer::trimmedStringOrNull($ref);
                if ($ref === null) {
                    continue;
                }
                $total++;
                if (! AtlasCanonicalContextRef::isCanonical($ref)) {
                    $byKind[self::FIELD_NON_CANONICAL]++;

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
            self::FIELD_BY_KIND => $byKind,
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
                self::FIELD_VERSION => null,
                self::FIELD_RECALL_AT_5 => null,
                self::FIELD_IMPROPER_FLOOR_DISCARDS => null,
                self::FIELD_STATUS => self::STATUS_UNAVAILABLE,
            ];
        }

        if (! is_array($report)) {
            return [
                self::FIELD_VERSION => null,
                self::FIELD_RECALL_AT_5 => null,
                self::FIELD_IMPROPER_FLOOR_DISCARDS => null,
                self::FIELD_STATUS => self::STATUS_UNAVAILABLE,
            ];
        }

        $versions = is_array($report[self::FIELD_MEMORY_RECALL_GOLDEN_VERSIONS] ?? null)
            ? $report[self::FIELD_MEMORY_RECALL_GOLDEN_VERSIONS]
            : $report;

        // Prefer the highest version available (v2 > v1); fall back to v1 for legacy.
        $chosenKey = null;
        foreach (['v2', 'v3', 'v4'] as $candidate) {
            if (isset($versions[$candidate]) && is_array($versions[$candidate])) {
                $chosenKey = $candidate;
                break;
            }
        }
        if ($chosenKey === null && isset($versions[self::FIELD_V1]) && is_array($versions[self::FIELD_V1])) {
            $chosenKey = 'v1';
        }
        if ($chosenKey === null) {
            return [
                self::FIELD_VERSION => null,
                self::FIELD_RECALL_AT_5 => null,
                self::FIELD_IMPROPER_FLOOR_DISCARDS => null,
                self::FIELD_STATUS => self::STATUS_UNAVAILABLE,
            ];
        }

        $chosen = $versions[$chosenKey];
        $recall = $chosen[self::FIELD_RECALL_AT_5] ?? $chosen[self::FIELD_R5] ?? null;
        $discards = $chosen[self::FIELD_IMPROPER_FLOOR_DISCARDS] ?? $chosen[self::FIELD_FD] ?? null;

        $recallNumeric = AiValueNormalizer::finiteFloatOrNull($recall);
        $discardsNumeric = AiValueNormalizer::finiteFloatOrNull($discards);

        return [
            self::FIELD_VERSION => $chosenKey,
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
                self::FIELD_METRIC => 'ref_stability',
                self::FIELD_VALUE => $refStability,
                self::FIELD_FLOOR => self::REF_STABILITY_ALERT_FLOOR,
            ];
        }

        if ($golden[self::FIELD_RECALL_AT_5] !== null && $golden[self::FIELD_RECALL_AT_5] < self::GOLDEN_RECALL_AT_5_ALERT_FLOOR) {
            $violations[] = [
                self::FIELD_METRIC => 'golden_recall_at_5',
                self::FIELD_VALUE => $golden[self::FIELD_RECALL_AT_5],
                self::FIELD_FLOOR => self::GOLDEN_RECALL_AT_5_ALERT_FLOOR,
                self::FIELD_VERSION => $golden[self::FIELD_VERSION],
            ];
        }

        if ($golden[self::FIELD_IMPROPER_FLOOR_DISCARDS] !== null && $golden[self::FIELD_IMPROPER_FLOOR_DISCARDS] > self::IMPROPER_FLOOR_DISCARD_ALERT_CEILING) {
            $violations[] = [
                self::FIELD_METRIC => 'improper_floor_discards',
                self::FIELD_VALUE => $golden[self::FIELD_IMPROPER_FLOOR_DISCARDS],
                self::FIELD_CEILING => self::IMPROPER_FLOOR_DISCARD_ALERT_CEILING,
                self::FIELD_VERSION => $golden[self::FIELD_VERSION],
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
