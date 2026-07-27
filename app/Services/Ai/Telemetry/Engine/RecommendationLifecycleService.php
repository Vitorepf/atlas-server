<?php

namespace App\Services\Ai\Telemetry\Engine;

use App\Models\AiPerformanceRecommendation;
use App\Services\Ai\Telemetry\Engine\Dto\DiagnosticFinding;
use App\Services\Ai\Telemetry\Engine\Dto\DiagnosticResult;
use App\Services\Ai\Telemetry\Engine\Dto\RecommendationResult;
use App\Services\Ai\Telemetry\Engine\Dto\ReportContext;
use App\Services\Ai\Telemetry\Engine\Dto\StatisticalResult;
use App\Services\Ai\Support\DatabaseTableAvailability;
use Carbon\CarbonImmutable;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Log;
use Illuminate\Validation\ValidationException;
use Throwable;

/**
 * Layer 5 — Recommendation Lifecycle.
 *
 * Converts DiagnosticFindings into tracked recommendations with state machine.
 * Persistence enables: dedup across days (don't re-emit yesterday's recommendation),
 * supersession (more specific dimension wins), measurement (compare metric pre/post fix),
 * effectiveness learning (which recommendation kinds historically work).
 *
 * State machine (legal transitions only):
 *   proposed → acknowledged | applied | rejected | snoozed | superseded | expired | self_healed
 *   acknowledged → in_progress | applied | rejected | snoozed | superseded | expired
 *   in_progress → applied | rejected | snoozed | superseded
 *   applied → measured | rejected
 *   measured → resolved
 *   resolved/rejected/expired/superseded/self_healed → terminal (no further transitions)
 *
 * Per Decisão #13: measurement window varies by kind family (cost: 3d, latency: 7d, quality: 14d).
 * Per Decisão #11: cross-version protection delegated to Statistical layer (we trust input).
 */
class RecommendationLifecycleService
{
    public const TERMINAL_STATES = ['resolved', 'rejected', 'expired', 'superseded', 'self_healed'];
    public const OPEN_STATES = ['proposed', 'acknowledged', 'in_progress', 'applied'];
    private const DEDUPE_STATES = ['proposed', 'acknowledged', 'in_progress', 'applied', 'snoozed'];
    private const LEGAL_TRANSITIONS = [
        'proposed' => ['acknowledged', 'applied', 'rejected', 'snoozed', 'superseded', 'expired', 'self_healed'],
        'acknowledged' => ['in_progress', 'applied', 'rejected', 'snoozed', 'superseded', 'expired'],
        'in_progress' => ['applied', 'rejected', 'snoozed', 'superseded'],
        'snoozed' => ['acknowledged', 'in_progress', 'applied', 'rejected', 'expired'],
        'applied' => ['measured', 'rejected'],
        'measured' => ['resolved'],
    ];

    public function __construct(
        private readonly RecommendationInboxEmitter $inboxEmitter,
    ) {}

    public function recommend(
        ReportContext $ctx,
        DiagnosticResult $diagnostic,
        StatisticalResult $statistical,
        string $userId = 'vitor',
    ): RecommendationResult {
        try {
            return $this->recommendInternal($ctx, $diagnostic, $statistical, $userId);
        } catch (Throwable $e) {
            Log::warning('RecommendationLifecycleService failed', [
                'exception' => $e->getMessage(),
                'trace' => substr((string) $e, 0, 500),
            ]);
            return new RecommendationResult(meta: ['skipped' => true, 'skip_reason' => 'exception:'.class_basename($e)]);
        }
    }

