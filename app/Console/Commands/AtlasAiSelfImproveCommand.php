<?php

namespace App\Console\Commands;

use App\Services\Ai\SelfImprovement\AtlasSelfImprovementInput;
use App\Services\Ai\SelfImprovement\AtlasSelfImprovementOrchestrator;
use App\Services\Ai\SelfImprovement\AtlasSelfImprovementScheduleService;
use Illuminate\Console\Command;

class AtlasAiSelfImproveCommand extends Command
{
    protected $signature = 'atlas:ai:self-improve
        {--flow=nightly_review : Self-improvement flow to run}
        {--hours=24 : Evidence Ledger lookback window}
        {--limit=5 : Maximum findings/proposals}
        {--domain= : Filter SLO drift by domain dimension}
        {--slo-flow= : Filter SLO drift by flow dimension}
        {--surface= : Filter SLO drift by surface_id dimension}
        {--provider= : Filter SLO drift by provider dimension}
        {--model= : Filter SLO drift by model dimension}
        {--runtime= : Filter SLO drift by runtime dimension}
        {--tool= : Filter SLO drift by tool_id dimension}
        {--repair-status= : Filter Repair Loop findings by repair decision status}
        {--repair-strategy= : Filter Repair Loop findings by repair strategy}
        {--failure-domain= : Filter Repair Loop findings by failure domain}
        {--repair-emitter-stage= : Filter Repair Loop findings by ledger emitter stage}
        {--kernel-status= : Filter Kernel Pipeline findings by contract status}
        {--kernel-input-mode= : Filter Kernel Pipeline findings by input mode}
        {--kernel-emitter-stage= : Filter Kernel Pipeline findings by ledger emitter stage}
        {--onboarding-status= : Filter Domain Onboarding findings by status: ready, executable_incomplete, scaffold}
        {--emit : Emit safe review proposals to the Inbox}
        {--plan-only : Print the execution plan without running the runtime}
        {--list-flows : List supported self-improvement flows}
        {--schedule-plan : Print the recurring self-improvement schedule plan}
        {--schedule-health : Print the compact recurring self-improvement schedule health}
        {--fail-on-schedule-warning : Return a non-zero exit code when the recurring schedule health is not healthy}
        {--json : Print machine-readable JSON}';

    protected $description = 'Run the Atlas AI self-improvement review over Evidence Ledger events.';

