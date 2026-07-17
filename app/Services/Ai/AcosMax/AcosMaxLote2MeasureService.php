<?php

declare(strict_types=1);

namespace App\Services\Ai\AcosMax;

use App\Services\Ai\Support\AiValueNormalizer;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

final class AcosMaxLote2MeasureService
{
    public const MAXL06_MEASURE_ID = 'atlas.evidence.delta_attribution.v1';

    public const MULTN1704_MEASURE_ID = 'atlas.originator.predicted_impact_calibration.v1';

    public const MULTX01_MEASURE_ID = 'acos.flywheel.loops.v1';

    public const MULTX06_MEASURE_ID = 'acos.learning_latency.v1';

    public const MULTX09_MEASURE_ID = 'acos.windows_orchestrator.v1';

    public const MULTJ01_MEASURE_ID = 'atlas.ai.lesson_half_life.v2';

    public const MULTJ02_MEASURE_ID = 'atlas.ai.lesson_semantic_dedup.v1';

    public const MULTJ03_MEASURE_ID = 'atlas.ai.counterfactual_lift.v2';

    public const MULTJ04_MEASURE_ID = 'atlas.ai.procedural_skill_promoter.v1';

    public const MULTJ06_MEASURE_ID = 'atlas.ai.abstraction_ladder.v1';

    public const TETO02_MEASURE_ID = 'mission_e2e.v1';

    public const REPORT_SCHEMA = 'atlas.acos.lote2.measure_report.v1';
    public const FIELD_NEVER_DELIVERED = 'never_delivered';
    public const FIELD_NEVER_CITED = 'never_cited';
    public const FIELD_ROWS = 'rows';
    public const FIELD_FREEZE = 'freeze';
    public const FIELD_DELIVERED = 'delivered';
    public const FIELD_CITED = 'cited';
    public const FIELD_BLOCKED_BY = 'blocked_by';
    public const FIELD_SCORE = 'score';

    public const STATUS_OK = 'ok';

    public const STATUS_PENDING_WINDOW = 'pending_window';

    public const STATUS_INSUFFICIENT_SIGNAL = 'insufficient_signal';

    public const STATUS_MEASURED = 'measured';

    public const REASON_MISSING_LINEAGE_LEDGER = 'missing_lineage_ledger_dependencies';

    public const REASON_PENDING_REAL_ORIGINATOR_OUTCOME = 'pending_real_originator_outcome_window';

    public const REASON_LOOP_SOURCE_TABLES_MISSING = 'loop_source_tables_missing';

    public const REASON_LEARNING_LATENCY_SOURCE_TABLES_MISSING = 'learning_latency_source_tables_missing';

    public const REASON_NO_MEASURED_LESSON_USAGE_BUCKETS = 'no_measured_lesson_usage_buckets';

    public const REASON_CALIBRATION_FREEZE_ONLY = 'calibration_freeze_only_before_enforce';

    public const REASON_PAIRED_FEEDBACK_TABLE_MISSING = 'paired_feedback_table_missing';

    public const REASON_MISSION_DELIVERY_TABLE_MISSING = 'mission_delivery_table_missing';

    public const KIND_MEASURE_FREEZE = 'measure_freeze';

    public const MODE_OBSERVE = 'observe';

    public const BASIS_UNAVAILABLE = 'unavailable';

    public const MEMORY_TYPE_UNKNOWN = 'unknown';

    public const FIELD_COMPLETE = 'complete';

    public const FIELD_PARTIAL = 'partial';

    public const FIELD_INCOMPLETE = 'incomplete';

    public const FIELD_PROVEN_REAL = 'proven_real';

    public const FIELD_FIXTURE = 'fixture';

    public const FIELD_IS_FIXTURE = 'is_fixture';

    public const MISSION_STATUS_COMPLETED = 'completed';

    public const MISSION_STATUS_DELIVERED = 'delivered';

    public const MISSION_STATUS_SUCCEEDED = 'succeeded';

    public const MISSION_STATUS_SUCCESS = 'success';

    public const FIELD_MEASURE_ID = 'measure_id';

    public const FIELD_FORMULA_VERSION = 'formula_version';

    public const FIELD_DENOMINATOR_MIN = 'denominator_min';

    public const FIELD_KIND = 'kind';

    public const FIELD_FORMULA = 'formula';

    public const FIELD_SLICE = 'slice';

    public const FIELD_STATUS = 'status';

    public const FIELD_REASON = 'reason';

    public const FIELD_MEMORY_TYPE = 'memory_type';

    public const FIELD_N_PAIRS = 'n_pairs';

    public const FIELD_GENERATED_AT = 'generated_at';

    public const FIELD_SCHEMA_VERSION = 'schema_version';
    public const FIELD_READ_ONLY = 'read_only';
    public const FIELD_LESSON_CLASS = 'lesson_class';
    public const FIELD_CITATION_LATENCIES = 'citation_latencies';
    public const FIELD_RECORD_USAGE_FOR_PEEK = 'record_usage_for_peek';
    public const FIELD_PROVIDER_CALLS_MADE = 'provider_calls_made';
    public const FIELD_MEMORY_WRITTEN = 'memory_written';
    public const FIELD_DELIVERY_P50 = 'delivery_p50';
    public const FIELD_DELIVERY_P95 = 'delivery_p95';
    public const FIELD_CITATION_P50 = 'citation_p50';
    public const FIELD_CITATION_P95 = 'citation_p95';
    public const FIELD_DELIVERY_LATENCIES = 'delivery_latencies';
    public const FIELD_MEMORY_TYPES = 'memory_types';

    /** @return array<string,mixed> */
    public static function freezePayload(string $slice): array
    {
        $slice = AiValueNormalizer::upperTrimmedString($slice);
        $payloads = self::freezePayloads();

        return $payloads[$slice] ?? [
            self::FIELD_KIND => self::KIND_MEASURE_FREEZE,
            self::FIELD_MEASURE_ID => AiValueNormalizer::lowerTrimmedString($slice).'.unknown',
            self::FIELD_FORMULA_VERSION => AiValueNormalizer::lowerTrimmedString($slice).'.unknown',
            self::FIELD_FORMULA => 'Unknown LOTE 2 measure freeze.',
            'thresholds' => [],
            self::FIELD_DENOMINATOR_MIN => 1,
            'ttl_days' => 30,
            'author_engine_id' => 'cursor-acos-max-lote2',
            'judge_engine_id' => 'codex-independent-lote2-judge',
        ];
    }

