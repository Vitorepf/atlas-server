<?php

declare(strict_types=1);

namespace App\Services\Ai\Programming\AtlasDev\Gate;

use App\Services\Ai\Programming\AtlasDev\Provider\DiffParseResult;
use App\Services\Ai\Programming\AtlasDev\Provider\ProviderCallResult;
use App\Services\Ai\Programming\AtlasDev\Schemas\Components\CompletionSummary;
use App\Services\Ai\Programming\AtlasDev\Schemas\LightTaskContract;
use App\Services\Ai\Programming\AtlasDev\Schemas\ScopeGuardReceipt;

/**
 * Decides the final completion state for the one-call run.
 *
 * Order of precedence (highest first):
 *   - blocked       → diff parser reported MODE_BLOCKED;
 *   - no_patch_needed → diff parser reported MODE_NO_PATCH_NEEDED;
 *   - failed        → scope_guard failed OR verification failed
 *                       OR provider call returned non-ok / invalid diff;
 *   - escalate_forge → reserved (Fatia 5); one-call slice never emits;
 *   - needs_review  → scope needs_review OR verification needs_review
 *                       OR honesty flags surfaced from any layer;
 *   - passed        → everything green AND zero honesty flags.
 *
 * Honesty flag invariant (CompletionSummary): status=passed forbids any
 * honesty flag. This gate downgrades passed→needs_review when flags exist.
 */
final class CompletionStateGate
{
    public function decide(
        LightTaskContract $taskContract,
        ScopeGuardReceipt $scopeReceipt,
        VerificationGateResult $verificationResult,
        ProviderCallResult $callResult,
        DiffParseResult $diffResult,
    ): CompletionDecision {
        $reasons = [];
        $honestyFlags = $verificationResult->honestyFlags;
        $residualRisks = [];

        if ($diffResult->isBlocked()) {
            $reasons[] = 'diff_parser_reported_blocked';

            return new CompletionDecision(
                status: CompletionSummary::STATUS_BLOCKED,
                honestyFlags: [],
                residualRisks: $residualRisks,
                reasons: $reasons,
            );
        }

        if ($diffResult->isNoPatchNeeded()) {
            $reasons[] = 'diff_parser_reported_no_patch_needed';

            return new CompletionDecision(
                status: CompletionSummary::STATUS_NO_PATCH_NEEDED,
                honestyFlags: [],
                residualRisks: $residualRisks,
                reasons: $reasons,
            );
        }

        if (! $callResult->ok()) {
            $reasons[] = 'provider_call_not_ok';
            foreach ($callResult->errors as $err) {
                $reasons[] = 'provider_error:'.$err;
            }

            return new CompletionDecision(
                status: CompletionSummary::STATUS_FAILED,
                honestyFlags: $honestyFlags,
                residualRisks: $residualRisks,
                reasons: $reasons,
            );
        }

        if ($diffResult->isInvalid()) {
            $reasons[] = 'diff_parser_invalid';
            foreach ($diffResult->errors as $err) {
                $reasons[] = 'diff_error:'.$err;
            }

            return new CompletionDecision(
                status: CompletionSummary::STATUS_FAILED,
                honestyFlags: $honestyFlags,
                residualRisks: $residualRisks,
                reasons: $reasons,
            );
        }

        if ($scopeReceipt->status === ScopeGuardReceipt::STATUS_FAILED) {
            $reasons[] = 'scope_guard_failed:'.$scopeReceipt->statusReason;

            return new CompletionDecision(
                status: CompletionSummary::STATUS_FAILED,
                honestyFlags: $honestyFlags,
                residualRisks: $residualRisks,
                reasons: $reasons,
            );
        }

        if ($verificationResult->aggregateStatus === VerificationGateResult::STATUS_FAILED) {
            $reasons[] = 'verification_failed';
            // Repair is OUT OF SCOPE for this slice. We just report failed.
            // Future repair loop (Fatia 4) decides whether to retry.
            return new CompletionDecision(
                status: CompletionSummary::STATUS_FAILED,
                honestyFlags: $honestyFlags,
                residualRisks: $residualRisks,
                reasons: $reasons,
            );
        }

        $needsReview = $scopeReceipt->status === ScopeGuardReceipt::STATUS_NEEDS_REVIEW
            || $verificationResult->aggregateStatus === VerificationGateResult::STATUS_NEEDS_REVIEW;

        if ($needsReview) {
            $reasons[] = 'review_required';
            if ($scopeReceipt->status === ScopeGuardReceipt::STATUS_NEEDS_REVIEW) {
                $reasons[] = 'scope_needs_review:'.$scopeReceipt->statusReason;
            }
            if ($verificationResult->aggregateStatus === VerificationGateResult::STATUS_NEEDS_REVIEW) {
                $reasons[] = 'verification_needs_review';
            }

            return new CompletionDecision(
                status: CompletionSummary::STATUS_NEEDS_REVIEW,
                honestyFlags: $this->ensureFlag($honestyFlags, 'needs_review_decision'),
                residualRisks: $residualRisks,
                reasons: $reasons,
            );
        }

        if ($honestyFlags !== []) {
            // Invariant: passed forbids honesty flags. Downgrade to needs_review.
            $reasons[] = 'passed_downgraded_due_to_honesty_flags';

            return new CompletionDecision(
                status: CompletionSummary::STATUS_NEEDS_REVIEW,
                honestyFlags: $honestyFlags,
                residualRisks: $residualRisks,
                reasons: $reasons,
            );
        }

        $reasons[] = 'all_checks_passed';

        return new CompletionDecision(
            status: CompletionSummary::STATUS_PASSED,
            honestyFlags: [],
            residualRisks: $residualRisks,
            reasons: $reasons,
        );
    }

    /**
     * @param  list<string>  $flags
     * @return list<string>
     */
    private function ensureFlag(array $flags, string $flag): array
    {
        if (in_array($flag, $flags, true)) {
            return $flags;
        }
        $flags[] = $flag;

        return array_values($flags);
    }
}
