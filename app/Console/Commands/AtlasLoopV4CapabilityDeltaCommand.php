<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Services\Ai\AutonomousEvolution\V4\AtlasLoopV4CapabilityDeltaAttribution;
use Illuminate\Console\Command;

/**
 * Arms the dormant {@see AtlasLoopV4CapabilityDeltaAttribution::attribute()} at the operator surface: reads pre
 * and post capability snapshots plus the landed proposals from JSON and emits the capability-delta attribution
 * — per-dimension deltas with their co-claimants, the unattributed (confound) dimensions, and any refusal —
 * as deterministic facts.
 *
 * Read-only + pure: refuses on snapshot schema drift or zero movement; no I/O, DB, or provider.
 */
final class AtlasLoopV4CapabilityDeltaCommand extends Command
{
    protected $signature = 'atlas:loop:v4-capability-delta {--input=} {--json}';

    protected $description = 'Read-only capability-delta attribution (per-dimension delta -> claiming proposals + confounds).';

    public function handle(): int
    {
        $input = trim((string) $this->option('input'));
        if ($input === '' || ! is_file($input) || ! is_readable($input)) {
            return $this->refuse('v4-capability-delta requires --input=<path to a readable JSON>');
        }

        $decoded = json_decode((string) file_get_contents($input), true);
        if (! is_array($decoded) || ! is_array($decoded['pre'] ?? null) || ! is_array($decoded['post'] ?? null)) {
            return $this->refuse('input JSON must be an object with `pre` and `post` snapshot maps');
        }
        $landed = is_array($decoded['landed_proposals'] ?? null) ? array_values($decoded['landed_proposals']) : [];

        $attribution = app(AtlasLoopV4CapabilityDeltaAttribution::class)->attribute($decoded['pre'], $decoded['post'], $landed);

        $facts = ['schema' => 'atlas.loop.v4_capability_delta.v1'] + $attribution;

        if ($this->option('json')) {
            $this->line((string) json_encode($facts, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR));
        } else {
            $this->line('attributed: '.($facts['attributed'] ? 'yes' : 'no').'  refuse_reason: '.($facts['refuse_reason'] ?? '-'));
            $this->line('unattributed: '.implode(', ', $facts['unattributed_dimensions']));
        }

        return self::SUCCESS;
    }

    private function refuse(string $message): int
    {
        $this->line((string) json_encode([
            'outcome' => 'refused',
            'reason' => 'usage_error',
            'message' => $message,
        ], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR));

        return self::FAILURE;
    }
}