    /** @return array<string,mixed> */
    public function maxl06DeltaAttribution(): array
    {
        return $this->emptyReport('MAXL-06', self::STATUS_PENDING_WINDOW, self::REASON_MISSING_LINEAGE_LEDGER, [
            self::FIELD_MEASURE_ID => self::MAXL06_MEASURE_ID,
            'basis' => self::BASIS_UNAVAILABLE,
            'allowed_basis' => ['lineage_ledger', 'git_log'],
            'counterfactual_basis' => 'none',
            'correlation_label_required' => 'correlational_attribution',
            'attributed_delta' => [],
            'dependencies' => ['ASI-11', 'MAXL-04'],
        ]);
    }

    /** @return array<string,mixed> */
    public function multn1704PredictedImpact(): array
    {
        $originations = $this->countTableIfPresent('atlas_loop_origination_outcomes');

        return $this->emptyReport('MULTN17-04', self::STATUS_INSUFFICIENT_SIGNAL, self::REASON_PENDING_REAL_ORIGINATOR_OUTCOME, [
            self::FIELD_MEASURE_ID => self::MULTN1704_MEASURE_ID,
            self::FIELD_DENOMINATOR_MIN => 20,
            'denominator' => [
                'originations' => $originations,
                'resolved_outcomes' => 0,
            ],
            'bands' => [],
            'unresolved' => $originations,
        ]);
    }

    /** @return array<string,mixed> */
    public function multx01FlywheelLoops(): array
    {
        $requiredTables = ['ai_run_outcomes', 'ai_rag_feedback_events', 'ai_learning_candidates'];
        $missingTables = array_values(array_filter($requiredTables, static fn (string $table): bool => ! Schema::hasTable($table)));
        if ($missingTables !== []) {
            return $this->emptyReport('MULTX-01', self::STATUS_INSUFFICIENT_SIGNAL, self::REASON_LOOP_SOURCE_TABLES_MISSING, [
                self::FIELD_MEASURE_ID => self::MULTX01_MEASURE_ID,
                self::FIELD_DENOMINATOR_MIN => 1,
                'loops_complete' => 0,
                'loops' => [],
                'loops_partial' => [],
                'n_total' => 0,
                'fixture_rejected' => 0,
                'missing_tables' => $missingTables,
                'time_per_loop' => [
                    'p50_seconds' => null,
                    'p95_seconds' => null,
                ],
                'marco_esp_v1' => [
                    'satisfied' => false,
                    self::FIELD_BLOCKED_BY => [self::REASON_LOOP_SOURCE_TABLES_MISSING],
                ],
                'valid_loop_definition' => $this->multx01ValidLoopDefinition(),
            ]);
        }

        $deliveriesByOutcome = [];
        $recallsByCandidate = [];
        foreach (DB::table('ai_rag_feedback_events')->orderBy('created_at')->get() as $row) {
            $outcomeId = AiValueNormalizer::trimmedStringOrNull($row->run_outcome_id ?? null) ?? '';
            if ($outcomeId !== '') {
                $deliveriesByOutcome[$outcomeId][] = $row;
            }

            $candidateId = AiValueNormalizer::trimmedStringOrNull($row->memory_candidate_id ?? null) ?? '';
            if ($candidateId !== '') {
                $recallsByCandidate[$candidateId][] = $row;
            }
        }

        $candidatesByOutcome = [];
        foreach (DB::table('ai_learning_candidates')->orderBy('created_at')->get() as $candidate) {
            $outcomeId = AiValueNormalizer::trimmedStringOrNull($candidate->run_outcome_id ?? null) ?? '';
            if ($outcomeId !== '') {
                $candidatesByOutcome[$outcomeId][] = $candidate;
            }
        }

        $loops = [];
        $partial = [];
        $durations = [];
        $fixtureRejected = 0;

        foreach (DB::table('ai_run_outcomes')->orderBy('created_at')->get() as $outcome) {
            $assembled = $this->assembleMultx01Loop(
                $outcome,
                $deliveriesByOutcome[AiValueNormalizer::trimmedScalarStringOrNull($outcome->id ?? null) ?? ''] ?? [],
                $candidatesByOutcome[AiValueNormalizer::trimmedScalarStringOrNull($outcome->id ?? null) ?? ''] ?? [],
                $recallsByCandidate,
            );

            if ($assembled[self::FIELD_COMPLETE] === true) {
                $loops[] = $assembled['loop'];
                $durations[] = (int) (AiValueNormalizer::finiteFloatOrNull(data_get($assembled, 'loop.time_to_recall_seconds')) ?? 0);
            } else {
                $partial[] = $assembled[self::FIELD_PARTIAL];
                if (in_array('fixture_chain', AiValueNormalizer::arrayOrEmpty($assembled[self::FIELD_PARTIAL][self::FIELD_BLOCKED_BY] ?? null), true)) {
                    $fixtureRejected++;
                }
            }
        }

        $loopsComplete = count($loops);
        $marcoSatisfied = $loopsComplete >= 1;
        $blockedByTop = $this->blockedByTopN($partial, 10);

        return [
            self::FIELD_SCHEMA_VERSION => self::REPORT_SCHEMA,
            self::FIELD_SLICE => 'MULTX-01',
            self::FIELD_STATUS => $marcoSatisfied ? self::STATUS_OK : self::STATUS_INSUFFICIENT_SIGNAL,
            self::FIELD_REASON => $marcoSatisfied ? null : 'no_complete_proven_real_loop_window',
            self::FIELD_FORMULA_VERSION => AiValueNormalizer::trimmedStringOrNull(data_get(self::freezePayload('MULTX-01'), self::FIELD_FORMULA_VERSION)) ?? '',
            self::FIELD_GENERATED_AT => now()->toIso8601String(),
            self::FIELD_FREEZE => self::freezePayload('MULTX-01'),
            self::FIELD_MEASURE_ID => self::MULTX01_MEASURE_ID,
            self::FIELD_DENOMINATOR_MIN => 1,
            'loops_complete' => $loopsComplete,
            'loops' => $loops,
            'loops_partial' => $partial,
            'blocked_by_top' => $blockedByTop,
            'n_total' => $loopsComplete + count($partial),
            'fixture_rejected' => $fixtureRejected,
            'time_per_loop' => [
                'p50_seconds' => $this->percentileInt($durations, 0.50),
                'p95_seconds' => $this->percentileInt($durations, 0.95),
            ],
            'marco_esp_v1' => [
                'satisfied' => $marcoSatisfied,
                self::FIELD_BLOCKED_BY => $marcoSatisfied ? [] : ['no_complete_proven_real_loop_window'],
                'requires_loops_complete_min' => 1,
                'requires_proven_real' => true,
                'requires_chained_ids' => true,
                'requires_zero_fixture' => true,
            ],
            'valid_loop_definition' => $this->multx01ValidLoopDefinition(),
            'claim_policy' => [
                self::FIELD_READ_ONLY => true,
                self::FIELD_PROVIDER_CALLS_MADE => false,
                self::FIELD_MEMORY_WRITTEN => false,
                'synthetic_fixture_claim_allowed' => false,
                'completion_claim_allowed_without_proven_real' => false,
            ],
        ];
    }

