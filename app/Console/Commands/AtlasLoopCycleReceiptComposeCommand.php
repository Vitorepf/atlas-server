<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Services\Ai\AutonomousEvolution\Receipts\AtlasLoopCycleReceiptComposer;
use Illuminate\Console\Command;

/**
 * Arms the dormant pure {@see AtlasLoopCycleReceiptComposer::compose()} at the operator surface: composes a
 * cycle receipt from its sources (base/head commit + the impact / frozen-verdict / telemetry / feedback /
 * maestro facts) and emits it as deterministic facts. Pure, read-only.
 *
 * --sources accepts inline JSON or a path to a JSON file.
 */
final class AtlasLoopCycleReceiptComposeCommand extends Command
{
    protected $signature = 'atlas:loop:cycle-receipt-compose {--cycle-id=} {--sources=} {--json}';

    protected $description = 'Read-only: compose a loop cycle receipt from its sources.';

    public function handle(AtlasLoopCycleReceiptComposer $composer): int
    {
        $cycleId = trim((string) $this->option('cycle-id'));
        if ($cycleId === '') {
            $this->line((string) json_encode(['status' => 'usage_error', 'reason' => 'cycle_id_required'], JSON_UNESCAPED_SLASHES));

            return self::INVALID;
        }

        $sources = [];
        $sourcesOption = $this->option('sources');
        if ($sourcesOption !== null && trim((string) $sourcesOption) !== '') {
            $raw = is_file((string) $sourcesOption) ? (string) file_get_contents((string) $sourcesOption) : (string) $sourcesOption;
            $decoded = json_decode($raw, true);
            if (! is_array($decoded)) {
                $this->line((string) json_encode(['status' => 'invalid_json', 'sources' => (string) $sourcesOption], JSON_UNESCAPED_SLASHES));

                return self::INVALID;
            }
            $sources = $decoded;
        }

        $this->line((string) json_encode(
            $composer->compose($cycleId, $sources),
            JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR,
        ));

        return self::SUCCESS;
    }
}
