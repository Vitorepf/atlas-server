<?php

declare(strict_types=1);

namespace App\Services\Ai\Compounding;

use App\Models\AiCompoundingMemory;
use App\Models\AiRagFeedbackEvent;
use App\Models\AiRunOutcome;
use App\Models\AtlasMemoryEntry;
use App\Services\Ai\Support\DatabaseTableAvailability;
use Illuminate\Support\Carbon;

/**
 * L5-11 measurement over the existing compounding/RAG feedback spine.
 *
 * This is deliberately read-only: it does not promote memories, alter retrieval,
 * or infer success from semantic recall alone. A completion claim requires real
 * feedback rows where an active compounding memory was marked useful/used in a
 * passing outcome, plus an A/B lift against comparable rows without that recall.
 */
final class AtlasLearningRecallUseLiftService
{
    public const SCHEMA_VERSION = 'atlas.ai.learning_recall_use_lift.v1';

    public const LESSON_TYPE_YIELD_SCHEMA_VERSION = 'atlas.ai.lesson_type_yield.report.v2';

    public const LESSON_TYPE_YIELD_MEASURE_ID = 'atlas.ai.lesson_type_yield.v2';

    public const LESSON_TYPE_YIELD_FORMULA_VERSION = 'atlas_ai_lesson_type_yield_v2';

    public const LESSON_TYPE_YIELD_DENOMINATOR_MIN = 8;

    /**
     * @return array<string,mixed>
     */
    public function report(?int $minCases = null, ?int $minPassingUse = null): array
    {
        if (! (bool) config('atlas.ai.loop.learning_recall_use_lift.enabled', true)) {
            return $this->disabledReport();
        }

        $requiredTables = ['ai_compounding_memories', 'ai_rag_feedback_events', 'ai_run_outcomes'];
        $missingTables = array_values(array_filter(
            $requiredTables,
            static fn (string $table): bool => ! DatabaseTableAvailability::has($table),
        ));
        if ($missingTables !== []) {
            return $this->blockedReport('tables_missing', ['missing_tables' => $missingTables]);
        }

        $minCases = max(1, $minCases ?? (int) config('atlas.ai.loop.learning_recall_use_lift.min_cases_per_arm', 2));
        $minPassingUse = max(1, $minPassingUse ?? (int) config('atlas.ai.loop.learning_recall_use_lift.min_passing_memory_use', 1));
        $memories = AiCompoundingMemory::query()
            ->active()
            ->get(['id', 'memory_hash', 'claim', 'flow_id', 'confidence'])
            ->all();
        $atlasMemoryEntries = $this->atlasMemoryEntries();
        $memoryKeys = $this->memoryKeys($memories, $atlasMemoryEntries);

        $events = AiRagFeedbackEvent::query()
            ->orderByDesc('created_at')
            ->limit(max(50, (int) config('atlas.ai.loop.learning_recall_use_lift.max_events', 500)))
            ->get()
            ->map(fn (AiRagFeedbackEvent $event): array => $this->eventPayload($event, $memoryKeys))
            ->values()
            ->all();

        $withRecall = array_values(array_filter(
            $events,
            static fn (array $event): bool => (bool) ($event['memory_recall_used'] ?? false),
        ));
        $withoutRecall = array_values(array_filter(
            $events,
            static fn (array $event): bool => ! (bool) ($event['memory_recall_used'] ?? false),
        ));
        $passingWithRecall = array_values(array_filter(
            $withRecall,
            static fn (array $event): bool => ($event['outcome_status'] ?? null) === 'passed',
        ));

        $withMetrics = $this->armMetrics($withRecall);
        $withoutMetrics = $this->armMetrics($withoutRecall);
        $measurementReady = $withMetrics['case_count'] >= $minCases && $withoutMetrics['case_count'] >= $minCases;
        $lift = round((float) $withMetrics['passed_rate'] - (float) $withoutMetrics['passed_rate'], 4);
        $liveDodMet = $measurementReady && count($passingWithRecall) >= $minPassingUse && $lift > 0.0;
        $blockers = $this->blockers(
            memoryCount: count($memories),
            atlasMemoryEntryCount: count($atlasMemoryEntries),
            withCount: (int) $withMetrics['case_count'],
            withoutCount: (int) $withoutMetrics['case_count'],
            passingWithCount: count($passingWithRecall),
            minCases: $minCases,
            minPassingUse: $minPassingUse,
            lift: $lift,
        );

        return [
            'schema_version' => self::SCHEMA_VERSION,
            'status' => $liveDodMet
                ? 'positive_live_lift'
                : ($measurementReady ? 'non_positive_or_uncertified_live_lift' : 'insufficient_live_ab_evidence'),
            'generated_at' => Carbon::now()->toIso8601String(),
            'measurement' => [
                'evaluation_mode' => 'existing_compounding_rag_feedback_ab',
                'active_compounding_memory_count' => count($memories),
                'active_atlas_memory_entry_count' => count($atlasMemoryEntries),
                'feedback_event_count' => count($events),
                'min_cases_per_arm' => $minCases,
                'min_passing_memory_use' => $minPassingUse,
                'with_recalled_memory' => $withMetrics,
                'without_recalled_memory' => $withoutMetrics,
                'passed_rate_lift' => $lift,
                'passing_memory_use_count' => count($passingWithRecall),
                'measurement_ready' => $measurementReady,
                'live_dod_met' => $liveDodMet,
                'blockers' => $blockers,
            ],
            'sample' => [
                'with_recalled_memory' => array_slice($withRecall, 0, 5),
                'without_recalled_memory' => array_slice($withoutRecall, 0, 5),
            ],
            'claim_policy' => [
                'read_only' => true,
                'provider_calls_made' => false,
                'memory_written' => false,
                'retrieval_policy_changed' => false,
                'synthetic_fixture_claim_allowed' => false,
                'completion_claim_allowed' => $liveDodMet,
            ],
            'commands_next' => [
                'measure' => 'php artisan atlas:ai:learning-recall-lift --json',
                'strict' => 'php artisan atlas:ai:learning-recall-lift --json --strict',
                'collect_live_recall_use' => 'record AiRagFeedbackEvent.source_utility with active compounding memory hash/id when recalled memory is actually used in a passing task',
            ],
        ];
    }

