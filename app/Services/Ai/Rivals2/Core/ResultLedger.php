<?php

namespace App\Services\Ai\Rivals2\Core;

use App\Services\Ai\Rivals2\Support\RunPaths;
use App\Services\Ai\Rivals2\Support\SchemaContract;
use RuntimeException;

/**
 * Ledger append-only com hash chain: entry_hash = sha256(prev_hash + payload
 * canônico). Corrupção de qualquer linha quebra a chain na verificação.
 * ponytail: flock single-writer local; fila multi-processo só se precisar.
 */
class ResultLedger
{
    public function append(string $runId, array $verdict): array
    {
        $path = RunPaths::ledgerPath();
        RunPaths::ensureDir(dirname($path));

        $handle = fopen($path, 'c+');
        if ($handle === false || ! flock($handle, LOCK_EX)) {
            throw new RuntimeException('rivals2_ledger_lock_failed');
        }

        try {
            $prevHash = 'genesis';
            $lastLine = null;
            while (($line = fgets($handle)) !== false) {
                if (trim($line) !== '') {
                    $lastLine = $line;
                }
            }
            if ($lastLine !== null) {
                $prevHash = (json_decode($lastLine, true) ?? [])['entry_hash'] ?? 'genesis';
            }

            $entry = [
                'schema_version' => SchemaContract::LEDGER_ENTRY,
                'entry_id' => 'le_'.bin2hex(random_bytes(8)),
                'prev_hash' => $prevHash,
                'run_id' => $runId,
                'verdict' => $verdict,
                'appended_at' => now()->toIso8601String(),
            ];
            $entry['entry_hash'] = self::hashEntry($entry);

            fseek($handle, 0, SEEK_END);
            fwrite($handle, json_encode($entry, JSON_UNESCAPED_SLASHES).PHP_EOL);
        } finally {
            flock($handle, LOCK_UN);
            fclose($handle);
        }

        return $entry;
    }

    /** @return array{verified: bool, entries: int, failures: list<string>} */
    public function verifyChain(): array
    {
        $path = RunPaths::ledgerPath();
        if (! is_file($path)) {
            return ['verified' => true, 'entries' => 0, 'failures' => []];
        }

        $failures = [];
        $prevHash = 'genesis';
        $count = 0;
        foreach (array_filter(explode(PHP_EOL, file_get_contents($path))) as $index => $line) {
            $entry = json_decode($line, true);
            if (! is_array($entry)) {
                $failures[] = "line_unparseable:{$index}";
                continue;
            }
            $count++;
            if (($entry['prev_hash'] ?? null) !== $prevHash) {
                $failures[] = "chain_broken_prev_hash:line_{$index}";
            }
            $recorded = $entry['entry_hash'] ?? '';
            unset($entry['entry_hash']);
            if (self::hashEntry($entry) !== $recorded) {
                $failures[] = "entry_hash_mismatch:line_{$index}";
            }
            $prevHash = $recorded;
        }

        return ['verified' => $failures === [], 'entries' => $count, 'failures' => $failures];
    }

    public function tail(int $lines = 10): array
    {
        $path = RunPaths::ledgerPath();
        if (! is_file($path)) {
            return [];
        }
        $all = array_values(array_filter(explode(PHP_EOL, file_get_contents($path))));

        return array_map(fn ($l) => json_decode($l, true), array_slice($all, -$lines));
    }

    private static function hashEntry(array $entryWithoutHash): string
    {
        ksort($entryWithoutHash);

        return hash('sha256', json_encode($entryWithoutHash, JSON_UNESCAPED_SLASHES));
    }
}
