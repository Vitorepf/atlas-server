<?php

namespace App\Services\Ai\Evidence;

use App\Models\AiClaim;
use Illuminate\Support\Str;

class ClaimVerificationService
{
    public const TYPE_VERIFIED = 'verified';

    public const TYPE_SUPPORTED = 'supported';

    public const TYPE_INFERRED = 'inferred';

    public const TYPE_UNCERTAIN = 'uncertain';

    public const TYPE_BLOCKED = 'blocked';

    public const TYPE_SUPERIORITY = 'superiority';

    public const ALLOWED_TYPES = [
        self::TYPE_VERIFIED,
        self::TYPE_SUPPORTED,
        self::TYPE_INFERRED,
        self::TYPE_UNCERTAIN,
        self::TYPE_BLOCKED,
        self::TYPE_SUPERIORITY,
    ];

    public const STATUS_UNVERIFIED = 'unverified';

    public const STATUS_SUPPORTED = 'supported';

    public const STATUS_CONTRADICTED = 'contradicted';

    public const STATUS_INSUFFICIENT = 'insufficient';

    public const STATUS_BLOCKED = 'blocked';

    public const RISK_LOW = 'low';

    public const RISK_MEDIUM = 'medium';

    public const RISK_HIGH = 'high';

    public const RISK_CRITICAL = 'critical';

    public const ALLOWED_RISK = [
        self::RISK_LOW,
        self::RISK_MEDIUM,
        self::RISK_HIGH,
        self::RISK_CRITICAL,
    ];

    public function __construct(private readonly AuditEventService $auditEvents) {}

    /**
     * @param  array<string,mixed>  $args
     */
    public function register(array $args): AiClaim
    {
        $text = (string) ($args['claim_text'] ?? '');
        if ($text === '') {
            throw new \InvalidArgumentException('claim_text cannot be empty.');
        }
        $type = (string) ($args['claim_type'] ?? self::TYPE_INFERRED);
        if (! in_array($type, self::ALLOWED_TYPES, true)) {
            throw new \InvalidArgumentException("invalid claim_type [{$type}]");
        }
        $risk = (string) ($args['risk_level'] ?? self::RISK_LOW);
        if (! in_array($risk, self::ALLOWED_RISK, true)) {
            throw new \InvalidArgumentException("invalid risk_level [{$risk}]");
        }
        $confidence = $args['confidence'] ?? null;
        if ($confidence !== null) {
            $confidence = (float) $confidence;
            if ($confidence < 0.0 || $confidence > 1.0) {
                throw new \InvalidArgumentException('confidence must be between 0.0 and 1.0');
            }
        }

        /** @var array<int,mixed> $evidenceRefs */
        $evidenceRefs = (array) ($args['evidence_refs'] ?? []);

        $verificationStatus = $this->classify($type, $risk, $evidenceRefs);

        $hashInput = [
            'claim_text' => $text,
            'claim_type' => $type,
            'risk_level' => $risk,
            'confidence' => $confidence,
            'evidence_refs' => $evidenceRefs,
            'verification_status' => $verificationStatus,
        ];

        $claim = AiClaim::query()->create([
            'uuid' => (string) Str::uuid(),
            'claim_text' => $text,
            'claim_type' => $type,
            'confidence' => $confidence,
            'evidence_refs' => $evidenceRefs !== [] ? $evidenceRefs : null,
            'verification_status' => $verificationStatus,
            'risk_level' => $risk,
            'mission_id' => $args['mission_id'] ?? null,
            'domain_id' => $args['domain_id'] ?? null,
            'claim_hash' => EvidenceCanonicalHash::sha256($hashInput),
        ]);

        $this->auditEvents->record(
            AuditEventService::EVENT_CLAIM_MADE,
            'claim',
            (string) $claim->id,
            [
                'claim_id' => $claim->id,
                'claim_type' => $type,
                'verification_status' => $verificationStatus,
                'risk_level' => $risk,
                'evidence_ref_count' => count($evidenceRefs),
            ],
            missionId: $claim->mission_id,
        );

        return $claim;
    }

    public function revise(AiClaim $claim, string $newStatus, ?string $reason = null): AiClaim
    {
        $allowed = [
            self::STATUS_UNVERIFIED,
            self::STATUS_SUPPORTED,
            self::STATUS_CONTRADICTED,
            self::STATUS_INSUFFICIENT,
            self::STATUS_BLOCKED,
        ];
        if (! in_array($newStatus, $allowed, true)) {
            throw new \InvalidArgumentException("invalid verification_status [{$newStatus}]");
        }
        $previous = $claim->verification_status;
        $claim->verification_status = $newStatus;
        $claim->save();

        $this->auditEvents->record(
            AuditEventService::EVENT_CLAIM_REVISED,
            'claim',
            (string) $claim->id,
            [
                'claim_id' => $claim->id,
                'previous_status' => $previous,
                'new_status' => $newStatus,
                'reason' => $reason,
            ],
            missionId: $claim->mission_id,
        );

        return $claim;
    }

    /**
     * @param  array<int,mixed>  $evidenceRefs
     */
    private function classify(string $type, string $risk, array $evidenceRefs): string
    {
        if ($evidenceRefs === []) {
            if ($type === self::TYPE_SUPERIORITY) {
                return self::STATUS_BLOCKED;
            }

            return in_array($risk, [self::RISK_HIGH, self::RISK_CRITICAL], true)
                ? self::STATUS_INSUFFICIENT
                : self::STATUS_UNVERIFIED;
        }

        if ($type === self::TYPE_SUPERIORITY && ! $this->hasBenchmarkRef($evidenceRefs)) {
            return self::STATUS_INSUFFICIENT;
        }

        return self::STATUS_SUPPORTED;
    }

    /**
     * @param  array<int,mixed>  $evidenceRefs
     */
    private function hasBenchmarkRef(array $evidenceRefs): bool
    {
        foreach ($evidenceRefs as $ref) {
            if (is_array($ref)) {
                $kind = strtolower((string) ($ref['kind'] ?? $ref['type'] ?? ''));
                if ($kind === 'benchmark' || $kind === 'test_result' || $kind === 'gate_run') {
                    return true;
                }
            }
            if (is_string($ref) && str_contains(strtolower($ref), 'benchmark')) {
                return true;
            }
        }

        return false;
    }
}
