<?php

declare(strict_types=1);

namespace App\Services\Ai\SoftwareCompanyStewardship\AreaFocusLoop\AtomicBacklog;

use App\Services\Ai\Kernel\Evidence\AtlasEvidenceLedger;
use App\Services\Ai\Support\DatabaseTableAvailability;
use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;
use Illuminate\Support\Facades\DB;

final class EvidenceLedgerHashChainIntegrityVerifier
{
    public const SCHEMA_VERSION = 'atlas.evidence.ledger_hash_chain_integrity.v1';

    /**
     * @param  list<array<string,mixed>>  $events
     * @return list<array<string,mixed>>
     */
    public function verify(array $events): array
    {
        if ($events === []) {
            return [$this->verifyChain('', [])];
        }

        $scopeChains = [];
        foreach ($events as $event) {
            $scopeKey = $event['scope_key'] ?? '';
            if (! isset($scopeChains[$scopeKey])) {
                $scopeChains[$scopeKey] = [];
            }
            $scopeChains[$scopeKey][] = $event;
        }

        $results = [];
        foreach ($scopeChains as $scopeKey => $chain) {
            usort($chain, fn ($a, $b) => $a['event_id'] <=> $b['event_id']);
            $results[] = $this->verifyChain($scopeKey, $chain);
        }

        return $results;
    }

    /**
     * @return array<string,mixed>
     */
    public function verifyStoredScopeChain(string $scopeType, string $scopeId): array
    {
        return $this->verifyStoredChain($this->storedRowsForScope($scopeType, $scopeId), $scopeType.':'.$scopeId);
    }

    /**
     * @return array<string,mixed>
     */
    public function verifyStoredCorrelationChain(string $correlationId): array
    {
        return $this->verifyStoredChain($this->storedRowsForCorrelation($correlationId), 'correlation_id:'.$correlationId);
    }

    /**
     * @return list<array<string,mixed>>
     */
    public function verifyStoredChainsForDay(CarbonInterface|string|null $day = null): array
    {
        if (! DatabaseTableAvailability::has('atlas_ledger_events')) {
            return [];
        }

        $rows = $this->storedRowsForDay($day);
        $chains = [];
        foreach ($rows as $row) {
            $key = $this->chainKey($row);
            $chains[$key] ??= [];
            $chains[$key][] = $row;
        }

        $results = [];
        foreach ($chains as $key => $chainRows) {
            $results[] = $this->verifyStoredChain($chainRows, $key);
        }

        usort($results, static fn (array $a, array $b): int => strcmp((string) $a['chain_key'], (string) $b['chain_key']));

        return $results;
    }

    /**
     * @return list<array<string,mixed>>
     */
    public function chainHeadsForDay(CarbonInterface|string|null $day = null): array
    {
        return array_values(array_filter(array_map(
            static function (array $result): ?array {
                $head = (string) ($result['chain_head_event_hash'] ?? '');
                if ($head === '') {
                    return null;
                }

                return [
                    'chain_key' => (string) $result['chain_key'],
                    'status' => (string) $result['status'],
                    'chain_length' => (int) $result['chain_length'],
                    'legacy_unchained_count' => (int) $result['legacy_unchained_count'],
                    'gap_count' => (int) $result['gap_count'],
                    'tampered_event_ids' => $result['tampered_event_ids'],
                    'chain_head_event_id' => $result['last_event_id'],
                    'chain_head_event_hash' => $head,
                ];
            },
            $this->verifyStoredChainsForDay($day),
        )));
    }

    /**
     * @param  list<array<string,mixed>>  $rows
     * @return array<string,mixed>
     */
    private function verifyStoredChain(array $rows, string $chainKey): array
    {
        $legacyRows = array_values(array_filter(
            $rows,
            static fn (array $row): bool => (string) ($row['chain_basis'] ?? '') === AtlasEvidenceLedger::CHAIN_BASIS_LEGACY_UNCHAINED,
        ));
        // full_envelope_v2 belongs here too. The writer emits it whenever the v2
        // columns exist (event_hash, prev_event_hash, chain_basis, chain_key_hash,
        // chain_position — all added 2026-07-23), which is every row written since.
        // Accepting only 'hash_chained' matched neither those rows nor the legacy
        // bucket, so they fell out of BOTH lists and were verified by nobody: the
        // WDG-01 watchdog reported an intact chain over an empty set.
        $chain = array_values(array_filter(
            $rows,
            static fn (array $row): bool => in_array(
                (string) ($row['chain_basis'] ?? AtlasEvidenceLedger::CHAIN_BASIS_HASH_CHAINED),
                [AtlasEvidenceLedger::CHAIN_BASIS_HASH_CHAINED, AtlasEvidenceLedger::CHAIN_BASIS_FULL_ENVELOPE_V2],
                true,
            ),
        ));

        $result = $this->verifyChain($chainKey, array_map(fn (array $row): array => $this->storedRowToVerifierEvent($row), $chain));
        $result['chain_key'] = $chainKey;
        $result['scope_key'] = $chainKey;
        $result['legacy_unchained_count'] = count($legacyRows);
        $result['chain_head_event_hash'] = $chain === [] ? null : (string) ($chain[count($chain) - 1]['event_hash'] ?? '');

        if ($chain === [] && $legacyRows !== []) {
            $result['status'] = 'legacy_unchained';
        }

        return $result;
    }

