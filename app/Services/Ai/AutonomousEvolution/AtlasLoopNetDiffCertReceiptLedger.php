<?php

declare(strict_types=1);

namespace App\Services\Ai\AutonomousEvolution;

use App\Services\Ai\Support\AppendOnlyJsonlStore;
use Carbon\CarbonImmutable;
use Closure;
use Throwable;

/**
 * APPEND-ONLY ledger for net-diff cert verdicts (produced by AtlasLoopNetDiffCertJudge). One receipt per
 * (cert_id, attempt_id), persisted to the dedicated Loop ledger spool (the same append-only JSONL storage the
 * other Loop ledgers use — {@see AtlasLoopModelFloorReceiptLedger}, {@see AtlasLoopProviderHealthProbe}). No DB,
 * no migration.
 *
 * Audit-grade chain: each receipt for a cert carries prior_receipt_chain_sha = sha256(canonical JSON of the
 * PREVIOUS receipt for the SAME cert_id), null for the first — a tamper-evident chain per cert.
 *
 * IMMUTABLE by contract: there is NO update() and NO delete() public API. Re-recording the same
 * (cert_id, attempt_id) is an idempotent no-op that returns the EXISTING receipt (one row, same id).
 */
final class AtlasLoopNetDiffCertReceiptLedger
{
    public const SCHEMA_VERSION = 'atlas.loop.netdiff_cert_receipt.v1';

    private const RELATIVE_PATH = 'atlas/loop/netdiff-cert-receipts.jsonl';

    /** The business fields a caller supplies (the ledger stamps id / recorded_at / prior_receipt_chain_sha). */
    private const FIELDS = [
        'cert_id', 'attempt_id', 'verdict', 'metric_kind', 'baseline', 'candidate', 'signed_delta',
        'min_delta_used', 'tolerance_used', 'baseline_sha', 'candidate_sha', 'collector_reason', 'judge_reason',
    ];

    private ?Closure $clock = null;

    public function __construct(private readonly ?string $path = null) {}

    public function setClock(Closure $clock): void
    {
        $this->clock = $clock;
    }

    public function path(): string
    {
        return $this->path ?? storage_path(self::RELATIVE_PATH);
    }

    /**
     * Idempotent append. Re-recording the same (cert_id, attempt_id) returns the existing receipt unchanged.
     *
     * @param  array<string,mixed>  $receipt
     * @return array<string,mixed>  the persisted receipt (with id, recorded_at, prior_receipt_chain_sha)
     */
    public function append(array $receipt): array
    {
        $certId = trim((string) ($receipt['cert_id'] ?? ''));
        $attemptId = trim((string) ($receipt['attempt_id'] ?? ''));
        $id = $this->receiptId($certId, $attemptId);

        $rows = $this->readAll();

        // Idempotent: an existing (cert_id, attempt_id) is a no-op returning the stored receipt.
        foreach ($rows as $row) {
            if ((string) ($row['id'] ?? '') === $id) {
                return $row;
            }
        }

        // Chain off the LAST receipt for this same cert_id (null for the first).
        $prior = null;
        foreach ($rows as $row) {
            if ((string) ($row['cert_id'] ?? '') === $certId) {
                $prior = $row;
            }
        }
        $priorChainSha = $prior !== null ? hash('sha256', $this->canonicalJson($prior)) : null;

        $persisted = ['schema_version' => self::SCHEMA_VERSION, 'id' => $id];
        foreach (self::FIELDS as $field) {
            $persisted[$field] = $receipt[$field] ?? null;
        }
        $persisted['cert_id'] = $certId;
        $persisted['attempt_id'] = $attemptId;
        $persisted['recorded_at'] = $this->now()->toIso8601String();
        $persisted['prior_receipt_chain_sha'] = $priorChainSha;

        try {
            AppendOnlyJsonlStore::append($this->path(), $persisted);
        } catch (Throwable) {
            // best-effort persistence; the returned receipt is still authoritative for the caller
        }

        return $persisted;
    }

    /**
     * Receipts for a cert in append order.
     *
     * @return list<array<string,mixed>>
     */
    public function history(string $certId): array
    {
        $certId = trim($certId);

        return array_values(array_filter(
            $this->readAll(),
            static fn (array $row): bool => (string) ($row['cert_id'] ?? '') === $certId,
        ));
    }

    /** The canonical JSON used for the chain hash (sorted keys, JSON_THROW). Exposed so the chain is verifiable. */
    public function canonicalJson(mixed $value): string
    {
        return (string) json_encode(
            $this->canonicalize($value),
            JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE,
        );
    }

    /**
     * @return list<array<string,mixed>>
     */
    private function readAll(): array
    {
        try {
            return AppendOnlyJsonlStore::read($this->path());
        } catch (Throwable) {
            return [];
        }
    }

    private function receiptId(string $certId, string $attemptId): string
    {
        return 'ncr_'.hash('sha256', $certId.'|'.$attemptId);
    }

    private function now(): CarbonImmutable
    {
        if ($this->clock !== null) {
            return CarbonImmutable::instance(($this->clock)())->utc();
        }

        return CarbonImmutable::now('UTC');
    }

    private function canonicalize(mixed $value): mixed
    {
        if (! is_array($value)) {
            return $value;
        }
        if (array_is_list($value)) {
            return array_map(fn (mixed $v): mixed => $this->canonicalize($v), $value);
        }
        ksort($value);
        foreach ($value as $key => $child) {
            $value[$key] = $this->canonicalize($child);
        }

        return $value;
    }
}
