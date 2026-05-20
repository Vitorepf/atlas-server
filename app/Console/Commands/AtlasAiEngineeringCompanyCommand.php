<?php

namespace App\Console\Commands;

use App\Services\Ai\EngineeringCompany\AtlasRealEngineeringCompanyRuntimeService;
use App\Services\Ai\Holding\AutonomousHoldingEnterpriseBuildoutService;
use Illuminate\Console\Command;

class AtlasAiEngineeringCompanyCommand extends Command
{
    protected $signature = 'atlas:ai:engineering-company
        {action=readiness : readiness, run, control-plane, certify, enterprise-analysis}
        {--action= : Optional action override for generated enterprise runtime commands}
        {--goal= : Goal text for run}
        {--context-sufficiency=86 : Autonomous preflight context sufficiency}
        {--test-status=passed : Real execution test status}
        {--review-status=passed : Independent review status}
        {--qa-status=passed : QA status}
        {--fixture : Run an enterprise flow fixture action}
        {--runtime-mode=internal : enterprise flow runtime mode: internal or fixture}
        {--json : Print machine-readable JSON}';

    protected $description = 'Run Atlas Real Engineering Company Runtime readiness, execution, control plane and certification commands.';

    public function handle(
        AtlasRealEngineeringCompanyRuntimeService $service,
        AutonomousHoldingEnterpriseBuildoutService $enterpriseBuildout,
    ): int
    {
        $actionOption = $this->option('action');
        $action = strtolower(trim(is_string($actionOption) && trim($actionOption) !== ''
            ? $actionOption
            : (string) $this->argument('action')));
        $fixtureRuntime = app(\App\Services\Ai\Holding\EnterpriseFlowFixtureActionRuntimeService::class);
        if ($fixtureRuntime->supports('software', $action)) {
            $payload = $fixtureRuntime->run('software', $action, $this->fixtureRequested());
            if ((bool) $this->option('json')) {
                $this->line(json_encode($payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));

                return self::SUCCESS;
            }

            $this->components->twoColumnDetail('<fg=bright-blue;options=bold>Atlas Engineering Fixture Action</>', $action);
            $this->components->twoColumnDetail('Status', (string) $payload['status']);
            $this->components->twoColumnDetail('Writes', 'no');

            return self::SUCCESS;
        }

        $payload = match ($action) {
            'readiness', 'status' => $service->readiness(),
            'run', 'smoke' => $service->run($this->goalText(), [
                'context_sufficiency' => (int) $this->option('context-sufficiency'),
                'test_status' => (string) $this->option('test-status'),
                'review_status' => (string) $this->option('review-status'),
                'qa_status' => (string) $this->option('qa-status'),
            ]),
            'control-plane', 'control_plane' => $service->controlPlane(),
            'certify', 'certification' => $service->certify()->toArray(),
            'enterprise-analysis', 'enterprise_analysis' => $this->enterprisePayload($enterpriseBuildout),
            default => [
                'schema_version' => 'atlas.ai.engineering_company.command.v1',
                'status' => 'failed',
                'error' => 'unsupported_action',
                'supported_actions' => ['readiness', 'run', 'control-plane', 'certify', 'enterprise-analysis'],
                'writes' => false,
            ],
        };

        if ((bool) $this->option('json')) {
            $this->line(json_encode($payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));

            return $this->exitCodeFor($payload);
        }

        $this->components->twoColumnDetail('<fg=bright-blue;options=bold>Atlas Real Engineering Company Runtime</>', $action);
        $this->components->twoColumnDetail('Status', (string) ($payload['status'] ?? 'unknown'));
        $this->components->twoColumnDetail('Writes', ($payload['writes'] ?? false) ? 'yes' : 'no');

        return $this->exitCodeFor($payload);
    }

    private function goalText(): string
    {
        return (string) ($this->option('goal') ?: 'execute um smoke de engineering company runtime com review e QA');
    }

    private function fixtureRequested(): bool
    {
        return (string) $this->input->getParameterOption('--runtime-mode', (string) $this->option('runtime-mode')) === 'fixture'
            || (bool) $this->option('fixture');
    }

    /**
     * @return array<string,mixed>
     */
    private function enterprisePayload(AutonomousHoldingEnterpriseBuildoutService $enterpriseBuildout): array
    {
        $payload = $enterpriseBuildout->companyPacket('software');
        $payload['ok'] = (bool) ($payload['readiness']['ok'] ?? false);
        $payload['status'] = $payload['ok'] ? 'ready' : 'failed';
        $payload['writes'] = false;

        return $payload;
    }

    /**
     * @param  array<string,mixed>  $payload
     */
    private function exitCodeFor(array $payload): int
    {
        return in_array(($payload['status'] ?? null), ['failed', 'blocked'], true)
            || in_array(data_get($payload, 'certification.status'), ['failed', 'blocked'], true)
            ? self::FAILURE
            : self::SUCCESS;
    }
}