    /**
     * @param  list<array<string,mixed>>  $partial
     * @return list<array{reason:string,count:int}>
     */
    private function blockedByTopN(array $partial, int $limit): array
    {
        $counts = [];
        foreach ($partial as $row) {
            foreach (AiValueNormalizer::arrayOrEmpty($row[self::FIELD_BLOCKED_BY] ?? null) as $reason) {
                $key = AiValueNormalizer::trimmedStringOrNull($reason) ?? '';
                if ($key === '') {
                    continue;
                }
                $counts[$key] = ($counts[$key] ?? 0) + 1;
            }
        }
        arsort($counts);
        $top = [];
        foreach (array_slice($counts, 0, max(1, $limit), true) as $reason => $count) {
            $top[] = [self::FIELD_REASON => AiValueNormalizer::trimmedScalarStringOrNull($reason) ?? '', 'count' => (int) $count];
        }

        return $top;
    }

    /** @return array<string,mixed> */
    private function multx01ValidLoopDefinition(): array
    {
        return [
            'requires_proven_real_outcome' => true,
            'requires_decision_receipt_id' => true,
            'requires_delivered_context_receipt' => true,
            'requires_learning_candidate' => true,
            'requires_subsequent_measured_recall' => true,
            'requires_zero_fixture' => true,
            'legacy_unjoined_rows' => 'legacy_unjoined',
        ];
    }

    /**
     * @param  list<object>  $deliveries
     * @param  list<object>  $candidates
     * @param  array<string,list<object>>  $recallsByCandidate
     * @return array{complete:bool,loop?:array<string,mixed>,partial?:array<string,mixed>}
     */
    private function assembleMultx01Loop(object $outcome, array $deliveries, array $candidates, array $recallsByCandidate): array
    {
        $outcomePayload = $this->decodeJsonObject($outcome->payload ?? null);
        $delivery = $this->firstContextDelivery($deliveries);
        $candidate = $candidates[0] ?? null;
        $recall = $candidate === null ? null : $this->firstSubsequentRecall(
            $recallsByCandidate[AiValueNormalizer::trimmedScalarStringOrNull($candidate->id ?? null) ?? ''] ?? [],
            AiValueNormalizer::trimmedString($candidate->created_at ?? $outcome->created_at ?? ''),
        );

        $decisionId = $this->firstNonEmpty([
            data_get($outcomePayload, 'decision_id'),
            data_get($outcomePayload, 'decision_receipt_id'),
            data_get($outcomePayload, 'receipt_id'),
        ]);
        $provenReal = data_get($outcomePayload, self::FIELD_PROVEN_REAL) === true;
        $fixture = $this->isFixtureMarked($outcome, $outcomePayload)
            || ($delivery !== null && $this->isFixtureMarked($delivery, $this->decodeJsonObject($delivery->payload ?? null)))
            || ($candidate !== null && $this->isFixtureMarked($candidate, $this->decodeJsonObject($candidate->payload ?? null)))
            || ($recall !== null && $this->isFixtureMarked($recall, $this->decodeJsonObject($recall->payload ?? null)));

        $blockedBy = [];
        if (! $provenReal) {
            $blockedBy[] = 'outcome_not_proven_real';
        }
        if ($decisionId === '') {
            $blockedBy[] = 'decision_receipt_missing';
        }
        if ($delivery === null || (AiValueNormalizer::trimmedStringOrNull($delivery->retrieval_receipt_id ?? null) ?? '') === '') {
            $blockedBy[] = 'delivered_context_missing';
        }
        if ($candidate === null) {
            $blockedBy[] = 'learning_candidate_missing';
        }
        if ($recall === null) {
            $blockedBy[] = 'subsequent_measured_recall_missing';
        }
        if ($fixture) {
            $blockedBy[] = 'fixture_chain';
        }

        $taskId = $this->firstNonEmpty([
            data_get($outcomePayload, 'task_id'),
            $outcome->run_id ?? null,
        ]);

        $chain = [
            'task_id' => $taskId,
            'outcome_id' => AiValueNormalizer::trimmedScalarStringOrNull($outcome->id ?? null) ?? '',
            'decision_id' => $decisionId,
            'retrieval_receipt_id' => $delivery === null ? null : (AiValueNormalizer::trimmedScalarStringOrNull($delivery->retrieval_receipt_id ?? null) ?? ''),
            'learning_candidate_id' => $candidate === null ? null : (AiValueNormalizer::trimmedScalarStringOrNull($candidate->id ?? null) ?? ''),
            'subsequent_recall_feedback_id' => $recall === null ? null : (AiValueNormalizer::trimmedScalarStringOrNull($recall->id ?? null) ?? ''),
        ];

        if ($blockedBy !== []) {
            return [
                self::FIELD_COMPLETE => false,
                self::FIELD_PARTIAL => [
                    'loop_id' => hash('sha256', implode('|', array_map(static fn ($value): string => AiValueNormalizer::trimmedScalarStringOrNull($value) ?? '', $chain))),
                    'chain' => $chain,
                    self::FIELD_PROVEN_REAL => $provenReal,
                    'fixture_free' => ! $fixture,
                    self::FIELD_BLOCKED_BY => array_values(array_unique($blockedBy)),
                ],
            ];
        }

        return [
            self::FIELD_COMPLETE => true,
            'loop' => [
                'loop_id' => hash('sha256', implode('|', array_map(static fn ($value): string => AiValueNormalizer::trimmedScalarStringOrNull($value) ?? '', $chain))),
                'chain' => $chain,
                self::FIELD_PROVEN_REAL => true,
                'fixture_free' => true,
                'time_to_recall_seconds' => $this->secondsBetween(
                    AiValueNormalizer::trimmedString($outcome->created_at ?? ''),
                    AiValueNormalizer::trimmedString($recall->created_at ?? ''),
                ),
            ],
        ];
    }

    /**
     * @param  list<object>  $deliveries
     */
    private function firstContextDelivery(array $deliveries): ?object
    {
        foreach ($deliveries as $delivery) {
            if ((AiValueNormalizer::trimmedStringOrNull($delivery->retrieval_receipt_id ?? null) ?? '') !== '') {
                return $delivery;
            }
        }

        return null;
    }

    /**
     * @param  list<object>  $recalls
     */
    private function firstSubsequentRecall(array $recalls, string $candidateCreatedAt): ?object
    {
        foreach ($recalls as $recall) {
            if ($candidateCreatedAt === '' || strtotime(AiValueNormalizer::trimmedString($recall->created_at ?? '')) >= strtotime($candidateCreatedAt)) {
                return $recall;
            }
        }

        return null;
    }

