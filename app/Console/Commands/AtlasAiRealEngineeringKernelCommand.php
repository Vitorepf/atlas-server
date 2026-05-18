<?php

namespace App\Console\Commands;

use App\Services\Ai\RealExecution\AtlasRealEngineeringExecutionKernelService;
use Illuminate\Console\Command;

class AtlasAiRealEngineeringKernelCommand extends Command
{
    protected $signature = 'atlas:ai:real-engineering-kernel
        {action=readiness : readiness, run, control-plane, certify, import-external-benchmark}
        {--goal= : Goal text for run}
        {--goal-id= : Goal UUID or goal_id for external benchmark import}
        {--evidence-file= : JSON evidence file exported by Forge Provider Arena}
        {--context-sufficiency=84 : Autonomous preflight context sufficiency}
        {--test-status=passed : Simulated impact test status}
        {--scope=kernel : Certification scope: kernel or full}
        {--json : Print machine-readable JSON}';

    protected $description = 'Run Atlas Real Engineering Execution Kernel readiness, execution, control plane and certification commands.';

    public function handle(AtlasRealEngineeringExecutionKernelService $service): int
    {
        $action = strtolower(trim((string) $this->argument('action')));
        $payload = match ($action) {
            'readiness', 'status' => $service->readiness(),
            'run', 'smoke' => $service->run($this->goalText(), [
                'context_sufficiency' => (int) $this->option('context-sufficiency'),
                'test_status' => (string) $this->option('test-status'),
            ]),
            'control-plane', 'control_plane' => $service->controlPlane(),
            'certify', 'certification' => $service->certify(null, (string) $this->option('scope'))->toArray(),
            'import-external-benchmark', 'record-external-benchmark' => $service->importExternalRivalsBenchmark(
                $this->externalEvidencePack(),
                $this->stringOption('goal-id'),
            ),
            default => [
                'schema_version' => 'atlas.ai.real_execution.command.v1',
                'status' => 'failed',
                'error' => 'unsupported_action',
                'supported_actions' => ['readiness', 'run', 'control-plane', 'certify', 'import-external-benchmark'],
                'writes' => false,
            ],
        };

        if ((bool) $this->option('json')) {
            $this->line(json_encode($payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));

            return $this->exitCodeFor($payload);
        }

        $this->components->twoColumnDetail('<fg=bright-blue;options=bold>Atlas Real Engineering Execution Kernel</>', $action);
        $this->components->twoColumnDetail('Status', (string) ($payload['status'] ?? 'unknown'));
        $this->components->twoColumnDetail('Writes', ($payload['writes'] ?? false) ? 'yes' : 'no');

        return $this->exitCodeFor($payload);
    }

    private function goalText(): string
    {
        return (string) ($this->option('goal') ?: 'implemente um smoke real de engenharia com worktree, patch, teste e delivery pack');
    }

    /**
     * @return array<string,mixed>
     */
    private function externalEvidencePack(): array
    {
        $path = $this->stringOption('evidence-file');
        if ($path === null || $path === '' || ! is_file($path)) {
            return [];
        }

        $decoded = json_decode((string) file_get_contents($path), true);

        return is_array($decoded) ? $decoded : [];
    }

    private function stringOption(string $name): ?string
    {
        $value = $this->option($name);

        return is_string($value) && trim($value) !== '' ? trim($value) : null;
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