    /**
     * @return array<string,mixed>
     */
    public function lessonTypeYieldReport(?int $minCases = null): array
    {
        if (! (bool) config('atlas.ai.loop.learning_recall_use_lift.enabled', true)) {
            return [
                'schema_version' => self::LESSON_TYPE_YIELD_SCHEMA_VERSION,
                'measure_id' => self::LESSON_TYPE_YIELD_MEASURE_ID,
                'formula_version' => self::LESSON_TYPE_YIELD_FORMULA_VERSION,
                'status' => 'disabled',
                'generated_at' => Carbon::now()->toIso8601String(),
                'types' => [],
                'claim_policy' => [
                    'read_only' => true,
                    'provider_calls_made' => false,
                    'memory_written' => false,
                    'retrieval_policy_changed' => false,
                    'completion_claim_allowed' => false,
                ],
            ];
        }

        $requiredTables = ['ai_compounding_memories', 'ai_rag_feedback_events', 'ai_run_outcomes'];
        $missingTables = array_values(array_filter(
            $requiredTables,
            static fn (string $table): bool => ! DatabaseTableAvailability::has($table),
        ));
        if ($missingTables !== []) {
            return [
                'schema_version' => self::LESSON_TYPE_YIELD_SCHEMA_VERSION,
                'measure_id' => self::LESSON_TYPE_YIELD_MEASURE_ID,
                'formula_version' => self::LESSON_TYPE_YIELD_FORMULA_VERSION,
                'status' => 'blocked',
                'generated_at' => Carbon::now()->toIso8601String(),
                'measurement' => [
                    'missing_tables' => $missingTables,
                    'blockers' => ['tables_missing'],
                ],
                'types' => [],
                'claim_policy' => [
                    'read_only' => true,
                    'provider_calls_made' => false,
                    'memory_written' => false,
                    'retrieval_policy_changed' => false,
                    'completion_claim_allowed' => false,
                ],
            ];
        }

        $minCases = max(self::LESSON_TYPE_YIELD_DENOMINATOR_MIN, $minCases ?? self::LESSON_TYPE_YIELD_DENOMINATOR_MIN);
        $memories = AiCompoundingMemory::query()
            ->active()
            ->get(['id', 'memory_hash', 'claim', 'flow_id', 'confidence', 'memory_type'])
            ->all();
        $atlasMemoryEntries = $this->atlasMemoryEntries();
        $memoryKeys = $this->memoryKeys($memories, $atlasMemoryEntries);
        $memoryTypes = $this->memoryTypes($memories);

        $events = AiRagFeedbackEvent::query()
            ->orderByDesc('created_at')
            ->limit(max(50, (int) config('atlas.ai.loop.learning_recall_use_lift.max_events', 500)))
            ->get()
            ->map(fn (AiRagFeedbackEvent $event): array => $this->eventPayload($event, $memoryKeys, $memoryTypes))
            ->values()
            ->all();

        $baseline = array_values(array_filter(
            $events,
            static fn (array $event): bool => ((array) ($event['matched_memory_types'] ?? [])) === [],
        ));
        $baselineMetrics = $this->armMetrics($baseline);

        $types = [];
        foreach ($this->knownMemoryTypes($memories) as $type) {
            $withType = array_values(array_filter(
                $events,
                static fn (array $event): bool => in_array($type, (array) ($event['matched_memory_types'] ?? []), true),
            ));
            $withMetrics = $this->armMetrics($withType);
            $caseCount = (int) $withMetrics['case_count'];
            $baselineCount = (int) $baselineMetrics['case_count'];
            $measurementReady = $caseCount >= $minCases && $baselineCount >= $minCases;
            $lift = $measurementReady
                ? round((float) $withMetrics['passed_rate'] - (float) $baselineMetrics['passed_rate'], 4)
                : null;

            $types[] = [
                'memory_type' => $type,
                'status' => $measurementReady ? 'measured' : 'insufficient_signal',
                'reason' => $caseCount < $minCases
                    ? 'case_count_below_lesson_type_floor'
                    : ($baselineCount < $minCases ? 'baseline_count_below_lesson_type_floor' : null),
                'case_count' => $caseCount,
                'baseline_case_count' => $baselineCount,
                'denominator_min' => $minCases,
                'with_recalled_memory_type' => $withMetrics,
                'without_recalled_memory_type' => $baselineMetrics,
                'passed_rate_lift' => $lift,
            ];
        }

        $measuredTypeCount = count(array_filter($types, static fn (array $type): bool => ($type['status'] ?? null) === 'measured'));

        return [
            'schema_version' => self::LESSON_TYPE_YIELD_SCHEMA_VERSION,
            'measure_id' => self::LESSON_TYPE_YIELD_MEASURE_ID,
            'formula_version' => self::LESSON_TYPE_YIELD_FORMULA_VERSION,
            'status' => $measuredTypeCount > 0 ? 'ok' : 'insufficient_signal',
            'generated_at' => Carbon::now()->toIso8601String(),
            'denominator_min' => $minCases,
            'totals' => [
                'memory_type_count' => count($types),
                'measured_type_count' => $measuredTypeCount,
                'feedback_event_count' => count($events),
                'baseline_case_count' => (int) $baselineMetrics['case_count'],
            ],
            'types' => $types,
            'dual_read' => [
                'aggregate_v1_schema_version' => self::SCHEMA_VERSION,
                'aggregate_v1_command' => 'php artisan atlas:ai:learning-recall-lift --json',
                'aggregate_v1_min_cases_config_key_unchanged' => 'atlas.ai.loop.learning_recall_use_lift.min_cases_per_arm',
            ],
            'claim_policy' => [
                'read_only' => true,
                'provider_calls_made' => false,
                'memory_written' => false,
                'retrieval_policy_changed' => false,
                'synthetic_fixture_claim_allowed' => false,
                'completion_claim_allowed' => false,
            ],
            'commands_next' => [
                'measure' => 'php artisan atlas:ai:lesson-type-yield --json',
            ],
        ];
    }

