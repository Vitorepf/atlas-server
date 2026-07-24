<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Services\Ai\OpenBrain\AtlasAobgLatencyLedger;
use Illuminate\Console\Command;
use App\Console\Concerns\EmitsCanonicalJson;

final class AtlasContextLatencyCommand extends Command
{
    use EmitsCanonicalJson;

    protected $signature = 'atlas:context:latency
        {--day= : YYYY-MM-DD day to report}
        {--days=7 : Number of latest days to report when --day is omitted}
        {--json : Emit canonical JSON}';

    protected $description = 'Report AOBG context latency p50/p95 by op/day from the local JSONL ledger.';

    public function handle(AtlasAobgLatencyLedger $ledger): int
    {
        $day = $this->option('day');
        $days = max(1, (int) $this->option('days'));
        $payload = $ledger->report(
            is_string($day) && trim($day) !== '' ? trim($day) : null,
            $days,
        );

        if ((bool) $this->option('json')) {
            $this->line($this->encode($payload));

            return self::SUCCESS;
        }

        $rows = [];
        foreach ((array) ($payload['days'] ?? []) as $date => $dayReport) {
            foreach ((array) ($dayReport['ops'] ?? []) as $op => $stats) {
                $rows[] = [
                    (string) $date,
                    (string) $op,
                    (string) ($stats['samples'] ?? 0),
                    $stats['p50_ms'] === null ? '-' : (string) $stats['p50_ms'],
                    $stats['p95_ms'] === null ? '-' : (string) $stats['p95_ms'],
                ];
            }
        }

        $this->table(['day', 'op', 'samples', 'p50_ms', 'p95_ms'], $rows);

        return self::SUCCESS;
    }
}
