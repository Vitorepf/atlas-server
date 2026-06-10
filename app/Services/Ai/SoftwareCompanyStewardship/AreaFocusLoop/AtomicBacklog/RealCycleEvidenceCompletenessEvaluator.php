<?php

declare(strict_types=1);

namespace App\Services\Ai\SoftwareCompanyStewardship\AreaFocusLoop\AtomicBacklog;

use App\Services\Ai\SoftwareCompanyStewardship\AreaFocusLoop\AreaFocusLoopPayloadNormalizer;

final class RealCycleEvidenceCompletenessEvaluator
{
    private const SCHEMA_VERSION = 'atlas.loop.real_cycle_evidence_completeness.v1';

    /**
     * @param  array<string, mixed>  $cycle
     * @return array{
     *     schema_version: string,
     *     counted_real: bool,
     *     final_status: string,
     *     missing_receipts: list<string>,
     *     merge_truth_ok: bool,
     *     quality_floor_passed: bool
     * }
     */
    public function evaluate(array $cycle): array
    {
        $missingReceipts = $this->missingRealAttemptReceipts($cycle);

        $qualityFloorPassed = $this->qualityFloorPassed($cycle);
        $mergeTruthOk = $this->mergeTruthOk($cycle);

        $mergeAttempted = $this->isFlagTrue($cycle, 'merge_performed');
        $hasBlockerReceipt = $this->hasNonEmptyString($cycle, 'blocker_receipt_ref');

        $realAttemptComplete = $missingReceipts === [];

        $countedReal = false;
        $finalStatus = 'not_counted';

        if (! $realAttemptComplete) {
            $finalStatus = 'incomplete_real_attempt_evidence';
        } elseif ($mergeAttempted) {
            if ($mergeTruthOk) {
                $countedReal = true;
                $finalStatus = 'counted_real';
            } else {
                $finalStatus = 'merge_truth_violation';
            }
        } elseif ($hasBlockerReceipt) {
            $countedReal = true;
            $finalStatus = 'blocked_honest_after_real_attempt';
        } else {
            $finalStatus = 'no_merge_no_blocker_receipt';
        }

        return [
            'schema_version' => self::SCHEMA_VERSION,
            'counted_real' => $countedReal,
            'final_status' => $finalStatus,
            'missing_receipts' => $missingReceipts,
            'merge_truth_ok' => $mergeTruthOk,
            'quality_floor_passed' => $qualityFloorPassed,
        ];
    }

    /**
     * @param  array<string, mixed>  $cycle
     * @return list<string>
     */
    private function missingRealAttemptReceipts(array $cycle): array
    {
        $missing = [];

        if (! $this->hasNonEmptyString($cycle, 'slice_plan_ref')) {
            $missing[] = 'slice_plan';
        }

        if (! $this->hasNonEmptyList($cycle, 'lane_receipts')) {
            $missing[] = 'lane_receipts';
        }

        if (! $this->providerStateHonest($cycle)) {
            $missing[] = 'provider_honest_state';
        }

        if (! $this->hasNonEmptyString($cycle, 'branch_ref')) {
            $missing[] = 'branch';
        }

        if (! $this->hasNonEmptyString($cycle, 'worktree_ref')) {
            $missing[] = 'worktree';
        }

        if (! $this->hasNonEmptyString($cycle, 'validation_receipt_ref')) {
            $missing[] = 'validation';
        }

        if (! $this->hasNonEmptyString($cycle, 'judge_receipt_ref')) {
            $missing[] = 'judge';
        }

        if (! $this->hasNonEmptyString($cycle, 'evidence_ref')) {
            $missing[] = 'evidence_ref';
        }

        return $missing;
    }

    /**
     * @param  array<string, mixed>  $cycle
     */
    private function providerStateHonest(array $cycle): bool
    {
        if (! $this->isFlagTrue($cycle, 'provider_honest')) {
            return false;
        }

        if ($this->isFlagTrue($cycle, 'provider_invoked')) {
            return $this->hasNonEmptyString($cycle, 'provider_receipt_ref');
        }

        return true;
    }

    /**
     * @param  array<string, mixed>  $cycle
     */
    private function qualityFloorPassed(array $cycle): bool
    {
        return $this->hasNonEmptyString($cycle, 'validation_receipt_ref')
            && $this->isFlagTrue($cycle, 'validation_passed')
            && $this->hasNonEmptyString($cycle, 'judge_receipt_ref')
            && $this->isFlagTrue($cycle, 'judge_passed');
    }

    /**
     * @param  array<string, mixed>  $cycle
     */
    private function mergeTruthOk(array $cycle): bool
    {
        $before = $cycle['main_before'] ?? null;
        $after = $cycle['main_after'] ?? null;

        if (! is_string($before) || $before === '') {
            return false;
        }

        if (! is_string($after) || $after === '') {
            return false;
        }

        return $before !== $after;
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    private function isFlagTrue(array $payload, string $key): bool
    {
        return ($payload[$key] ?? false) === true;
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    private function hasNonEmptyString(array $payload, string $key): bool
    {
        $value = $payload[$key] ?? null;

        return is_string($value) && $value !== '';
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    private function hasNonEmptyList(array $payload, string $key): bool
    {
        return AreaFocusLoopPayloadNormalizer::payloadHasNonEmptyArray($payload, $key);
    }
}