    public function handle(
        AtlasSelfImprovementOrchestrator $orchestrator,
        AtlasSelfImprovementScheduleService $schedule,
        AtlasSelfImprovementInput $input,
    ): int {
        if ((bool) $this->option('list-flows')) {
            return $this->renderFlows($orchestrator);
        }

        if ((bool) $this->option('schedule-plan')) {
            return $this->renderSchedulePlan($schedule);
        }

        if ((bool) $this->option('schedule-health')) {
            return $this->renderScheduleHealth($schedule);
        }

        $options = [
            'emit' => (bool) $this->option('emit'),
            ...$input->runtimeOptions([
                'hours' => $this->option('hours'),
                'limit' => $this->option('limit'),
            ]),
            'filters' => $this->dimensionFilters(),
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
            ...$input->runtimeOptions([
                'hours' => $this->option('hours'),
                'limit' => $this->option('limit'),
            ]),
            'filters' => $this->dimensionFilters(),
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
        if (($runtime['filters'] ?? []) !== []) {
            $this->components->twoColumnDetail('Filters', json_encode($runtime['filters'], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));
        }
        $this->components->twoColumnDetail('Findings', (string) count((array) ($runtime['findings'] ?? [])));
        $this->components->twoColumnDetail('Emitted', (string) ($runtime['emitted_count'] ?? 0));

        foreach ((array) ($runtime['findings'] ?? []) as $finding) {
            $this->line('- '.($finding['title'] ?? 'Finding'));
        }

        return self::SUCCESS;
    }

    /**
     * @return array<string,string>
     */
    private function dimensionFilters(): array
    {
        $map = [
            'domain' => 'domain',
            'slo-flow' => 'flow',
            'surface' => 'surface_id',
            'provider' => 'provider',
            'model' => 'model',
            'runtime' => 'runtime',
            'tool' => 'tool_id',
            'repair-status' => 'status',
            'repair-strategy' => 'strategy',
            'failure-domain' => 'failure_domain',
            'repair-emitter-stage' => 'emitter_stage',
            'kernel-status' => 'status',
            'kernel-input-mode' => 'input_mode',
            'kernel-emitter-stage' => 'emitter_stage',
            'onboarding-status' => 'onboarding_status',
        ];
        $filters = [];

        foreach ($map as $option => $dimension) {
            $value = $this->option($option);
            if (is_scalar($value) && trim((string) $value) !== '') {
                $filters[$dimension] = trim((string) $value);
            }
        }

        return $filters;
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

    private function renderSchedulePlan(AtlasSelfImprovementScheduleService $schedule): int
    {
        $payload = $schedule->schedulePlan();
        $commands = $payload['commands'];
        $exitCode = $this->schedulePlanExitCode($payload);

        if ((bool) $this->option('json')) {
            $this->line(json_encode($payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));

            return $exitCode;
        }

        $this->components->twoColumnDetail('<fg=bright-blue;options=bold>Atlas AI Self-Improvement Schedule</>', ($payload['enabled'] ?? false) ? 'enabled' : 'disabled');
        $this->components->twoColumnDetail('Health', (string) data_get($payload, 'health.status', 'unknown'));
        $this->components->twoColumnDetail('Time', (string) $payload['time']);
        $this->components->twoColumnDetail('Flows', (string) $payload['count']);
        $this->components->twoColumnDetail('Defaulted', ($payload['defaulted'] ?? false) ? 'yes' : 'no');
        $this->components->twoColumnDetail('Schedulable', ($payload['schedulable'] ?? false) ? 'yes' : 'no');
        $this->components->twoColumnDetail('Scheduler registration', (string) data_get($payload, 'scheduler_registration.status', 'unknown'));
        $this->components->twoColumnDetail('Registered commands', (string) data_get($payload, 'scheduler_registration.registered_command_count', 0));
        if (data_get($payload, 'scheduler_registration.skipped_reason') !== null) {
            $this->components->twoColumnDetail('Skipped reason', (string) data_get($payload, 'scheduler_registration.skipped_reason'));
        }
        $this->components->twoColumnDetail('Emit proposals', ($payload['emit'] ?? false) ? 'yes' : 'no');

        if (($payload['invalid_flows'] ?? []) !== []) {
            $this->components->warn('Invalid configured flows: '.implode(', ', $payload['invalid_flows']));
        }

        foreach ((array) data_get($payload, 'health.actions', []) as $action) {
            $this->line('- '.$action);
        }

        $this->table(['flow', 'time', 'command'], collect($commands)
            ->map(fn (array $command): array => [
                $command['flow'] ?? '-',
                $command['time'] ?? '-',
                $command['command'] ?? '-',
            ])
            ->all());

        return $exitCode;
    }

    private function renderScheduleHealth(AtlasSelfImprovementScheduleService $schedule): int
    {
        $payload = $schedule->scheduleHealth();
        $exitCode = $this->schedulePlanExitCode($payload);

        if ((bool) $this->option('json')) {
            $this->line(json_encode($payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));

            return $exitCode;
        }

        $this->components->twoColumnDetail('<fg=bright-blue;options=bold>Atlas AI Self-Improvement Schedule Health</>', (string) data_get($payload, 'health.status', 'unknown'));
        $this->components->twoColumnDetail('Enabled', ($payload['enabled'] ?? false) ? 'yes' : 'no');
        $this->components->twoColumnDetail('Time', (string) $payload['time']);
        $this->components->twoColumnDetail('Flows', (string) $payload['flow_count']);
        $this->components->twoColumnDetail('Invalid flows', (string) $payload['invalid_flow_count']);
        $this->components->twoColumnDetail('Defaulted', ($payload['defaulted'] ?? false) ? 'yes' : 'no');
        $this->components->twoColumnDetail('Schedulable', ($payload['schedulable'] ?? false) ? 'yes' : 'no');
        $this->components->twoColumnDetail('Scheduler registration', (string) data_get($payload, 'scheduler_registration.status', 'unknown'));
        $this->components->twoColumnDetail('Registered commands', (string) data_get($payload, 'scheduler_registration.registered_command_count', 0));
        if (data_get($payload, 'scheduler_registration.skipped_reason') !== null) {
            $this->components->twoColumnDetail('Skipped reason', (string) data_get($payload, 'scheduler_registration.skipped_reason'));
        }
        $this->components->twoColumnDetail('Emit proposals', ($payload['emit'] ?? false) ? 'yes' : 'no');

        foreach ((array) data_get($payload, 'health.actions', []) as $action) {
            $this->line('- '.$action);
        }

        return $exitCode;
    }

    /**
     * @param  array<string,mixed>  $payload
     */
    private function schedulePlanExitCode(array $payload): int
    {
        if (! (bool) $this->option('fail-on-schedule-warning')) {
            return self::SUCCESS;
        }

        return data_get($payload, 'health.status') === 'healthy'
            ? self::SUCCESS
            : self::FAILURE;
    }
}
