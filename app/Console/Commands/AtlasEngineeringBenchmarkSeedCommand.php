<?php

namespace App\Console\Commands;

use App\Models\AtlasEngineeringRun;
use App\Services\Engineering\EngineeringBenchmarkService;
use Illuminate\Console\Command;

class AtlasEngineeringBenchmarkSeedCommand extends Command
{
    protected $signature = 'atlas:engineering:benchmark:seed
        {--suite=atlas-core-smoke : Suite slug to create or update}
        {--name= : Suite display name}
        {--description= : Suite description}
        {--workspace= : Workspace path used to scope/promote runs}
        {--from-run=* : Promote one or more engineering run ids into benchmark cases}
        {--from-recent-runs=0 : Promote the latest eligible resolved runs}
        {--min-source-score=85 : Minimum score for recent source runs}
        {--decision=resolved : Source decision filter for recent runs}
        {--expected-decision= : Expected benchmark decision for promoted cases}
        {--min-score= : Minimum benchmark score for promoted cases}
        {--tier=smoke : Corpus tier for promoted cases}
        {--domain= : Corpus domain slug for promoted cases}
        {--risk= : Risk profile: low, medium, high or critical}
        {--curation-status=curated : Curation status for promoted cases}
        {--tag=* : Extra tags applied to promoted cases}
        {--status=active : Case status for promoted cases}
        {--refresh-manifest : Refresh and persist the suite corpus manifest}
        {--json : Print machine-readable JSON}';

    protected $description = 'Create the default Atlas-Bench suite and promote real Harness runs into reusable benchmark cases.';

    public function handle(EngineeringBenchmarkService $benchmarks): int
    {
        $suite = $benchmarks->ensureDefaultSuite([
            'slug' => $this->option('suite'),
            'name' => $this->option('name'),
            'description' => $this->option('description'),
            'status' => 'active',
        ]);

        $promoted = collect();
        foreach ((array) $this->option('from-run') as $runId) {
            if (! is_string($runId) || trim($runId) === '') {
                continue;
            }

            $run = AtlasEngineeringRun::query()->find($runId);
            if (! $run) {
                $this->error("Engineering run nao encontrado: {$runId}");

                return self::FAILURE;
            }

            $promoted->push($benchmarks->promoteRunToCase($run, $suite, $this->caseOptions()));
        }

        $recentLimit = max(0, (int) $this->option('from-recent-runs'));
        if ($recentLimit > 0) {
            $promoted = $promoted->merge($benchmarks->promoteRecentRuns($suite, array_merge($this->caseOptions(), [
                'limit' => $recentLimit,
                'min_source_score' => (int) $this->option('min-source-score'),
                'decision' => $this->option('decision'),
            ])));
        }

        $promoted = $promoted
            ->unique('id')
            ->values();
        $manifest = ((bool) $this->option('refresh-manifest') || $promoted->isNotEmpty())
            ? $benchmarks->refreshCorpusManifest($suite->refresh())
            : (array) data_get($suite->metadata, 'corpus_manifest', []);
        $payload = [
            'suite' => $benchmarks->suitePayload($suite->refresh())['suite'] ?? null,
            'corpus_manifest' => $manifest,
            'promoted_count' => $promoted->count(),
            'promoted_cases' => $promoted
                ->map(fn ($case): array => $benchmarks->casePayload($case))
                ->values()
                ->all(),
        ];

        if ((bool) $this->option('json')) {
            $this->line(json_encode($payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));

            return self::SUCCESS;
        }

        $this->render($payload);

        return self::SUCCESS;
    }

    /**
     * @return array<string,mixed>
     */
    private function caseOptions(): array
    {
        return [
            'workspace' => $this->workspace(),
            'expected_decision' => is_string($this->option('expected-decision')) && trim((string) $this->option('expected-decision')) !== ''
                ? $this->option('expected-decision')
                : null,
            'min_score' => is_numeric($this->option('min-score')) ? (int) $this->option('min-score') : null,
            'corpus_tier' => is_string($this->option('tier')) ? $this->option('tier') : 'smoke',
            'domain_slug' => is_string($this->option('domain')) ? $this->option('domain') : null,
            'risk_profile' => is_string($this->option('risk')) ? $this->option('risk') : null,
            'curation_status' => is_string($this->option('curation-status')) ? $this->option('curation-status') : 'curated',
            'tags' => (array) $this->option('tag'),
            'status' => is_string($this->option('status')) ? $this->option('status') : 'active',
        ];
    }

    private function workspace(): ?string
    {
        $workspace = $this->option('workspace');
        if (! is_string($workspace) || trim($workspace) === '') {
            return null;
        }

        $resolved = realpath($workspace);

        return $resolved && is_dir($resolved) ? $resolved : $workspace;
    }

    /**
     * @param  array<string,mixed>  $payload
     */
    private function render(array $payload): void
    {
        $suite = (array) ($payload['suite'] ?? []);
        $cases = collect((array) ($payload['promoted_cases'] ?? []));

        $this->newLine();
        $this->components->twoColumnDetail('<fg=bright-blue;options=bold>Atlas-Bench Corpus</>', (string) ($suite['slug'] ?? '-'));
        $this->components->twoColumnDetail('Suite', (string) ($suite['name'] ?? '-'));
        $this->components->twoColumnDetail('Promoted cases', (string) ($payload['promoted_count'] ?? 0));
        $this->components->twoColumnDetail('Active corpus cases', (string) data_get($payload, 'corpus_manifest.active_cases', '-'));

        if ($cases->isNotEmpty()) {
            $this->newLine();
            $this->table(
                ['case', 'tier', 'risk', 'expected', 'min score', 'tags'],
                $cases
                    ->map(fn (array $case): array => [
                        $case['case_code'] ?? '-',
                        $case['corpus_tier'] ?? '-',
                        $case['risk_profile'] ?? '-',
                        $case['expected_decision'] ?? 'resolved',
                        $case['min_score'] ?? '-',
                        implode(', ', array_slice((array) ($case['tags'] ?? []), 0, 5)),
                    ])
                    ->all(),
            );
        }
    }
}
