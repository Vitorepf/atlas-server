<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Services\Ai\AutonomousEvolution\Resilience\AtlasLoopRespawnPolicy;
use Illuminate\Console\Command;

/**
 * Arms the dormant {@see AtlasLoopRespawnPolicy::decide()} at the operator surface: from a campaign's state,
 * emits whether the supervisor should be respawned plus the gating reasons (master switch, status, budget,
 * cooldown, supervisor liveness) as deterministic facts. Decision only — it respawns nothing.
 *
 * --campaign accepts inline JSON or a path to a JSON file.
 */
final class AtlasLoopRespawnDecideCommand extends Command
{
    protected $signature = 'atlas:loop:respawn-decide {--campaign=} {--json}';

    protected $description = 'Read-only: decide whether a campaign supervisor should respawn (decision only).';

    public function handle(AtlasLoopRespawnPolicy $policy): int
    {
        $campaignOption = $this->option('campaign');
        if ($campaignOption === null || trim((string) $campaignOption) === '') {
            $this->line((string) json_encode(['status' => 'usage_error', 'reason' => 'campaign_required'], JSON_UNESCAPED_SLASHES));

            return self::INVALID;
        }

        $raw = is_file((string) $campaignOption) ? (string) file_get_contents((string) $campaignOption) : (string) $campaignOption;
        $campaign = json_decode($raw, true);
        if (! is_array($campaign)) {
            $this->line((string) json_encode(['status' => 'invalid_json', 'campaign' => (string) $campaignOption], JSON_UNESCAPED_SLASHES));

            return self::INVALID;
        }

        $this->line((string) json_encode(
            $policy->decide($campaign),
            JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR,
        ));

        return self::SUCCESS;
    }
}