    private function recommendInternal(
        ReportContext $ctx,
        DiagnosticResult $diagnostic,
        StatisticalResult $statistical,
        string $userId,
    ): RecommendationResult {
        if (! DatabaseTableAvailability::has('ai_performance_recommendations')) {
            return new RecommendationResult(meta: ['skipped' => true, 'skip_reason' => 'recommendations_table_missing']);
        }

        $created = [];
        $reaffirmed = [];
        $superseded = [];

        foreach ($diagnostic->findings as $finding) {
            $result = $ctx->runMode === 'dry_run'
                ? $this->previewFinding($ctx, $finding, $statistical, $userId)
                : $this->processFinding($ctx, $finding, $statistical, $userId);
            match ($result['action']) {
                'created' => $created[] = $result['recommendation'],
                'reaffirmed' => $reaffirmed[] = $result['recommendation'],
                'superseded' => $superseded[] = $result['recommendation'],
                default => null,
            };
        }

        return new RecommendationResult(
            created: $created,
            reaffirmed: $reaffirmed,
            superseded: $superseded,
            effectivenessScorecard: $this->effectivenessScorecard($userId),
            meta: [
                'dry_run' => $ctx->runMode === 'dry_run',
                'created_count' => count($created),
                'reaffirmed_count' => count($reaffirmed),
                'superseded_count' => count($superseded),
                'findings_processed' => count($diagnostic->findings),
            ],
        );
    }

    /**
     * Dedup + supersede logic per Agent 5 design:
     *  - Same kind + metric + dimension overlap → reaffirm existing
     *  - More specific dimension (more keys) → supersede less-specific, create new
     *  - No match → create new
     */
    private function processFinding(
        ReportContext $ctx,
        DiagnosticFinding $finding,
        StatisticalResult $statistical,
        string $userId,
    ): array {
        $kind = $this->kindFor($finding);
        $existing = $this->findOpenMatch($userId, $kind, $finding->metric, $finding->attributionDimensions);

        if ($existing !== null) {
            $existingDimsCount = count((array) $existing->target_dimension);
            $newDimsCount = count($finding->attributionDimensions);

            if ($newDimsCount > $existingDimsCount) {
                // New finding is more specific — supersede existing, create new
                $existing->update([
                    'state' => 'superseded',
                    'closed_at' => $ctx->clock,
                    'closed_reason' => 'superseded_by_more_specific',
                    'state_history' => array_merge((array) $existing->state_history, [[
                        'state' => 'superseded',
                        'at' => $ctx->clock->toIso8601String(),
                        'reason' => 'more_specific_dim_arrived',
                    ]]),
                ]);
                $existing->update(['superseded_by_id' => null]); // Will be set after creating new
                $newRec = $this->createRecommendation($ctx, $finding, $statistical, $userId, $kind);
                $existing->update(['superseded_by_id' => $newRec->id]);
                return ['action' => 'superseded', 'recommendation' => $newRec];
            }

            // Same or less-specific — reaffirm
            $existing->update([
                'state_history' => array_merge((array) $existing->state_history, [[
                    'state' => $existing->state,
                    'at' => $ctx->clock->toIso8601String(),
                    'reason' => 'reaffirmed_by_new_finding',
                    'finding_id' => $finding->id,
                ]]),
            ]);
            return ['action' => 'reaffirmed', 'recommendation' => $existing->fresh()];
        }

        $rec = $this->createRecommendation($ctx, $finding, $statistical, $userId, $kind);
        return ['action' => 'created', 'recommendation' => $rec];
    }

    private function previewFinding(
        ReportContext $ctx,
        DiagnosticFinding $finding,
        StatisticalResult $statistical,
        string $userId,
    ): array {
        $kind = $this->kindFor($finding);
        $existing = $this->findOpenMatch($userId, $kind, $finding->metric, $finding->attributionDimensions);

        if ($existing !== null) {
            $existingDimsCount = count((array) $existing->target_dimension);
            $newDimsCount = count($finding->attributionDimensions);

            if ($newDimsCount > $existingDimsCount) {
                return [
                    'action' => 'superseded',
                    'recommendation' => $this->previewRecommendation($ctx, $finding, $statistical, $userId, $kind),
                ];
            }

            return ['action' => 'reaffirmed', 'recommendation' => $existing];
        }

        return [
            'action' => 'created',
            'recommendation' => $this->previewRecommendation($ctx, $finding, $statistical, $userId, $kind),
        ];
    }

