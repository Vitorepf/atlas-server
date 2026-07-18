<?php

declare(strict_types=1);

namespace App\Services\Ai\AgenticEngineeringOs;

use App\Services\Ai\Support\AiValueNormalizer;
use InvalidArgumentException;

/**
 * P13 delivery-pack completeness gate.
 *
 * Live consumer: {@see AtlasUniversalGatesEvaluator::deliveryPackCompletenessSignal()}
 * for the universal gate `delivery_pack_completeness_min_0_95`.
 */
final class DeliveryPackCompletenessScorer
{
    public const FIELD_STATUS = 'status';
    public const FIELD_DELIVERY_HASH = 'delivery_hash';
    public const SCHEMA = 'atlas.aaeos.delivery_pack_completeness.v1';

    public const STATUS_PASSED = 'passed';

    public const STATUS_NEEDS_REVIEW = 'needs_review';

    public const STATUS_FAILED = 'failed';

    public const BLOCKER_MISSING_HASH = 'missing_signed_delivery_hash';

    public const BLOCKER_EVIDENCE_REQUIRED = 'evidence_hashes_required_for_changes';
    public const FIELD_RATIO = 'ratio';
    public const FIELD_RECEIPT_PRESENT = 'receipt_present';
    public const FIELD_RISK_REGISTER_PRESENT = 'risk_register_present';
    public const FIELD_BLOCKERS = 'blockers';
    public const FIELD_CHANGED_FILES = 'changed_files';
    public const FIELD_TEST_EVIDENCE = 'test_evidence';
    public const FIELD_FILES_HAVE_EVIDENCE = 'files_have_evidence';
    public const FIELD_TESTS_PRESENT = 'tests_present';
    public const FIELD_EVIDENCE_HASHES = 'evidence_hashes';
    public const FIELD_EVIDENCE_PRESENT = 'evidence_present';
    public const FIELD_FACTORS = 'factors';
    public const FIELD_HASH_SIGNED = 'hash_signed';
    public const FIELD_SCHEMA = 'schema';
    public const FIELD_NO_TEST_REASON = 'no_test_reason';

    /** @var list<string> */
    public const REQUIRED_KEYS = [
        'changed_files',
        'test_evidence',
        self::FIELD_NO_TEST_REASON,
        'evidence_hashes',
        'risk_register_present',
        'receipt_present',
        'delivery_hash',
    ];

    /** @var list<string> */
    public const STATUSES = [
        self::STATUS_PASSED,
        self::STATUS_NEEDS_REVIEW,
        self::STATUS_FAILED,
    ];

