<?php

namespace App\Services\Ai\Kernel\Architecture;

use App\Models\AtlasStrategyRivalsReview;
use InvalidArgumentException;

class AtlasRivalsStrategyReviewRecorder
{
    public const SCHEMA_VERSION = 'atlas.rivals_strategy.review_recording.v1';

    /**
     * @param  array<string,mixed>  $input
     * @return array<string,mixed>
     */
    public function record(array $input): array
    {
        $review = $this->review($input);
        $regretScore = $this->score($input['regret_score'] ?? $input['regret'] ?? null, 'regret_score');
        $alignmentScore = $this->score($input['alignment_score'] ?? $input['alignment'] ?? null, 'alignment_score');
        $agencyScore = $this->score($input['agency_score'] ?? $input['agency'] ?? null, 'agency_score');

        $review->forceFill([
            'status' => 'reviewed',
            'reviewed_at' => now(),
            'regret_score' => $regretScore,
            'alignment_score' => $alignmentScore,
            'agency_score' => $agencyScore,
            'outcome_summary' => $this->nullableString($input['outcome_summary'] ?? $input['outcome'] ?? null),
            'metadata' => [
                ...((array) ($review->metadata ?? [])),
                'schema_version' => self::SCHEMA_VERSION,
                'recorded_by' => $this->string($input['recorded_by'] ?? 'atlas.rivals_strategy.review_recorder'),
                'operator_approved' => true,
                'no_external_action' => true,
            ],
        ])->save();

        return [
            'schema_version' => self::SCHEMA_VERSION,
            'status' => 'ok',
            'review_id' => $review->id,
            'case_id' => $review->case_id,
            'horizon_days' => $review->horizon_days,
            'scores' => [
                'regret' => $regretScore,
                'alignment' => $alignmentScore,
                'agency' => $agencyScore,
            ],
            'reviewed_at' => $review->reviewed_at?->toJSON(),
        ];
    }

    /**
     * @param  array<string,mixed>  $input
     */
    private function review(array $input): AtlasStrategyRivalsReview
    {
        $reviewId = $this->string($input['review_id'] ?? '');
        if ($reviewId !== '') {
            $review = AtlasStrategyRivalsReview::query()->find($reviewId);
            if ($review instanceof AtlasStrategyRivalsReview) {
                return $review;
            }

            throw new InvalidArgumentException('Rivals Strategy review not found for review_id.');
        }

        $caseId = $this->string($input['case_id'] ?? '');
        $horizonDays = (int) ($input['horizon_days'] ?? $input['review_horizon'] ?? 0);
        if ($caseId === '' || $horizonDays <= 0) {
            throw new InvalidArgumentException('Either review_id or case_id plus horizon_days is required.');
        }

        $review = AtlasStrategyRivalsReview::query()
            ->where('case_id', $caseId)
            ->where('horizon_days', $horizonDays)
            ->first();

        if (! $review instanceof AtlasStrategyRivalsReview) {
            throw new InvalidArgumentException('Rivals Strategy review not found for case_id and horizon_days.');
        }

        return $review;
    }

    private function score(mixed $value, string $field): int
    {
        if (! is_numeric($value)) {
            throw new InvalidArgumentException("{$field} must be an integer from 0 to 100.");
        }

        $score = (int) $value;
        if ($score < 0 || $score > 100) {
            throw new InvalidArgumentException("{$field} must be an integer from 0 to 100.");
        }

        return $score;
    }

    private function string(mixed $value): string
    {
        return trim((string) $value);
    }

    private function nullableString(mixed $value): ?string
    {
        $string = $this->string($value);

        return $string !== '' ? $string : null;
    }
}
