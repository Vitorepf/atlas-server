<?php

declare(strict_types=1);

namespace App\Services\Ai\AutonomousEvolution\MultiCycle;

use Closure;
use RuntimeException;

final class AtlasLoopMultiCycleCoordinationProtocol
{
    /**
     * @param  ?Closure():string  $clock  returns ISO-8601 timestamp
     */
    public function __construct(
        private readonly ?string $journalPath = null,
        private readonly ?Closure $clock = null,
    ) {
    }

    /**
     * @param  array<string,mixed>  $subScope
     * @return array<string,mixed>
     */
    public function claim(string $cycleId, array $subScope): array
    {
        return $this->withJournalLock(function ($handle) use ($cycleId, $subScope): array {
            $facts = $this->readFacts($handle);
            $active = $this->activeClaims($facts);
            $subScopeHash = $this->subScopeHash($subScope);

            if (isset($active[$subScopeHash]) && (string) $active[$subScopeHash]['cycle_id'] !== $cycleId) {
                throw new ClaimDeniedException([
                    'cycle_id' => $cycleId,
                    'sub_scope_hash' => $subScopeHash,
                    'holding_cycle_id' => (string) $active[$subScopeHash]['cycle_id'],
                    'status' => (string) $active[$subScopeHash]['status'],
                ]);
            }

            $fact = [
                'cycle_id' => $cycleId,
                'sub_scope_hash' => $subScopeHash,
                'claimed_at' => $this->now(),
                'released_at' => null,
                'status' => 'claimed',
            ];

            $this->appendFact($handle, $fact);

            return $fact;
        });
    }

    /**
     * @param  array<string,mixed>  $subScope
     * @return array<string,mixed>
     */
    public function release(string $cycleId, array $subScope, string $outcome): array
    {
        return $this->withJournalLock(function ($handle) use ($cycleId, $subScope, $outcome): array {
            $facts = $this->readFacts($handle);
            $active = $this->activeClaims($facts);
            $subScopeHash = $this->subScopeHash($subScope);
            $claim = $active[$subScopeHash] ?? null;

            if (! is_array($claim) || (string) $claim['cycle_id'] !== $cycleId) {
                throw new RuntimeException('No active claim found for cycle '.$cycleId.' and sub-scope '.$subScopeHash);
            }

            $fact = [
                'cycle_id' => $cycleId,
                'sub_scope_hash' => $subScopeHash,
                'claimed_at' => (string) $claim['claimed_at'],
                'released_at' => $this->now(),
                'status' => 'released:'.trim($outcome),
            ];

            $this->appendFact($handle, $fact);

            return $fact;
        });
    }

    /**
     * @return list<array<string,mixed>>
     */
    public function holders(): array
    {
        return $this->withJournalLock(function ($handle): array {
            $active = array_values($this->activeClaims($this->readFacts($handle)));

            usort(
                $active,
                static fn (array $left, array $right): int => [$left['claimed_at'], $left['cycle_id']] <=> [$right['claimed_at'], $right['cycle_id']],
            );

            return $active;
        });
    }

    public function journalPath(): string
    {
        return $this->journalPath ?? storage_path('atlas/loop/multicycle/journal.jsonl');
    }

    /**
     * @template T
     * @param  Closure(resource):T  $callback
     * @return T
     */
    private function withJournalLock(Closure $callback): mixed
    {
        $path = $this->journalPath();
        $dir = dirname($path);
        if (! is_dir($dir) && ! mkdir($dir, 0775, true) && ! is_dir($dir)) {
            throw new RuntimeException('Unable to create journal directory: '.$dir);
        }

        $handle = fopen($path, 'c+');
        if ($handle === false) {
            throw new RuntimeException('Unable to open journal: '.$path);
        }

        try {
            if (! flock($handle, LOCK_EX)) {
                throw new RuntimeException('Unable to lock journal: '.$path);
            }

            return $callback($handle);
        } finally {
            flock($handle, LOCK_UN);
            fclose($handle);
        }
    }

    /**
     * @param  resource  $handle
     * @return list<array<string,mixed>>
     */
    private function readFacts($handle): array
    {
        rewind($handle);

        $facts = [];
        while (($line = fgets($handle)) !== false) {
            $line = trim($line);
            if ($line === '') {
                continue;
            }

            $decoded = json_decode($line, true);
            if (is_array($decoded)) {
                $facts[] = $decoded;
            }
        }

        return $facts;
    }

    /**
     * @param  resource  $handle
     * @param  array<string,mixed>  $fact
     */
    private function appendFact($handle, array $fact): void
    {
        fseek($handle, 0, SEEK_END);
        fwrite($handle, json_encode($fact, JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR)."\n");
        fflush($handle);
    }

    /**
     * @param  list<array<string,mixed>>  $facts
     * @return array<string,array<string,mixed>>
     */
    private function activeClaims(array $facts): array
    {
        $active = [];
        foreach ($facts as $fact) {
            $hash = (string) ($fact['sub_scope_hash'] ?? '');
            if ($hash === '') {
                continue;
            }

            $status = (string) ($fact['status'] ?? '');
            if ($status === 'claimed') {
                $active[$hash] = $fact;

                continue;
            }

            if (str_starts_with($status, 'released:')) {
                unset($active[$hash]);
            }
        }

        ksort($active, SORT_STRING);

        return $active;
    }

    /**
     * @param  array<string,mixed>  $subScope
     */
    private function subScopeHash(array $subScope): string
    {
        $files = array_values(array_unique(array_filter(array_map(
            static fn (mixed $path): string => ltrim(str_replace('\\', '/', trim((string) $path)), '/'),
            is_array($subScope['files'] ?? null) ? $subScope['files'] : [],
        ), static fn (string $path): bool => $path !== '')));
        sort($files, SORT_STRING);

        return sha1(json_encode($files, JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR));
    }

    private function now(): string
    {
        return $this->clock !== null
            ? ($this->clock)()
            : date(DATE_ATOM);
    }
}

final class ClaimDeniedException extends RuntimeException
{
    /**
     * @param  array<string,mixed>  $payload
     */
    public function __construct(
        private readonly array $payload,
    ) {
        parent::__construct('Claim denied for sub-scope '.$payload['sub_scope_hash'].'; held by '.$payload['holding_cycle_id']);
    }

    /**
     * @return array<string,mixed>
     */
    public function payload(): array
    {
        return $this->payload;
    }
}
