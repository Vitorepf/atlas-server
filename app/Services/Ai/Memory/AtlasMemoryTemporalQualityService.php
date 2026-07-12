<?php

declare(strict_types=1);

namespace App\Services\Ai\Memory;

use App\Models\AtlasMemoryEntry;
use App\Models\AtlasMemoryEntryRelation;
use App\Services\Ai\Support\DatabaseTableAvailability;
use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;

final class AtlasMemoryTemporalQualityService
{
    public const SCHEMA_VERSION = 'atlas.memory.temporal_quality.v1';

    public const MEASURE_ID = 'atlas.memory.temporal_truth.v2';

    public const FORMULA_VERSION = 'memory_temporal_truth.v2';

    private const DENOMINATOR_MIN_ACTIVE = 8;

    private const RELATION_WINDOW_DAYS = 30;

    private const RELATION_CONFIDENCE_FLOOR = 0.86;

    private const RELATION_QUALITY_FLOOR = 0.75;

    private const R8_RECALL_LIFT_MIN = 0.01;

    private const SCANNER_COSINE_THRESHOLD = 0.82;

    private const SCANNER_CONFIDENCE_FLOOR = 0.86;

    private const SCANNER_CANDIDATE_MIN_PAIRS = 12;

    /** @return array<string,mixed> */
    public static function freezePayload(): array
    {
        return [
            'kind' => 'measure_freeze',
            'measure_id' => self::MEASURE_ID,
            'formula_version' => self::FORMULA_VERSION,
            'formula' => 'temporal_truth_v2 exposes raw temporal_provenance_coverage, truth_density_v2 and supersession_maintained counts; no scalar score.',
            'thresholds' => [
                'active_denominator_min' => self::DENOMINATOR_MIN_ACTIVE,
                'relation_window_days' => self::RELATION_WINDOW_DAYS,
                'relation_confidence_floor' => self::RELATION_CONFIDENCE_FLOOR,
                'relation_quality_floor' => self::RELATION_QUALITY_FLOOR,
                'r8_recall_lift_min' => self::R8_RECALL_LIFT_MIN,
                'scanner_cosine_threshold' => self::SCANNER_COSINE_THRESHOLD,
                'scanner_confidence_floor' => self::SCANNER_CONFIDENCE_FLOOR,
                'scanner_candidate_min_pairs' => self::SCANNER_CANDIDATE_MIN_PAIRS,
                'non_pathological_density_rule' => 'relation counts only when frozen confidence+quality bars pass OR R8 recall lift improves; scanner thresholds are blind to density output.',
            ],
            'denominator_min' => self::DENOMINATOR_MIN_ACTIVE,
            'ttl_days' => 30,
            'author_engine_id' => 'cursor-acos-max-maxh-01',
            'judge_engine_id' => 'codex-maxh-01-temporal-quality-judge',
            'series_registry' => [
                'series' => self::MEASURE_ID,
                'reader_command' => 'atlas:memory:temporal-quality --json',
                'watchdog_plugin' => 'elev-20s.dead_series_registry',
            ],
            'dual_read' => [
                'valor_antigo' => 'atlas:memory:quality frozen v1 remains unchanged and carries no temporal_truth_v2 keys',
                'valor_novo' => 'atlas:memory:temporal-quality --json reports three raw temporal truth coverages with frozen thresholds',
                'justificativa' => 'MAXH-01 versions temporal truth measurement beside the frozen memory quality scorecard before any temporal producers run.',
            ],
        ];
    }

