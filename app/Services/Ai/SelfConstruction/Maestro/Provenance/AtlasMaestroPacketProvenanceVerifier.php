<?php

declare(strict_types=1);

namespace App\Services\Ai\SelfConstruction\Maestro\Provenance;

/**
 * Pure FACT-check verifier for an {@see AtlasMaestroPacketProvenanceComposer} record.
 *
 * Verifies (closed reason-code enum):
 *   - CHAIN_EMPTY              chain[] missing or empty
 *   - ORIGIN_MISMATCH          genesis link's source_kind/source_id don't match origin_kind/origin_id
 *   - PARENT_MISSING           a non-genesis link declares a parent_id not seen earlier
 *   - CYCLE_DETECTED           parent_id refers to a later link or to itself
 *   - HASH_MISMATCH            a link's content_hash is not byte-equal to the recomputed hash
 *   - TIMESTAMP_REGRESSION     captured_at strictly decreases between adjacent links
 *   - OK                       all checks passed
 *
 * NEVER mutates the input.
 */
final class AtlasMaestroPacketProvenanceVerifier
{
    public const REASON_OK = 'OK';

    public const REASON_CHAIN_EMPTY = 'CHAIN_EMPTY';

    public const REASON_ORIGIN_MISMATCH = 'ORIGIN_MISMATCH';

    public const REASON_PARENT_MISSING = 'PARENT_MISSING';

    public const REASON_CYCLE_DETECTED = 'CYCLE_DETECTED';

    public const REASON_HASH_MISMATCH = 'HASH_MISMATCH';

    public const REASON_TIMESTAMP_REGRESSION = 'TIMESTAMP_REGRESSION';

    /**
     * @param  array<string,mixed>  $record
     * @return array{ok:bool, reason_code:string, broken_link_id?:string}
     */
    public function verify(array $record): array
    {
        $chain = is_array($record['chain'] ?? null) ? array_values($record['chain']) : [];
        if ($chain === []) {
            return $this->verdict(false, self::REASON_CHAIN_EMPTY);
        }

        // Genesis link MUST match origin_kind/origin_id.
        $genesis = $chain[0];
        $originKind = (string) ($record['origin_kind'] ?? '');
        $originId = (string) ($record['origin_id'] ?? '');
        if ((string) ($genesis['source_kind'] ?? '') !== $originKind
            || (string) ($genesis['source_id'] ?? '') !== $originId) {
            return $this->verdict(false, self::REASON_ORIGIN_MISMATCH, (string) ($genesis['link_id'] ?? ''));
        }

        $knownIds = [];
        $prevCaptured = null;
        foreach ($chain as $idx => $link) {
            $linkId = (string) ($link['link_id'] ?? '');
            $parentId = $link['parent_id'] ?? null;
            $sourceKind = (string) ($link['source_kind'] ?? '');
            $sourceId = (string) ($link['source_id'] ?? '');
            $capturedAt = (string) ($link['captured_at'] ?? '');
            $contentHash = (string) ($link['content_hash'] ?? '');

            if ($idx > 0) {
                if ($parentId === null || $parentId === '') {
                    return $this->verdict(false, self::REASON_PARENT_MISSING, $linkId);
                }
                if (! isset($knownIds[$parentId])) {
                    // parent_id refers to a link not seen yet (future or absent) ⇒ cycle/missing.
                    $futureSeen = false;
                    foreach (array_slice($chain, $idx) as $future) {
                        if ((string) ($future['link_id'] ?? '') === $parentId) {
                            $futureSeen = true;
                            break;
                        }
                    }

                    return $futureSeen
                        ? $this->verdict(false, self::REASON_CYCLE_DETECTED, $linkId)
                        : $this->verdict(false, self::REASON_PARENT_MISSING, $linkId);
                }
                if ($parentId === $linkId) {
                    return $this->verdict(false, self::REASON_CYCLE_DETECTED, $linkId);
                }
            }

            $expectedHash = hash('sha256', $this->canonicalJson([
                'parent_id' => $parentId,
                'source_kind' => $sourceKind,
                'source_id' => $sourceId,
                'captured_at' => $capturedAt,
            ]));
            if ($contentHash !== $expectedHash) {
                return $this->verdict(false, self::REASON_HASH_MISMATCH, $linkId);
            }

            if ($prevCaptured !== null && strcmp($capturedAt, $prevCaptured) < 0) {
                return $this->verdict(false, self::REASON_TIMESTAMP_REGRESSION, $linkId);
            }

            $knownIds[$linkId] = true;
            $prevCaptured = $capturedAt;
        }

        return $this->verdict(true, self::REASON_OK);
    }

    /**
     * @return array{ok:bool, reason_code:string, broken_link_id?:string}
     */
    private function verdict(bool $ok, string $reason, ?string $brokenLinkId = null): array
    {
        $v = ['ok' => $ok, 'reason_code' => $reason];
        if ($brokenLinkId !== null && $brokenLinkId !== '') {
            $v['broken_link_id'] = $brokenLinkId;
        }

        return $v;
    }

    private function canonicalJson(mixed $value): string
    {
        return (string) json_encode($this->sortRecursive($value), JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);
    }

    private function sortRecursive(mixed $value): mixed
    {
        if (! is_array($value)) {
            return $value;
        }
        if (array_is_list($value)) {
            return array_map(fn (mixed $item): mixed => $this->sortRecursive($item), $value);
        }
        ksort($value, SORT_STRING);
        $sorted = [];
        foreach ($value as $k => $v) {
            $sorted[$k] = $this->sortRecursive($v);
        }

        return $sorted;
    }
}
