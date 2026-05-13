<?php

namespace App\Services\Ai\Kernel\Architecture;

use App\Models\AtlasStrategyRivalsCase;
use App\Models\AtlasStrategyRivalsReview;
use Carbon\CarbonInterface;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Schema;

class AtlasRivalsStrategyReadModel
{
    public const SCHEMA_VERSION = 'atlas.rivals_strategy.v1';

    /**
     * @return array<string,mixed>
     */
    public function report(?CarbonInterface $since = null, ?CarbonInterface $until = null): array
    {
        $since ??= now()->subDays(365);
        $until ??= now();

        if (! $this->available()) {
            return [
                'available' => false,
                'schema_version' => self::SCHEMA_VERSION,
                'window' => ['since' => $since->toJSON(), 'until' => $until->toJSON()],
                ...$this->emptySummary(),
                'gates' => $this->gates($this->emptySummary(), false),
                'review_signal' => $this->reviewSignal($this->emptySummary(), false),
                'recent_cases' => [],
                'due_reviews' => [],
            ];
        }

        $cases = AtlasStrategyRivalsCase::query()
            ->with('reviews')
            ->whereBetween('created_at', [$since, $until])
            ->latest()
            ->get();
        $dueReviews = AtlasStrategyRivalsReview::query()
            ->whereBetween('review_due_at', [$since, $until])
            ->orderBy('review_due_at')
            ->get();
        $scheduledReviews = $cases
            ->flatMap(fn (AtlasStrategyRivalsCase $case): Collection => $case->reviews)
            ->values();
        $summary = $this->summary($cases, $scheduledReviews);

        return [
            'available' => true,
            'schema_version' => self::SCHEMA_VERSION,
            'window' => ['since' => $since->toJSON(), 'until' => $until->toJSON()],
            ...$summary,
            'gates' => $this->gates($summary, true),
            'review_signal' => $this->reviewSignal($summary, true),
            'recent_cases' => $cases->take(10)->map(fn (AtlasStrategyRivalsCase $case): array => $this->casePayload($case))->values()->all(),
            'due_reviews' => $dueReviews
                ->filter(fn (AtlasStrategyRivalsReview $review): bool => $review->review_due_at <= $until && $review->status === 'pending')
                ->take(10)
                ->map(fn (AtlasStrategyRivalsReview $review): array => $this->reviewPayload($review))
                ->values()
                ->all(),
        ];
    }

    public function benchmarkReady(?CarbonInterface $since = null, ?CarbonInterface $until = null): bool
    {
        $report = $this->report($since, $until);

        return (bool) ($report['available'] ?? false)
            && (int) ($report['case_count'] ?? 0) > 0
            && (int) ($report['scheduled_review_count'] ?? 0) > 0
            && (int) ($report['scored_review_count'] ?? 0) > 0;
    }

    /**
     * @return array<string,mixed>
     */
    public function dueReviews(int $days = 30, int $limit = 20): array
    {
        $days = max(0, min(365, $days));
        $limit = max(1, min(100, $limit));
        $until = now()->addDays($days);

        if (! $this->available()) {
            return [
                'available' => false,
                'schema_version' => self::SCHEMA_VERSION,
                'status' => 'storage_unavailable',
                'days' => $days,
                'due_review_count' => 0,
                'due_reviews' => [],
                'review_signal' => [
                    'status' => 'unknown',
                    'severity' => 'medium',
                    'recommended_action' => 'run_strategy_rivals_migrations',
                ],
            ];
        }

        $reviews = AtlasStrategyRivalsReview::query()
            ->with('case')
            ->where('status', 'pending')
            ->where('review_due_at', '<=', $until)
            ->orderBy('review_due_at')
            ->limit($limit)
            ->get();

        return [
            'available' => true,
            'schema_version' => self::SCHEMA_VERSION,
            'status' => 'ok',
            'days' => $days,
            'limit' => $limit,
            'due_until' => $until->toJSON(),
            'due_review_count' => $reviews->count(),
            'due_reviews' => $reviews
                ->map(fn (AtlasStrategyRivalsReview $review): array => $this->reviewPayload($review))
                ->values()
                ->all(),
            'review_signal' => [
                'status' => $reviews->isEmpty() ? 'ok' : 'warning',
                'severity' => $reviews->isEmpty() ? 'none' : 'low',
                'recommended_action' => $reviews->isEmpty() ? 'no_due_rivals_strategy_reviews' : 'record_due_rivals_strategy_reviews',
            ],
        ];
    }

    private function available(): bool
    {
        return Schema::hasTable('atlas_strategy_rivals_cases')
            && Schema::hasTable('atlas_strategy_rivals_reviews');
    }

    /**
     * @return array<string,mixed>
     */
    private function emptySummary(): array
    {
        return [
            'case_count' => 0,
            'active_case_count' => 0,
            'scheduled_review_count' => 0,
            'pending_review_count' => 0,
            'reviewed_count' => 0,
            'scored_review_count' => 0,
            'average_regret_score' => null,
            'average_alignment_score' => null,
            'average_agency_score' => null,
            'strategy_multiplier_score' => null,
            'horizon_counts' => [],
        ];
    }