    /** @return array<string,mixed> */
    public function report(): array
    {
        $freeze = self::freezePayload();
        $thresholds = (array) $freeze['thresholds'];
        $now = CarbonImmutable::now('UTC');

        if (! DatabaseTableAvailability::has('atlas_memory_entries')) {
            return $this->emptyReport('memory_table_missing', $freeze, $now);
        }

        /** @var Collection<int,AtlasMemoryEntry> $active */
        $active = AtlasMemoryEntry::query()
            ->where('status', 'active')
            ->whereNull('archived_at')
            ->orderBy('recorded_at')
            ->get();

        $activeIds = $active
            ->pluck('id')
            ->filter(fn (mixed $id): bool => is_string($id) && $id !== '')
            ->map(fn (mixed $id): string => (string) $id)
            ->values()
            ->all();

        $relations = $this->relationRows($activeIds, $now->subDays(self::RELATION_WINDOW_DAYS));
        $metrics = [
            'temporal_provenance_coverage' => $this->countPayload(
                $active->filter(fn (AtlasMemoryEntry $entry): bool => $this->hasNonDefaultTemporalProvenance($entry))->count(),
                $active->count(),
            ),
            'truth_density_v2' => $this->countPayload(
                $relations->filter(fn (AtlasMemoryEntryRelation $relation): bool => $this->relationQualifiesForTruthDensity($relation))->count(),
                $relations->count(),
            ),
            'supersession_maintained' => $this->supersessionMaintained($relations, $activeIds),
        ];

        [$status, $reason] = $this->status($active->count(), $relations->count(), $metrics);

        return [
            'schema_version' => self::SCHEMA_VERSION,
            'measure_id' => self::MEASURE_ID,
            'formula_version' => self::FORMULA_VERSION,
            'status' => $status,
            'reason' => $reason,
            'generated_at' => $now->toIso8601String(),
            'active_entry_count' => $active->count(),
            'relation_window_days' => self::RELATION_WINDOW_DAYS,
            'metrics' => $metrics,
            'freeze' => [
                'measure_id' => self::MEASURE_ID,
                'formula_version' => self::FORMULA_VERSION,
                'denominator_min' => self::DENOMINATOR_MIN_ACTIVE,
                'thresholds' => $thresholds,
                'author_engine_id' => (string) $freeze['author_engine_id'],
                'judge_engine_id' => (string) $freeze['judge_engine_id'],
                'judge_author_distinct' => $freeze['author_engine_id'] !== $freeze['judge_engine_id'],
            ],
            'sources' => [
                'entries' => 'atlas_memory_entries active rows',
                'relations' => 'atlas_memory_entry_relations rows in frozen relation window',
                'read_only' => true,
            ],
        ];
    }

    /** @return array<string,mixed> */
    private function emptyReport(string $reason, array $freeze, CarbonImmutable $now): array
    {
        return [
            'schema_version' => self::SCHEMA_VERSION,
            'measure_id' => self::MEASURE_ID,
            'formula_version' => self::FORMULA_VERSION,
            'status' => 'no_signal',
            'reason' => $reason,
            'generated_at' => $now->toIso8601String(),
            'active_entry_count' => 0,
            'relation_window_days' => self::RELATION_WINDOW_DAYS,
            'metrics' => [
                'temporal_provenance_coverage' => $this->countPayload(0, 0),
                'truth_density_v2' => $this->countPayload(0, 0),
                'supersession_maintained' => $this->countPayload(0, 0),
            ],
            'freeze' => [
                'measure_id' => self::MEASURE_ID,
                'formula_version' => self::FORMULA_VERSION,
                'denominator_min' => self::DENOMINATOR_MIN_ACTIVE,
                'thresholds' => (array) $freeze['thresholds'],
                'author_engine_id' => (string) $freeze['author_engine_id'],
                'judge_engine_id' => (string) $freeze['judge_engine_id'],
                'judge_author_distinct' => $freeze['author_engine_id'] !== $freeze['judge_engine_id'],
            ],
            'sources' => [
                'entries' => 'atlas_memory_entries active rows',
                'relations' => 'atlas_memory_entry_relations rows in frozen relation window',
                'read_only' => true,
            ],
        ];
    }

    /**
     * @param  list<string>  $activeIds
     * @return Collection<int,AtlasMemoryEntryRelation>
     */
    private function relationRows(array $activeIds, CarbonImmutable $since): Collection
    {
        if ($activeIds === [] || ! DatabaseTableAvailability::has('atlas_memory_entry_relations')) {
            return collect();
        }

        return AtlasMemoryEntryRelation::query()
            ->where('created_at', '>=', $since)
            ->where(function ($query) use ($activeIds): void {
                $query->whereIn('source_memory_entry_id', $activeIds)
                    ->orWhereIn('target_memory_entry_id', $activeIds);
            })
            ->orderBy('created_at')
            ->get();
    }

    private function hasNonDefaultTemporalProvenance(AtlasMemoryEntry $entry): bool
    {
        $hasTemporalValue = $entry->observed_at !== null
            || $entry->verified_at !== null
            || $entry->stale_after !== null
            || $entry->valid_from !== null
            || $entry->valid_until !== null
            || trim((string) ($entry->source_hash ?? '')) !== ''
            || trim((string) ($entry->authority_level ?? '')) !== '';

        if (! $hasTemporalValue) {
            return false;
        }

        $metadata = (array) ($entry->metadata ?? []);
        $provenance = strtolower(trim((string) (
            data_get($metadata, 'temporal_truth.provenance')
            ?? data_get($metadata, 'temporal.provenance')
            ?? data_get($metadata, 'temporal_provenance')
            ?? ''
        )));

        return in_array($provenance, [
            'caller_supplied',
            'evidence_derived',
            'operator_supplied',
            'observed_evidence',
        ], true);
    }