    /**
     * @return array<string,mixed>
     */
    private function disabledReport(): array
    {
        return [
            'schema_version' => self::SCHEMA_VERSION,
            'status' => 'disabled',
            'generated_at' => Carbon::now()->toIso8601String(),
            'measurement' => [
                'live_dod_met' => false,
                'blockers' => ['learning_recall_use_lift_disabled'],
            ],
            'claim_policy' => [
                'read_only' => true,
                'provider_calls_made' => false,
                'memory_written' => false,
                'retrieval_policy_changed' => false,
                'completion_claim_allowed' => false,
            ],
        ];
    }

    /**
     * @param  array<string,mixed>  $extra
     * @return array<string,mixed>
     */
    private function blockedReport(string $reason, array $extra = []): array
    {
        return [
            'schema_version' => self::SCHEMA_VERSION,
            'status' => 'blocked',
            'generated_at' => Carbon::now()->toIso8601String(),
            'measurement' => [
                ...$extra,
                'live_dod_met' => false,
                'blockers' => [$reason],
            ],
            'claim_policy' => [
                'read_only' => true,
                'provider_calls_made' => false,
                'memory_written' => false,
                'retrieval_policy_changed' => false,
                'completion_claim_allowed' => false,
            ],
        ];
    }

    /**
     * @return list<AtlasMemoryEntry>
     */
    private function atlasMemoryEntries(): array
    {
        if (! DatabaseTableAvailability::has('atlas_memory_entries')) {
            return [];
        }

        $columns = ['id'];
        if (DatabaseTableAvailability::hasColumn('atlas_memory_entries', 'content_hash')) {
            $columns[] = 'content_hash';
        }

        return AtlasMemoryEntry::query()
            ->active()
            ->get($columns)
            ->all();
    }

