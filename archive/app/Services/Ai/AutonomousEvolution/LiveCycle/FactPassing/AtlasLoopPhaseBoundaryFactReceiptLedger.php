<?php

declare(strict_types=1);

namespace App\Services\Ai\AutonomousEvolution\LiveCycle\FactPassing;


use App\Services\Ai\EngineeringKernel\Adapters\JsonlReceiptStore;
use App\Services\Ai\SelfConstruction\Support\KsortsArraysByReference;

/**
 * Append-only JSONL ledger of every phase-boundary fact passing event. Records validation outcome
 * and a deterministic fact_hash so two records of the same payload yield identical hashes.
 *
 * Path is config-driven via atlas.loop.fact_passing.ledger_path (default:
 * storage_path('atlas/loop/fact-passing/receipts.jsonl')). Append uses LOCK_EX for crash safety.
 */
final class AtlasLoopPhaseBoundaryFactReceiptLedger
{
    use KsortsArraysByReference;

    public function __construct(
        private readonly AtlasLoopPhaseBoundaryFactValidator $validator,
        private readonly ?string $ledgerPathOverride = null,
    ) {}

    /**
     * @param  array<string,mixed>  $fact
     * @return array<string,mixed>
     */
    public function record(string $cycleId, string $boundary, array $fact): array
    {
        $validation = $this->validator->validate($boundary, $fact);
        $row = [
            'cycle_id' => $cycleId,
            'boundary' => $boundary,
            'fact_hash' => $this->factHash($fact),
            'validation_ok' => $validation->ok,
            'missing_keys' => $validation->missingKeys,
            'type_mismatches' => $validation->typeMismatches,
            'unknown_keys' => $validation->unknownKeys,
            'recorded_at' => gmdate('Y-m-d\TH:i:s\Z'),
        ];
        $this->append($row);

        return $row;
    }

    /**
     * @return list<array<string,mixed>>
     */
    public function readSince(string $cycleId): array
    {
        return array_values(array_filter(
            (new JsonlReceiptStore($this->ledgerPath()))->replay(),
            static fn (array $row): bool => (string) ($row['cycle_id'] ?? '') === $cycleId,
        ));
    }

    public function ledgerPath(): string
    {
        if ($this->ledgerPathOverride !== null) {
            return $this->ledgerPathOverride;
        }
        if (function_exists('config')) {
            $configured = config('atlas.loop.fact_passing.ledger_path');
            if (is_string($configured) && $configured !== '') {
                return $configured;
            }
        }

        return storage_path('atlas/loop/fact-passing/receipts.jsonl');
    }

    /**
     * @param  array<string,mixed>  $row
     */
    private function append(array $row): void
    {
        try {
            (new JsonlReceiptStore($this->ledgerPath()))->append($row);
        } catch (\Throwable) {
            // was: uncreatable dir / suppressed write ⇒ silent no-op, record() still returns the row
        }
    }

    /**
     * @param  array<string,mixed>  $fact
     */
    public function factHash(array $fact): string
    {
        $canonical = $fact;
        $this->ksortRecursiveByReference($canonical);

        return hash('sha256', (string) json_encode($canonical, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));
    }

    /**
     * @param  array<string,mixed>  $arr
     */
}
