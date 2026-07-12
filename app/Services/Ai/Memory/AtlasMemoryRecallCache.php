<?php

declare(strict_types=1);

namespace App\Services\Ai\Memory;

use Illuminate\Support\Facades\Cache;

/**
 * MAXB-09 — Cache de recall por query_hash (usage-safe).
 *
 * Repeat-queries dos hooks (mesma task string re-injetada por turno) custam
 * ~0: chave `sha256(workspace|corpus_bump|query|filters|options_sem_record_usage)`.
 *
 * O `record_usage` NÃO entra na chave (é side-effect, não muda o payload). Na
 * chave entra o `corpus_bump` global — qualquer write em `atlas_memory_entries`
 * (via observer) invalida TODO o cache no próximo hit. Corpus pequeno torna
 * invalidação global barata e simples.
 */
final class AtlasMemoryRecallCache
{
    private const BUMP_KEY = 'atlas:memory:recall_cache:bump:v1';

    private const PAYLOAD_KEY_PREFIX = 'atlas:memory:recall_cache:v1:';

    public function isEnabled(): bool
    {
        return (bool) config('atlas.memory.recall_cache.enabled', true);
    }

    public function ttlSeconds(): int
    {
        $ttl = (int) config('atlas.memory.recall_cache.ttl_seconds', 600);
        $ttl = max(60, $ttl);
        $ttl = min(900, $ttl);

        return $ttl;
    }

    /**
     * @param  array<string,mixed>  $context
     * @param  array<string,mixed>  $filters
     * @param  array<string,mixed>  $options
     */
    public function keyFor(string $query, array $context, array $filters, array $options): string
    {
        $normalizedOpts = $options;
        unset($normalizedOpts['record_usage']);

        $workspace = (string) config('atlas.aobg.workspace_id', config('app.env', 'atlas'));
        $corpusBump = $this->corpusBump();

        $payload = implode("\n", [
            $workspace,
            'bump:'.$corpusBump,
            'q:'.trim($query),
            'ctx:'.json_encode($this->stringifyForKey($context), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
            'flt:'.json_encode($this->stringifyForKey($filters), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
            'opt:'.json_encode($this->stringifyForKey($normalizedOpts), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
        ]);

        return self::PAYLOAD_KEY_PREFIX.hash('sha256', $payload);
    }

    public function corpusBump(): int
    {
        $value = Cache::get(self::BUMP_KEY);
        if (! is_int($value)) {
            $value = 1;
            Cache::put(self::BUMP_KEY, $value, now()->addDays(30));
        }

        return $value;
    }

    public function bumpCorpus(): int
    {
        $next = $this->corpusBump() + 1;
        Cache::put(self::BUMP_KEY, $next, now()->addDays(30));

        return $next;
    }

    /**
     * @return array<string,mixed>|null
     */
    public function get(string $key): ?array
    {
        if (! $this->isEnabled()) {
            return null;
        }
        $cached = Cache::get($key);
        if (! is_array($cached)) {
            return null;
        }

        return $cached;
    }

    /**
     * @param  array<string,mixed>  $payload
     */
    public function put(string $key, array $payload): void
    {
        if (! $this->isEnabled()) {
            return;
        }
        Cache::put($key, $payload, now()->addSeconds($this->ttlSeconds()));
    }

    /**
     * @param  array<string,mixed>|scalar|null  $value
     * @return array<string,mixed>|scalar|null
     */
    private function stringifyForKey(mixed $value): mixed
    {
        if (is_array($value)) {
            ksort($value);
            foreach ($value as $k => $v) {
                $value[$k] = $this->stringifyForKey($v);
            }

            return $value;
        }
        if (is_object($value)) {
            return spl_object_hash($value);
        }

        return $value;
    }
}
