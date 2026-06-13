<?php

declare(strict_types=1);

namespace App\Services\Ai\Compounding;

use App\Models\AiCompoundingMemory;
use App\Models\AiRagFeedbackEvent;
use App\Models\AiRunOutcome;
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
        $memoryKeys = $this->memoryKeys($memories);

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
     * @param  list<AiCompoundingMemory>  $memories
     * @return array<string,true>
     */
    private function memoryKeys(array $memories): array
    {
        $keys = [];
        foreach ($memories as $memory) {
            foreach ([$memory->id, $memory->memory_hash] as $value) {
                if (! is_string($value) || trim($value) === '') {
                    continue;
                }
                $value = trim($value);
                foreach ([$value, 'memory:'.$value, 'compounding_memory:'.$value, 'ai_compounding_memory:'.$value] as $key) {
                    $keys[$key] = true;
                }
            }
        }

        return $keys;
    }

    /**
     * @param  array<string,true>  $memoryKeys
     * @return array<string,mixed>
     */
    private function eventPayload(AiRagFeedbackEvent $event, array $memoryKeys): array
    {
        $sourceUtility = $this->sourceUtility($event->source_utility);
        $matched = [];
        foreach ($sourceUtility as $key => $value) {
            if (! isset($memoryKeys[$key]) || ! $this->utilityIsPositive($value)) {
                continue;
            }
            $matched[] = hash('sha256', (string) $key);
        }

        $outcome = null;
        if (is_string($event->run_outcome_id) && trim($event->run_outcome_id) !== '') {
            $outcome = AiRunOutcome::query()->find($event->run_outcome_id);
        }

        return [
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
            'created_at' => $event->created_at?->toIso8601String(),
        ];
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
    private function blockers(int $memoryCount, int $withCount, int $withoutCount, int $passingWithCount, int $minCases, int $minPassingUse, float $lift): array
    {
        $blockers = [];
        if ($memoryCount === 0) {
            $blockers[] = 'no_active_compounding_memory';
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
}
