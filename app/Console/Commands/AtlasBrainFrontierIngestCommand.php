<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Services\Ai\AutonomousEvolution\Brain\AtlasBrainFrontierSourceRegistry;
use App\Services\Ai\AutonomousEvolution\Brain\AtlasBrainResearchSourceRegistry;
use Illuminate\Console\Command;

final class AtlasBrainFrontierIngestCommand extends Command
{
    /** @var string */
    protected $signature = 'atlas:brain:frontier-ingest {--scope= : brain scope} {--limit=5 : max new rows} {--captured-at= : deterministic timestamp} {--dry-run} {--json} {--raw : single-line JSON}';

    /** @var string */
    protected $description = 'Append local research-source discover rows into the brain frontier registry without fetching the network.';

    public function handle(): int
    {
        $scope = trim((string) ($this->option('scope') ?: config('atlas.brain.default_scope', 'autonomous')));
        $limit = max(0, (int) $this->option('limit'));
        $capturedAt = trim((string) ($this->option('captured-at') ?: gmdate('c')));
        $dryRun = (bool) $this->option('dry-run');

        $frontier = app(AtlasBrainFrontierSourceRegistry::class);
        $readLimit = max(AtlasBrainFrontierSourceRegistry::DEFAULT_K, $frontier->count($scope));
        $existingUrls = array_flip(array_filter(array_map(
            static fn (array $row): string => trim((string) ($row['url'] ?? '')),
            $frontier->topK($scope, $readLimit)
        )));

        $candidates = [];
        $skipped = 0;
        foreach (app(AtlasBrainResearchSourceRegistry::class)->forTier('discover') as $source) {
            $url = $source['url_pattern'];
            if (isset($existingUrls[$url])) {
                $skipped++;

                continue;
            }
            if (count($candidates) >= $limit) {
                break;
            }
            $candidates[] = [
                'title' => 'Harvest frontier from '.$url,
                'url' => $url,
                'summary' => 'Operator-seeded discover source; harvest techniques, then read/ground via github/arxiv.',
                'source' => 'research-source-registry',
                'captured_at' => $capturedAt,
            ];
        }

        $written = [];
        if (! $dryRun) {
            foreach ($candidates as $candidate) {
                $row = $frontier->append($scope, $candidate);
                if ($row !== null) {
                    $written[] = $row;
                }
            }
        }

        $payload = [
            'schema' => 'atlas.brain.frontier_ingest.v1',
            'scope' => $scope,
            'dry_run' => $dryRun,
            'appended' => count($written),
            'skipped_existing' => $skipped,
            'candidates' => $dryRun ? $candidates : $written,
        ];

        $flags = JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE;
        if (! $this->option('raw')) {
            $flags |= JSON_PRETTY_PRINT;
        }
        $this->line((string) json_encode($payload, $flags));

        return self::SUCCESS;
    }
}
