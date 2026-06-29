<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Models\AtlasLoopCampaign;
use App\Services\Ai\AutonomousEvolution\Discovery\AtlasLoopObraClusterDetectorService;
use Illuminate\Console\Command;

/**
 * Arms the dormant {@see AtlasLoopObraClusterDetectorService::detect()} at the operator surface: builds a
 * campaign (id + base workspace) from the options, reads the claimed candidate targets from a JSON file, runs
 * detect(), and emits the parked obra-cluster candidates as deterministic facts.
 *
 * Read-only at this surface: detect() is flag-gated (atlas.loop.obra_cluster_detection_enabled, default OFF ⇒
 * byte-identical empty), and the command only reports the candidates the detector would park.
 */
final class AtlasLoopObraClusterDetectCommand extends Command
{
    protected $signature = 'atlas:loop:obra-cluster-detect {--campaign=} {--input=} {--repo-root=} {--json}';

    protected $description = 'Read-only obra-cluster detection over claimed candidate targets (flag-gated, never enqueues).';

    public function handle(): int
    {
        $campaignId = trim((string) $this->option('campaign'));
        if ($campaignId === '') {
            return $this->refuse('obra-cluster-detect requires --campaign=<id>');
        }
        $input = trim((string) $this->option('input'));
        if ($input === '' || ! is_file($input) || ! is_readable($input)) {
            return $this->refuse('obra-cluster-detect requires --input=<path to a readable targets JSON>');
        }
        $decoded = json_decode((string) file_get_contents($input), true);
        if (! is_array($decoded)) {
            return $this->refuse('input file is not a JSON array/object');
        }
        $targets = isset($decoded['targets']) && is_array($decoded['targets']) ? array_values($decoded['targets']) : array_values($decoded);

        $repoRoot = trim((string) $this->option('repo-root'));
        if ($repoRoot === '') {
            $repoRoot = base_path();
        }

        $campaign = new AtlasLoopCampaign();
        $campaign->base_workspace = $repoRoot;

        $clusters = app(AtlasLoopObraClusterDetectorService::class)->detect($campaign, $targets);

        $facts = [
            'schema' => 'atlas.loop.obra_cluster_detect.v1',
            'campaign_id' => $campaignId,
            'cluster_count' => count($clusters),
            'clusters' => $clusters,
        ];

        if ($this->option('json')) {
            $this->line((string) json_encode($facts, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR));
        } else {
            $this->line('cluster_count: '.$facts['cluster_count']);
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