    /**
     * @param  array<string,mixed>  $row
     * @return array<string,mixed>
     */
    private function storedRowToVerifierEvent(array $row): array
    {
        $payload = $this->payloadArray($row['payload'] ?? null);
        $canonicalPayload = $this->canonicalize($payload);
        $canonicalPayloadHash = hash('sha256', json_encode($canonicalPayload, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR));
        $storedPayloadHash = (string) ($row['payload_hash'] ?? '');
        $eventHash = (string) ($row['event_hash'] ?? '');
        $expectedHashes = $this->expectedEventHashes($row, $storedPayloadHash);

        return [
            'event_id' => (string) ($row['event_id'] ?? ''),
            'scope_key' => $this->chainKey($row),
            'event_hash' => $eventHash,
            'prev_event_hash' => $this->nullableString($row['prev_event_hash'] ?? null),
            'canonical_payload_hash' => $storedPayloadHash,
            'payload_hash_valid' => $storedPayloadHash !== '' && hash_equals($storedPayloadHash, $canonicalPayloadHash),
            'event_hash_valid' => $eventHash !== '' && in_array($eventHash, $expectedHashes, true),
        ];
    }

    /**
     * @param  array<string,mixed>  $row
     * @return list<string>
     */
    private function expectedEventHashes(array $row, string $payloadHash): array
    {
        $occurredAtCandidates = array_values(array_unique(array_filter([
            $this->nullableString($row['occurred_at'] ?? null),
            $this->isoOccurredAt($row['occurred_at'] ?? null),
        ])));
        $hashes = [];

        // A v2 row's event_hash covers the FULL envelope and is computed with a
        // different function, so recomputing it with the v1 basis below yields a
        // mismatch on a perfectly intact row. Including v2 rows in the chain
        // without this would have swapped a silent gap for a flood of false
        // tamper alarms — worse than the bug being fixed.
        if ((string) ($row['chain_basis'] ?? '') === AtlasEvidenceLedger::CHAIN_BASIS_FULL_ENVELOPE_V2) {
            $hashes[] = AtlasEvidenceLedger::computeV2EnvelopeHash(
                AtlasEvidenceLedger::fullEnvelopeHashBasis(array_merge(
                    $row,
                    ['payload' => $this->payloadArray($row['payload'] ?? null)],
                )),
            );
        }

        foreach ($occurredAtCandidates as $occurredAt) {
            foreach ([true, false] as $includePrev) {
                $hashes[] = AtlasEvidenceLedger::computeEventHash([
                    'event_id' => (string) ($row['event_id'] ?? ''),
                    'event_type' => (string) ($row['event_type'] ?? ''),
                    'envelope_id' => (string) ($row['envelope_id'] ?? ''),
                    'correlation_id' => (string) ($row['correlation_id'] ?? ''),
                    'causation_id' => $this->nullableString($row['causation_id'] ?? null),
                    'scope_type' => $this->nullableString($row['scope_type'] ?? null),
                    'scope_id' => $this->nullableString($row['scope_id'] ?? null),
                    'prev_event_hash' => $includePrev ? $this->nullableString($row['prev_event_hash'] ?? null) : null,
                    'payload_hash' => $payloadHash,
                    'occurred_at' => $occurredAt,
                ]);
            }
        }

        return array_values(array_unique($hashes));
    }

    private function isoOccurredAt(mixed $value): ?string
    {
        try {
            return $value === null ? null : CarbonImmutable::parse((string) $value)->toISOString();
        } catch (\Throwable) {
            return null;
        }
    }

    /**
     * @return list<array<string,mixed>>
     */
    private function storedRowsForScope(string $scopeType, string $scopeId): array
    {
        if (! DatabaseTableAvailability::has('atlas_ledger_events')
            || ! DatabaseTableAvailability::hasColumn('atlas_ledger_events', 'scope_type')
            || ! DatabaseTableAvailability::hasColumn('atlas_ledger_events', 'scope_id')) {
            return [];
        }

        return $this->normalizeRows(DB::table('atlas_ledger_events')
            ->where('scope_type', $scopeType)
            ->where('scope_id', $scopeId)
            ->orderBy('occurred_at')
            ->orderBy('event_id')
            ->get());
    }

