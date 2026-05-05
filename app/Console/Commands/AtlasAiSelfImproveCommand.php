<?php

namespace App\Console\Commands;

use App\Services\Ai\SelfImprovement\AtlasSelfImprovementOrchestrator;
use Illuminate\Console\Command;

class AtlasAiSelfImproveCommand extends Command
{
    protected $signature = 'atlas:ai:self-improve
        {--flow=nightly_review : Self-improvement flow to run}
        {--hours=24 : Evidence Ledger lookback window}
        {--limit=5 : Maximum findings/proposals}
        {--emit : Emit safe review proposals to the Inbox}
        {--plan-only : Print the execution plan without running the runtime}
        {--list-flows : List supported self-improvement flows}
        {--json : Print machine-readable JSON}';

    protected $description = 'Run the Atlas AI self-improvement review over Evidence Ledger events.';

    public function handle(AtlasSelfImprovementOrchestrator $orchestrator): int
    {
        if ((bool) $this->option('list-flows')) {
            return $this->renderFlows($orchestrator);
        }

        $options = [
            'emit' => (bool) $this->option('emit'),
            'hours' => (int) $this->option('hours'),
            'limit' => (int) $this->option('limit'),
        ];
        $flow = (string) $this->option('flow');

        if ((bool) $this->option('plan-only')) {
            $plan = $orchestrator->flowPlan($flow, $options);

            if ((bool) $this->option('json')) {
                $this->line(json_encode([
                    'schema_version' => 1,
                    'status' => 'planned',
                    'plan' => $plan,
                ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));

                return self::SUCCESS;
            }

            $this->components->twoColumnDetail('<fg=bright-blue;options=bold>Atlas AI Self-Improvement Plan</>', (string) data_get($plan, 'flow'));
            $this->components->twoColumnDetail('Runtime', (string) data_get($plan, 'runtime'));
            $this->components->twoColumnDetail('Executor', (string) data_get($plan, 'execution_policy.executor_preference', '-'));
            $this->components->twoColumnDetail('Autonomy', (string) data_get($plan, 'autonomy', 'low'));

            return self::SUCCESS;
        }

        $payload = $orchestrator->executeFlow((string) $this->option('flow'), [
            'emit' => (bool) $this->option('emit'),
            'hours' => (int) $this->option('hours'),
            'limit' => (int) $this->option('limit'),
        ]);
        $runtime = (array) ($payload['runtime'] ?? []);

        if ((bool) $this->option('json')) {
            $this->line(json_encode($payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));

            return self::SUCCESS;
        }

        $this->components->twoColumnDetail('<fg=bright-blue;options=bold>Atlas AI Self-Improvement</>', (string) ($payload['status'] ?? 'unknown'));
        $this->components->twoColumnDetail('Flow', (string) data_get($payload, 'plan.flow', '-'));
        $this->components->twoColumnDetail('Run', (string) ($runtime['run_id'] ?? '-'));
        $this->components->twoColumnDetail('Dry run', ($runtime['dry_run'] ?? true) ? 'yes' : 'no');
        $this->components->twoColumnDetail('Findings', (string) count((array) ($runtime['findings'] ?? [])));
        $this->components->twoColumnDetail('Emitted', (string) ($runtime['emitted_count'] ?? 0));

        foreach ((array) ($runtime['findings'] ?? []) as $finding) {
            $this->line('- '.($finding['title'] ?? 'Finding'));
        }

        return self::SUCCESS;
    }

    private function renderFlows(AtlasSelfImprovementOrchestrator $orchestrator): int
    {
        $flows = $orchestrator->supportedFlows();

        if ((bool) $this->option('json')) {
            $this->line(json_encode([
                'schema_version' => 1,
                'status' => 'ok',
                'flows' => $flows,
                'count' => count($flows),
            ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));

            return self::SUCCESS;
        }

        $this->components->twoColumnDetail('<fg=bright-blue;options=bold>Atlas AI Self-Improvement Flows</>', (string) count($flows));
        foreach ($flows as $flow) {
            $this->line('- '.$flow);
        }

        return self::SUCCESS;
    }
}
