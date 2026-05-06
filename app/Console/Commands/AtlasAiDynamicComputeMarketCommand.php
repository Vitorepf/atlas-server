<?php

namespace App\Console\Commands;

use App\Services\Ai\Kernel\Decision\DynamicComputeMarketReportService;
use Illuminate\Console\Command;
use InvalidArgumentException;

class AtlasAiDynamicComputeMarketCommand extends Command
{
    protected $signature = 'atlas:ai:dynamic-compute-market
        {--provider= : Selected provider to evaluate}
        {--model= : Selected model to evaluate}
        {--domain= : Domain filter}
        {--flow= : Flow filter}
        {--task-type= : Task type filter}
        {--specialist-profile= : Specialist profile filter}
        {--json : Print machine-readable JSON}';

    protected $description = 'Explain Dynamic Compute Market advice for a selected provider without changing routing.';

    public function handle(DynamicComputeMarketReportService $reports): int
    {
        try {
            $payload = $reports->report([
                'provider' => $this->option('provider'),
                'model' => $this->option('model'),
                'domain' => $this->option('domain'),
                'flow' => $this->option('flow'),
                'task_type' => $this->option('task-type'),
                'specialist_profile' => $this->option('specialist-profile'),
            ]);
        } catch (InvalidArgumentException $exception) {
            return $this->renderError($exception->getMessage());
        }

        return $this->render($payload);
    }

    private function renderError(string $message): int
    {
        if ((bool) $this->option('json')) {
            $this->line(json_encode([
                'status' => 'invalid_input',
                'ok' => false,
                'error' => $message,
            ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));

            return self::FAILURE;
        }

        $this->error('Invalid Dynamic Compute Market input: '.$message);

        return self::FAILURE;
    }

    /**
     * @param  array<string,mixed>  $payload
     */
    private function render(array $payload): int
    {
        if ((bool) $this->option('json')) {
            $this->line(json_encode($payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));

            return ($payload['status'] ?? null) === 'ok' ? self::SUCCESS : self::FAILURE;
        }

        $market = (array) ($payload['dynamic_compute_market'] ?? []);
        $this->components->twoColumnDetail('<fg=bright-blue;options=bold>Atlas Dynamic Compute Market</>', (string) ($payload['status'] ?? 'unknown'));
        $this->components->twoColumnDetail('Mode', (string) ($payload['mode'] ?? 'unknown'));
        $this->components->twoColumnDetail('Authority', (string) ($payload['authority'] ?? 'unknown'));
        $this->components->twoColumnDetail('Provider', (string) data_get($payload, 'input.provider', '-'));
        $this->components->twoColumnDetail('Model', (string) (data_get($payload, 'input.model') ?: '-'));
        $this->components->twoColumnDetail('Recommendation', (string) ($market['recommendation'] ?? 'unknown'));
        $this->components->twoColumnDetail('Next action', (string) ($market['recommended_next_action'] ?? 'unknown'));
        $this->components->twoColumnDetail('Reason', (string) ($market['recommendation_reason'] ?? 'unknown'));
        $this->components->twoColumnDetail('Confidence', (string) ($market['confidence'] ?? 'unknown'));
        $this->components->twoColumnDetail('Risk', (string) ($market['risk'] ?? 'unknown'));
        $this->components->twoColumnDetail('Changes provider', data_get($market, 'routing_control.changes_provider') ? 'yes' : 'no');
        $this->components->twoColumnDetail('AP-99 available', data_get($market, 'ap99.available') ? 'yes' : 'no');

        $candidate = data_get($market, 'benchmark_candidate');
        if (is_array($candidate)) {
            $this->table(['candidate', 'sample', 'events', 'success', 'latency', 'cost'], [[
                $candidate['provider'] ?? '-',
                $candidate['sample_status'] ?? '-',
                $candidate['event_count'] ?? 0,
                $candidate['success_rate'] ?? '-',
                $candidate['average_latency_seconds'] ?? '-',
                $candidate['average_cost_microusd'] ?? '-',
            ]]);
        }

        return ($payload['status'] ?? null) === 'ok' ? self::SUCCESS : self::FAILURE;
    }
}
