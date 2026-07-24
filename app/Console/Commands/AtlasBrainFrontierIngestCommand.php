<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Services\Ai\AutonomousEvolution\Brain\AtlasBrainFrontierSourceRegistry;
use App\Services\Ai\AutonomousEvolution\Brain\AtlasBrainGovernedFrontierFetcher;
use App\Services\Ai\AutonomousEvolution\Brain\AtlasBrainResearchSourceRegistry;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Http;
use App\Support\UtcIsoTimestamp;

final class AtlasBrainFrontierIngestCommand extends Command
{
    /** @var string */
    protected $signature = 'atlas:brain:frontier-ingest {--scope= : brain scope} {--limit=5 : max new rows} {--captured-at= : deterministic timestamp} {--dry-run} {--fetch : gated governed discover/read/ground fetcher} {--json} {--raw : single-line JSON}';

    /** @var string */
    protected $description = 'Append frontier rows, optionally from the default-gated governed discover/read/ground fetcher.';

    public function handle(): int
    {
        $scope = trim((string) ($this->option('scope') ?: config('atlas.brain.default_scope', 'autonomous')));
        $limit = max(0, (int) $this->option('limit'));
        $capturedAt = trim((string) ($this->option('captured-at') ?: UtcIsoTimestamp::now()));
        $dryRun = (bool) $this->option('dry-run');
        $fetch = (bool) $this->option('fetch');

        $frontier = app(AtlasBrainFrontierSourceRegistry::class);
        $readLimit = max(AtlasBrainFrontierSourceRegistry::DEFAULT_K, $frontier->count($scope));
        $existingUrls = array_flip(array_filter(array_map(
            static fn (array $row): string => trim((string) ($row['url'] ?? '')),
            $frontier->topK($scope, $readLimit)
        )));

        $fetchReport = null;
        if ($fetch) {
            $fetchReport = app(AtlasBrainGovernedFrontierFetcher::class)->run(
                limit: $limit,
                dryRun: $dryRun,
                enabled: (bool) config('atlas.brain.frontier_fetcher_enabled', false),
                transport: $dryRun ? null : fn (array $payload): array => $this->performGovernedFetch($payload),
                capturedAt: $capturedAt,
            );
            [$candidates, $skipped] = $this->dedupeCandidates((array) ($fetchReport['candidates'] ?? []), $existingUrls, $limit);
        } else {
            [$candidates, $skipped] = $this->localDiscoverCandidates($existingUrls, $limit, $capturedAt);
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
        if ($fetchReport !== null) {
            $payload += [
                'fetch_status' => (string) ($fetchReport['status'] ?? 'unknown'),
                'fetch_enabled' => (bool) ($fetchReport['enabled'] ?? false),
                'network_attempted' => (bool) ($fetchReport['network_attempted'] ?? false),
                'outbound_payloads' => (array) ($fetchReport['outbound_payloads'] ?? []),
                'egress_safety' => (array) ($fetchReport['egress_safety'] ?? []),
                'connector_governance' => (array) ($fetchReport['connector_governance'] ?? []),
            ];
        }

        $flags = JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE;
        if (! $this->option('raw')) {
            $flags |= JSON_PRETTY_PRINT;
        }
        $this->line((string) json_encode($payload, $flags));

        return self::SUCCESS;
    }

    /**
     * @param  array<string,bool>  $existingUrls
     * @return array{0:list<array<string,mixed>>,1:int}
     */
    private function localDiscoverCandidates(array $existingUrls, int $limit, string $capturedAt): array
    {
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

        return [$candidates, $skipped];
    }

    /**
     * @param  list<array<string,mixed>>  $candidates
     * @param  array<string,bool>  $existingUrls
     * @return array{0:list<array<string,mixed>>,1:int}
     */
    private function dedupeCandidates(array $candidates, array $existingUrls, int $limit): array
    {
        $out = [];
        $skipped = 0;
        foreach ($candidates as $candidate) {
            $url = trim((string) ($candidate['url'] ?? ''));
            if ($url !== '' && isset($existingUrls[$url])) {
                $skipped++;

                continue;
            }
            if (count($out) >= $limit) {
                break;
            }
            $out[] = $candidate;
        }

        return [$out, $skipped];
    }

    /** @param  array<string,mixed>  $payload */
    private function performGovernedFetch(array $payload): array
    {
        $endpoint = (string) ($payload['endpoint'] ?? '');
        if ($endpoint === '') {
            return ['items' => [], 'status' => 'missing_endpoint'];
        }

        try {
            $response = Http::timeout(10)->acceptJson()->get($endpoint, (array) ($payload['query'] ?? []));
        } catch (\Throwable) {
            return ['items' => [], 'status' => 'transport_failed'];
        }

        if (! $response->successful()) {
            return ['items' => [], 'status' => 'http_error', 'code' => $response->status()];
        }

        $json = $response->json();

        return is_array($json) ? $json : ['items' => [], 'status' => 'non_json_response'];
    }
}
