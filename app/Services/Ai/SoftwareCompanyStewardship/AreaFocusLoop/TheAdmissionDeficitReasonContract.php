<?php

declare(strict_types=1);

namespace App\Services\Ai\SoftwareCompanyStewardship\AreaFocusLoop;

/**
 * Minimal data contract for the admission-deficit reason attached to a
 * {@see LoopPreflightCycleFirewallService} backlog_exhausted preflight block.
 * Step 1 of 3: shape only — no firewall service wiring in this class.
 */
final class TheAdmissionDeficitReasonContract
{
    public const SCHEMA = 'atlas.software_company_stewardship.admission_deficit_reason.v1';

    public const AP806_CANONICAL = 'docs/ap/AP-806-loop-autonomy-certification-contract.md';

    public const CONTRACT_ID = 'admission_deficit_reason';

    public const PREFLIGHT_BLOCK_STATUS = LoopPreflightCycleFirewallService::BLOCK_BACKLOG_EXHAUSTED;

    public const REASON_ALL_REVIEW_LOCKED = 'all_review_locked';

    public const REASON_ALL_AUTHORITY_GATED = 'all_authority_gated';

    public const REASON_ALL_ROUTINE_TEST = 'all_routine_test';

    public const REASON_GENUINELY_EMPTY = 'genuinely_empty_backlog';

    public const REASON_MIXED = 'mixed_rejection_profile';

    public const BUCKET_REVIEW_LOCKED = 'review_locked';

    public const BUCKET_AUTHORITY_GATED = 'authority_gated';

    public const BUCKET_ROUTINE_TEST = 'routine_test';

    public const BUCKET_OTHER = 'other';

    /**
     * @param  list<string>  $selectionRejectionReasons
     */
    private function __construct(
        public readonly string $areaId,
        public readonly string $focus,
        public readonly array $selectionRejectionReasons,
        public readonly string $admissionDeficitReason,
        public readonly int $candidatesConsidered,
    ) {}

    public static function defaults(
        string $areaId = 'agentic_engineering_os',
        string $focus = 'dev_forge',
    ): self {
        return new self(
            areaId: $areaId,
            focus: $focus,
            selectionRejectionReasons: [],
            admissionDeficitReason: self::REASON_GENUINELY_EMPTY,
            candidatesConsidered: 0,
        );
    }

    /**
     * @param  array<string,mixed>  $input
     */
    public static function fromArray(array $input): self
    {
        $reasons = array_values(array_filter(
            (array) ($input['selection_rejection_reasons'] ?? []),
            static fn (mixed $reason): bool => is_string($reason) && trim($reason) !== '',
        ));

        $candidatesConsidered = max(0, (int) ($input['candidates_considered'] ?? count($reasons)));

        return new self(
            areaId: trim((string) ($input['area_id'] ?? 'agentic_engineering_os')),
            focus: trim((string) ($input['focus'] ?? 'dev_forge')),
            selectionRejectionReasons: $reasons,
            admissionDeficitReason: self::resolveReason($reasons, $candidatesConsidered),
            candidatesConsidered: $candidatesConsidered,
        );
    }

    /**
     * @return array<string,mixed>
     */
    public function toArray(): array
    {
        $buckets = self::bucketCounts($this->selectionRejectionReasons);

        return [
            'schema_version' => self::SCHEMA,
            'contract_id' => self::CONTRACT_ID,
            'ap806_canonical' => self::AP806_CANONICAL,
            'preflight_block_status' => self::PREFLIGHT_BLOCK_STATUS,
            'area_id' => $this->areaId,
            'focus' => $this->focus,
            'inputs' => [
                'selection_rejection_reasons' => $this->selectionRejectionReasons,
                'candidates_considered' => $this->candidatesConsidered,
            ],
            'outputs' => [
                'admission_deficit_reason' => $this->admissionDeficitReason,
                'rejection_bucket_counts' => $buckets,
                'surfaces_operator_actionable_reason' => $this->admissionDeficitReason !== self::REASON_MIXED,
            ],
        ];
    }

    /**
     * @param  list<string>  $reasons
     */
    private static function resolveReason(array $reasons, int $candidatesConsidered): string
    {
        if ($reasons === [] && $candidatesConsidered === 0) {
            return self::REASON_GENUINELY_EMPTY;
        }

        if ($reasons === []) {
            return self::REASON_MIXED;
        }

        $buckets = self::bucketCounts($reasons);
        $nonZeroBuckets = array_keys(array_filter($buckets, static fn (int $count): bool => $count > 0));

        if (count($nonZeroBuckets) !== 1) {
            return self::REASON_MIXED;
        }

        return match ($nonZeroBuckets[0]) {
            self::BUCKET_REVIEW_LOCKED => self::REASON_ALL_REVIEW_LOCKED,
            self::BUCKET_AUTHORITY_GATED => self::REASON_ALL_AUTHORITY_GATED,
            self::BUCKET_ROUTINE_TEST => self::REASON_ALL_ROUTINE_TEST,
            default => self::REASON_MIXED,
        };
    }

    /**
     * @param  list<string>  $reasons
     * @return array<string,int>
     */
    private static function bucketCounts(array $reasons): array
    {
        $counts = [
            self::BUCKET_REVIEW_LOCKED => 0,
            self::BUCKET_AUTHORITY_GATED => 0,
            self::BUCKET_ROUTINE_TEST => 0,
            self::BUCKET_OTHER => 0,
        ];

        foreach ($reasons as $reason) {
            $normalized = strtolower(trim($reason));
            $counts[self::classifyReason($normalized)]++;
        }

        return $counts;
    }

    private static function classifyReason(string $reason): string
    {
        if (str_contains($reason, 'review_locked')
            || str_contains($reason, 'review-locked')) {
            return self::BUCKET_REVIEW_LOCKED;
        }

        if (in_array($reason, AreaFocusSelfConstructionAdmissionBridgeService::ADMISSIBLE_REJECTION_REASONS, true)
            || str_starts_with($reason, 'factory_max_rejects_')
            || str_contains($reason, 'authority')
            || str_contains($reason, 'owner_runtime_')
            || str_contains($reason, 'cross_system')) {
            return self::BUCKET_AUTHORITY_GATED;
        }

        if (str_contains($reason, 'missing_test')
            || str_contains($reason, 'routine_missing_test')
            || str_contains($reason, 'routine test')) {
            return self::BUCKET_ROUTINE_TEST;
        }

        return self::BUCKET_OTHER;
    }
}