    /**
     * @param  array<string,mixed>  $payload
     */
    private function isFixtureMarked(object $row, array $payload): bool
    {
        if (data_get($payload, self::FIELD_FIXTURE) === true || data_get($payload, self::FIELD_IS_FIXTURE) === true) {
            return true;
        }

        return str_contains(AiValueNormalizer::lowerTrimmedString($row->source ?? ''), 'fixture');
    }

    /**
     * @param  list<mixed>  $values
     */
    private function firstNonEmpty(array $values): string
    {
        foreach ($values as $value) {
            $string = AiValueNormalizer::trimmedStringOrNull($value) ?? '';
            if ($string !== '') {
                return $string;
            }
        }

        return '';
    }

    private function secondsBetween(string $start, string $end): int
    {
        $startTs = strtotime($start);
        $endTs = strtotime($end);
        if ($startTs === false || $endTs === false) {
            return 0;
        }

        return max(0, $endTs - $startTs);
    }

    /**
     * @param  list<int>  $values
     */
    private function percentileInt(array $values, float $percentile): ?int
    {
        if ($values === []) {
            return null;
        }

        sort($values);
        $index = (int) ceil(count($values) * $percentile) - 1;
        $index = max(0, min(count($values) - 1, $index));

        return $values[$index];
    }

    /** @return array<string,mixed> */
    public function multx06LearningLatency(): array
    {
        $requiredTables = ['ai_run_outcomes', 'ai_rag_feedback_events', 'ai_learning_candidates'];
        $missingTables = array_values(array_filter($requiredTables, static fn (string $table): bool => ! Schema::hasTable($table)));
        $denominatorMin = (int) data_get(self::freezePayload('MULTX-06'), 'thresholds.denominator_min_promoted_lessons', 8);

        if ($missingTables !== []) {
            return $this->emptyReport('MULTX-06', self::STATUS_INSUFFICIENT_SIGNAL, self::REASON_LEARNING_LATENCY_SOURCE_TABLES_MISSING, [
                self::FIELD_MEASURE_ID => self::MULTX06_MEASURE_ID,
                self::FIELD_DENOMINATOR_MIN => $denominatorMin,
                'n' => 0,
                'by_lesson_class' => [],
                self::FIELD_NEVER_DELIVERED => 0,
                self::FIELD_NEVER_CITED => 0,
                'latency_seconds' => [
                    self::FIELD_DELIVERY_P50 => null,
                    self::FIELD_DELIVERY_P95 => null,
                    self::FIELD_CITATION_P50 => null,
                    self::FIELD_CITATION_P95 => null,
                ],
                'missing_tables' => $missingTables,
            ]);
        }

        $outcomes = [];
        foreach (DB::table('ai_run_outcomes')->get() as $outcome) {
            $outcomes[AiValueNormalizer::trimmedScalarStringOrNull($outcome->id ?? null) ?? ''] = $outcome;
        }

        $deliveriesByOutcome = [];
        $citationsByCandidate = [];
        foreach (DB::table('ai_rag_feedback_events')->orderBy('created_at')->get() as $row) {
            $outcomeId = AiValueNormalizer::trimmedStringOrNull($row->run_outcome_id ?? null) ?? '';
            if ($outcomeId !== '') {
                $deliveriesByOutcome[$outcomeId][] = $row;
            }
            $candidateId = AiValueNormalizer::trimmedStringOrNull($row->memory_candidate_id ?? null) ?? '';
            if ($candidateId !== '') {
                $citationsByCandidate[$candidateId][] = $row;
            }
        }

        $rows = [];
        $deliveryLatencies = [];
        $citationLatencies = [];
        $neverDelivered = 0;
        $neverCited = 0;
        $byClass = [];

        foreach (DB::table('ai_learning_candidates')->orderBy('created_at')->get() as $candidate) {
            if (! $this->isPromotedLearningCandidate($candidate)) {
                continue;
            }

            $candidateId = AiValueNormalizer::trimmedScalarStringOrNull($candidate->id ?? null) ?? '';
            $lessonClass = AiValueNormalizer::trimmedStringOrNull($candidate->memory_type ?? null) ?? self::MEMORY_TYPE_UNKNOWN;
            $outcome = $outcomes[AiValueNormalizer::trimmedScalarStringOrNull($candidate->run_outcome_id ?? null) ?? ''] ?? null;
            if ($outcome === null) {
                continue;
            }

            $delivery = $this->firstContextDelivery($deliveriesByOutcome[AiValueNormalizer::trimmedScalarStringOrNull($outcome->id ?? null) ?? ''] ?? []);
            $citation = $this->firstSubsequentRecall($citationsByCandidate[$candidateId] ?? [], AiValueNormalizer::trimmedString($candidate->created_at ?? ''));
            $deliverySeconds = $delivery === null ? null : $this->secondsBetween(AiValueNormalizer::trimmedString($outcome->created_at ?? ''), AiValueNormalizer::trimmedString($delivery->created_at ?? ''));
            $citationSeconds = $citation === null ? null : $this->secondsBetween(AiValueNormalizer::trimmedString($outcome->created_at ?? ''), AiValueNormalizer::trimmedString($citation->created_at ?? ''));

            if ($deliverySeconds === null) {
                $neverDelivered++;
            } else {
                $deliveryLatencies[] = $deliverySeconds;
            }
            if ($citationSeconds === null) {
                $neverCited++;
            } else {
                $citationLatencies[] = $citationSeconds;
            }

            $byClass[$lessonClass] ??= [
                self::FIELD_LESSON_CLASS => $lessonClass,
                'n' => 0,
                self::FIELD_DELIVERED => 0,
                self::FIELD_CITED => 0,
                self::FIELD_NEVER_DELIVERED => 0,
                self::FIELD_NEVER_CITED => 0,
                self::FIELD_DELIVERY_LATENCIES => [],
                self::FIELD_CITATION_LATENCIES => [],
            ];
            $byClass[$lessonClass]['n']++;
            if ($deliverySeconds === null) {
                $byClass[$lessonClass][self::FIELD_NEVER_DELIVERED]++;
            } else {
                $byClass[$lessonClass][self::FIELD_DELIVERED]++;
                $byClass[$lessonClass][self::FIELD_DELIVERY_LATENCIES][] = $deliverySeconds;
            }
            if ($citationSeconds === null) {
                $byClass[$lessonClass][self::FIELD_NEVER_CITED]++;
            } else {
                $byClass[$lessonClass][self::FIELD_CITED]++;
                $byClass[$lessonClass][self::FIELD_CITATION_LATENCIES][] = $citationSeconds;
            }

            $rows[] = [
                'candidate_id' => $candidateId,
                'outcome_id' => AiValueNormalizer::trimmedScalarStringOrNull($outcome->id ?? null) ?? '',
                self::FIELD_LESSON_CLASS => $lessonClass,
                self::FIELD_DELIVERED => $deliverySeconds !== null,
                self::FIELD_CITED => $citationSeconds !== null,
                'delivery_latency_seconds' => $deliverySeconds,
                'citation_latency_seconds' => $citationSeconds,
            ];
        }

        $n = count($rows);
        $status = $n >= $denominatorMin ? self::STATUS_OK : self::STATUS_INSUFFICIENT_SIGNAL;

        return [
            self::FIELD_SCHEMA_VERSION => self::REPORT_SCHEMA,
            self::FIELD_SLICE => 'MULTX-06',
            self::FIELD_STATUS => $status,
            self::FIELD_REASON => $status === self::STATUS_OK ? null : 'promoted_lesson_denominator_below_min',
            self::FIELD_FORMULA_VERSION => AiValueNormalizer::trimmedStringOrNull(data_get(self::freezePayload('MULTX-06'), self::FIELD_FORMULA_VERSION)) ?? '',
            self::FIELD_GENERATED_AT => now()->toIso8601String(),
            self::FIELD_FREEZE => self::freezePayload('MULTX-06'),
            self::FIELD_MEASURE_ID => self::MULTX06_MEASURE_ID,
            self::FIELD_DENOMINATOR_MIN => $denominatorMin,
            'n' => $n,
            'by_lesson_class' => $this->learningLatencyByClass($byClass),
            self::FIELD_NEVER_DELIVERED => $neverDelivered,
            self::FIELD_NEVER_CITED => $neverCited,
            'latency_seconds' => [
                self::FIELD_DELIVERY_P50 => $this->percentileInt($deliveryLatencies, 0.50),
                self::FIELD_DELIVERY_P95 => $this->percentileInt($deliveryLatencies, 0.95),
                self::FIELD_CITATION_P50 => $this->percentileInt($citationLatencies, 0.50),
                self::FIELD_CITATION_P95 => $this->percentileInt($citationLatencies, 0.95),
            ],
            self::FIELD_ROWS => $rows,
            'claim_policy' => [
                self::FIELD_READ_ONLY => true,
                self::FIELD_PROVIDER_CALLS_MADE => false,
                self::FIELD_MEMORY_WRITTEN => false,
                'never_delivered_in_denominator' => true,
            ],
        ];
    }

