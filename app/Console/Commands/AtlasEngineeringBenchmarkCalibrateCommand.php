<?php

namespace App\Console\Commands;

use App\Models\AtlasEngineeringBenchmarkSuite;
use App\Services\Engineering\EngineeringBenchmarkService;
use Illuminate\Console\Command;

class AtlasEngineeringBenchmarkCalibrateCommand extends Command
{
    protected $signature = 'atlas:engineering:benchmark:calibrate
        {--suite=atlas-core-smoke : Suite slug or id}
        {--limit=200 : Maximum outcome-bearing runs used for calibration}
        {--json : Print machine-readable JSON}';

    protected $description = 'Calibrate Atlas-Bench rollout policy and corpus health from recorded benchmark outcomes.';

    public function handle(EngineeringBenchmarkService $benchmarks): int
    {
        $suiteRef = is_string($this->option('suite')) ? trim($this->option('suite')) : '';
        if ($suiteRef === '') {
            $this->error('--suite e obrigatorio.');

            return self::FAILURE;
        }

        $suite = AtlasEngineeringBenchmarkSuite::query()
            ->where('id', $suiteRef)
            ->orWhere('slug', $suiteRef)
            ->first();
        if (! $suite) {
            $this->error("Benchmark suite nao encontrada: {$suiteRef}");

            return self::FAILURE;
        }

        $calibration = $benchmarks->calibrateSuite($suite, [
            'limit' => max(1, min(500, (int) $this->option('limit'))),
        ]);
        $payload = [
            'suite' => $benchmarks->suitePayload($suite->refresh())['suite'] ?? null,
            'rollout_calibration' => $calibration,
        ];

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
        $calibration = (array) ($payload['rollout_calibration'] ?? []);
        $policy = (array) ($calibration['recommended_policy'] ?? []);
        $candidates = collect((array) ($calibration['quarantine_candidates'] ?? []));
        $overrides = collect((array) ($calibration['risk_overrides'] ?? []));

        $this->newLine();
        $this->components->twoColumnDetail('<fg=bright-blue;options=bold>Atlas-Bench Calibration</>', (string) ($suite['slug'] ?? '-'));
        $this->components->twoColumnDetail('Status', (string) ($calibration['status'] ?? 'unknown'));
        $this->components->twoColumnDetail('Confidence', (string) ($calibration['confidence'] ?? 'none'));
        $this->components->twoColumnDetail('Outcomes', (string) ($calibration['total_outcomes'] ?? 0));
        $this->components->twoColumnDetail('Bad outcome rate', $calibration['bad_outcome_rate'] === null ? '-' : $calibration['bad_outcome_rate'].'%');
        $this->components->twoColumnDetail('Passed gate rollout', (string) ($policy['default_status_after_passed_gate'] ?? '-'));
        $this->components->twoColumnDetail('Warning gate rollout', (string) ($policy['default_status_after_warning_gate'] ?? '-'));
        $this->components->twoColumnDetail('Outcome SLA', (string) ($policy['outcome_required_within_hours'] ?? '-').'h');
        $this->components->twoColumnDetail('Min healthy score', (string) ($policy['healthy_outcome_min_score'] ?? '-'));
        $this->components->twoColumnDetail('Risk overrides', (string) $overrides->count());
        $this->components->twoColumnDetail('Quarantine candidates', (string) $candidates->count());

        if ($overrides->isNotEmpty()) {
            $this->newLine();
            $this->table(
                ['dimension', 'value', 'sample', 'bad rate', 'policy'],
                $overrides
                    ->map(fn (array $override): array => [
                        $override['dimension'] ?? '-',
                        $override['value'] ?? '-',
                        $override['sample_size'] ?? '-',
                        isset($override['bad_outcome_rate']) ? $override['bad_outcome_rate'].'%' : '-',
                        data_get($override, 'policy.default_status_after_passed_gate', '-'),
                    ])
                    ->all(),
            );
        }

        if ($candidates->isNotEmpty()) {
            $this->newLine();
            $this->table(
                ['case', 'risk', 'bad', 'failed', 'suggested'],
                $candidates
                    ->take(10)
                    ->map(fn (array $candidate): array => [
                        $candidate['case_code'] ?? $candidate['case_id'] ?? '-',
                        $candidate['risk_profile'] ?? '-',
                        $candidate['bad_outcome_count'] ?? 0,
                        $candidate['failed_result_count'] ?? 0,
                        $candidate['suggested_curation_status'] ?? '-',
                    ])
                    ->all(),
            );
        }
    }
}
