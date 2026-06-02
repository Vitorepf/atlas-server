<?php

declare(strict_types=1);

namespace App\Services\Ai\SoftwareCompanyStewardship\AreaFocusLoop;

/**
 * S89 — Mission Control Human Review Closure Gate.
 *
 * Blocks Obra Owner L4+ certification when the Mission Control P14
 * snapshot / review surface is missing or stale. Human oversight is
 * required for L4+; L3 and below never require the P14 surface.
 *
 * Pure decision: every returned field is computed from the method
 * inputs. The caller supplies the snapshot age (no clock is read here).
 */
final class MissionControlHumanReviewClosureGate
{
    private const SCHEMA_VERSION = 'atlas.aaeos.mission_control_human_review_closure.v1';

    /**
     * Lowest autonomy level at which the P14 human-review surface is required.
     */
    private const HUMAN_REVIEW_REQUIRED_FROM_LEVEL = 4;

    /**
     * A snapshot older than this many seconds is treated as stale.
     */
    private const MAX_SNAPSHOT_AGE_SECONDS = 86400;

    private const STATUS_NOT_REQUIRED = 'not_required';
    private const STATUS_BLOCKED_MISSING_SNAPSHOT = 'blocked_missing_snapshot';
    private const STATUS_BLOCKED_STALE_SNAPSHOT = 'blocked_stale_snapshot';
    private const STATUS_PENDING_REVIEW = 'pending_review';
    private const STATUS_SATISFIED = 'satisfied';

    /**
     * @param  int|string  $level  Autonomy level (int tier or label like "L4").
     * @param  array<string, mixed>  $snapshot  Mission Control P14 snapshot/review surface.
     * @return array{
     *     schema_version: string,
     *     level: int,
     *     mission_control_snapshot_ref: string,
     *     human_review_required: bool,
     *     human_review_status: string,
     *     snapshot_present: bool,
     *     snapshot_stale: bool,
     *     certification_allowed: bool,
     *     blockers: list<string>
     * }
     */
    public function evaluate(int|string $level, array $snapshot): array
    {
        $normalizedLevel = $this->normalizeLevel($level);
        $reviewRequired = $normalizedLevel >= self::HUMAN_REVIEW_REQUIRED_FROM_LEVEL;

        $snapshotRef = $this->snapshotRef($snapshot);
        $snapshotPresent = $snapshotRef !== '';
        $snapshotStale = $snapshotPresent && $this->isStale($snapshot);
        $reviewApproved = $this->isReviewApproved($snapshot);

        $blockers = [];

        if (! $reviewRequired) {
            $status = self::STATUS_NOT_REQUIRED;
        } elseif (! $snapshotPresent) {
            $status = self::STATUS_BLOCKED_MISSING_SNAPSHOT;
            $blockers[] = 'mission_control_snapshot_missing';
        } elseif ($snapshotStale) {
            $status = self::STATUS_BLOCKED_STALE_SNAPSHOT;
            $blockers[] = 'mission_control_snapshot_stale';
        } elseif (! $reviewApproved) {
            $status = self::STATUS_PENDING_REVIEW;
            $blockers[] = 'human_review_not_completed';
        } else {
            $status = self::STATUS_SATISFIED;
        }

        $certificationAllowed = $status === self::STATUS_NOT_REQUIRED
            || $status === self::STATUS_SATISFIED;

        return [
            'schema_version' => self::SCHEMA_VERSION,
            'level' => $normalizedLevel,
            'mission_control_snapshot_ref' => $snapshotRef,
            'human_review_required' => $reviewRequired,
            'human_review_status' => $status,
            'snapshot_present' => $snapshotPresent,
            'snapshot_stale' => $snapshotStale,
            'certification_allowed' => $certificationAllowed,
            'blockers' => $blockers,
        ];
    }

    private function normalizeLevel(int|string $level): int
    {
        if (is_int($level)) {
            return max(0, $level);
        }

        $digits = preg_replace('/[^0-9]/', '', $level);

        if ($digits === '' || $digits === null) {
            return 0;
        }

        return max(0, (int) $digits);
    }

    /**
     * @param  array<string, mixed>  $snapshot
     */
    private function snapshotRef(array $snapshot): string
    {
        foreach (['mission_control_snapshot_ref', 'snapshot_ref', 'ref'] as $key) {
            if (! array_key_exists($key, $snapshot)) {
                continue;
            }

            $value = $snapshot[$key];

            if (is_string($value)) {
                return trim($value);
            }

            if (is_int($value) || is_float($value)) {
                return (string) $value;
            }
        }

        return '';
    }

    /**
     * @param  array<string, mixed>  $snapshot
     */
    private function isStale(array $snapshot): bool
    {
        if (($snapshot['stale'] ?? false) === true) {
            return true;
        }

        if (array_key_exists('fresh', $snapshot) && $snapshot['fresh'] === false) {
            return true;
        }

        if (array_key_exists('age_seconds', $snapshot)) {
            $age = $snapshot['age_seconds'];

            if (is_int($age)) {
                return $age > self::MAX_SNAPSHOT_AGE_SECONDS;
            }

            if (is_float($age)) {
                // A NaN age is not a valid freshness proof. INF already trips the
                // stale comparison, but NaN compares false against every bound and
                // would otherwise fail open and silently satisfy the L4+ gate, so
                // an uncomputable age takes the fail-closed (stale) path.
                if (is_nan($age)) {
                    return true;
                }

                return $age > self::MAX_SNAPSHOT_AGE_SECONDS;
            }

            // A numeric-string age (heterogeneous callers / JSON-as-text) must
            // still be honoured; otherwise a genuinely stale snapshot would
            // fail open and wrongly satisfy the L4+ human-review gate.
            if (is_string($age) && is_numeric(trim($age))) {
                return (float) trim($age) > self::MAX_SNAPSHOT_AGE_SECONDS;
            }
        }

        return false;
    }

    /**
     * @param  array<string, mixed>  $snapshot
     */
    private function isReviewApproved(array $snapshot): bool
    {
        return ($snapshot['reviewed'] ?? false) === true
            || ($snapshot['review_approved'] ?? false) === true;
    }
}