    /**
     * @param  list<AiCompoundingMemory>  $memories
     * @param  list<AtlasMemoryEntry>  $atlasMemoryEntries
     * @return array<string,string>
     */
    private function memoryKeys(array $memories, array $atlasMemoryEntries): array
    {
        $keys = [];
        foreach ($memories as $memory) {
            foreach ([$memory->id, $memory->memory_hash] as $value) {
                if (! is_string($value) || trim($value) === '') {
                    continue;
                }
                $value = trim($value);
                foreach ([$value, 'memory:'.$value, 'compounding_memory:'.$value, 'ai_compounding_memory:'.$value] as $key) {
                    $keys[$key] = 'ai_compounding_memory';
                }
            }
        }
        foreach ($atlasMemoryEntries as $entry) {
            foreach ([$entry->id, $entry->content_hash ?? null] as $value) {
                if (! is_string($value) || trim($value) === '') {
                    continue;
                }
                $value = trim($value);
                foreach ([$value, 'memory:'.$value, 'atlas_memory_entry:'.$value] as $key) {
                    $keys[$key] = 'atlas_memory_entry';
                }
            }
        }

        return $keys;
    }

    /**
     * @param  array<string,string>  $memoryKeys
     * @return array<string,mixed>
     */
    private function eventPayload(AiRagFeedbackEvent $event, array $memoryKeys, ?array $memoryTypes = null): array
    {
        $sourceUtility = $this->sourceUtility($event->source_utility);
        $matched = [];
        $matchedSources = [];
        $matchedTypes = [];
        foreach ($sourceUtility as $key => $value) {
            if (! isset($memoryKeys[$key]) || ! $this->utilityIsPositive($value)) {
                continue;
            }
            $matched[] = hash('sha256', (string) $key);
            $matchedSources[$memoryKeys[$key]] = true;
            if ($memoryTypes !== null && isset($memoryTypes[$key])) {
                $matchedTypes[$memoryTypes[$key]] = true;
            }
        }

        $outcome = null;
        if (is_string($event->run_outcome_id) && trim($event->run_outcome_id) !== '') {
            $outcome = AiRunOutcome::query()->find($event->run_outcome_id);
        }

        $payload = [
            'event_hash' => hash('sha256', (string) $event->id),
            'flow_id' => $event->flow_id,
            'outcome_status' => $event->outcome_status ?? $outcome?->outcome_status,
            'context_sufficiency' => (int) $event->context_sufficiency,
            'post_execution_utility' => (int) $event->post_execution_utility,
            'used_sources' => (int) $event->used_sources,
            'included_sources' => (int) $event->included_sources,
            'execution_quality' => $outcome?->execution_quality,
            'evidence_quality' => $outcome?->evidence_quality,
            'memory_recall_used' => $matched !== [],
            'matched_memory_ref_hashes' => $matched,
            'matched_memory_sources' => array_keys($matchedSources),
            'created_at' => $event->created_at?->toIso8601String(),
        ];

        if ($memoryTypes !== null) {
            $payload['matched_memory_types'] = array_keys($matchedTypes);
        }

        return $payload;
    }

