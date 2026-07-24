<?php

namespace App\Console\Commands;

use App\Services\Ai\Kernel\Architecture\AtlasProviderReleaseSourceRegistry;
use Illuminate\Console\Command;
use App\Support\YesNo;

class AtlasAiProviderReleaseSourcesCommand extends Command
{
    protected $signature = 'atlas:ai:provider-release-sources
        {--provider= : Filter by provider}
        {--tier= : Filter by source tier}
        {--cadence= : Filter by cadence}
        {--track= : Filter by tracked capability family}
        {--url= : Optional detected release URL to classify as a read-only candidate}
        {--title= : Optional detected release title}
        {--published-at= : Optional source publication timestamp}
        {--content-hash= : Optional precomputed content/body hash}
        {--json : Print machine-readable JSON}';

    protected $description = 'List governed provider release sources or classify a detected URL into a read-only candidate.';

    public function handle(AtlasProviderReleaseSourceRegistry $registry): int
    {
        $payload = $this->payload($registry);

        if ((bool) $this->option('json')) {
            $this->line(json_encode($payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));

            return self::SUCCESS;
        }

        $this->components->twoColumnDetail('<fg=bright-blue;options=bold>Atlas Provider Release Sources</>', $payload['status']);
        $this->components->twoColumnDetail('Mode', $payload['mode']);
        $this->components->twoColumnDetail('Authority', $payload['authority']);
        $this->components->twoColumnDetail('Network fetching', data_getYesNo::format($payload, 'guardrails.network_fetching_enabled'));
        $this->components->twoColumnDetail('Routing changes', data_getYesNo::format($payload, 'guardrails.changes_routing'));

        if (isset($payload['candidate'])) {
            $this->components->twoColumnDetail('Candidate', data_get($payload, 'candidate.status'));
            $this->components->twoColumnDetail('Source', data_get($payload, 'candidate.source.id'));
            $this->components->twoColumnDetail('Release type', data_get($payload, 'candidate.release_type'));
            $this->components->twoColumnDetail('Triage', data_get($payload, 'candidate.recommended_triage_action'));

            return self::SUCCESS;
        }

        $this->components->twoColumnDetail('Sources', (string) $payload['source_count']);
        $this->newLine();
        $this->table(
            ['id', 'provider', 'tier', 'cadence', 'tracks'],
            collect($payload['sources'])->map(fn (array $source): array => [
                $source['id'],
                $source['provider'],
                $source['tier'],
                $source['cadence'],
                implode(', ', array_slice((array) $source['tracks'], 0, 4)),
            ])->all(),
        );

        return self::SUCCESS;
    }

    /**
     * @return array<string,mixed>
     */
    private function payload(AtlasProviderReleaseSourceRegistry $registry): array
    {
        $summary = $registry->summary([
            'provider' => $this->option('provider'),
            'tier' => $this->option('tier'),
            'cadence' => $this->option('cadence'),
            'track' => $this->option('track'),
        ]);

        $url = trim((string) $this->option('url'));
        if ($url === '') {
            return $summary;
        }

        return array_merge($summary, [
            'mode' => 'read_only_candidate_preview',
            'candidate' => $registry->candidateFromDetection(
                url: $url,
                title: trim((string) $this->option('title')) ?: 'untitled-provider-release-candidate',
                contentHash: trim((string) $this->option('content-hash')) ?: null,
                publishedAt: trim((string) $this->option('published-at')) ?: null,
            ),
        ]);
    }
}