    private function relationQualifiesForTruthDensity(AtlasMemoryEntryRelation $relation): bool
    {
        $metadata = (array) ($relation->metadata ?? []);
        $confidence = (float) ($relation->confidence ?? 0.0);
        $quality = (float) (
            data_get($metadata, 'quality')
            ?? data_get($metadata, 'verdict_quality')
            ?? data_get($metadata, 'judge.quality')
            ?? 0.0
        );
        $r8Lift = (float) (
            data_get($metadata, 'r8.recall_lift')
            ?? data_get($metadata, 'r8_recall_lift')
            ?? 0.0
        );

        return ($confidence >= self::RELATION_CONFIDENCE_FLOOR && $quality >= self::RELATION_QUALITY_FLOOR)
            || $r8Lift >= self::R8_RECALL_LIFT_MIN;
    }

    /** @param Collection<int,AtlasMemoryEntryRelation> $relations @param list<string> $activeIds */
    private function supersessionMaintained(Collection $relations, array $activeIds): array
    {
        $active = array_fill_keys($activeIds, true);
        $supersedes = $relations
            ->filter(fn (AtlasMemoryEntryRelation $relation): bool => (string) $relation->relation_type === 'supersedes')
            ->values();
        $maintained = $supersedes
            ->filter(fn (AtlasMemoryEntryRelation $relation): bool => (string) $relation->getAttribute('judgment_status') === 'judged'
                && isset($active[(string) $relation->target_memory_entry_id]))
            ->count();

        return $this->countPayload($maintained, $supersedes->count());
    }

    /**
     * MAXH-10 — read-only cadence + regression checks for the temporal
     * truth pipeline. Emits AT LEAST 3 checks per §1557 so
     * `atlas:memory:temporal-quality --check --json | jq '.checks | length >= 3'`
     * is satisfied by construction. Fail-open: each check reports its
     * own status; a corrupt/unreachable input NEVER masks the others.
     *
     * @return array<string,mixed>
     */
    public function checks(?CarbonImmutable $now = null): array
    {
        $now = $now ?? CarbonImmutable::now('UTC');
        $report = $this->report();

        return [
            'schema_version' => self::SCHEMA_VERSION.'#checks',
            'generated_at' => $now->toIso8601String(),
            'measure_id' => self::MEASURE_ID,
            'formula_version' => self::FORMULA_VERSION,
            'checks' => [
                $this->consolidationLedgerCadenceCheck($now),
                $this->supersessionMaintainedCheck($report),
                $this->provenanceCoverageCheck($report),
                $this->truthDensityCheck($report),
            ],
        ];
    }

    /**
     * Check 1 — consolidation-scan ledger cadence. Reads the newest
     * `consolidation-proposals-YYYY-MM-DD.ndjson` file under the ledger
     * root (MAXH-03 producer) and alerts if the freshest entry is older
     * than the configured dead-cadence threshold.
     *
     * @return array<string,mixed>
     */
    private function consolidationLedgerCadenceCheck(CarbonImmutable $now): array
    {
        $id = 'maxh_10.consolidation_ledger_cadence';
        $root = (string) config('atlas.memory_consolidation.ledger_root');
        $threshold = (int) config('atlas.memory_consolidation.watchdog.max_days_between_passes', 7);
        $threshold = max(1, $threshold);

        if ($root === '' || ! is_dir($root)) {
            return $this->checkPayload($id, 'skipped', ['reason' => 'ledger_root_missing', 'threshold_days' => $threshold]);
        }

        $files = glob($root.'/consolidation-proposals-*.ndjson') ?: [];
        if ($files === []) {
            return $this->checkPayload($id, 'alert', [
                'reason' => 'ledger_empty',
                'threshold_days' => $threshold,
                'ledger_root' => $root,
            ]);
        }

        $mtimes = array_map(static fn (string $f): int => (int) @filemtime($f), $files);
        $latestMtime = max($mtimes);
        if ($latestMtime <= 0) {
            return $this->checkPayload($id, 'error', ['reason' => 'ledger_mtime_unreadable', 'threshold_days' => $threshold]);
        }

        $ageSeconds = max(0, $now->getTimestamp() - $latestMtime);
        $ageDays = $ageSeconds / 86400.0;

        return $this->checkPayload(
            $id,
            $ageDays > $threshold ? 'alert' : 'ok',
            [
                'age_days' => round($ageDays, 3),
                'threshold_days' => $threshold,
                'latest_mtime' => $latestMtime,
                'ledger_files' => count($files),
            ],
        );
    }

