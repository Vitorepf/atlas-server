<?php

declare(strict_types=1);

namespace App\Services\Ai\Programming\AtlasDev\Repair;

use App\Services\Ai\Programming\AtlasDev\Schemas\Components\ObservedSignals;
use App\Services\Ai\Programming\AtlasDev\Schemas\FailureCapsule;
use App\Services\Ai\Programming\AtlasDev\Schemas\FastPathErrorLedgerEntry;
use App\Services\Ai\Programming\AtlasDev\Telemetry\ErrorLedgerWriter;

/**
 * Thin wrapper around {@see ErrorLedgerWriter} that turns a repair
 * outcome into an append-only error-ledger entry when the run actually
 * deserves recording (failed/needs_review/blocked/escalate_forge).
 *
 * Wrapper, not a re-implementation: the writer remains the single source
 * of truth for "where do entries live" and "how monotonic versions work".
 * We only add the orchestrator-shaped helper here so the loop doesn't
 * touch persistence concerns directly.
 */
final class RepairTelemetryRecorder
{
    public function __construct(
        private readonly ErrorLedgerWriter $writer,
        private readonly FailureModeClassifier $classifier,
    ) {}

    /**
     * Returns `null` when the completion state does not warrant recording,
     * or `['path' => ..., 'version' => ..., 'entry_hash' => ...]` on append.
     *
     * @return array{path:string, version:int, entry_hash:string}|null
     */
    public function recordIfWorthRecording(
        string $completionState,
        FailureCapsule $capsule,
        ObservedSignals $signals,
        int $attemptCount,
        array $escalationSignals = [],
        array $correctionRecommendation = [],
        bool $shouldHaveEscalated = false,
        array $missingEscalationSignals = [],
    ): ?array {
        if (! $this->writer->shouldRecord($completionState)) {
            return null;
        }

        $actualMode = $this->classifier->classify(
            $capsule,
            $completionState,
            $attemptCount,
            $escalationSignals,
        );

        // The DTO's invariant 2 requires non-empty missing_escalation_signals
        // for actual_failure_mode=missed_escalation. Fall back to the
        // capsule's own escalation_signal_delta when callers don't provide
        // a dedicated list — that's the honest answer available at this
        // point in the loop.
        if ($actualMode === FastPathErrorLedgerEntry::FAILURE_MODE_MISSED_ESCALATION
            && $missingEscalationSignals === []) {
            $missingEscalationSignals = $capsule->escalationSignalDelta;
        }

        $entry = FastPathErrorLedgerEntry::issue(
            runId: $capsule->runId,
            failureSignature: $capsule->failureSignature,
            completionState: $completionState,
            actualFailureMode: $actualMode,
            shouldHaveEscalated: $shouldHaveEscalated ? true : null,
            missingEscalationSignals: array_values($missingEscalationSignals),
            observedSignals: $signals,
            correctionRecommendation: array_values($correctionRecommendation),
        );

        $result = $this->writer->append($entry);

        return [
            'path' => $result['path'],
            'version' => $result['version'],
            'entry_hash' => $entry->entryHash,
        ];
    }
}
