<?php

declare(strict_types=1);

namespace App\Services\Ai\SelfConstruction\Maestro\Pinning;

/**
 * Decides whether a worker may claim a (possibly pinned) task. Consumes ONLY
 * the pinning registry — does not touch the queue. Pure FACT-driven outcome.
 */
final class AtlasMaestroTaskPinningPolicy
{
    public const DECISION_ALLOW = 'allow';
    public const DECISION_REFUSE = 'refuse';
    public const REASON_NO_PIN = 'no_pin';
    public const REASON_PIN_MATCH = 'pin_match';
    public const REASON_PIN_CONFLICT = 'pinned_to_other_worker';
    public const REASON_INVALID_WORKER = 'invalid_worker_id';
    public const REASON_PIN_EXPIRED = 'pin_expired';

    public const REQUEST_ALLOW = 'allow';
    public const REQUEST_REFUSE = 'refuse';

    /** the ONLY legitimate pin-request reasons — task pinning is never a vague preference. */
    public const ALLOWED_REASON_CATEGORIES = ['capability_fit', 'continuity', 'recovery'];

    public const REQUEST_REASON_VAGUE_CATEGORY = 'vague_or_unlisted_reason_category';
    public const REQUEST_REASON_INVALID_TTL = 'ttl_expired_or_non_positive';
    public const REQUEST_REASON_STARVATION_RISK = 'starvation_risk_ttl_too_long';
    public const REQUEST_REASON_APPROVED = 'capability_fit_or_continuity_or_recovery';

    private const MIN_REQUEST_TTL_SECONDS = 1;

    /** beyond this, a pin stops being a bounded capability-fit/continuity/recovery aid and
     *  starts being permanent starvation of every other worker for this task. */
    private const MAX_SAFE_TTL_SECONDS = 21_600; // 6 hours

    public function __construct(
        private readonly AtlasMaestroTaskPinningRegistry $registry,
        private readonly ?\Closure $now = null,
    ) {}

    /**
     * @return array{decision:string, reason:string, task_packet_id:string, worker_id:string, pinned_worker_id:?string}
     */
    public function decide(string $taskPacketId, string $workerId): array
    {
        $base = [
            'pinned_worker_id' => null,
            'task_packet_id' => $taskPacketId,
            'worker_id' => $workerId,
        ];

        if (trim($workerId) === '') {
            return $base + ['decision' => self::DECISION_REFUSE, 'reason' => self::REASON_INVALID_WORKER];
        }

        $pin = $this->registry->lookup($taskPacketId);
        if ($pin === null) {
            return $base + ['decision' => self::DECISION_ALLOW, 'reason' => self::REASON_NO_PIN];
        }

        $expiredAt = (string) ($pin['expired_at'] ?? '');
        if ($expiredAt !== '') {
            $now = $this->now !== null ? (string) ($this->now)() : gmdate('Y-m-d\TH:i:s\Z');
            if (strtotime($expiredAt) !== false && strtotime($now) !== false && strtotime($expiredAt) <= strtotime($now)) {
                return $base + ['decision' => self::DECISION_ALLOW, 'reason' => self::REASON_PIN_EXPIRED];
            }
        }

        $pinned = (string) ($pin['worker_id'] ?? '');
        if ($pinned === $workerId) {
            return ['pinned_worker_id' => $pinned, 'task_packet_id' => $taskPacketId, 'worker_id' => $workerId,
                'decision' => self::DECISION_ALLOW, 'reason' => self::REASON_PIN_MATCH];
        }

        return ['pinned_worker_id' => $pinned, 'task_packet_id' => $taskPacketId, 'worker_id' => $workerId,
            'decision' => self::DECISION_REFUSE, 'reason' => self::REASON_PIN_CONFLICT];
    }

