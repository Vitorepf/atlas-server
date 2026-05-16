<?php

declare(strict_types=1);

namespace App\Services\Ai\Programming\AtlasDev\Telemetry;

use App\Services\Ai\Programming\AtlasDev\Persistence\GenericArtifactPersister;
use App\Services\Ai\Programming\AtlasDev\Schemas\Components\CompletionSummary;
use App\Services\Ai\Programming\AtlasDev\Schemas\FastPathErrorLedgerEntry;

/**
 * Append-only writer for FastPathErrorLedgerEntry artifacts.
 *
 * The error ledger is written only when the run has a real failure or
 * blocking signal worth tuning thresholds against. Repeated calls for the
 * same run append new entries with monotonic versions; existing entries are
 * never mutated.
 */
final class ErrorLedgerWriter
{
    public const STATES_WORTH_RECORDING = [
        CompletionSummary::STATUS_FAILED,
        CompletionSummary::STATUS_NEEDS_REVIEW,
        CompletionSummary::STATUS_BLOCKED,
        CompletionSummary::STATUS_ESCALATE_FORGE,
    ];

    public function __construct(
        private readonly GenericArtifactPersister $persister,
    ) {}

    /**
     * Decide whether the given completion state warrants an error ledger entry.
     * Callers should consult this BEFORE building a FastPathErrorLedgerEntry to
     * avoid recording noise from healthy runs. The DTO itself can technically
     * record any completion_state for post-hoc reviews (e.g. recording a
     * `false_escalation` against a `passed` run after a reviewer sees it),
     * which is why the writer does not gate the actual append on this flag.
     */
    public function shouldRecord(string $completionState): bool
    {
        return in_array($completionState, self::STATES_WORTH_RECORDING, true);
    }

    /**
     * @return array{path: string, version: int}
     */
    public function append(FastPathErrorLedgerEntry $entry): array
    {
        return $this->persister->appendErrorLedger($entry);
    }

    /**
     * @return list<FastPathErrorLedgerEntry>
     */
    public function read(string $runId): array
    {
        return $this->persister->readErrorLedger($runId);
    }

    public function entryCountFor(string $runId): int
    {
        return count($this->read($runId));
    }
}
