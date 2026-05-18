<?php

namespace App\Console\Commands;

use App\Services\Ai\EngineeringCompany\AtlasRealEngineeringCompanyRuntimeService;
use Illuminate\Console\Command;

class AtlasAiEngineeringCompanyCommand extends Command
{
    protected $signature = 'atlas:ai:engineering-company
        {action=readiness : readiness, run, control-plane, certify}
        {--goal= : Goal text for run}
        {--context-sufficiency=86 : Autonomous preflight context sufficiency}
        {--test-status=passed : Real execution test status}
        {--review-status=passed : Independent review status}
        {--qa-status=passed : QA status}
        {--json : Print machine-readable JSON}';

    protected $description = 'Run Atlas Real Engineering Company Runtime readiness, execution, control plane and certification commands.';

    public function handle(AtlasRealEngineeringCompanyRuntimeService $service): int
    {
        $action = strtolower(trim((string) $this->argument('action')));
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
            default => [
                'schema_version' => 'atlas.ai.engineering_company.command.v1',
                'status' => 'failed',
                'error' => 'unsupported_action',
                'supported_actions' => ['readiness', 'run', 'control-plane', 'certify'],
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
