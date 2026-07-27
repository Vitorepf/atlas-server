<?php

declare(strict_types=1);

namespace App\Services\Ai\SelfConstruction\Maestro\Tiering;

use App\Services\Ai\EngineeringKernel\Adapters\JsonlReceiptStore;
use App\Services\Ai\SelfConstruction\Support\UsesUtcClock;

/**
 * Append-only audit ledger that records EVERY tier-mismatch refusal surfaced by the routing policy. Allow
 * verdicts (allow / allow_unknown_worker) are deliberately NOT recorded — signal-to-noise.
 *
 * PÉTREO: the class exposes record() + history() and nothing else — no update, no delete, no truncate
 * code path exists. Persistence is the kernel JsonlReceiptStore (flock LOCK_EX, seek-to-end append) so
 * existing bytes are never overwritten.
 */
final class AtlasMaestroTierMismatchLedger
{
    use UsesUtcClock;

    public const SCHEMA = 'atlas.maestro.tier_mismatch.v1';

    /** @var null|callable():string */
    private $clock;

    public function __construct(private readonly string $ledgerPath, ?callable $clock = null)
    {
        $this->clock = $clock;
    }

    /**
     * @param  array{verdict?:string, packet_tier?:string, packet_fact_basis?:list<string>, worker_declared_max_tier?:?string, client_id?:string, reason?:string}  $verdict
     * @param  array<string,mixed>  $context
     */
    public function record(array $verdict, array $context = []): void
    {
        if (($verdict['verdict'] ?? '') !== AtlasMaestroTieredRoutingPolicy::VERDICT_REFUSE) {
            return; // allow verdicts are intentionally NOT recorded
        }

        $row = [
            'schema' => self::SCHEMA,
            'recorded_at' => $this->now(),
            'packet_id' => (string) ($context['packet_id'] ?? ''),
            'client_id' => (string) ($verdict['client_id'] ?? ''),
            'inferred_tier' => (string) ($verdict['packet_tier'] ?? ''),
            'fact_basis' => array_values((array) ($verdict['packet_fact_basis'] ?? [])),
            'worker_declared_tier' => $verdict['worker_declared_max_tier'] ?? null,
            'reason' => (string) ($verdict['reason'] ?? ''),
        ];
        $canonical = $row;
        ksort($canonical);
        $row['content_hash'] = hash('sha256', (string) json_encode($canonical, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));

        $store = new JsonlReceiptStore($this->ledgerPath);
        // Duplicate suppression runs INSIDE the write lock — same content_hash already recorded → skip.
        $store->appendWith(function (?string $lastLine) use ($store, $row): ?array {
            foreach ($store->replay() as $existing) {
                if (($existing['content_hash'] ?? '') === $row['content_hash']) {
                    return null;
                }
            }

            return $row;
        });
    }

    /**
     * @return list<array<string,mixed>>  newest-first
     */
    public function history(int $limit, ?string $clientId = null): array
    {
        if (! is_file($this->ledgerPath)) {
            return [];
        }
        $out = [];
        foreach (file($this->ledgerPath, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) ?: [] as $line) {
            $decoded = json_decode((string) $line, true);
            if (! is_array($decoded)) {
                continue;
            }
            if ($clientId !== null && (string) ($decoded['client_id'] ?? '') !== $clientId) {
                continue;
            }
            $out[] = $decoded;
        }
        $out = array_reverse($out); // newest-first

        return array_slice($out, 0, max(0, $limit));
    }

    /**
     * All mismatch rows for a given worker (client_id), oldest-first.
     *
     * @return list<array<string,mixed>>
     */
    public function forWorker(string $clientId): array
    {
        return array_values(array_filter($this->allRows(), static fn (array $r): bool => ($r['client_id'] ?? '') === $clientId));
    }

    /**
     * All mismatch rows where packet_id starts with $family.
     *
     * @return list<array<string,mixed>>
     */
    public function forFamily(string $family): array
    {
        return array_values(array_filter($this->allRows(), static fn (array $r): bool => str_starts_with((string) ($r['packet_id'] ?? ''), $family)));
    }

    /**
     * Learning signal recommendation for a worker: which tiers to avoid assigning.
     *
     * @return array{client_id:string, avoid_tiers:list<string>, mismatch_count:int, signal:string}
     */
    public function recommend(string $clientId): array
    {
        $rows = $this->forWorker($clientId);
        $tiers = array_values(array_unique(array_filter(array_map(static fn (array $r): string => (string) ($r['inferred_tier'] ?? ''), $rows))));
        sort($tiers, SORT_STRING);

        return [
            'client_id'     => $clientId,
            'avoid_tiers'   => $tiers,
            'mismatch_count' => count($rows),
            'signal'        => count($tiers) > 0 ? 'avoid_tiers:'.implode(',', $tiers) : 'no_mismatches',
        ];
    }

    /** @return list<array<string,mixed>> oldest-first */
    private function allRows(): array
    {
        if (! is_file($this->ledgerPath)) {
            return [];
        }
        $out = [];
        foreach (file($this->ledgerPath, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) ?: [] as $line) {
            $decoded = json_decode((string) $line, true);
            if (is_array($decoded)) {
                $out[] = $decoded;
            }
        }

        return $out;
    }

}
