<?php

declare(strict_types=1);

namespace App\Services\Ai\SoftwareCompanyStewardship\AreaFocusLoop\AtomicBacklog;

final class EvidenceLedgerHashChainIntegrityVerifier
{
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

    private function verifyChain(string $scopeKey, array $chain): array
    {
        if (empty($chain)) {
            return [
                'schema_version' => 'atlas.evidence.ledger_hash_chain_integrity.v1',
                'scope_key' => $scopeKey,
                'status' => 'ok',
                'chain_length' => 0,
                'gap_count' => 0,
                'tampered_event_ids' => [],
                'first_event_id' => null,
                'last_event_id' => null,
            ];
        }

        $gapCount = 0;
        $tamperedEventIds = [];
        $prevEventHash = null;

        foreach ($chain as $event) {
            $eventId = $event['event_id'];
            $eventHash = $event['event_hash'];
            $prevEventHashFromEvent = $event['prev_event_hash'] ?? null;
            $canonicalPayloadHash = $event['canonical_payload_hash'];

            if ($eventHash !== $canonicalPayloadHash) {
                $tamperedEventIds[] = $eventId;
            }

            if ($prevEventHash !== null && $prevEventHashFromEvent !== $prevEventHash) {
                $gapCount++;
            }

            $prevEventHash = $eventHash;
        }

        return [
            'schema_version' => 'atlas.evidence.ledger_hash_chain_integrity.v1',
            'scope_key' => $scopeKey,
            'status' => empty($tamperedEventIds) ? ($gapCount > 0 ? 'gap' : 'ok') : 'tampered',
            'chain_length' => count($chain),
            'gap_count' => $gapCount,
            'tampered_event_ids' => $tamperedEventIds,
            'first_event_id' => $chain[0]['event_id'],
            'last_event_id' => $chain[count($chain) - 1]['event_id'],
        ];
    }
}