    /**
     * @param  array<string,mixed>  $metadata
     */
    public function transition(AiPerformanceRecommendation|string $recommendation, string $state, ?string $reason = null, array $metadata = []): AiPerformanceRecommendation
    {
        $recommendation = $recommendation instanceof AiPerformanceRecommendation
            ? $recommendation
            : AiPerformanceRecommendation::query()->findOrFail($recommendation);

        $state = trim($state);
        $current = (string) $recommendation->state;
        if (in_array($current, self::TERMINAL_STATES, true)) {
            throw ValidationException::withMessages(['state' => 'Recommendation ja esta em estado terminal.']);
        }

        if (! in_array($state, self::LEGAL_TRANSITIONS[$current] ?? [], true)) {
            throw ValidationException::withMessages(['state' => "Transicao {$current} -> {$state} nao permitida."]);
        }

        $clock = CarbonImmutable::now();
        $updates = [
            'state' => $state,
            'state_history' => array_merge((array) $recommendation->state_history, [[
                'state' => $state,
                'at' => $clock->toIso8601String(),
                'reason' => $reason,
                'metadata' => $metadata,
            ]]),
        ];

        if ($state === 'applied') {
            $days = max(1, (int) ($recommendation->measurement_window_days ?? $this->measurementWindowDaysFor((string) $recommendation->kind)));
            $updates['measurement_window_days'] = $days;
            $updates['measurement_due_at'] = $clock->addDays($days);
        }

        if ($state === 'snoozed' && isset($metadata['snoozed_until'])) {
            try {
                $updates['snoozed_until'] = Carbon::parse((string) $metadata['snoozed_until']);
            } catch (\Throwable) {
                $updates['snoozed_until'] = Carbon::now();
            }
        }

        if (in_array($state, self::TERMINAL_STATES, true)) {
            $updates['closed_at'] = $clock;
            $updates['closed_reason'] = $reason ?: $state;
        }

        $recommendation->update($updates);

        return $recommendation->refresh();
    }

    private function findOpenMatch(string $userId, string $kind, string $metric, array $dimensions): ?AiPerformanceRecommendation
    {
        $query = AiPerformanceRecommendation::query()
            ->where('user_id', $userId)
            ->where('kind', $kind)
            ->where('target_metric', $metric)
            ->whereIn('state', self::DEDUPE_STATES);

        return $query->get()->first(function (AiPerformanceRecommendation $r) use ($dimensions): bool {
            $existing = (array) $r->target_dimension;
            // Match: every key/value in $existing must be present in $dimensions OR vice-versa
            return $this->dimensionsOverlap($existing, $dimensions);
        });
    }

    private function dimensionsOverlap(array $a, array $b): bool
    {
        $smaller = count($a) <= count($b) ? $a : $b;
        $larger = count($a) <= count($b) ? $b : $a;
        foreach ($smaller as $k => $v) {
            if (($larger[$k] ?? null) !== $v) {
                return false;
            }
        }
        return ! empty($smaller);
    }

    private function createRecommendation(
        ReportContext $ctx,
        DiagnosticFinding $finding,
        StatisticalResult $statistical,
        string $userId,
        string $kind,
    ): AiPerformanceRecommendation {
        $recommendation = AiPerformanceRecommendation::query()->create(
            $this->recommendationAttributes($ctx, $finding, $statistical, $userId, $kind),
        );

        if (! $ctx->isShadow() && (bool) config('atlas.report.recommendation_inbox_enabled', true)) {
            $this->inboxEmitter->emit($recommendation);
        }

        return $recommendation;
    }

    private function previewRecommendation(
        ReportContext $ctx,
        DiagnosticFinding $finding,
        StatisticalResult $statistical,
        string $userId,
        string $kind,
    ): AiPerformanceRecommendation {
        $recommendation = new AiPerformanceRecommendation();
        $recommendation->forceFill($this->recommendationAttributes($ctx, $finding, $statistical, $userId, $kind));

        return $recommendation;
    }