    /**
     * Governs whether a NEW pin may be CREATED at all — distinct from decide(), which governs
     * whether an EXISTING pin allows a claim. Pinning is allowed only for a named capability-fit,
     * continuity or recovery reason with a bounded ttl; a vague preference or an unbounded/expired
     * ttl is refused before it ever reaches the registry, so pinning can never become permanent
     * starvation of every other worker for this task.
     *
     * @param  array{reason_category?:string, ttl_seconds?:int}  $request
     * @return array{decision:string, reason:string, reason_category:string, ttl_seconds:int}
     */
    public function evaluatePinRequest(array $request): array
    {
        $category = (string) ($request['reason_category'] ?? '');
        $ttlSeconds = (int) ($request['ttl_seconds'] ?? 0);
        $base = ['reason_category' => $category, 'ttl_seconds' => $ttlSeconds];

        if (! in_array($category, self::ALLOWED_REASON_CATEGORIES, true)) {
            return $base + ['decision' => self::REQUEST_REFUSE, 'reason' => self::REQUEST_REASON_VAGUE_CATEGORY];
        }

        if ($ttlSeconds < self::MIN_REQUEST_TTL_SECONDS) {
            return $base + ['decision' => self::REQUEST_REFUSE, 'reason' => self::REQUEST_REASON_INVALID_TTL];
        }

        if ($ttlSeconds > self::MAX_SAFE_TTL_SECONDS) {
            return $base + ['decision' => self::REQUEST_REFUSE, 'reason' => self::REQUEST_REASON_STARVATION_RISK];
        }

        return $base + ['decision' => self::REQUEST_ALLOW, 'reason' => self::REQUEST_REASON_APPROVED];
    }

    /**
     * Enriched pin evaluation: pin_allowed, pin_ttl_seconds, release_reason, safety_evidence_required.
     *
     * @param  array{reason_category?:string, ttl_seconds?:int, worker_fit?:float, safety_evidence?:list<string>}  $request
     * @return array{pin_allowed:bool, pin_ttl_seconds:int, release_reason:string, safety_evidence_required:bool, decision:string, reason:string, reason_category:string, ttl_seconds:int}
     */
    public function evaluatePinWithContract(array $request): array
    {
        $base = $this->evaluatePinRequest($request);
        $category = (string) ($request['reason_category'] ?? '');
        $ttlSeconds = (int) ($request['ttl_seconds'] ?? 0);
        $workerFit = (float) ($request['worker_fit'] ?? 0.0);
        $safetyEvidence = (array) ($request['safety_evidence'] ?? []);

        $pinAllowed = $base['decision'] === self::REQUEST_ALLOW;
        $releaseReason = '';
        $safetyEvidenceRequired = false;

        // Determine release reason
        if (! $pinAllowed) {
            $releaseReason = $base['reason'];
        }

        // Safety evidence is required for capability_fit pins
        if ($category === 'capability_fit') {
            $safetyEvidenceRequired = true;
            // Reject if no safety evidence provided for capability_fit
            if ($pinAllowed && $safetyEvidence === []) {
                $pinAllowed = false;
                $releaseReason = 'capability_fit_requires_safety_evidence';
            }
            // Reject if worker fit drops below threshold
            if ($pinAllowed && $workerFit < 0.5) {
                $pinAllowed = false;
                $releaseReason = 'worker_fit_below_threshold';
            }
        }

        // Release pin when TTL is zero or negative
        if ($pinAllowed && $ttlSeconds <= 0) {
            $pinAllowed = false;
            $releaseReason = 'ttl_expired_or_non_positive';
        }

        return [
            'pin_allowed' => $pinAllowed,
            'pin_ttl_seconds' => $ttlSeconds,
            'release_reason' => $releaseReason,
            'safety_evidence_required' => $safetyEvidenceRequired,
            'decision' => $base['decision'],
            'reason' => $base['reason'],
            'reason_category' => $base['reason_category'],
            'ttl_seconds' => $base['ttl_seconds'],
        ];
    }
}
