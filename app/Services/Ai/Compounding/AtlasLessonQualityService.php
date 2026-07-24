<?php

declare(strict_types=1);

namespace App\Services\Ai\Compounding;

use App\Models\AiLearningCandidate;
use App\Models\AiRagFeedbackEvent;
use App\Services\Ai\Support\DatabaseTableAvailability;
use Illuminate\Support\Carbon;

final class AtlasLessonQualityService
{
    public const SCHEMA_VERSION = 'atlas.ai.lesson_quality.report.v2';

    public const MEASURE_ID = 'atlas.ai.lesson_quality.v2';

    public const FORMULA_VERSION = 'atlas_ai_lesson_quality_v2';

    public const DENOMINATOR_MIN = 1;

    /**
     * @return array<string,mixed>
     */
    public function report(?int $minCases = null): array
    {
        $requiredTables = ['ai_learning_candidates', 'ai_rag_feedback_events', 'ai_run_outcomes'];
        $missingTables = array_values(array_filter(
            $requiredTables,
            static fn (string $table): bool => ! DatabaseTableAvailability::has($table),
        ));
        if ($missingTables !== []) {
            return $this->blockedReport('tables_missing', ['missing_tables' => $missingTables]);
        }

        $minCases = max(self::DENOMINATOR_MIN, $minCases ?? self::DENOMINATOR_MIN);
        $groups = [];
        $candidateById = [];

        /** @var list<AiLearningCandidate> $candidates */
        $candidates = AiLearningCandidate::query()
            ->with('outcome')
            ->orderBy('created_at')
            ->get()
            ->all();

        foreach ($candidates as $candidate) {
            $candidateById[(string) $candidate->id] = $candidate;
            $key = $this->candidateGroupKey($candidate);
            $groups[$key] ??= $this->emptyGroup($candidate);
            $groups[$key]['candidate_count']++;
            if ($this->candidatePromoted($candidate)) {
                $groups[$key]['promoted_count']++;
            }
        }

        /** @var list<AiRagFeedbackEvent> $feedbackEvents */
        $feedbackEvents = AiRagFeedbackEvent::query()
            ->orderBy('created_at')
            ->get()
            ->all();

        $baselineByFlow = [];
        foreach ($feedbackEvents as $event) {
            $candidate = $this->candidateForFeedback($event, $candidateById);
            if (! $candidate instanceof AiLearningCandidate) {
                $baselineByFlow[(string) $event->flow_id][] = $event;

                continue;
            }

            $key = $this->candidateGroupKey($candidate, (string) $event->flow_id);
            $groups[$key] ??= $this->emptyGroup($candidate, (string) $event->flow_id);
            $groups[$key]['case_count']++;
            $groups[$key]['measured_count']++;
            if ($this->feedbackPassed($event)) {
                $groups[$key]['passed_count']++;
            } else {
                $groups[$key]['negative_count']++;
            }
        }

        $groups = array_values(array_map(
            fn (array $group): array => $this->finalizeGroup($group, $baselineByFlow[$group['flow_id']] ?? [], $minCases),
            $groups,
        ));
        usort($groups, static fn (array $a, array $b): int => [$a['memory_type'], $a['flow_id'], $a['scope']]
            <=> [$b['memory_type'], $b['flow_id'], $b['scope']]);

        $measuredCount = array_sum(array_column($groups, 'measured_count'));
        $candidateCount = array_sum(array_column($groups, 'candidate_count'));

        return [
            'schema_version' => self::SCHEMA_VERSION,
            'measure_id' => self::MEASURE_ID,
            'formula_version' => self::FORMULA_VERSION,
            'status' => $measuredCount > 0 ? 'ok' : 'insufficient_signal',
            'generated_at' => Carbon::now()->toIso8601String(),
            'denominator_min' => $minCases,
            'totals' => [
                'candidate_count' => $candidateCount,
                'measured_count' => $measuredCount,
                'case_count' => array_sum(array_column($groups, 'case_count')),
                'negative_count' => array_sum(array_column($groups, 'negative_count')),
                'total' => $candidateCount,
            ],
            'groups' => $groups,
            'dual_read' => [
                'aggregate_v1_schema_version' => AtlasLearningRecallUseLiftService::SCHEMA_VERSION,
                'aggregate_v1_command' => 'php artisan atlas:ai:learning-recall-lift --json',
                'aggregate_v1_unchanged_by_this_reader' => true,
            ],
            'claim_policy' => [
                'read_only' => true,
                'provider_calls_made' => false,
                'memory_written' => false,
                'retrieval_policy_changed' => false,
                'synthetic_fixture_claim_allowed' => false,
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
            'measure_id' => self::MEASURE_ID,
            'formula_version' => self::FORMULA_VERSION,
            'status' => 'blocked',
            'generated_at' => Carbon::now()->toIso8601String(),
            'measurement' => [
                ...$extra,
                'blockers' => [$reason],
            ],
            'groups' => [],
            'claim_policy' => [
                'read_only' => true,
                'provider_calls_made' => false,
                'memory_written' => false,
                'retrieval_policy_changed' => false,
                'completion_claim_allowed' => false,
            ],
        ];
    }

    private function candidateGroupKey(AiLearningCandidate $candidate, ?string $flowId = null): string
    {
        return implode("\0", [
            $this->candidateMemoryType($candidate),
            $flowId ?? $this->candidateFlowId($candidate),
            $this->candidateScope($candidate),
        ]);
    }

    /**
     * @return array<string,mixed>
     */
    private function emptyGroup(AiLearningCandidate $candidate, ?string $flowId = null): array
    {
        return [
            'memory_type' => $this->candidateMemoryType($candidate),
            'flow_id' => $flowId ?? $this->candidateFlowId($candidate),
            'scope' => $this->candidateScope($candidate),
            'candidate_count' => 0,
            'promoted_count' => 0,
            'case_count' => 0,
            'passed_count' => 0,
            'negative_count' => 0,
            'measured_count' => 0,
        ];
    }

    /**
     * @param  list<AiRagFeedbackEvent>  $baseline
     * @return array<string,mixed>
     */
    private function finalizeGroup(array $group, array $baseline, int $minCases): array
    {
        $candidateCount = (int) $group['candidate_count'];
        $caseCount = (int) $group['case_count'];
        $passedCount = (int) $group['passed_count'];
        $baselineCount = count($baseline);
        $baselinePassed = count(array_filter($baseline, fn (AiRagFeedbackEvent $event): bool => $this->feedbackPassed($event)));
        $baselineRate = $baselineCount === 0 ? null : round($baselinePassed / $baselineCount, 4);
        $passedRate = $caseCount === 0 ? 0.0 : round($passedCount / $caseCount, 4);

        return [
            'memory_type' => (string) $group['memory_type'],
            'flow_id' => (string) $group['flow_id'],
            'scope' => (string) $group['scope'],
            'status' => $caseCount >= $minCases ? 'measured' : 'insufficient_signal',
            'candidate_count' => $candidateCount,
            'promoted_count' => (int) $group['promoted_count'],
            'promoted_rate' => $candidateCount === 0 ? 0.0 : round((int) $group['promoted_count'] / $candidateCount, 4),
            'case_count' => $caseCount,
            'passed_count' => $passedCount,
            'negative_count' => (int) $group['negative_count'],
            'measured_count' => (int) $group['measured_count'],
            'total' => $candidateCount,
            'passed_rate' => $passedRate,
            'baseline_case_count' => $baselineCount,
            'baseline_passed_rate' => $baselineRate,
            'measured_lift' => $baselineRate === null ? null : round($passedRate - $baselineRate, 4),
        ];
    }

    /**
     * @param  array<string,AiLearningCandidate>  $candidateById
     */
    private function candidateForFeedback(AiRagFeedbackEvent $event, array $candidateById): ?AiLearningCandidate
    {
        $id = trim((string) $event->memory_candidate_id);

        return $id === '' ? null : ($candidateById[$id] ?? null);
    }

    private function candidateMemoryType(AiLearningCandidate $candidate): string
    {
        $value = trim((string) ($candidate->memory_type ?? ''));

        return $value === '' ? 'unknown' : $value;
    }

    private function candidateFlowId(AiLearningCandidate $candidate): string
    {
        $value = trim((string) ($candidate->outcome?->flow_id ?? data_get($candidate->payload, 'flow_id', '')));

        return $value === '' ? 'unknown' : $value;
    }

    private function candidateScope(AiLearningCandidate $candidate): string
    {
        $value = trim((string) ($candidate->scope ?? ''));

        return $value === '' ? 'global' : $value;
    }

    private function candidatePromoted(AiLearningCandidate $candidate): bool
    {
        if ((bool) $candidate->promotion_allowed) {
            return true;
        }

        return in_array(strtolower(trim((string) $candidate->status)), ['promoted', 'approved', 'accepted', 'applied'], true)
            || in_array(strtolower(trim((string) $candidate->decision)), ['promoted', 'promote', 'approved', 'accepted', 'applied'], true);
    }

    private function feedbackPassed(AiRagFeedbackEvent $event): bool
    {
        return strtolower(trim((string) $event->outcome_status)) === 'passed';
    }
}
