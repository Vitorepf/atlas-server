<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Services\Ai\AutonomousEvolution\AtlasLoopProviderHealthProbe;
use Illuminate\Console\Command;

/**
 * Arms the dormant {@see AtlasLoopProviderHealthProbe::snapshot()} at the operator surface: emits the windowed
 * provider-health distribution (sample count, p50/p95 latency, ok/error counts, cost sum) for a provider key
 * from its append-only ledger.
 *
 * Read-only: a pure read over the JSONL ledger — no record, no score, no recommendation, no action. An empty
 * ledger yields an all-zero snapshot.
 */
final class AtlasLoopProviderHealthCommand extends Command
{
    protected $signature = 'atlas:loop:provider-health {--provider=} {--window-seconds=86400} {--json}';

    protected $description = 'Read-only windowed provider-health snapshot (p50/p95 latency, ok/error, cost) for a provider.';

    public function handle(): int
    {
        $provider = trim((string) $this->option('provider'));
        if ($provider === '') {
            $this->line((string) json_encode([
                'outcome' => 'refused',
                'reason' => 'usage_error',
                'message' => 'provider-health requires --provider=<key>',
            ], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR));

            return self::FAILURE;
        }

        $window = max(1, (int) $this->option('window-seconds'));
        $snapshot = app(AtlasLoopProviderHealthProbe::class)->snapshot($provider, $window);

        if ($this->option('json')) {
            $this->line((string) json_encode($snapshot, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR));
        } else {
            foreach ($snapshot as $k => $v) {
                $this->line($k.': '.$v);
            }
        }

        return self::SUCCESS;
    }
}
