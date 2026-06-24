<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Services\Ai\AutonomousEvolution\Cortex\AtlasCortexAdoptionReceiptLedger;
use Illuminate\Console\Command;

/**
 * Operator-facing CLI for the {@see AtlasCortexAdoptionReceiptLedger}. Prints every recorded adoption — the
 * canonical answer to "which scopes have a Cortex today?" — as a table (default) or as JSON (`--json`).
 */
final class AtlasLoopCortexAdoptionListCommand extends Command
{
    /** @var string */
    protected $signature = 'atlas:loop:cortex-adoption-list {--json}';

    /** @var string */
    protected $description = 'List every recorded Cortex adoption (repo+scope combinations with valid facts).';

    public function handle(AtlasCortexAdoptionReceiptLedger $ledger): int
    {
        $rows = $ledger->list();

        if ((bool) $this->option('json')) {
            $this->line((string) json_encode($rows, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));

            return self::SUCCESS;
        }

        if ($rows === []) {
            $this->info('No Cortex adoptions recorded yet at '.$ledger->ledgerPath());

            return self::SUCCESS;
        }

        $headers = ['recorded_at_unix', 'repo_root', 'scope_root', 'snapshot_id', 'units_count', 'orphans_count', 'clones_count'];
        $tableRows = [];
        foreach ($rows as $r) {
            $tableRows[] = [
                (string) ($r['recorded_at_unix'] ?? ''),
                (string) ($r['repo_root'] ?? ''),
                (string) ($r['scope_root'] ?? ''),
                (string) ($r['snapshot_id'] ?? ''),
                (string) ($r['units_count'] ?? ''),
                (string) ($r['orphans_count'] ?? ''),
                (string) ($r['clones_count'] ?? ''),
            ];
        }
        $this->table($headers, $tableRows);

        return self::SUCCESS;
    }
}
