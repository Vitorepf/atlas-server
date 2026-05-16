<?php

declare(strict_types=1);

namespace App\Repositories;

/**
 * Repositório de settings com cache em-memória.
 *
 * BUG (seed): update() grava no store mas NÃO invalida a entrada de cache;
 * get() seguinte devolve o valor antigo até o cache expirar. O fix exige
 * invalidação determinística (write-through ou invalidate explícito) sem
 * depender de TTL.
 *
 * Contract local do test: store/cache são arrays em-memória; o cache
 * registra hits/misses para o test poder afirmar o comportamento sem
 * sleep nem clock real.
 */
final class SettingsRepository
{
    /** @var array<string,string> */
    private array $store = [];

    /** @var array<string,string> */
    private array $cache = [];

    public int $cacheHits = 0;

    public int $cacheMisses = 0;

    public function get(string $key): ?string
    {
        if (array_key_exists($key, $this->cache)) {
            $this->cacheHits++;

            return $this->cache[$key];
        }
        $this->cacheMisses++;
        if (! array_key_exists($key, $this->store)) {
            return null;
        }
        $value = $this->store[$key];
        $this->cache[$key] = $value;

        return $value;
    }

    public function update(string $key, string $value): void
    {
        // BUG: persiste no store mas o cache continua segurando o valor antigo.
        $this->store[$key] = $value;
    }
}
