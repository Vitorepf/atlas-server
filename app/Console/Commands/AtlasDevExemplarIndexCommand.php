<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Services\Ai\Programming\AtlasDev\Discovery\DevGreenRunExemplarRetriever;
use Illuminate\Console\Command;
use App\Console\Concerns\EmitsCanonicalJson;

/**
 * One-time (idempotent) backfill of the green-run exemplar index over the whole
 * receipts store. After this, retrievals serve the FULL proven history at
 * O(index) while per-call disk scans stay capped; new runs self-index lazily on
 * every retrieval, so the command only needs re-running after bulk imports.
 */
class AtlasDevExemplarIndexCommand extends Command
{
    use EmitsCanonicalJson;

    protected $signature = 'atlas:dev:exemplar-index {--adopt-origin= : Workspace cujos green rows órfãos de identidade devem adotar o origin (inferência por files_touched existentes)} {--json : Print machine-readable JSON}';

    protected $description = 'Backfill the exemplar index (exemplar_index.jsonl) over the whole Dev receipts store.';

    public function handle(): int
    {
        $summary = (new DevGreenRunExemplarRetriever)->indexAll();

        // Adoção de identidade para o HISTÓRICO: greens antigos rodaram em
        // sandboxes efêmeros (73 workspace_hashes distintos) e ANTES do sidecar
        // workspace_origin — com identidade de caller o retriever retornava 0
        // exemplares para sempre (auditado 03/07). Um green cujo files_touched
        // existe no workspace dado pertence a este repo; adota o origin_hash e
        // registra a inferência (origin_adopted=true, nunca silencioso).
        $adopt = trim((string) $this->option('adopt-origin'));
        if ($adopt !== '' && is_dir($adopt)) {
            $summary['origin_adopted'] = $this->adoptOrigin($adopt);
        }

        if ($this->option('json')) {
            $this->line($this->encode($summary));

            return self::SUCCESS;
        }

        $this->line('  exemplar index backfill');
        $this->line('  indexed_green='.$summary['indexed_green'].'  indexed_other='.$summary['indexed_other']
            .'  already_indexed='.$summary['already_indexed'].'  unreadable='.$summary['unreadable']
            .(isset($summary['origin_adopted']) ? '  origin_adopted='.$summary['origin_adopted'] : ''));

        return self::SUCCESS;
    }

    /** Reescreve o índice adotando o origin do workspace nos greens órfãos de identidade. */
    private function adoptOrigin(string $workspace): int
    {
        $indexPath = storage_path('atlas-dev/receipts/exemplar_index.jsonl');
        if (! is_file($indexPath)) {
            return 0;
        }
        $origin = \App\Services\Ai\Programming\AtlasDev\Support\WorkspaceOriginIdentity::hash($workspace);
        $adopted = 0;
        $lines = [];
        foreach (file($indexPath, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) ?: [] as $line) {
            $row = json_decode($line, true);
            if (is_array($row)
                && ($row['outcome'] ?? '') === 'passed'
                && trim((string) ($row['origin_hash'] ?? '')) === ''
            ) {
                $existing = array_filter(
                    array_map('strval', (array) ($row['files_touched'] ?? [])),
                    static fn (string $f): bool => is_file(rtrim($workspace, '/').'/'.ltrim($f, '/')),
                );
                if ($existing !== []) {
                    $row['origin_hash'] = $origin;
                    $row['origin_adopted'] = true;
                    $adopted++;
                    $lines[] = json_encode($row, JSON_UNESCAPED_SLASHES);

                    continue;
                }
            }
            $lines[] = $line;
        }
        if ($adopted > 0) {
            file_put_contents($indexPath, implode("\n", $lines)."\n", LOCK_EX);
        }

        return $adopted;
    }
}