    /**
     * @return list<array<string,mixed>>
     */
    private function storedRowsForCorrelation(string $correlationId): array
    {
        if (! DatabaseTableAvailability::has('atlas_ledger_events')) {
            return [];
        }

        return $this->normalizeRows(DB::table('atlas_ledger_events')
            ->where('correlation_id', $correlationId)
            ->orderBy('occurred_at')
            ->orderBy('event_id')
            ->get());
    }

    /**
     * @return list<array<string,mixed>>
     */
    private function storedRowsForDay(CarbonInterface|string|null $day): array
    {
        $date = $day instanceof CarbonInterface
            ? $day->toDateString()
            : CarbonImmutable::parse($day ?? 'now')->toDateString();

        return $this->normalizeRows(DB::table('atlas_ledger_events')
            ->where('occurred_at', '>=', $date.' 00:00:00')
            ->where('occurred_at', '<=', $date.' 23:59:59')
            ->orderBy('occurred_at')
            ->orderBy('event_id')
            ->get());
    }

    /**
     * @param  iterable<object>  $rows
     * @return list<array<string,mixed>>
     */
    private function normalizeRows(iterable $rows): array
    {
        $normalized = [];
        foreach ($rows as $row) {
            $normalized[] = (array) $row;
        }

        return $normalized;
    }

    /**
     * @param  array<string,mixed>  $row
     */
    private function chainKey(array $row): string
    {
        $scopeType = $this->nullableString($row['scope_type'] ?? null);
        $scopeId = $this->nullableString($row['scope_id'] ?? null);
        if ($scopeType !== null && $scopeId !== null) {
            return $scopeType.':'.$scopeId;
        }

        return 'correlation_id:'.(string) ($row['correlation_id'] ?? '');
    }

    /**
     * @return array<string,mixed>
     */
    private function payloadArray(mixed $payload): array
    {
        if (is_array($payload)) {
            return $payload;
        }
        if (is_string($payload) && $payload !== '') {
            $decoded = json_decode($payload, true);

            return is_array($decoded) ? $decoded : [];
        }

        return [];
    }

    /**
     * @param  array<string,mixed>  $payload
     * @return array<string,mixed>
     */
    private function canonicalize(array $payload): array
    {
        ksort($payload);
        foreach ($payload as $key => $value) {
            if (is_array($value)) {
                $payload[$key] = $this->canonicalize($value);
            }
        }

        return $payload;
    }

    private function nullableString(mixed $value): ?string
    {
        if ($value === null) {
            return null;
        }

        $value = trim((string) $value);

        return $value === '' ? null : $value;
    }

    /**
     * @param  list<array<string,mixed>>  $chain
     * @return array<string,mixed>
     */
    private function verifyChain(string $scopeKey, array $chain): array
    {
        if (empty($chain)) {
            return [
                'schema_version' => self::SCHEMA_VERSION,
                'chain_key' => $scopeKey,
                'scope_key' => $scopeKey,
                'status' => 'ok',
                'chain_length' => 0,
                'gap_count' => 0,
                'tampered_event_ids' => [],
                'first_event_id' => null,
                'last_event_id' => null,
                'legacy_unchained_count' => 0,
                'chain_head_event_hash' => null,
            ];
        }

        $gapCount = 0;
        $tamperedEventIds = [];
        $prevEventHash = null;

        foreach ($chain as $event) {
            $eventId = $event['event_id'];
            $eventHash = $event['event_hash'];
            $prevEventHashFromEvent = $event['prev_event_hash'] ?? null;

            if (($event['payload_hash_valid'] ?? true) !== true || ($event['event_hash_valid'] ?? ($eventHash === ($event['canonical_payload_hash'] ?? null))) !== true) {
                $tamperedEventIds[] = $eventId;
            }

            if ($prevEventHash !== null && $prevEventHashFromEvent !== $prevEventHash) {
                $gapCount++;
            }

            $prevEventHash = $eventHash;
        }

        return [
            'schema_version' => self::SCHEMA_VERSION,
            'chain_key' => $scopeKey,
            'scope_key' => $scopeKey,
            'status' => empty($tamperedEventIds) ? ($gapCount > 0 ? 'gap' : 'ok') : 'tampered',
            'chain_length' => count($chain),
            'gap_count' => $gapCount,
            'tampered_event_ids' => $tamperedEventIds,
            'first_event_id' => $chain[0]['event_id'],
            'last_event_id' => $chain[count($chain) - 1]['event_id'],
            'legacy_unchained_count' => 0,
            'chain_head_event_hash' => $chain[count($chain) - 1]['event_hash'] ?? null,
        ];
    }
}
