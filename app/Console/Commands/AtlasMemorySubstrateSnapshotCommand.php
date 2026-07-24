<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Services\Ai\Memory\Substrate\AtlasMemorySubstrateSnapshotService;
use Illuminate\Console\Command;
use App\Support\YesNo;

/**
 * ACOS Excellence SUB-01 — verified snapshot/backup of memory substrate.
 */
final class AtlasMemorySubstrateSnapshotCommand extends Command
{
    protected $signature = 'atlas:memory:substrate-snapshot
        {--destination= : Override snapshot root (defaults outside live tree)}
        {--skip-restore-proof : Skip ephemeral restore verification}
        {--skip-ledger : Skip Evidence Ledger receipt}
        {--json : Emit canonical JSON}';

    protected $description = 'ACOS SUB-01 — verified snapshot/backup of memory tables + series JSONLs.';

    public function handle(AtlasMemorySubstrateSnapshotService $service): int
    {
        $destination = $this->option('destination');
        $destinationRoot = is_string($destination) && trim($destination) !== '' ? trim($destination) : null;

        $result = $service->run(
            destinationRoot: $destinationRoot,
            skipRestoreProof: (bool) $this->option('skip-restore-proof'),
            skipLedger: (bool) $this->option('skip-ledger'),
        );

        return $this->emit($result, ($result['ok'] ?? false) ? self::SUCCESS : self::FAILURE);
    }

    /**
     * @param  array<string,mixed>  $payload
     */
    private function emit(array $payload, int $code): int
    {
        if ((bool) $this->option('json')) {
            $this->line((string) json_encode(
                $payload,
                JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE,
            ));
        } else {
            foreach (['slice', 'destination', 'dump_hash_sha256', 'dump_ok', 'restore_proof_ok', 'event_id'] as $key) {
                if (array_key_exists($key, $payload)) {
                    $value = $payload[$key];
                    $this->components->twoColumnDetail($key, is_bool($value) ? (YesNo::trueFalse($value)) : (string) $value);
                }
            }
        }

        return $code;
    }
}
