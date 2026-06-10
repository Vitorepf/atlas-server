<?php

declare(strict_types=1);

namespace App\Services\Ai\Compression;

use App\Models\AtlasCcrOriginal;
use App\Services\Ai\Kernel\Evidence\AtlasEvidenceLedger;
use App\Services\Ai\Kernel\Evidence\LedgerEventType;
use App\Services\Ai\Support\DatabaseTableAvailability;
use Throwable;

/**
 * Compress-Cache-Retrieve store (AP-813), the piece that makes Atlas CCR
 * "lossless-by-governance".
 *
 * Unlike headroom's in-memory/SQLite TTL+LRU cache (where the original is DELETED
 * on expiry), the original is persisted in `atlas_ccr_originals` (content-addressed
 * by sha256) and cross-linked to an append-only Evidence Ledger event. It is never
 * auto-expired, survives restarts, and is retrievable cross-session by hash.
 *
 * Every method is FAIL-OPEN: any storage/ledger error is swallowed and degrades to
 * "not persisted" — compression must never break a provider call. Privacy
 * enforcement on retrieval is the CALLER's responsibility (the MCP tool blocks
 * secret/sensitive on the provider path); the store surfaces `privacy_class`.
 */
final class AtlasCcrStore
{
    public function __construct(
        private readonly ?AtlasEvidenceLedger $ledger = null,
        private readonly string $codec = 'gzip',
    ) {}

    /**
     * Store an original block (dedup by content hash). Returns hash + metadata.
     *
     * @param  array<string,mixed>  $context  content_type, privacy_class, scope_type, scope_id, recorded_by + ledger context
     * @return array{hash:string, persisted:bool, deduped:bool, original_bytes:int, compressed_bytes:int, ledger_event_id:?string}
     */
    public function store(string $content, array $context = []): array
    {
        $hash = hash('sha256', $content);
        $originalBytes = strlen($content);
        $result = [
            'hash' => $hash,
            'persisted' => false,
            'deduped' => false,
            'original_bytes' => $originalBytes,
            'compressed_bytes' => $originalBytes,
            'ledger_event_id' => null,
        ];

        if (! $this->tableExists()) {
            return $result;
        }

        try {
            $existing = AtlasCcrOriginal::query()->where('original_hash', $hash)->first();
            if ($existing !== null) {
                return [
                    'hash' => $hash,
                    'persisted' => true,
                    'deduped' => true,
                    'original_bytes' => (int) $existing->original_bytes,
                    'compressed_bytes' => (int) $existing->compressed_bytes,
                    'ledger_event_id' => $existing->ledger_event_id,
                ];
            }

            [$codec, $blob] = $this->encode($content);
            $compressedBytes = strlen($blob);
            $contentType = (string) ($context['content_type'] ?? 'text');
            $privacyClass = (string) ($context['privacy_class'] ?? 'internal');

            $ledgerEventId = $this->recordLedger(LedgerEventType::CcrOriginalStored, [
                'schema_version' => 'atlas.ccr.original.v1',
                'original_hash' => $hash,
                'content_type' => $contentType,
                'codec' => $codec,
                'original_bytes' => $originalBytes,
                'compressed_bytes' => $compressedBytes,
                'privacy_class' => $privacyClass,
            ], $context);

            AtlasCcrOriginal::query()->create([
                'original_hash' => $hash,
                'content_type' => $contentType,
                'codec' => $codec,
                'original_bytes' => $originalBytes,
                'compressed_bytes' => $compressedBytes,
                'compressed_blob' => base64_encode($blob),
                'privacy_class' => $privacyClass,
                'ledger_event_id' => $ledgerEventId,
                'scope_type' => $context['scope_type'] ?? null,
                'scope_id' => $context['scope_id'] ?? null,
                'recorded_by' => (string) ($context['recorded_by'] ?? 'atlas.compression'),
            ]);

            $result['persisted'] = true;
            $result['compressed_bytes'] = $compressedBytes;
            $result['ledger_event_id'] = $ledgerEventId;
        } catch (Throwable) {
            // Fail-open: a failed store still returns the hash; retrieval will miss.
        }

        return $result;
    }

    /**
     * Retrieve an original by content hash. Records a retrieval ledger event and
     * bumps the counter (best-effort). Returns found=false on miss.
     *
     * @param  array<string,mixed>  $context  ledger context
     * @return array{found:bool, original:?string, content_type:?string, privacy_class:?string}
     */
    public function retrieve(string $hash, array $context = []): array
    {
        $miss = ['found' => false, 'original' => null, 'content_type' => null, 'privacy_class' => null];

        if ($hash === '' || ! $this->tableExists()) {
            return $miss;
        }

        try {
            $row = AtlasCcrOriginal::query()->where('original_hash', $hash)->first();
            if ($row === null) {
                return $miss;
            }

            $blob = base64_decode((string) $row->compressed_blob, true);
            $original = $blob === false ? null : $this->decode((string) $row->codec, $blob);
            if ($original === null) {
                return $miss;
            }

            try {
                $row->forceFill([
                    'retrieved_count' => (int) $row->retrieved_count + 1,
                    'last_retrieved_at' => now(),
                ])->saveQuietly();
            } catch (Throwable) {
                // counter is best-effort.
            }

            $this->recordLedger(LedgerEventType::CcrOriginalRetrieved, [
                'schema_version' => 'atlas.ccr.retrieve.v1',
                'original_hash' => $hash,
                'content_type' => $row->content_type,
            ], $context);

            return [
                'found' => true,
                'original' => $original,
                'content_type' => (string) $row->content_type,
                'privacy_class' => (string) $row->privacy_class,
            ];
        } catch (Throwable) {
            return $miss;
        }
    }

    private function tableExists(): bool
    {
        return DatabaseTableAvailability::has('atlas_ccr_originals');
    }

    /**
     * @return array{0:string,1:string} [codec, blob]
     */
    private function encode(string $content): array
    {
        if ($this->codec === 'zstd' && function_exists('zstd_compress')) {
            $z = @zstd_compress($content);
            if (is_string($z)) {
                return ['zstd', $z];
            }
        }

        $gz = @gzencode($content, 6);
        if (is_string($gz)) {
            return ['gzip', $gz];
        }

        // Could not compress the blob itself — store it raw (still recoverable).
        return ['raw', $content];
    }

    private function decode(string $codec, string $blob): ?string
    {
        if ($blob === '') {
            return null;
        }

        return match ($codec) {
            'zstd' => function_exists('zstd_uncompress') ? ($this->orNull(@zstd_uncompress($blob))) : null,
            'gzip' => $this->orNull(@gzdecode($blob)),
            'raw' => $blob,
            default => null,
        };
    }

    private function orNull(mixed $value): ?string
    {
        return is_string($value) ? $value : null;
    }

    /**
     * @param  array<string,mixed>  $payload
     * @param  array<string,mixed>  $context
     */
    private function recordLedger(LedgerEventType $type, array $payload, array $context): ?string
    {
        if ($this->ledger === null) {
            return null;
        }

        try {
            $event = $this->ledger->record($type, $payload, $context);

            return $event?->event_id !== null ? (string) $event->event_id : null;
        } catch (Throwable) {
            return null;
        }
    }
}