    /**
     * @return array<string,mixed>
     */
    private function sourceUtility(mixed $value): array
    {
        if (is_array($value)) {
            return $value;
        }
        if (is_string($value) && trim($value) !== '') {
            $decoded = json_decode($value, true);
            if (is_array($decoded)) {
                return $decoded;
            }
        }

        return [];
    }

    private function utilityIsPositive(mixed $value): bool
    {
        if (is_array($value)) {
            return (bool) ($value['used'] ?? false)
                || (bool) ($value['useful'] ?? false)
                || in_array(strtolower(trim((string) ($value['utility'] ?? $value['status'] ?? ''))), ['used', 'useful', 'helpful', 'critical', 'required'], true);
        }

        return is_scalar($value)
            && in_array(strtolower(trim((string) $value)), ['used', 'useful', 'helpful', 'critical', 'required'], true);
    }

    /**
     * @param  list<array<string,mixed>>  $events
     * @return array<string,mixed>
     */
    private function armMetrics(array $events): array
    {
        $count = count($events);
        $passed = count(array_filter($events, static fn (array $event): bool => ($event['outcome_status'] ?? null) === 'passed'));

        return [
            'case_count' => $count,
            'passed_count' => $passed,
            'passed_rate' => $count === 0 ? 0.0 : round($passed / $count, 4),
            'avg_context_sufficiency' => $this->average(array_column($events, 'context_sufficiency')),
            'avg_post_execution_utility' => $this->average(array_column($events, 'post_execution_utility')),
            'avg_execution_quality' => $this->average(array_filter(array_column($events, 'execution_quality'), 'is_numeric')),
            'avg_evidence_quality' => $this->average(array_filter(array_column($events, 'evidence_quality'), 'is_numeric')),
        ];
    }

    /**
     * @param  array<int,mixed>  $values
     */
    private function average(array $values): float
    {
        $numbers = array_values(array_filter($values, 'is_numeric'));
        if ($numbers === []) {
            return 0.0;
        }

        return round(array_sum(array_map('floatval', $numbers)) / count($numbers), 4);
    }

    /**
     * @return list<string>
     */
    private function blockers(int $memoryCount, int $atlasMemoryEntryCount, int $withCount, int $withoutCount, int $passingWithCount, int $minCases, int $minPassingUse, float $lift): array
    {
        $blockers = [];
        if (($memoryCount + $atlasMemoryEntryCount) === 0) {
            $blockers[] = 'no_active_memory_spine_entry';
        }
        if ($withCount < $minCases) {
            $blockers[] = 'insufficient_memory_recall_use_cases';
        }
        if ($withoutCount < $minCases) {
            $blockers[] = 'insufficient_baseline_cases_without_recall';
        }
        if ($passingWithCount < $minPassingUse) {
            $blockers[] = 'no_passing_task_with_memory_recall_use';
        }
        if ($withCount >= $minCases && $withoutCount >= $minCases && $lift <= 0.0) {
            $blockers[] = 'non_positive_passed_rate_lift';
        }

        return array_values(array_unique($blockers));
    }

    /**
     * @param  list<AiCompoundingMemory>  $memories
     * @return array<string,string>
     */
    private function memoryTypes(array $memories): array
    {
        $types = [];
        foreach ($memories as $memory) {
            $memoryType = trim((string) $memory->memory_type);
            if ($memoryType === '') {
                continue;
            }
            foreach ([$memory->id, $memory->memory_hash] as $value) {
                if (! is_string($value) || trim($value) === '') {
                    continue;
                }
                $value = trim($value);
                foreach ([$value, 'memory:'.$value, 'compounding_memory:'.$value, 'ai_compounding_memory:'.$value] as $key) {
                    $types[$key] = $memoryType;
                }
            }
        }

        return $types;
    }

    /**
     * @param  list<AiCompoundingMemory>  $memories
     * @return list<string>
     */
    private function knownMemoryTypes(array $memories): array
    {
        $types = [];
        foreach ($memories as $memory) {
            $type = trim((string) $memory->memory_type);
            if ($type !== '') {
                $types[$type] = true;
            }
        }

        $types = array_keys($types);
        sort($types);

        return $types;
    }
}
