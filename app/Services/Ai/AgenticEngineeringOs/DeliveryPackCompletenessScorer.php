<?php

declare(strict_types=1);

namespace App\Services\Ai\AgenticEngineeringOs;

use InvalidArgumentException;

final class DeliveryPackCompletenessScorer
{
    private const SCHEMA = 'atlas.aaeos.delivery_pack_completeness.v1';

    private const STATUS_PASSED = 'passed';

    private const STATUS_NEEDS_REVIEW = 'needs_review';

    private const STATUS_FAILED = 'failed';

    private const BLOCKER_MISSING_HASH = 'missing_signed_delivery_hash';

    private const BLOCKER_EVIDENCE_REQUIRED = 'evidence_hashes_required_for_changes';

    private const REQUIRED_KEYS = [
        'changed_files',
        'test_evidence',
        'no_test_reason',
        'evidence_hashes',
        'risk_register_present',
        'receipt_present',
        'delivery_hash',
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

        $changedFiles = max(0, $this->intValue($composition['changed_files']));
        $testEvidence = $this->arrayValue($composition['test_evidence']);
        $evidenceHashes = $this->arrayValue($composition['evidence_hashes']);
        $riskRegisterPresent = $this->boolValue($composition['risk_register_present']);
        $receiptPresent = $this->boolValue($composition['receipt_present']);

        $hashSigned = $this->stringValue($composition['delivery_hash']) !== '';
        $hasEvidenceHashes = $evidenceHashes !== [];

        $factors = [
            'files_have_evidence' => $changedFiles === 0 ? true : $hasEvidenceHashes,
            'tests_present' => $testEvidence !== [],
            'evidence_present' => $hasEvidenceHashes,
            'receipt_present' => $receiptPresent,
            'risk_register_present' => $riskRegisterPresent,
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
            'schema' => self::SCHEMA,
            'ratio' => $ratio,
            'status' => $this->resolveStatus($blockers, $ratio),
            'factors' => $factors,
            'blockers' => $blockers,
            'hash_signed' => $hashSigned,
        ];
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
        return is_int($value) ? $value : (int) $value;
    }

    /**
     * @param  mixed  $value
     * @return array<int|string, mixed>
     */
    private function arrayValue($value): array
    {
        return is_array($value) ? $value : [];
    }

    /**
     * @param  mixed  $value
     */
    private function boolValue($value): bool
    {
        return $value === true;
    }

    /**
     * @param  mixed  $value
     */
    private function stringValue($value): string
    {
        return is_string($value) ? trim($value) : '';
    }
}
