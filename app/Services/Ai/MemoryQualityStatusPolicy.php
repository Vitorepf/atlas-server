<?php

namespace App\Services\Ai;

final class MemoryQualityStatusPolicy
{
    private const STATUS_EMPTY = 'empty';

    private const STATUS_CRITICAL = 'critical';

    private const STATUS_NEEDS_REVIEW = 'needs_review';

    private const STATUS_WATCH = 'watch';

    private const STATUS_READY = 'ready';

    private const CRITICAL_SCORE_FLOOR = 50;

    private const NEEDS_REVIEW_SCORE_FLOOR = 70;

    private const WATCH_SCORE_FLOOR = 85;

    /**
     * @return array{status:string,ok:bool,injection_allowed:bool,reason:string}
     */
    public static function classify(int $activeCount, int $compositeScore, bool $hasCriticalIssue): array
    {
        [$status, $reason] = self::resolve($activeCount, $compositeScore, $hasCriticalIssue);

        return [
            'status' => $status,
            'ok' => ! in_array($status, [self::STATUS_EMPTY, self::STATUS_CRITICAL], true),
            'injection_allowed' => in_array($status, [self::STATUS_WATCH, self::STATUS_READY], true),
            'reason' => $reason,
        ];
    }

    /**
     * @return array{0:string,1:string}
     */
    private static function resolve(int $activeCount, int $compositeScore, bool $hasCriticalIssue): array
    {
        if ($activeCount < 1) {
            return [self::STATUS_EMPTY, 'no_active_memory'];
        }

        if ($hasCriticalIssue) {
            return [self::STATUS_CRITICAL, 'critical_issue_present'];
        }

        if ($compositeScore < self::CRITICAL_SCORE_FLOOR) {
            return [self::STATUS_CRITICAL, 'composite_score_below_critical_floor'];
        }

        if ($compositeScore < self::NEEDS_REVIEW_SCORE_FLOOR) {
            return [self::STATUS_NEEDS_REVIEW, 'composite_score_below_needs_review_floor'];
        }

        if ($compositeScore < self::WATCH_SCORE_FLOOR) {
            return [self::STATUS_WATCH, 'composite_score_below_watch_floor'];
        }

        return [self::STATUS_READY, 'composite_score_meets_ready_floor'];
    }
}