    /**
     * @param  Collection<int,AtlasStrategyRivalsCase>  $cases
     * @param  Collection<int,AtlasStrategyRivalsReview>  $reviews
     * @return array<string,mixed>
     */
    private function summary(Collection $cases, Collection $reviews): array
    {
        $scored = $reviews->filter(fn (AtlasStrategyRivalsReview $review): bool => $review->regret_score !== null
            && $review->alignment_score !== null
            && $review->agency_score !== null);

        return [
            'case_count' => $cases->count(),
            'active_case_count' => $cases->where('status', 'active')->count(),
            'scheduled_review_count' => $reviews->count(),
            'pending_review_count' => $reviews->where('status', 'pending')->count(),
            'reviewed_count' => $reviews->where('status', 'reviewed')->count(),
            'scored_review_count' => $scored->count(),
            'average_regret_score' => $this->average($scored->pluck('regret_score')),
            'average_alignment_score' => $this->average($scored->pluck('alignment_score')),
            'average_agency_score' => $this->average($scored->pluck('agency_score')),
            'strategy_multiplier_score' => $this->strategyMultiplierScore($scored),
            'horizon_counts' => $reviews->pluck('horizon_days')->countBy()->all(),
        ];
    }

    /**
     * @param  Collection<int,int|null>  $values
     */
    private function average(Collection $values): ?float
    {
        $values = $values->filter(fn (mixed $value): bool => is_numeric($value));

        return $values->isEmpty() ? null : round((float) $values->avg(), 2);
    }

    /**
     * @param  Collection<int,AtlasStrategyRivalsReview>  $reviews
     */
    private function strategyMultiplierScore(Collection $reviews): ?float
    {
        if ($reviews->isEmpty()) {
            return null;
        }

        $scores = $reviews->map(function (AtlasStrategyRivalsReview $review): float {
            $lowRegret = 100 - (int) $review->regret_score;

            return ($lowRegret * 0.4)
                + ((int) $review->alignment_score * 0.35)
                + ((int) $review->agency_score * 0.25);
        });

        return round((float) $scores->avg(), 2);
    }

    /**
     * @param  array<string,mixed>  $summary
     * @return array<int,array{id:string,status:string,reason:string}>
     */
    private function gates(array $summary, bool $available): array
    {
        return [
            $this->gate('strategy_rivals_storage', $available, 'Rivals Strategy tables must exist.'),
            $this->gate('strategy_case_registered', (int) $summary['case_count'] > 0, 'At least one strategic decision case must be registered.'),
            $this->gate('revisit_schedule_created', (int) $summary['scheduled_review_count'] > 0, 'Cases need 30/90/180/365 day revisit schedule.'),
            $this->gate('review_scores_recorded', (int) $summary['scored_review_count'] > 0, 'At least one revisit must record regret, alignment and agency scores.'),
            $this->gate('agency_score_preserved', ((float) ($summary['average_agency_score'] ?? 0)) >= 70, 'Agency score must stay healthy before P4+ claims.'),
        ];
    }

    /**
     * @return array{id:string,status:string,reason:string}
     */
    private function gate(string $id, bool $passed, string $reason): array
    {
        return ['id' => $id, 'status' => $passed ? 'passed' : 'missing', 'reason' => $reason];
    }

    /**
     * @param  array<string,mixed>  $summary
     * @return array<string,mixed>
     */
    private function reviewSignal(array $summary, bool $available): array
    {
        if (! $available) {
            return [
                'status' => 'unknown',
                'severity' => 'medium',
                'reasons' => ['strategy_rivals_storage_missing'],
                'recommended_action' => 'run_strategy_rivals_migrations',
            ];
        }
        if ((int) $summary['case_count'] === 0) {
            return [
                'status' => 'warning',
                'severity' => 'medium',
                'reasons' => ['no_strategy_rivals_cases'],
                'recommended_action' => 'register_first_strategy_rivals_case',
            ];
        }
        if ((int) $summary['scored_review_count'] === 0) {
            return [
                'status' => 'warning',
                'severity' => 'low',
                'reasons' => ['waiting_for_scored_revisits'],
                'recommended_action' => 'wait_for_30_90_180_365_day_reviews',
            ];
        }
        if (((float) ($summary['average_agency_score'] ?? 0)) < 70) {
            return [
                'status' => 'warning',
                'severity' => 'high',
                'reasons' => ['agency_score_below_threshold'],
                'recommended_action' => 'pause_p4_claims_and_review_operator_agency',
            ];
        }

        return [
            'status' => 'ok',
            'severity' => 'none',
            'reasons' => [],
            'recommended_action' => 'use_strategy_rivals_signal_for_qualitative_level',
        ];
    }

    /**
     * @return array<string,mixed>
     */
    private function casePayload(AtlasStrategyRivalsCase $case): array
    {
        return [
            'id' => $case->id,
            'title' => $case->title,
            'decision_domain' => $case->decision_domain,
            'status' => $case->status,
            'horizon_days' => $case->horizon_days,
            'source_hash' => $case->source_hash,
            'review_count' => $case->reviews->count(),
            'created_at' => $case->created_at?->toJSON(),
        ];
    }

    /**
     * @return array<string,mixed>
     */
    private function reviewPayload(AtlasStrategyRivalsReview $review): array
    {
        return [
            'id' => $review->id,
            'case_id' => $review->case_id,
            'case_title' => (string) data_get($review, 'case.title', ''),
            'horizon_days' => $review->horizon_days,
            'status' => $review->status,
            'review_due_at' => $review->review_due_at?->toJSON(),
            'record_command' => sprintf(
                'php artisan atlas:ai:rivals-strategy record-review --review-id=%s --regret=<0-100> --alignment=<0-100> --agency=<0-100> --json',
                escapeshellarg((string) $review->id),
            ),
        ];
    }
}