    /**
     * P13 delivery-pack completeness gate.
     *
     * Pure reduction over a delivery-pack composition. Each of the five
     * factors contributes an equal weight to the completeness ratio
     * (true_factor_count / 5, rounded to 4 decimals). Two hard gates can
     * force a 'failed' verdict regardless of the ratio: an unsigned
     * delivery hash, and changed files that ship without evidence hashes.
     * Otherwise a perfect ratio is 'passed' and anything short of it is
     * 'needs_review'. Factors are independent: files_have_evidence is
     * satisfied when there is nothing to evidence (changed_files === 0),
     * while evidence_present strictly tracks the evidence_hashes payload.
     *
     * @param  array<string, mixed>  $composition
     * @return array{
     *     schema: string,
     *     ratio: float,
     *     status: string,
     *     factors: array{
     *         files_have_evidence: bool,
     *         tests_present: bool,
     *         evidence_present: bool,
     *         receipt_present: bool,
     *         risk_register_present: bool
     *     },
     *     blockers: array<int, string>,
     *     hash_signed: bool
     * }
     *
     * @throws InvalidArgumentException
     */
    public function score(array $composition): array
    {
        $this->guard($composition);

        $changedFiles = max(0, $this->intValue($composition[self::FIELD_CHANGED_FILES]));
        $testEvidence = $this->arrayValue($composition[self::FIELD_TEST_EVIDENCE]);
        $evidenceHashes = $this->arrayValue($composition[self::FIELD_EVIDENCE_HASHES]);
        $riskRegisterPresent = $this->boolValue($composition[self::FIELD_RISK_REGISTER_PRESENT]);
        $receiptPresent = $this->boolValue($composition[self::FIELD_RECEIPT_PRESENT]);

        $hashSigned = AiValueNormalizer::trimmedStringOrNull($composition[self::FIELD_DELIVERY_HASH] ?? null) !== null;
        $hasEvidenceHashes = $evidenceHashes !== [];

        $factors = [
            self::FIELD_FILES_HAVE_EVIDENCE => $changedFiles === 0 ? true : $hasEvidenceHashes,
            self::FIELD_TESTS_PRESENT => $testEvidence !== [],
            self::FIELD_EVIDENCE_PRESENT => $hasEvidenceHashes,
            self::FIELD_RECEIPT_PRESENT => $receiptPresent,
            self::FIELD_RISK_REGISTER_PRESENT => $riskRegisterPresent,
        ];

        $ratio = $this->computeRatio($factors);

        $blockers = [];

        if (! $hashSigned) {
            $blockers[] = self::BLOCKER_MISSING_HASH;
        }

        if ($changedFiles > 0 && ! $hasEvidenceHashes) {
            $blockers[] = self::BLOCKER_EVIDENCE_REQUIRED;
        }

        return [
            self::FIELD_SCHEMA => self::SCHEMA,
            self::FIELD_RATIO => $ratio,
            self::FIELD_STATUS => $this->resolveStatus($blockers, $ratio),
            self::FIELD_FACTORS => $factors,
            self::FIELD_BLOCKERS => $blockers,
            self::FIELD_HASH_SIGNED => $hashSigned,
        ];
    }

    /**
     * Universal-gate floor: status passed AND ratio >= $minRatio.
     *
     * @param  array<string, mixed>  $composition
     */
    public function passesMin(array $composition, float $minRatio = 0.95): bool
    {
        $report = $this->score($composition);

        return ($report[self::FIELD_STATUS] ?? '') === self::STATUS_PASSED
            && (AiValueNormalizer::finiteFloatOrNull($report[self::FIELD_RATIO] ?? null) ?? 0.0) >= $minRatio;
    }

    /**
     * @param  array<string, bool>  $factors
     */
    private function computeRatio(array $factors): float
    {
        $total = count($factors);

        if ($total === 0) {
            return 0.0;
        }

        $satisfied = 0;

        foreach ($factors as $factor) {
            if ($factor === true) {
                $satisfied++;
            }
        }

        return round($satisfied / $total, 4);
    }

    /**
     * @param  array<int, string>  $blockers
     */
    private function resolveStatus(array $blockers, float $ratio): string
    {
        if ($blockers !== []) {
            return self::STATUS_FAILED;
        }

        if ($ratio >= 1.0) {
            return self::STATUS_PASSED;
        }

        return self::STATUS_NEEDS_REVIEW;
    }

    /**
     * @param  array<string, mixed>  $composition
     *
     * @throws InvalidArgumentException
     */
    private function guard(array $composition): void
    {
        if ($composition === []) {
            throw new InvalidArgumentException('Delivery-pack composition must not be empty.');
        }

        foreach (self::REQUIRED_KEYS as $key) {
            if (! array_key_exists($key, $composition)) {
                throw new InvalidArgumentException(
                    'Delivery-pack composition is missing required key: '.$key.'.'
                );
            }
        }
    }

    /**
     * @param  mixed  $value
     */
    private function intValue($value): int
    {
        return (int) (AiValueNormalizer::finiteFloatOrNull($value) ?? 0);
    }

    /**
     * @param  mixed  $value
     * @return array<int|string, mixed>
     */
    private function arrayValue($value): array
    {
        return AiValueNormalizer::arrayOrEmpty($value);
    }

    /**
     * @param  mixed  $value
     */
    private function boolValue($value): bool
    {
        return $value === true;
    }
}