    private function isPromotedLearningCandidate(object $candidate): bool
    {
        return (AiValueNormalizer::trimmedScalarStringOrNull($candidate->status ?? null) ?? '') === 'promoted'
            || (AiValueNormalizer::boolOrNull($candidate->promotion_allowed ?? null) ?? false) === true;
    }

    /**
     * @param  array<string,array<string,mixed>>  $byClass
     * @return list<array<string,mixed>>
     */
    private function learningLatencyByClass(array $byClass): array
    {
        ksort($byClass);

        return array_values(array_map(function (array $row): array {
            return [
                self::FIELD_LESSON_CLASS => AiValueNormalizer::trimmedScalarStringOrNull($row[self::FIELD_LESSON_CLASS] ?? null) ?? '',
                'n' => (int) (AiValueNormalizer::finiteFloatOrNull($row['n'] ?? null) ?? 0),
                self::FIELD_DELIVERED => (int) (AiValueNormalizer::finiteFloatOrNull($row[self::FIELD_DELIVERED] ?? null) ?? 0),
                self::FIELD_CITED => (int) (AiValueNormalizer::finiteFloatOrNull($row[self::FIELD_CITED] ?? null) ?? 0),
                self::FIELD_NEVER_DELIVERED => (int) (AiValueNormalizer::finiteFloatOrNull($row[self::FIELD_NEVER_DELIVERED] ?? null) ?? 0),
                self::FIELD_NEVER_CITED => (int) (AiValueNormalizer::finiteFloatOrNull($row[self::FIELD_NEVER_CITED] ?? null) ?? 0),
                'latency_seconds' => [
                    self::FIELD_DELIVERY_P50 => $this->percentileInt(AiValueNormalizer::arrayOrEmpty($row[self::FIELD_DELIVERY_LATENCIES] ?? null), 0.50),
                    self::FIELD_DELIVERY_P95 => $this->percentileInt(AiValueNormalizer::arrayOrEmpty($row[self::FIELD_DELIVERY_LATENCIES] ?? null), 0.95),
                    self::FIELD_CITATION_P50 => $this->percentileInt(AiValueNormalizer::arrayOrEmpty($row[self::FIELD_CITATION_LATENCIES] ?? null), 0.50),
                    self::FIELD_CITATION_P95 => $this->percentileInt(AiValueNormalizer::arrayOrEmpty($row[self::FIELD_CITATION_LATENCIES] ?? null), 0.95),
                ],
            ];
        }, $byClass));
    }

    /** @return array<string,mixed> */
    public function multj01LessonHalfLife(): array
    {
        return $this->emptyReport('MULTJ-01', self::STATUS_INSUFFICIENT_SIGNAL, self::REASON_NO_MEASURED_LESSON_USAGE_BUCKETS, [
            self::FIELD_MEASURE_ID => self::MULTJ01_MEASURE_ID,
            self::FIELD_DENOMINATOR_MIN => 8,
            'bucket_width_weeks' => 2,
            self::FIELD_MEMORY_TYPES => [],
            'buckets' => [],
        ]);
    }

    /** @return array<string,mixed> */
    public function multj02DedupCalibration(): array
    {
        return $this->emptyReport('MULTJ-02', self::STATUS_PENDING_WINDOW, self::REASON_CALIBRATION_FREEZE_ONLY, [
            self::FIELD_MEASURE_ID => self::MULTJ02_MEASURE_ID,
            'mode' => self::MODE_OBSERVE,
            'would_merge_count' => 0,
            'actual_merge_count' => 0,
            'threshold' => data_get(self::freezePayload('MULTJ-02'), 'thresholds.cosine_merge_threshold'),
            'reversible_receipt_required' => true,
        ]);
    }

