<?php

declare(strict_types=1);

namespace App\Services\Ai\Programming\AtlasDev\Repair;

use App\Services\Ai\Programming\AtlasDev\Schemas\Components\CompletionSummary;
use App\Services\Ai\Programming\AtlasDev\Schemas\FailureCapsule;
use App\Services\Ai\Programming\AtlasDev\Schemas\FastPathErrorLedgerEntry;

/**
 * Maps a `(FailureCapsule, completion_state, attempt_count)` triple to one
 * of {@see FastPathErrorLedgerEntry}'s canonical `actual_failure_mode`
 * values, so the error ledger entries reflect the true category of failure
 * instead of "other" everywhere.
 *
 * The classifier is deliberately conservative: when nothing in the inputs
 * justifies a specific mode, it returns `other` rather than guessing.
 * Reviewers can promote `other` to a more precise mode post-hoc via the
 * DTO's `withPostHocReview` flow.
 */
final class FailureModeClassifier
{
    /**
     * @param  list<string>  $escalationSignals  union of signals seen by the loop
     */
    public function classify(
        FailureCapsule $capsule,
        string $completionState,
        int $attemptCount,
        array $escalationSignals = [],
    ): string {
        $gate = strtolower($capsule->gate);

        // Scope-related failures dominate when present.
        if (in_array(FailureCapsuleBuilder::SIGNAL_SCOPE_VIOLATION, $escalationSignals, true)
            || str_contains($gate, 'scope_guard')
            || str_contains($gate, 'scope')) {
            return FastPathErrorLedgerEntry::FAILURE_MODE_WRONG_SCOPE;
        }

        // Repair-loop dead ends.
        if (in_array(FailureCapsuleBuilder::SIGNAL_SAME_SIGNATURE_TWICE, $escalationSignals, true)
            || in_array(FailureCapsuleBuilder::SIGNAL_DIFF_GROWTH, $escalationSignals, true)) {
            return FastPathErrorLedgerEntry::FAILURE_MODE_BAD_REPAIR;
        }

        // Multi-attempt run that ended in failure/needs_review without
        // recovering — the repair attempts themselves were the problem.
        if ($attemptCount >= 2
            && in_array($completionState, [
                CompletionSummary::STATUS_FAILED,
                CompletionSummary::STATUS_NEEDS_REVIEW,
            ], true)) {
            return FastPathErrorLedgerEntry::FAILURE_MODE_BAD_REPAIR;
        }

        // Infrastructure gates take precedence over generic verification:
        // a `prompt_quality_gate` failure that happens to mention "assert"
        // is still a prompt-projection issue, not a missed test.
        if (str_contains($gate, 'prompt')) {
            return FastPathErrorLedgerEntry::FAILURE_MODE_PROMPT_PROJECTION_ERROR;
        }
        if (str_contains($gate, 'context')) {
            return FastPathErrorLedgerEntry::FAILURE_MODE_CONTEXT_ERROR;
        }

        // Wrong-file pattern: capsule changed exactly one file and it's a
        // backup/temporary path. Surface as wrong_file so reviewers spot it.
        if (count($capsule->changedFiles) === 1) {
            $only = strtolower($capsule->changedFiles[0]);
            if (str_contains($only, '.bak') || str_contains($only, '.tmp') || str_contains($only, '.swp')) {
                return FastPathErrorLedgerEntry::FAILURE_MODE_WRONG_FILE;
            }
        }

        // Verification gate or capsule with a failingTest pointer → missed_test.
        // The excerpt itself is intentionally NOT searched for the word
        // "assert" — most error excerpts mention assertions and we'd lose
        // the ability to distinguish unrelated failure modes.
        if (str_contains($gate, 'verification') || $capsule->failingTest !== null) {
            return FastPathErrorLedgerEntry::FAILURE_MODE_MISSED_TEST;
        }

        return FastPathErrorLedgerEntry::FAILURE_MODE_OTHER;
    }
}
