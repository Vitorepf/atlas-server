<?php

declare(strict_types=1);

namespace App\Services\Ai\OpenBrainContextPack;

use App\Services\Ai\AtlasOpenBrainContextPackService;
use App\Services\Ai\Context\AtlasDeliveredPackLedger;
use App\Services\Ai\OpenBrain\AtlasAobgLatencyLedger;
use App\Services\Ai\Support\DatabaseTableAvailability;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Throwable;

/**
 * GOD-DEBULK split of {@see \App\Services\Ai\AtlasOpenBrainContextPackService}.
 * Verbatim packcache family extracted from the AOBG context-pack
 * façade; behavior-preserving (private helpers -> Support; public API stays on the façade).
 */
final class PackCacheSection
{
    public function __construct(
        private readonly Support $support,
    ) {}

    /** @param array<string,mixed> $pack */
    public function recordLatencySample(int $startedAt, array $pack): void
    {
        try {
            app(AtlasAobgLatencyLedger::class)->recordPack($this->support->elapsedMs($startedAt), $pack);
        } catch (Throwable) {
            // Measurement is fail-open; context delivery is the product path.
        }
    }

    /**
     * @param  list<string>  $changedFiles
     * @return array<string,mixed>
     */
    public function packCacheContext(string $task, string $workspaceId, array $changedFiles): array
    {
        $queryHash = hash('sha256', $this->normalizePackCacheQuery($task));
        $corpusFingerprint = $this->corpusFingerprint($workspaceId);
        $ttl = max(1, (int) config('atlas.aobg.pack_cache.ttl_seconds', 300));
        $enabled = (bool) config('atlas.aobg.pack_cache.enabled', true) && $changedFiles === [];
        $reason = $enabled ? null : ($changedFiles === [] ? 'disabled_by_config' : 'changed_files_bypass');

        return [
            'schema_version' => 'atlas.aobg.pack_cache.v1',
            'enabled' => $enabled,
            'workspace' => $workspaceId,
            'query_hash' => $queryHash,
            'corpus_fingerprint' => $corpusFingerprint,
            'cache_key' => 'atlas:aobg:pack:v1:'.hash('sha256', $workspaceId."\n".$queryHash."\n".$corpusFingerprint),
            'ttl_seconds' => $ttl,
            'bypass_reason' => $reason,
        ];
    }

    /** @param array<string,mixed> $cache */
    public function cachedPackResponse(array $cache, int $startedAt): ?array
    {
        try {
            $cached = Cache::get((string) ($cache['cache_key'] ?? ''));
            if (! is_array($cached) || ! is_array($cached['pack'] ?? null)) {
                return null;
            }

            /** @var array<string,mixed> $pack */
            $pack = $cached['pack'];
            if (($pack['schema'] ?? null) !== AtlasOpenBrainContextPackService::SCHEMA || trim((string) ($pack['context_pack_hash'] ?? '')) === '') {
                return null;
            }

            $pack['generated_at'] = now()->toJSON();
            $pack['timings_ms'] = [
                'code_graph' => 0.0,
                'reality_graph' => 0.0,
                'memory' => 0.0,
                'total' => $this->support->elapsedMs($startedAt),
            ];
            $pack['cache'] = $this->packCacheTelemetry($cache, 'hit');

            if ((bool) config('atlas.aobg.delivered_pack_ledger.enabled', true)) {
                AtlasDeliveredPackLedger::fromConfig()->record($pack);
            }
            $this->recordLatencySample($startedAt, $pack);

            return $pack;
        } catch (Throwable) {
            return null;
        }
    }

    /** @param array<string,mixed> $cache */
    public function writePackCache(array $cache, array $pack): void
    {
        if (($cache['enabled'] ?? false) !== true) {
            return;
        }

        try {
            Cache::put(
                (string) $cache['cache_key'],
                [
                    'schema_version' => 'atlas.aobg.pack_cache_entry.v1',
                    'workspace' => (string) ($cache['workspace'] ?? ''),
                    'query_hash' => (string) ($cache['query_hash'] ?? ''),
                    'corpus_fingerprint' => (string) ($cache['corpus_fingerprint'] ?? ''),
                    'context_pack_hash' => (string) ($pack['context_pack_hash'] ?? ''),
                    'stored_at' => now()->toJSON(),
                    'pack' => $pack,
                ],
                now()->addSeconds(max(1, (int) ($cache['ttl_seconds'] ?? 300))),
            );
        } catch (Throwable) {
            // Cache is an optimization only; retrieval remains fail-open.
        }
    }

    /** @param array<string,mixed> $cache */
    public function packCacheTelemetry(array $cache, string $status): array
    {
        return [
            'schema_version' => 'atlas.aobg.pack_cache.v1',
            'status' => $status,
            'workspace' => (string) ($cache['workspace'] ?? ''),
            'query_hash' => (string) ($cache['query_hash'] ?? ''),
            'corpus_fingerprint' => (string) ($cache['corpus_fingerprint'] ?? ''),
            'cache_key_hash' => hash('sha256', (string) ($cache['cache_key'] ?? '')),
            'ttl_seconds' => (int) ($cache['ttl_seconds'] ?? 0),
            'bypass_reason' => $cache['bypass_reason'] ?? null,
        ];
    }

    public function normalizePackCacheQuery(string $task): string
    {
        $normalized = preg_replace('/\s+/u', ' ', mb_strtolower(trim($task))) ?? trim($task);

        return $normalized;
    }

    public function corpusFingerprint(string $workspaceId): string
    {
        $payload = [
            'schema_version' => 'atlas.aobg.pack_cache_corpus_fingerprint.v1',
            'workspace' => $workspaceId,
            'code_symbols' => $this->tableFreshness('atlas_engineering_code_symbols', $workspaceId),
            'code_modules' => $this->tableFreshness('atlas_engineering_code_modules', $workspaceId),
            'memory_entries' => $this->tableFreshness('atlas_memory_entries'),
        ];

        return hash('sha256', (string) json_encode($payload, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));
    }

    /**
     * @return array<string,mixed>
     */
    public function tableFreshness(string $table, ?string $workspaceId = null): array
    {
        if (! DatabaseTableAvailability::has($table)) {
            return ['status' => 'missing'];
        }

        try {
            $query = DB::table($table);
            if ($workspaceId !== null && DatabaseTableAvailability::hasColumn($table, 'workspace_id')) {
                $query->where('workspace_id', $workspaceId);
            }
            if (DatabaseTableAvailability::hasColumn($table, 'archived_at')) {
                $query->whereNull('archived_at');
            }

            $stats = [
                'status' => 'ok',
                'rows' => (clone $query)->count(),
            ];
            foreach (['updated_at', 'indexed_at', 'source_hash', 'content_hash'] as $column) {
                if (DatabaseTableAvailability::hasColumn($table, $column)) {
                    $stats['max_'.$column] = (string) ((clone $query)->max($column) ?? '');
                }
            }

            return $stats;
        } catch (Throwable) {
            return ['status' => 'unavailable'];
        }
    }
}
