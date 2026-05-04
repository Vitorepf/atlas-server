<?php

namespace App\Console\Commands;

use App\Models\AtlasEngineeringBenchmarkSuite;
use App\Services\Engineering\EngineeringBenchmarkService;
use Illuminate\Console\Command;
use Illuminate\Support\Str;

class AtlasEngineeringBenchmarkReportCommand extends Command
{
    protected $signature = 'atlas:engineering:benchmark:report
        {--suite=atlas-core-smoke : Suite slug or id}
        {--limit=20 : Maximum recent Fair Claude runs to include}
        {--json : Print machine-readable JSON}';

    protected $description = 'Summarize persisted Fair Claude paired benchmark scorecards.';

    public function handle(EngineeringBenchmarkService $benchmarks): int
    {
        $suiteRef = is_string($this->option('suite')) ? trim($this->option('suite')) : '';
        if ($suiteRef === '') {
            $this->error('--suite e obrigatorio.');

            return self::FAILURE;
        }

        $suiteQuery = AtlasEngineeringBenchmarkSuite::query()
            ->where('slug', $suiteRef);
        if (Str::isUuid($suiteRef)) {
            $suiteQuery->orWhere('id', $suiteRef);
        }
        $suite = $suiteQuery->first();
        if (! $suite) {
            $this->error("Benchmark suite nao encontrada: {$suiteRef}");

            return self::FAILURE;
        }

        $payload = $benchmarks->fairClaudeReportPayload($suite, [
            'limit' => max(1, min(200, (int) $this->option('limit'))),
        ]);

        if ((bool) $this->option('json')) {
            $this->line(json_encode($payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));

            return self::SUCCESS;
        }

        $this->render($payload);

        return self::SUCCESS;
    }

    /**
     * @param  array<string,mixed>  $payload
     */
    private function render(array $payload): void
    {
        $suite = (array) ($payload['suite'] ?? []);
        $readiness = (array) ($payload['readiness'] ?? []);
        $scope = (array) ($payload['scope'] ?? []);
        $paired = (array) ($payload['paired_scorecard'] ?? []);
        $allPaired = (array) ($payload['all_paired_scorecard'] ?? []);
        $baseline = (array) ($payload['claude_code_baseline'] ?? []);
        $replay = (array) ($payload['replay_manifest'] ?? []);
        $corpus = (array) ($payload['corpus_manifest'] ?? []);

        $this->newLine();
        $this->components->twoColumnDetail('<fg=bright-blue;options=bold>Fair Claude Benchmark Report</>', (string) ($readiness['status'] ?? 'unknown'));
        $this->components->twoColumnDetail('Suite', (string) ($suite['slug'] ?? '-'));
        $this->components->twoColumnDetail('Paired runs found', (string) ($scope['paired_run_count'] ?? $payload['run_count'] ?? 0));
        $this->components->twoColumnDetail('Official fair runs', (string) ($scope['fair_run_count'] ?? 0));
        $this->components->twoColumnDetail('Official fair results', (string) ($scope['fair_result_count'] ?? 0));
        $this->components->twoColumnDetail('Non-fair paired results', (string) ($scope['non_fair_paired_result_count'] ?? 0));
        $this->components->twoColumnDetail('Active corpus cases', (string) ($corpus['active_cases'] ?? $readiness['active_corpus_case_count'] ?? 0));
        $this->components->twoColumnDetail('Release corpus cases', (string) (data_get($corpus, 'official_subsets.release') ?? $readiness['release_corpus_case_count'] ?? 0));
        $this->components->twoColumnDetail('Official comparable', (string) ($paired['comparable_count'] ?? 0));
        $this->components->twoColumnDetail('All paired comparable', (string) ($allPaired['comparable_count'] ?? 0));
        $this->components->twoColumnDetail('Atlas wins', (string) ($paired['atlas_win_count'] ?? 0));
        $this->components->twoColumnDetail('Claude Code wins', (string) ($paired['claude_code_baseline_win_count'] ?? 0));
        $this->components->twoColumnDetail('Ties', (string) ($paired['tie_count'] ?? 0));
        $this->components->twoColumnDetail('Atlas pass_without_human', (string) ($paired['pass_without_human_rate'] ?? $paired['atlas_pass_without_human_rate'] ?? '-').'%');
        $this->components->twoColumnDetail('Claude Code pass_without_human', (string) ($paired['baseline_pass_without_human_rate'] ?? '-').'%');
        $this->components->twoColumnDetail('Repair conversion', (string) ($paired['repair_conversion_rate'] ?? '-').'%');
        $this->components->twoColumnDetail('Atlas time to green', (string) (data_get($paired, 'time_to_green.atlas_avg_ms') ?? '-').' ms');
        $this->components->twoColumnDetail('Claude Code time to green', (string) (data_get($paired, 'time_to_green.claude_code_baseline_avg_ms') ?? '-').' ms');
        $this->components->twoColumnDetail('Cost per green', (string) (data_get($paired, 'cost_per_green_case.usd') ?? '-').' USD');
        $this->components->twoColumnDetail('Autonomous success lift', (string) ($paired['autonomous_success_lift'] ?? '-').' pp');
        $this->components->twoColumnDetail('Provider violations', (string) ($paired['provider_violation_count'] ?? 0));
        $this->components->twoColumnDetail('Fallback violations', (string) ($paired['fallback_violation_count'] ?? 0));
        $this->components->twoColumnDetail('Baseline executed', (string) ($baseline['executed_count'] ?? 0));
        $this->components->twoColumnDetail('Replay packets', (string) ($replay['packet_count'] ?? 0));
        $this->components->twoColumnDetail('Replay integrity failures', (string) ($replay['artifact_integrity_failed_count'] ?? 0));

        $blocking = (array) ($readiness['blocking_reasons'] ?? []);
        foreach ($blocking as $reason) {
            if (is_scalar($reason) && trim((string) $reason) !== '') {
                $this->warn('Blocking: '.trim((string) $reason));
            }
        }
    }
}
