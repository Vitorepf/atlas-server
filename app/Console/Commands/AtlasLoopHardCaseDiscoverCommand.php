<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Services\Ai\AutonomousEvolution\AtlasLoopHardCaseAutoDiscovery;
use Illuminate\Console\Command;

/**
 * Runner for {@see AtlasLoopHardCaseAutoDiscovery}: surfaces hard cases from ledger rows and writes the typed
 * backlog (hard_cases.jsonl) the AtlasLoopBacklogAutoFeederService consumes. Read-only mining + one append-only
 * write of the backlog. Flag atlas.loop.hard_case_discovery_enabled default OFF; the discovery itself is also
 * gated by the §0 master switch (OFF ⇒ empty, no write).
 */
final class AtlasLoopHardCaseDiscoverCommand extends Command
{
    protected $signature = 'atlas:loop:hard-case-discover {--json : Canonical JSON output}';

    protected $description = 'Mine ledger rows for hard cases (provider disagreement / rollback / reprove flip) into a typed backlog.';

    public function handle(): int
    {
        if (! (bool) config('atlas.loop.hard_case_discovery_enabled', false)) {
            $this->line((string) json_encode(['status' => 'disabled', 'hard_case_count' => 0], JSON_UNESCAPED_SLASHES));

            return self::SUCCESS;
        }

        // Operator-supplied ledger rows (a JSON list); absent ⇒ nothing to mine. The discovery never fabricates.
        $rows = [];
        $rowsPath = trim((string) config('atlas.loop.hard_case_rows_path', ''));
        if ($rowsPath !== '' && is_file($rowsPath)) {
            $decoded = json_decode((string) @file_get_contents($rowsPath), true);
            if (is_array($decoded)) {
                $rows = array_values(array_filter($decoded, 'is_array'));
            }
        }

        $cases = (new AtlasLoopHardCaseAutoDiscovery)->discover($rows); // master-gated inside

        $outputPath = storage_path('atlas-loop/hard-cases/hard_cases.jsonl');
        if ($cases !== []) {
            @mkdir(dirname($outputPath), 0o775, true);
            $blob = '';
            foreach ($cases as $case) {
                $blob .= json_encode($case, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE).PHP_EOL;
            }
            @file_put_contents($outputPath, $blob, FILE_APPEND | LOCK_EX);
        }

        $this->line((string) json_encode([
            'status' => 'ok',
            'hard_case_count' => count($cases),
            'output' => $cases !== [] ? $outputPath : null,
        ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));

        return self::SUCCESS;
    }
}
