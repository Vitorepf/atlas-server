<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Services\Ai\Memory\Substrate\AtlasMemorySubstrateRestoreDrillService;
use Illuminate\Console\Command;

/**
 * ACOS Max ELEV-17 — recurring restore drill for SUB-01 snapshots.
 */
final class AtlasMemorySubstrateRestoreDrillCommand extends Command
{
    protected $signature = 'atlas:substrate:restore-drill
        {--snapshot= : Explicit SUB-01 snapshot directory (defaults to latest)}
        {--skip-ledger : Skip Evidence Ledger receipt; JSONL receipt is still written}
        {--json : Emit canonical JSON}';

    protected $description = 'ELEV-17 — restore latest SUB-01 snapshot into a disposable DB and diff against manifest.';

    public function handle(AtlasMemorySubstrateRestoreDrillService $service): int
    {
        $snapshot = $this->option('snapshot');
        $payload = $service->run(
            snapshotPath: is_string($snapshot) && trim($snapshot) !== '' ? trim($snapshot) : null,
            skipLedger: (bool) $this->option('skip-ledger'),
        );

        if ((bool) $this->option('json')) {
            $this->line((string) json_encode(
                $payload,
                JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE,
            ));
        } else {
            foreach (['status', 'snapshot_path', 'receipt_path', 'ledger_event_id'] as $key) {
                if (array_key_exists($key, $payload)) {
                    $this->components->twoColumnDetail($key, (string) ($payload[$key] ?? ''));
                }
            }
            $this->components->twoColumnDetail('diffs', (string) count((array) ($payload['diffs'] ?? [])));
        }

        return (($payload['status'] ?? null) === 'restored_ok') ? self::SUCCESS : self::FAILURE;
    }
}
