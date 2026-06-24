<?php

declare(strict_types=1);

namespace App\Services\Ai\AutonomousEvolution\Cortex;

use InvalidArgumentException;

/**
 * APPEND-ONLY JSON-lines ledger recording which scopes have a Cortex today — i.e. which `repo_root + scope_root`
 * combinations have produced VALID FACTS under {@see AtlasCortexUniversalFactsSchema::SCHEMA_ID}. The ledger
 * refuses to record any facts blob that fails the universal schema's `validate()` (only proven adoptions land).
 *
 * APPEND-ONLY INVARIANT: writes are exclusively via the documented APPEND + LOCK_EX persistence pattern —
 * the implementation contains no truncating fopen mode, no truncating put_contents call, and no record
 * deletion. Test enforces these via a string-grep over the source.
 */
final class AtlasCortexAdoptionReceiptLedger
{
    private ?string $rootForTesting = null;

    public function setRootForTesting(?string $path): void
    {
        $this->rootForTesting = $path === null ? null : rtrim($path, '/');
    }

    /**
     * Record one adoption. Validates the supplied FACTS payload against the universal schema FIRST; on any
     * validation error throws {@see InvalidArgumentException} and writes nothing.
     *
     * @param  array<string,mixed>  $facts  the FACTS produced by AtlasCortexUniversalContract::comprehend()
     * @return array<string,mixed>          the persisted record (also returned for caller correlation)
     */
    public function record(array $facts, string $factsPath): array
    {
        $errors = (new AtlasCortexUniversalFactsSchema)->validate($facts);
        if ($errors !== []) {
            throw new InvalidArgumentException('Adoption refused: facts blob failed schema validation: '.implode('; ', $errors));
        }

        $record = [
            'recorded_at_unix' => time(),
            'repo_root' => (string) ($facts['repo_root'] ?? ''),
            'scope_root' => (string) ($facts['scope_root'] ?? ''),
            'schema_id' => AtlasCortexUniversalFactsSchema::SCHEMA_ID,
            'snapshot_id' => (string) ($facts['snapshot_id'] ?? ''),
            'facts_path' => $factsPath,
            'units_count' => is_array($facts['units'] ?? null) ? count($facts['units']) : (is_array($facts['inventory'] ?? null) ? count($facts['inventory']) : 0),
            'orphans_count' => is_array($facts['orphans'] ?? null) ? count($facts['orphans']) : 0,
            'clones_count' => is_array($facts['clone_clusters'] ?? null) ? count($facts['clone_clusters']) : 0,
        ];

        $path = $this->ledgerPath();
        $dir = dirname($path);
        if (! is_dir($dir)) {
            @mkdir($dir, 0775, true);
        }
        // APPEND-ONLY write — FILE_APPEND + LOCK_EX. No truncating write, no fopen('w').
        file_put_contents($path, json_encode($record, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE)."\n", FILE_APPEND | LOCK_EX);

        return $record;
    }

    /**
     * @return list<array<string,mixed>>  all records, sorted ascending by recorded_at_unix
     */
    public function list(): array
    {
        $path = $this->ledgerPath();
        if (! is_file($path)) {
            return [];
        }
        $rows = [];
        foreach (file($path, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) ?: [] as $line) {
            $decoded = json_decode((string) $line, true);
            if (is_array($decoded)) {
                $rows[] = $decoded;
            }
        }
        usort($rows, static fn (array $x, array $y): int => (int) ($x['recorded_at_unix'] ?? 0) <=> (int) ($y['recorded_at_unix'] ?? 0));

        return $rows;
    }

    public function ledgerPath(): string
    {
        if ($this->rootForTesting !== null) {
            return $this->rootForTesting.'/adoption.jsonl';
        }
        if (function_exists('storage_path')) {
            try {
                return (string) storage_path('app/atlas/cortex/adoption.jsonl');
            } catch (\Throwable) {
            }
        }

        return sys_get_temp_dir().'/atlas-cortex-adoption.jsonl';
    }
}