    /** @return array<string,mixed> */
    public function multj03CounterfactualLift(): array
    {
        $denominatorMin = (int) data_get(self::freezePayload('MULTJ-03'), 'thresholds.denominator_min_pairs', 8);
        $sampleRate = AiValueNormalizer::finiteFloatOrNull(data_get(self::freezePayload('MULTJ-03'), 'thresholds.sample_rate', 0.05)) ?? 0.05;

        if (! Schema::hasTable('ai_rag_feedback_events')) {
            return $this->emptyReport('MULTJ-03', self::STATUS_INSUFFICIENT_SIGNAL, self::REASON_PAIRED_FEEDBACK_TABLE_MISSING, [
                self::FIELD_MEASURE_ID => self::MULTJ03_MEASURE_ID,
                self::FIELD_DENOMINATOR_MIN => $denominatorMin,
                'sample_rate' => $sampleRate,
                'rate' => $sampleRate,
                self::FIELD_N_PAIRS => 0,
                'paired_delta' => null,
                self::FIELD_MEMORY_TYPES => [],
                'peek_policy' => [
                    self::FIELD_RECORD_USAGE_FOR_PEEK => false,
                    'usage_rows_recorded' => 0,
                ],
                'invalid_pairs' => [
                    'peek_policy_violation' => 0,
                    self::FIELD_INCOMPLETE => 0,
                    'positive_lift_fabricated' => 0,
                ],
            ]);
        }

        $pairs = [];
        foreach (DB::table('ai_rag_feedback_events')->orderBy('created_at')->get() as $row) {
            $payload = $this->decodeJsonObject($row->payload ?? null);
            $meta = $this->counterfactualLiftMeta($payload);
            if ($meta === []) {
                continue;
            }

            $pairId = AiValueNormalizer::trimmedStringOrNull($meta['pair_id'] ?? null) ?? '';
            $arm = $this->counterfactualArm((AiValueNormalizer::trimmedStringOrNull($meta['arm'] ?? null) ?? ''));
            if ($pairId === '' || $arm === '') {
                continue;
            }

            $pairs[$pairId] ??= [
                self::FIELD_MEMORY_TYPE => $this->memoryTypeFromCounterfactualMeta($meta),
                self::FIELD_ROWS => [],
                'policy_violation_rows' => 0,
            ];
            $pairs[$pairId][self::FIELD_MEMORY_TYPE] = $pairs[$pairId][self::FIELD_MEMORY_TYPE] !== self::MEMORY_TYPE_UNKNOWN
                ? $pairs[$pairId][self::FIELD_MEMORY_TYPE]
                : $this->memoryTypeFromCounterfactualMeta($meta);
            $pairs[$pairId][self::FIELD_ROWS][$arm] = [
                self::FIELD_SCORE => $this->counterfactualScore($row, $meta),
                'policy_valid' => AiValueNormalizer::lowerTrimmedString($meta['mode'] ?? 'peek') === 'peek'
                    && ($meta['record_usage'] ?? false) === false,
            ];
            if (! $pairs[$pairId][self::FIELD_ROWS][$arm]['policy_valid']) {
                $pairs[$pairId]['policy_violation_rows']++;
            }
        }

        $groups = [];
        $validDeltas = [];
        $invalidPolicyPairs = 0;
        $invalidPolicyRows = 0;
        $incompletePairs = 0;
        $positiveLiftFabricated = 0;

        foreach ($pairs as $pair) {
            $rows = $pair[self::FIELD_ROWS];
            if (($pair['policy_violation_rows'] ?? 0) > 0) {
                $invalidPolicyPairs++;
                $invalidPolicyRows += count($rows);
                continue;
            }
            if (! isset($rows['control'], $rows['treatment'])) {
                $incompletePairs++;
                continue;
            }

            $memoryType = (AiValueNormalizer::trimmedStringOrNull($pair[self::FIELD_MEMORY_TYPE] ?? null) ?? self::MEMORY_TYPE_UNKNOWN);
            $control = AiValueNormalizer::finiteFloatOrNull($rows['control'][self::FIELD_SCORE] ?? null) ?? 0.0;
            $treatment = AiValueNormalizer::finiteFloatOrNull($rows['treatment'][self::FIELD_SCORE] ?? null) ?? 0.0;
            $delta = round($treatment - $control, 4);
            $groups[$memoryType] ??= [
                self::FIELD_MEMORY_TYPE => $memoryType,
                self::FIELD_N_PAIRS => 0,
                'control_score_sum' => 0.0,
                'treatment_score_sum' => 0.0,
                'delta_sum' => 0.0,
            ];
            $groups[$memoryType][self::FIELD_N_PAIRS]++;
            $groups[$memoryType]['control_score_sum'] += $control;
            $groups[$memoryType]['treatment_score_sum'] += $treatment;
            $groups[$memoryType]['delta_sum'] += $delta;
            $validDeltas[] = $delta;

            if ($memoryType === 'irrelevant' && $delta > 0.0001) {
                $positiveLiftFabricated++;
            }
        }

        $memoryTypes = array_values(array_map(
            fn (array $group): array => $this->finalizeCounterfactualLiftGroup($group, $denominatorMin),
            $groups,
        ));
        usort($memoryTypes, static fn (array $a, array $b): int => $a[self::FIELD_MEMORY_TYPE] <=> $b[self::FIELD_MEMORY_TYPE]);

        $measured = array_values(array_filter($memoryTypes, static fn (array $group): bool => $group[self::FIELD_STATUS] === self::STATUS_MEASURED));
        $measuredPairs = array_sum(array_column($measured, 'n_pairs'));
        $measuredDeltaSum = array_sum(array_map(
            static fn (array $group): float => (AiValueNormalizer::finiteFloatOrNull($group['paired_delta'] ?? null) ?? 0.0) * (int) $group[self::FIELD_N_PAIRS],
            $measured,
        ));

        return [
            self::FIELD_SCHEMA_VERSION => self::REPORT_SCHEMA,
            self::FIELD_SLICE => 'MULTJ-03',
            self::FIELD_STATUS => $measuredPairs > 0 ? self::STATUS_OK : self::STATUS_INSUFFICIENT_SIGNAL,
            self::FIELD_REASON => $measuredPairs > 0 ? null : 'paired_peek_floor_below_minimum',
            self::FIELD_FORMULA_VERSION => AiValueNormalizer::trimmedStringOrNull(data_get(self::freezePayload('MULTJ-03'), self::FIELD_FORMULA_VERSION)) ?? '',
            self::FIELD_GENERATED_AT => now()->toIso8601String(),
            self::FIELD_FREEZE => self::freezePayload('MULTJ-03'),
            self::FIELD_MEASURE_ID => self::MULTJ03_MEASURE_ID,
            self::FIELD_DENOMINATOR_MIN => $denominatorMin,
            'sample_rate' => $sampleRate,
            'rate' => $sampleRate,
            self::FIELD_N_PAIRS => count($validDeltas),
            'paired_delta' => $measuredPairs > 0 ? round($measuredDeltaSum / $measuredPairs, 4) : null,
            self::FIELD_MEMORY_TYPES => $memoryTypes,
            'peek_policy' => [
                self::FIELD_RECORD_USAGE_FOR_PEEK => false,
                'usage_rows_recorded' => $invalidPolicyRows,
            ],
            'invalid_pairs' => [
                'peek_policy_violation' => $invalidPolicyPairs,
                self::FIELD_INCOMPLETE => $incompletePairs,
                'positive_lift_fabricated' => $positiveLiftFabricated,
            ],
            'claim_policy' => [
                self::FIELD_READ_ONLY => true,
                self::FIELD_PROVIDER_CALLS_MADE => false,
                self::FIELD_MEMORY_WRITTEN => false,
                'retrieval_policy_changed' => false,
                self::FIELD_RECORD_USAGE_FOR_PEEK => false,
                'synthetic_fixture_claim_allowed' => false,
                'completion_claim_allowed' => false,
            ],
        ];
    }

