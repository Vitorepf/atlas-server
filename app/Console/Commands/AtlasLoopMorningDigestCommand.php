<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Services\Ai\AutonomousEvolution\AtlasLoopMorningDigestService;
use Illuminate\Console\Command;

/**
 * L4-6 · One-command morning digest for the autonomous Loop.
 */
final class AtlasLoopMorningDigestCommand extends Command
{
    protected $signature = 'atlas:loop:morning-digest
        {--hours= : Lookback window in hours (default config atlas.loop.morning_digest.window_hours)}
        {--json : Machine-readable JSON output}';

    protected $description = 'Answer what Atlas did by itself in the last Loop window: funnel, merges, impact receipts, canaries, cost, keepalive, review queue.';

    public function handle(AtlasLoopMorningDigestService $digest): int
    {
        if (! (bool) config('atlas.loop.morning_digest.enabled', true)) {
            $payload = [
                'schema_version' => AtlasLoopMorningDigestService::SCHEMA_VERSION,
                'status' => 'disabled',
                'reason' => 'morning_digest_disabled',
            ];

            return $this->emit($payload, self::FAILURE);
        }

        $payload = $digest->digest($this->hoursOption());

        return $this->emit($payload, self::SUCCESS);
    }

    /**
     * @param  array<string,mixed>  $payload
     */
    private function emit(array $payload, int $exit): int
    {
        if ((bool) $this->option('json')) {
            $this->line((string) json_encode($payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));

            return $exit;
        }

        $this->components->info((string) ($payload['title'] ?? 'Atlas Loop morning digest'));
        $this->line((string) ($payload['headline'] ?? ($payload['reason'] ?? '')));

        $sections = is_array($payload['sections'] ?? null) ? $payload['sections'] : [];
        $this->newLine();
        $this->components->twoColumnDetail('Window', (string) data_get($payload, 'window.hours', '-').'h');
        $this->components->twoColumnDetail('Merged', (string) data_get($sections, 'merges.merged_24h', 0));
        $this->components->twoColumnDetail('Impact receipts', (string) data_get($sections, 'merges.impact_receipts_24h', 0));
        $this->components->twoColumnDetail('Canary failures', (string) data_get($sections, 'canaries.failed_24h', 0));
        $this->components->twoColumnDetail('Cost measured', (string) data_get($sections, 'cost.coverage_pct_24h', 0.0).'%');
        $this->components->twoColumnDetail('Cost / merge', data_get($sections, 'cost.cost_per_merge_usd_24h') === null ? 'n/a' : '$'.(string) data_get($sections, 'cost.cost_per_merge_usd_24h'));
        $this->components->twoColumnDetail('Keepalive respawns', (string) data_get($sections, 'keepalive.respawned_24h', 0));
        $this->components->twoColumnDetail('Parked for review', (string) data_get($sections, 'operator_review.pending_parked_for_review', 0));

        return $exit;
    }

    private function hoursOption(): ?int
    {
        $raw = trim((string) ($this->option('hours') ?? ''));
        if ($raw === '' || ! ctype_digit($raw)) {
            return null;
        }

        return (int) $raw;
    }
}
