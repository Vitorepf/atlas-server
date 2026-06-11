<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Services\Ai\StrategicOperatingSystem\AtlasStrategicOperatingSystemRuntimeService;
use Illuminate\Console\Command;

final class AtlasStrategicOperatingSystemCommand extends Command
{
    protected $signature = 'atlas:strategic-os
        {action=snapshot : snapshot|feedback|experiment|organization|portfolio|governance|certify}
        {--workspace= : Workspace path}
        {--metric=* : Product metric as name=value}
        {--log=* : Runtime log summary}
        {--incident=* : Incident summary}
        {--regression=* : Regression summary}
        {--feedback=* : Human feedback summary}
        {--revenue= : Observed revenue USD}
        {--cost= : Observed cost USD}
        {--capital-budget=0 : Capital budget USD}
        {--json : Emit canonical JSON}
        {--strict : Exit non-zero unless ready}';

    protected $description = 'Operate the Atlas Strategic OS: feedback graph, experiments, organization twin, portfolio and governance evolution.';

    public function handle(AtlasStrategicOperatingSystemRuntimeService $runtime): int
    {
        $input = $this->inputPayload();
        $action = (string) $this->argument('action');
        $payload = match ($action) {
            'snapshot' => $runtime->operatingSystem($input),
            'feedback' => $runtime->runtimeFeedbackGraph($input),
            'experiment' => $runtime->experimentStrategyLoop($runtime->runtimeFeedbackGraph($input), $input),
            'organization' => $this->organization($runtime, $input),
            'portfolio' => $this->portfolio($runtime, $input),
            'governance' => $this->governance($runtime, $input),
            'certify' => $runtime->certify(),
            default => [
                'schema_version' => 'atlas.strategic_operating_system.command_error.v1',
                'status' => 'blocked',
                'blockers' => ['unknown_action'],
            ],
        };

        if ((bool) $this->option('json')) {
            $this->line(json_encode($payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) ?: '{}');

            return $this->exitCode($payload);
        }

        $this->components->twoColumnDetail('Strategic OS', (string) ($payload['status'] ?? 'unknown'));
        $this->components->twoColumnDetail('Schema', (string) ($payload['schema_version'] ?? $payload['schema'] ?? 'unknown'));
        $this->components->twoColumnDetail('Hash', (string) ($payload['strategic_operating_system_hash'] ?? $payload['certification_hash'] ?? $payload['feedback_graph_hash'] ?? ''));

        return $this->exitCode($payload);
    }

    /**
     * @return array<string,mixed>
     */
    private function organization(AtlasStrategicOperatingSystemRuntimeService $runtime, array $input): array
    {
        $feedback = $runtime->runtimeFeedbackGraph($input);
        $experiment = $runtime->experimentStrategyLoop($feedback, $input);

        return $runtime->organizationTwin($feedback, $experiment, $input);
    }

    /**
     * @return array<string,mixed>
     */
    private function portfolio(AtlasStrategicOperatingSystemRuntimeService $runtime, array $input): array
    {
        $feedback = $runtime->runtimeFeedbackGraph($input);
        $experiment = $runtime->experimentStrategyLoop($feedback, $input);
        $organization = $runtime->organizationTwin($feedback, $experiment, $input);

        return $runtime->portfolioCapitalBrain($feedback, $experiment, $organization, $input);
    }

    /**
     * @return array<string,mixed>
     */
    private function governance(AtlasStrategicOperatingSystemRuntimeService $runtime, array $input): array
    {
        $feedback = $runtime->runtimeFeedbackGraph($input);
        $experiment = $runtime->experimentStrategyLoop($feedback, $input);
        $organization = $runtime->organizationTwin($feedback, $experiment, $input);
        $portfolio = $runtime->portfolioCapitalBrain($feedback, $experiment, $organization, $input);

        return $runtime->governancePolicyEvolution($feedback, $experiment, $organization, $portfolio, $input);
    }

    /**
     * @return array<string,mixed>
     */
    private function inputPayload(): array
    {
        return [
            'workspace' => (string) ($this->option('workspace') ?: base_path()),
            'logs' => (array) $this->option('log'),
            'incidents' => (array) $this->option('incident'),
            'regressions' => (array) $this->option('regression'),
            'human_feedback' => (array) $this->option('feedback'),
            'metrics' => $this->metrics((array) $this->option('metric')),
            'revenue_usd' => $this->option('revenue'),
            'cost_usd' => $this->option('cost'),
            'capital_budget_usd' => $this->option('capital-budget'),
        ];
    }

    /**
     * @param  list<string>  $items
     * @return array<string,float|string>
     */
    private function metrics(array $items): array
    {
        $metrics = [];
        foreach ($items as $item) {
            if (! is_string($item) || ! str_contains($item, '=')) {
                continue;
            }
            [$key, $value] = array_map('trim', explode('=', $item, 2));
            if ($key === '') {
                continue;
            }
            $metrics[$key] = is_numeric($value) ? (float) $value : $value;
        }

        return $metrics;
    }

    /**
     * @param  array<string,mixed>  $payload
     */
    private function exitCode(array $payload): int
    {
        return (bool) $this->option('strict') && ($payload['status'] ?? null) !== 'ready'
            ? self::FAILURE
            : self::SUCCESS;
    }
}
