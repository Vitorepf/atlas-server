<?php

declare(strict_types=1);

namespace App\Services\Ai\Context;

use App\Services\Ai\EngineeringKernel\Adapters\JsonlReceiptStore;
use Throwable;

/**
 * COM-01 — append-only ledger of delivered context-pack refs keyed by context_pack_hash.
 *
 * Provider-safe: refs, budgets, policy snapshot and timestamps only — no raw source text.
 * Fail-open: write failures never break pack assembly.
 */
final class AtlasDeliveredPackLedger
{
    public const SCHEMA = 'atlas.aobg.delivered_pack_ledger.v1';

    public const DEFAULT_RETENTION_HOURS = 168;

    public function __construct(
        private readonly string $path,
        private readonly int $retentionHours = self::DEFAULT_RETENTION_HOURS,
    ) {}

    public static function fromConfig(): self
    {
        return new self(
            (string) config(
                'atlas.aobg.delivered_pack_ledger.path',
                storage_path('atlas/aobg/delivered-pack-ledger.jsonl'),
            ),
            max(1, (int) config('atlas.aobg.delivered_pack_ledger.retention_hours', self::DEFAULT_RETENTION_HOURS)),
        );
    }

    /**
     * @param  array<string,mixed>  $pack
     */
    public function record(array $pack): void
    {
        try {
            $hash = trim((string) ($pack['context_pack_hash'] ?? ''));
            if ($hash === '') {
                return;
            }

            $entry = [
                'schema' => self::SCHEMA,
                'context_pack_hash' => $hash,
                'delivered_refs' => AtlasCanonicalContextRef::deliveredFromPack($pack),
                'budgets' => (array) ($pack['budget'] ?? []),
                'policy_snapshot' => (array) ($pack['context_delivery_policy'] ?? []),
                'ts' => (string) ($pack['generated_at'] ?? now()->toJSON()),
            ];

            $store = new JsonlReceiptStore($this->path);
            $rows = $store->replay();
            $rows[] = $entry;
            $rows = $this->pruneRows($rows);
            $store->rewrite($rows, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_PRESERVE_ZERO_FRACTION);
        } catch (Throwable) {
            // Fail-open: ledger write must never break pack assembly.
        }
    }

    /**
     * @return array<string,mixed>|null
     */
    public function lookup(string $contextPackHash): ?array
    {
        $contextPackHash = trim($contextPackHash);
        if ($contextPackHash === '') {
            return null;
        }

        $match = null;
        foreach ($this->pruneRows((new JsonlReceiptStore($this->path))->replay()) as $row) {
            if (($row['context_pack_hash'] ?? null) === $contextPackHash) {
                $match = $row;
            }
        }

        return $match;
    }

    /**
     * @param  array<int,string>  $contextPackHashes
     * @return array{delivered_refs:array<int,string>,entries:array<int,array<string,mixed>>}
     */
    public function lookupMany(array $contextPackHashes): array
    {
        $wanted = AtlasCanonicalContextRef::uniqueStrings($contextPackHashes);
        if ($wanted === []) {
            return ['delivered_refs' => [], 'entries' => []];
        }

        $wantedSet = array_fill_keys($wanted, true);
        $entries = [];
        $refs = [];

        foreach ($this->pruneRows((new JsonlReceiptStore($this->path))->replay()) as $row) {
            $hash = (string) ($row['context_pack_hash'] ?? '');
            if ($hash === '' || ! isset($wantedSet[$hash])) {
                continue;
            }
            $entries[] = $row;
            foreach ((array) ($row['delivered_refs'] ?? []) as $ref) {
                if (is_string($ref) && trim($ref) !== '') {
                    $refs[] = trim($ref);
                }
            }
        }

        return [
            'delivered_refs' => AtlasCanonicalContextRef::uniqueStrings($refs),
            'entries' => $entries,
        ];
    }

    public function path(): string
    {
        return $this->path;
    }

    /**
     * @param  list<array<string,mixed>>  $rows
     * @return list<array<string,mixed>>
     */
    private function pruneRows(array $rows): array
    {
        $cutoff = now()->subHours($this->retentionHours);
        $kept = [];

        foreach ($rows as $row) {
            $ts = trim((string) ($row['ts'] ?? ''));
            if ($ts === '') {
                $kept[] = $row;

                continue;
            }

            try {
                if (now()->parse($ts)->greaterThanOrEqualTo($cutoff)) {
                    $kept[] = $row;
                }
            } catch (Throwable) {
                $kept[] = $row;
            }
        }

        return $kept;
    }
}
