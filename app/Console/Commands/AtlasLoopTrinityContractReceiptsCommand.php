<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Services\Ai\AutonomousEvolution\Trinity\AntiDecoupling\AtlasLoopTrinityContractReceiptLedger;
use Illuminate\Console\Command;

/**
 * Operator-facing reader of the Trinity anti-decoupling receipt ledger. Replays the entire NDJSON corpus
 * (daily-rotated) and prints every receipt as JSON. Read-only.
 */
final class AtlasLoopTrinityContractReceiptsCommand extends Command
{
    /** @var string */
    protected $signature = 'atlas:loop:trinity:contract-receipts {--primitive=} {--kind=} {--json}';

    /** @var string */
    protected $description = 'Print the append-only Trinity contract receipt ledger (replay).';

    public function handle(AtlasLoopTrinityContractReceiptLedger $ledger): int
    {
        $primitive = trim((string) $this->option('primitive'));
        $kind = trim((string) $this->option('kind'));
        $receipts = $ledger->replay();

        if ($primitive !== '') {
            $receipts = array_values(array_filter($receipts, static fn (array $r): bool => (string) ($r['primitive'] ?? '') === $primitive));
        }
        if ($kind !== '') {
            $receipts = array_values(array_filter($receipts, static fn (array $r): bool => (string) ($r['kind'] ?? '') === $kind));
        }

        $this->line(json_encode(['receipts' => $receipts], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));

        return self::SUCCESS;
    }
}