    /** @return array<string,mixed> */
    public function multj04ProceduralSkillPromoter(): array
    {
        return app(AcosMaxProceduralSkillPromoterService::class)->report();
    }

    /**
     * @param  array<string,mixed>  $group
     * @return array<string,mixed>
     */
    private function finalizeCounterfactualLiftGroup(array $group, int $denominatorMin): array
    {
        $n = (int) (AiValueNormalizer::finiteFloatOrNull($group[self::FIELD_N_PAIRS] ?? null) ?? 0);

        return [
            self::FIELD_MEMORY_TYPE => AiValueNormalizer::trimmedScalarStringOrNull($group[self::FIELD_MEMORY_TYPE] ?? null) ?? '',
            self::FIELD_STATUS => $n >= $denominatorMin ? self::STATUS_MEASURED : self::STATUS_INSUFFICIENT_SIGNAL,
            self::FIELD_N_PAIRS => $n,
            'control_score_mean' => $n > 0 ? round((AiValueNormalizer::finiteFloatOrNull($group['control_score_sum'] ?? null) ?? 0.0) / $n, 4) : null,
            'treatment_score_mean' => $n > 0 ? round((AiValueNormalizer::finiteFloatOrNull($group['treatment_score_sum'] ?? null) ?? 0.0) / $n, 4) : null,
            'paired_delta' => $n > 0 ? round((AiValueNormalizer::finiteFloatOrNull($group['delta_sum'] ?? null) ?? 0.0) / $n, 4) : null,
        ];
    }

    /** @return array<string,mixed> */
    private function decodeJsonObject(mixed $value): array
    {
        if (is_array($value)) {
            return $value;
        }
        if (AiValueNormalizer::trimmedStringOrNull($value) === null) {
            return [];
        }

        $decoded = json_decode($value, true);

        return AiValueNormalizer::arrayOrEmpty($decoded);
    }

    /**
     * @param  array<string,mixed>  $payload
     * @return array<string,mixed>
     */
    private function counterfactualLiftMeta(array $payload): array
    {
        $meta = data_get($payload, 'counterfactual_lift_v2');

        return AiValueNormalizer::arrayOrEmpty($meta);
    }

    private function counterfactualArm(string $arm): string
    {
        $arm = AiValueNormalizer::lowerTrimmedString($arm);

        return match ($arm) {
            'control', 'without', 'without_lesson' => 'control',
            'treatment', 'with', 'with_lesson' => 'treatment',
            default => '',
        };
    }

    /**
     * @param  array<string,mixed>  $meta
     */
    private function memoryTypeFromCounterfactualMeta(array $meta): string
    {
        $memoryType = AiValueNormalizer::trimmedStringOrNull($meta[self::FIELD_MEMORY_TYPE] ?? null) ?? '';

        return $memoryType === '' ? self::MEMORY_TYPE_UNKNOWN : $memoryType;
    }

    /**
     * @param  array<string,mixed>  $meta
     */
    private function counterfactualScore(object $row, array $meta): float
    {
        $score = $meta[self::FIELD_SCORE] ?? $row->post_execution_utility ?? $row->context_sufficiency ?? 0;

        return AiValueNormalizer::finiteFloatOrNull($score) ?? 0.0;
    }

    /** @return array<string,mixed> */
    public function teto02MissionE2e(?int $days = null): array
    {
        if (! Schema::hasTable('atlas_mission_deliveries')) {
            return $this->emptyReport('TETO-02', self::STATUS_INSUFFICIENT_SIGNAL, self::REASON_MISSION_DELIVERY_TABLE_MISSING, [
                self::FIELD_MEASURE_ID => self::TETO02_MEASURE_ID,
                self::FIELD_DENOMINATOR_MIN => 20,
                'window_days' => $days,
                'denominator' => ['operator_requests' => 0],
            ]);
        }

        $query = DB::table('atlas_mission_deliveries');
        if ($days !== null && $days > 0) {
            $query->where('created_at', '>=', now()->subDays($days));
        }
        $rows = $query->get();
        $total = $rows->count();
        $completed = $rows->filter(static fn ($row): bool => in_array(AiValueNormalizer::lowerTrimmedString($row->status ?? ''), [
            self::MISSION_STATUS_COMPLETED,
            self::MISSION_STATUS_DELIVERED,
            self::MISSION_STATUS_SUCCEEDED,
            self::MISSION_STATUS_SUCCESS,
        ], true))->count();

        return [
            self::FIELD_SCHEMA_VERSION => self::REPORT_SCHEMA,
            self::FIELD_SLICE => 'TETO-02',
            self::FIELD_STATUS => $total >= 20 ? self::STATUS_OK : self::STATUS_INSUFFICIENT_SIGNAL,
            self::FIELD_REASON => $total >= 20 ? null : 'operator_request_window_below_floor',
            self::FIELD_MEASURE_ID => self::TETO02_MEASURE_ID,
            self::FIELD_FORMULA_VERSION => 'mission_e2e_rate.v1',
            self::FIELD_GENERATED_AT => now()->toIso8601String(),
            self::FIELD_FREEZE => self::freezePayload('TETO-02'),
            self::FIELD_DENOMINATOR_MIN => 20,
            'window_days' => $days,
            'metrics' => [
                'operator_requests' => $total,
                'completed_e2e' => $completed,
                'mission_e2e_rate' => $total > 0 ? round($completed / $total, 4) : null,
                'asks_per_request' => null,
                'request_to_delivery_p50_seconds' => null,
                'request_to_delivery_p95_seconds' => null,
            ],
            'abandoned_count_as_not_completed' => true,
        ];
    }