    private function recommendationAttributes(
        ReportContext $ctx,
        DiagnosticFinding $finding,
        StatisticalResult $statistical,
        string $userId,
        string $kind,
    ): array {
        return [
            'user_id' => $userId,
            'finding_id' => $finding->id,
            'origin_report_date' => $ctx->windowStart->toDateString(),
            'kind' => $kind,
            'target_metric' => $finding->metric,
            'target_dimension' => $finding->attributionDimensions,
            'expected_impact' => [
                'direction' => $finding->direction === 'down' ? 'increase' : 'decrease',
                'magnitude' => $finding->magnitudePct,
                'rationale' => $finding->suggestedActionSeed,
                'confidence' => $finding->confidenceScore,
            ],
            'state' => 'proposed',
            'state_history' => [[
                'state' => 'proposed',
                'at' => $ctx->clock->toIso8601String(),
                'finding_id' => $finding->id,
            ]],
            'baseline_snapshot' => $this->baselineSnapshotFor($finding->metric, $statistical),
            'measurement_window_days' => $this->measurementWindowDaysFor($kind),
            'priority_score' => $this->priorityFromConfidence($finding->confidenceBand),
        ];
    }

    /**
     * Per Decisão #13: measurement window by kind family.
     */
    private function measurementWindowDaysFor(string $kind): int
    {
        $windows = (array) config('atlas.report.recommendation_measurement_window_days', []);
        $family = match (true) {
            str_starts_with($kind, 'cost_') => 'cost',
            str_starts_with($kind, 'latency_') => 'latency',
            str_starts_with($kind, 'quality_') => 'quality',
            default => 'default',
        };
        return (int) ($windows[$family] ?? $windows['default'] ?? 7);
    }

    private function kindFor(DiagnosticFinding $finding): string
    {
        return match ($finding->metric) {
            'final_quality_avg', 'auto_quality_score' => 'quality_drop',
            'final_efficiency_avg' => 'efficiency_drop',
            'first_pass_success_rate' => 'first_pass_regression',
            'needed_remediation_rate' => 'remediation_spike',
            'tool_failure_rate' => 'tool_failure_pattern',
            'permission_denial_rate' => 'permission_denial_spike',
            'app_visible_avg_ms' => 'latency_regression_user_visible',
            default => 'metric_anomaly:'.$finding->metric,
        };
    }

    private function priorityFromConfidence(string $band): int
    {
        return match ($band) {
            'high' => 80,
            'medium' => 60,
            'low' => 40,
            default => 50,
        };
    }

    private function baselineSnapshotFor(string $metric, StatisticalResult $statistical): ?array
    {
        $baseline = collect($statistical->baselines)->firstWhere('metric', $metric);
        return $baseline ?: null;
    }

    /**
     * Effectiveness scorecard: per-kind success rate over last 30 days.
     * Used by Agent 5's ranking + surfaced in next morning report.
     */
    private function effectivenessScorecard(string $userId, int $windowDays = 30): array
    {
        if (! DatabaseTableAvailability::has('ai_performance_recommendations')) {
            return [];
        }

        $since = now()->subDays($windowDays);
        $rows = AiPerformanceRecommendation::query()
            ->where('user_id', $userId)
            ->whereIn('state', ['resolved', 'rejected', 'self_healed'])
            ->where('closed_at', '>=', $since)
            ->get();

        $byKind = $rows->groupBy('kind')->map(function ($group): array {
            $total = $group->count();
            $effective = $group->where('state', 'resolved')->count();
            $selfHealed = $group->where('state', 'self_healed')->count();
            $rejected = $group->where('state', 'rejected')->count();
            return [
                'total' => $total,
                'effective' => $effective,
                'self_healed' => $selfHealed,
                'rejected' => $rejected,
                'effective_rate' => $total > 0 ? round($effective / $total, 4) : null,
                'confidence' => $total >= 10 ? 'high' : ($total >= 3 ? 'low' : 'insufficient'),
            ];
        })->all();

        return [
            'window_days' => $windowDays,
            'by_kind' => $byKind,
            'computed_at' => now()->toIso8601String(),
        ];
    }
}