    /**
     * Check 2 — supersession maintained cannot regress to zero when a
     * signal exists. If den ≥ 1 and num == 0, the supersession pipeline
     * is emitting relations that do not survive — that is the exact
     * failure §1557 pins ('superseded ainda supera superseder').
     *
     * @param  array<string,mixed>  $report
     * @return array<string,mixed>
     */
    private function supersessionMaintainedCheck(array $report): array
    {
        $id = 'maxh_10.supersession_maintained';
        $metric = (array) data_get($report, 'metrics.supersession_maintained', []);
        $num = (int) ($metric['num'] ?? 0);
        $den = (int) ($metric['den'] ?? 0);
        if ($den === 0) {
            return $this->checkPayload($id, 'no_signal', ['num' => $num, 'den' => $den]);
        }

        return $this->checkPayload(
            $id,
            $num === 0 ? 'alert' : ($num < $den ? 'warning' : 'ok'),
            ['num' => $num, 'den' => $den, 'ratio' => $den > 0 ? round($num / $den, 4) : null],
        );
    }

    /**
     * Check 3 — non-default temporal_provenance_coverage. §1557 pins
     * this as the source of truth for regression: because MAXH-01
     * counts only non-default provenance, "abaixo do baseline" has
     * signal again.
     *
     * @param  array<string,mixed>  $report
     * @return array<string,mixed>
     */
    private function provenanceCoverageCheck(array $report): array
    {
        $id = 'maxh_10.temporal_provenance_coverage';
        $metric = (array) data_get($report, 'metrics.temporal_provenance_coverage', []);
        $num = (int) ($metric['num'] ?? 0);
        $den = (int) ($metric['den'] ?? 0);
        if ($den < self::DENOMINATOR_MIN_ACTIVE) {
            return $this->checkPayload($id, 'no_signal', ['num' => $num, 'den' => $den, 'denominator_min' => self::DENOMINATOR_MIN_ACTIVE]);
        }

        $floor = (float) config('atlas.memory_consolidation.watchdog.provenance_coverage_floor', 0.1);
        $ratio = $den > 0 ? $num / $den : 0.0;

        return $this->checkPayload(
            $id,
            $ratio < $floor ? 'alert' : 'ok',
            ['num' => $num, 'den' => $den, 'ratio' => round($ratio, 4), 'floor' => $floor],
        );
    }

    /**
     * Check 4 — truth_density_v2 coverage. Any density ≥ 1 relation
     * that fails to qualify is a warning; zero-of-den ≥ 1 is an alert.
     *
     * @param  array<string,mixed>  $report
     * @return array<string,mixed>
     */
    private function truthDensityCheck(array $report): array
    {
        $id = 'maxh_10.truth_density_v2';
        $metric = (array) data_get($report, 'metrics.truth_density_v2', []);
        $num = (int) ($metric['num'] ?? 0);
        $den = (int) ($metric['den'] ?? 0);
        if ($den === 0) {
            return $this->checkPayload($id, 'no_signal', ['num' => $num, 'den' => $den]);
        }

        return $this->checkPayload(
            $id,
            $num === 0 ? 'alert' : ($num < $den ? 'warning' : 'ok'),
            ['num' => $num, 'den' => $den, 'ratio' => round($num / $den, 4)],
        );
    }

    /**
     * @param  array<string,mixed>  $evidence
     * @return array<string,mixed>
     */
    private function checkPayload(string $id, string $status, array $evidence): array
    {
        return [
            'id' => $id,
            'status' => $status,
            'evidence' => $evidence,
        ];
    }

    /** @return array{num:int,den:int,ratio:?float} */
    private function countPayload(int $num, int $den): array
    {
        return [
            'num' => $num,
            'den' => $den,
            'ratio' => $den > 0 ? round($num / $den, 4) : null,
        ];
    }

    /**
     * @param  array<string,array{num:int,den:int,ratio:?float}>  $metrics
     * @return array{0:string,1:string}
     */
    private function status(int $activeCount, int $relationCount, array $metrics): array
    {
        if ($activeCount < self::DENOMINATOR_MIN_ACTIVE) {
            return ['no_signal', 'active_below_minimum'];
        }
        if ($relationCount < 1) {
            return ['no_signal', 'relation_window_empty'];
        }

        foreach ($metrics as $metric) {
            if (($metric['den'] ?? 0) > 0 && ($metric['num'] ?? 0) < ($metric['den'] ?? 0)) {
                return ['attention', 'coverage_below_full'];
            }
        }

        return ['ok', 'all_observed_relations_qualified'];
    }
}