    /** @return array<string,array<string,mixed>> */
    public static function freezePayloads(): array
    {
        return [
            'MAXL-06' => self::payload(self::MAXL06_MEASURE_ID, 'maxl06.delta_attribution.v1', 'Report-only attribution joins daily measure deltas to lineage decision_ids/commits; when lineage is absent, basis must be labeled and causal language must use correlational_attribution.', 1, 30, 'cursor-acos-max-maxl06', 'codex-independent-maxl06-judge', ['allowed_basis' => ['lineage_ledger', 'git_log'], 'counterfactual_basis' => 'none']),
            'MULTN17-04' => self::payload(self::MULTN1704_MEASURE_ID, 'multn17.predicted_impact_calibration.v1', 'Derived predicted_impact band versus realized proven_real outcome curve for origination; report-only until at least 20 real originations resolve.', 20, 30, 'cursor-acos-max-multn17-04', 'codex-independent-multn17-04-judge', ['denominator_min_originations' => 20, 'max_abs_declared_realized_deviation' => 1]),
            'MULTX-01' => self::payload(self::MULTX01_MEASURE_ID, 'multx.flywheel_loop_definition.v1', 'A valid loop chains task, decision receipt, delivered context, execution outcome, lesson, and subsequent measured recall; proven_real outcome is mandatory.', 1, 30, 'cursor-acos-max-multx01', 'codex-independent-multx01-judge', ['requires_proven_real_outcome' => true]),
            'MULTX-06' => self::payload(self::MULTX06_MEASURE_ID, 'multx.learning_latency.v1', 'Measure p50/p95 latency from outcome-created lesson to first delivered context and first measured citation; never_delivered remains in denominator.', 8, 30, 'cursor-acos-max-multx06', 'codex-independent-multx06-judge', ['denominator_min_promoted_lessons' => 8]),
            'MULTX-09' => self::payload(self::MULTX09_MEASURE_ID, 'multx.windows_orchestrator.v1', 'Read-only PromotionProtocol window DAG: started windows publish days_remaining and critical path; not-started windows never receive fabricated ETA; associated series silence beyond the watchdog floor emits dead_window.', 1, 30, 'cursor-acos-max-multx09', 'codex-independent-multx09-judge', ['dead_window_silent_days' => 3, 'not_started_eta_allowed' => false, self::FIELD_READ_ONLY => true]),
            'MULTJ-01' => self::payload(self::MULTJ01_MEASURE_ID, 'multj.lesson_half_life.v2', 'Bucket lesson lift by age since promotion using two-week buckets; buckets below n=8 publish insufficient instead of null.', 8, 30, 'cursor-acos-max-multj01', 'codex-independent-multj01-judge', ['bucket_width_weeks' => 2, 'denominator_min_per_bucket' => 8]),
            'MULTJ-02' => self::payload(self::MULTJ02_MEASURE_ID, 'multj.semantic_dedup_freeze.v1', 'Semantic lesson dedup threshold freeze for observe-mode would-merge receipts; enforcement requires later calibrated promotion.', 1, 30, 'cursor-acos-max-multj02', 'codex-independent-multj02-judge', ['cosine_merge_threshold' => 0.88, 'observe_mode_actual_merges' => 0]),
            'MULTJ-03' => self::payload(self::MULTJ03_MEASURE_ID, 'multj.counterfactual_lift.v2', 'Paired peek evaluation of the same task with and without injected lesson; n_pairs below 8 publishes insufficient_signal and peek must not record usage.', 8, 30, 'cursor-acos-max-multj03', 'codex-independent-multj03-judge', ['sample_rate' => 0.05, 'denominator_min_pairs' => 8, self::FIELD_RECORD_USAGE_FOR_PEEK => false]),
            'MULTJ-04' => self::payload(self::MULTJ04_MEASURE_ID, 'multj.procedural_skill_promoter.v1', 'Procedural playbooks can propose skill.v1 candidates only after the real procedural case_count floor; output is default-OFF and ASI-02 holds promotion_allowed=false until gates pass.', AcosMaxProceduralSkillPromoterService::DEFAULT_CASE_COUNT_FLOOR, 30, 'cursor-acos-max-multj04', 'codex-independent-multj04-judge', ['procedural_case_count_floor' => AcosMaxProceduralSkillPromoterService::DEFAULT_CASE_COUNT_FLOOR, 'default_off' => true, 'admission_door' => 'ASI-02']),
            'MULTJ-06' => self::payload(self::MULTJ06_MEASURE_ID, 'multj.abstraction_ladder.v1', 'Distinct-signature patterns sharing primary_cause aggregate to level-3 principles when distinct_signature_k is met; derived_from refs must resolve or gate rejects.', 3, 30, 'cursor-acos-max-multj06', 'codex-independent-multj06-judge', ['distinct_signature_k' => 3, 'pattern_floor' => 1]),
            'TETO-02' => self::payload(self::TETO02_MEASURE_ID, 'mission_e2e_rate.v1', 'Operator natural-language request to completed result rate, asks per request, and request-to-delivery latency; abandoned missions stay in the denominator.', 20, 30, 'cursor-acos-max-teto02', 'codex-independent-teto02-judge', ['target_mission_e2e_rate' => 0.70, 'denominator_min_operator_requests' => 20]),
        ];
    }

    /** @return array<string,mixed> */
    private static function payload(string $measureId, string $formulaVersion, string $formula, int $denominatorMin, int $ttlDays, string $author, string $judge, array $thresholds): array
    {
        return [
            self::FIELD_KIND => self::KIND_MEASURE_FREEZE,
            self::FIELD_MEASURE_ID => $measureId,
            self::FIELD_FORMULA_VERSION => $formulaVersion,
            self::FIELD_FORMULA => $formula,
            'thresholds' => $thresholds,
            self::FIELD_DENOMINATOR_MIN => $denominatorMin,
            'ttl_days' => $ttlDays,
            'author_engine_id' => $author,
            'judge_engine_id' => $judge,
            'dual_read_required' => false,
        ];
    }

    /** @return array<string,mixed> */
    private function emptyReport(string $slice, string $status, string $reason, array $extra): array
    {
        return array_merge([
            self::FIELD_SCHEMA_VERSION => self::REPORT_SCHEMA,
            self::FIELD_SLICE => $slice,
            self::FIELD_STATUS => $status,
            self::FIELD_REASON => $reason,
            self::FIELD_FORMULA_VERSION => AiValueNormalizer::trimmedStringOrNull(data_get(self::freezePayload($slice), self::FIELD_FORMULA_VERSION)) ?? '',
            self::FIELD_GENERATED_AT => now()->toIso8601String(),
            self::FIELD_FREEZE => self::freezePayload($slice),
        ], $extra);
    }

    private function countTableIfPresent(string $table): int
    {
        if (! Schema::hasTable($table)) {
            return 0;
        }

        return (int) DB::table($table)->count();
    }
}
