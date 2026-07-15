<?php

declare(strict_types=1);

namespace App\Services\AtlasCode;

use App\Models\AtlasLedgerEvent;
use App\Services\Ai\Kernel\Evidence\AtlasEvidenceLedger;
use App\Services\Ai\Kernel\Evidence\LedgerEventType;
use InvalidArgumentException;

/** C23 · explicit append-only seam for operator-backed commit provenance. */
final class AtlasCodeProvenanceLedgerService
{
    public function __construct(private readonly AtlasEvidenceLedger $ledger) {}

    /**
     * The caller must supply the exact operator quote. This writer never
     * derives a quote from a commit message or from an agent response.
     *
     * @param array<int,mixed> $obra
     * @param array<int,mixed> $gates
     */
    public function record(
        string $repo,
        string $commitHash,
        string $operatorQuote,
        ?string $traceId = null,
        array $obra = [],
        array $gates = [],
    ): ?AtlasLedgerEvent {
        $repo = trim($repo);
        $commitHash = strtolower(trim($commitHash));
        $operatorQuote = trim($operatorQuote);
        if ($repo === '' || preg_match('/^[0-9a-f]{7,64}$/', $commitHash) !== 1) {
            throw new InvalidArgumentException('invalid_provenance_commit');
        }
        if ($operatorQuote === '') {
            throw new InvalidArgumentException('operator_quote_required');
        }

        return $this->ledger->record(
            LedgerEventType::CodeProvenanceRecorded,
            [
                'schema_version' => AtlasCodeProvenanceService::SCHEMA_VERSION,
                'source' => 'operator_mission',
                'repo' => $repo,
                'commit_hash' => $commitHash,
                'operator_quote' => $operatorQuote,
                'obra' => array_values($obra),
                'gates' => array_values($gates),
            ],
            [
                'trace_id' => $traceId,
                'correlation_id' => 'atlas-code:provenance:'.$repo.':'.$commitHash,
                'envelope_id' => 'atlas-code:provenance:'.$repo.':'.$commitHash,
                'scope_type' => 'atlas_code',
                'scope_id' => $repo,
                'emitter_stage' => 'atlas.code.provenance',
                'emitter_version' => AtlasCodeProvenanceService::SCHEMA_VERSION,
            ],
        );
    }

    public function correct(string $eventId, string $reason): ?AtlasLedgerEvent
    {
        $eventId = trim($eventId);
        $reason = trim($reason);
        if ($eventId === '' || $reason === '') {
            throw new InvalidArgumentException('provenance_correction_requires_reason');
        }

        return $this->ledger->record(
            LedgerEventType::CodeProvenanceCorrected,
            [
                'schema_version' => AtlasCodeProvenanceService::SCHEMA_VERSION,
                'source' => 'operator_mission',
                'corrects_event_id' => $eventId,
                'reason' => $reason,
            ],
            [
                'correlation_id' => 'atlas-code:provenance:correction:'.$eventId,
                'envelope_id' => 'atlas-code:provenance:correction:'.$eventId,
                'scope_type' => 'atlas_code',
                'scope_id' => 'provenance',
                'emitter_stage' => 'atlas.code.provenance',
                'emitter_version' => AtlasCodeProvenanceService::SCHEMA_VERSION,
            ],
        );
    }
}
