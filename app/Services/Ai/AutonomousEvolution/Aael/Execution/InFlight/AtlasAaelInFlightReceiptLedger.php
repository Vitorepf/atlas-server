<?php

declare(strict_types=1);

namespace App\Services\Ai\AutonomousEvolution\Aael\Execution\InFlight;

use App\Services\Ai\EngineeringKernel\Adapters\JsonlReceiptStore;
use RuntimeException;

/**
 * Append-only persistence for inter-step ValidationFact and rolling DriftFact receipts emitted
 * during an AAEL run. Keyed by aael_run_id + step_index; receipts are byte-stable JSON with
 * monotonic sequence ids per ledger file. No in-place updates — re-appending the same payload
 * always produces a NEW line with a fresh seq.
 *
 * Consolidated onto the kernel JsonlReceiptStore (fable-eng-r2): the raw fopen/flock/json-line
 * mechanics now live in the store; this class keeps its domain payload shaping (nextSeq,
 * canonicalize) and public API.
 */
final class AtlasAaelInFlightReceiptLedger
{
    public const SCHEMA = 'atlas.aael.inflight_receipt.v1';

    public const KIND_VALIDATION = 'validation';

    public const KIND_DRIFT = 'drift';

    private readonly JsonlReceiptStore $store;

    public function __construct(private readonly string $ledgerPath)
    {
        $this->store = new JsonlReceiptStore($ledgerPath);
    }

    /**
     * @param  array<string,mixed>  $fact
     * @return array<string,mixed>
     */
    public function appendValidation(string $aaelRunId, int $stepIndex, array $fact): array
    {
        return $this->append($aaelRunId, $stepIndex, self::KIND_VALIDATION, $fact);
    }

    /**
     * @param  array<string,mixed>  $fact
     * @return array<string,mixed>
     */
    public function appendDrift(string $aaelRunId, int $stepIndex, array $fact): array
    {
        return $this->append($aaelRunId, $stepIndex, self::KIND_DRIFT, $fact);
    }

    /**
     * @param  array<string,mixed>  $fact
     * @return array<string,mixed>
     */
    private function append(string $aaelRunId, int $stepIndex, string $kind, array $fact): array
    {
        $aaelRunId = trim($aaelRunId);
        if ($aaelRunId === '') {
            throw new RuntimeException('aael_inflight_receipt_missing_run_id');
        }

        $raw = $this->store->appendWith(function (?string $lastLine) use ($aaelRunId, $stepIndex, $kind, $fact): array {
            $seq = $this->nextSeq();

            return $this->canonicalize([
                'schema' => self::SCHEMA,
                'seq' => $seq,
                'aael_run_id' => $aaelRunId,
                'step_index' => $stepIndex,
                'kind' => $kind,
                'fact' => $fact,
            ]);
        });

        return json_decode((string) $raw, true);
    }

    /**
     * @return list<array<string,mixed>>
     */
    public function forRun(string $aaelRunId): array
    {
        $aaelRunId = trim($aaelRunId);
        if ($aaelRunId === '') {
            return [];
        }
        $rows = [];
        foreach ($this->store->replay() as $decoded) {
            if ((string) ($decoded['aael_run_id'] ?? '') === $aaelRunId) {
                $rows[] = $decoded;
            }
        }
        usort($rows, static fn (array $a, array $b): int => ((int) $a['seq']) <=> ((int) $b['seq']));

        return $rows;
    }

    /**
     * @return list<array<string,mixed>>
     */
    public function all(): array
    {
        return $this->store->replay();
    }

    public function ledgerPath(): string
    {
        return $this->ledgerPath;
    }

    /**
     * Compute the next monotonic sequence id by scanning the store's existing receipts.
     */
    private function nextSeq(): int
    {
        $max = 0;
        foreach ($this->store->replay() as $row) {
            if (isset($row['seq']) && (int) $row['seq'] > $max) {
                $max = (int) $row['seq'];
            }
        }

        return $max + 1;
    }

    /**
     * Recursively ksort associative arrays (list arrays preserve order).
     *
     * @param  array<string|int,mixed>  $value
     * @return array<string|int,mixed>
     */
    private function canonicalize(array $value): array
    {
        if (! array_is_list($value)) {
            ksort($value, SORT_STRING);
        }
        foreach ($value as $k => $v) {
            if (is_array($v)) {
                $value[$k] = $this->canonicalize($v);
            }
        }

        return $value;
    }
}
