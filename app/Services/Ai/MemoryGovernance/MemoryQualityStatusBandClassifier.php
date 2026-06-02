<?php

declare(strict_types=1);

namespace App\Services\Ai\MemoryGovernance;

/**
 * Pure projection of AtlasMemoryQualityService::status into a structured band.
 *
 * Mirrors the ordered status rules byte-for-byte and derives operator-facing
 * gates (ok, injection_allowed) from the resolved status. No I/O, no clock,
 * no randomness: every field is computed from the method inputs.
 */
final class MemoryQualityStatusBandClassifier
{
    private const SCHEMA_VERSION = 'atlas.memory_governance.quality_status_band.v1';

    private const STATUS_EMPTY = 'empty';

    private const STATUS_CRITICAL = 'critical';

    private const STATUS_NEEDS_REVIEW = 'needs_review';

    private const STATUS_WATCH = 'watch';

    private const STATUS_READY = 'ready';

    private const CRITICAL_SCORE_FLOOR = 50;

    private const NEEDS_REVIEW_SCORE_FLOOR = 70;

    private const WATCH_SCORE_FLOOR = 85;

    /**
     * @return array{
     *     schema_version: string,
     *     status: string,
     *     ok: bool,
     *     injection_allowed: bool,
     *     reason: string
     * }
     */
    public function classify(int $activeCount, int $compositeScore, bool $hasCriticalIssue): array
    {
        [$status, $reason] = $this->resolve($activeCount, $compositeScore, $hasCriticalIssue);

        $ok = ! in_array($status, [self::STATUS_EMPTY, self::STATUS_CRITICAL], true);
        $injectionAllowed = in_array($status, [self::STATUS_WATCH, self::STATUS_READY], true);

        return [
            'schema_version' => self::SCHEMA_VERSION,
            'status' => $status,
            'ok' => $ok,
            'injection_allowed' => $injectionAllowed,
            'reason' => $reason,
        ];
    }

    /**
     * @return array{0: string, 1: string}
     */
    private function resolve(int $activeCount, int $compositeScore, bool $hasCriticalIssue): array
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
