<?php

namespace App\Console\Commands;

use App\Services\Ai\Cli\AtlasCliDoctorService;
use Illuminate\Console\Command;

class AtlasCliDoctorCommand extends Command
{
    protected $signature = 'atlas:cli:doctor
        {--workspace= : Workspace path. Defaults to current directory}
        {--refresh-providers : Run provider health checks before diagnosing}
        {--run-tests : Run detected test command as part of readiness}
        {--strict : Return failure when terminal readiness is not fully passed}
        {--json : Print machine-readable JSON}';

    protected $description = 'Run the Atlas CLI terminal readiness doctor.';

    public function handle(
        AtlasCliDoctorService $doctor,
    ): int {
        $payload = $doctor->diagnose(
            workspace: $this->workspace(),
            refreshProviders: (bool) $this->option('refresh-providers'),
            runTests: (bool) $this->option('run-tests'),
        );

        if ((bool) $this->option('json')) {
            $this->line(json_encode($payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));

            return $payload['readiness']['status'] === 'passed' || ! (bool) $this->option('strict')
                ? self::SUCCESS
                : self::FAILURE;
        }

        $this->render($payload);

        return $payload['readiness']['status'] === 'passed' || ! (bool) $this->option('strict')
            ? self::SUCCESS
            : self::FAILURE;
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    private function render(array $payload): void
    {
        $this->newLine();
        $this->components->twoColumnDetail('<fg=bright-blue;options=bold>Atlas CLI Doctor</>', (string) data_get($payload, 'readiness.status'));
        $this->components->twoColumnDetail('Score', (string) data_get($payload, 'readiness.score'));
        $this->components->twoColumnDetail('Workspace', (string) $payload['workspace']);
        $this->components->twoColumnDetail('Provider', (string) data_get($payload, 'provider_strategy.recommended_provider'));

        $this->newLine();
        $this->table(
            ['gate', 'status', 'detail'],
            collect(data_get($payload, 'readiness.gates', []))->map(fn (array $gate): array => [
                $gate['name'] ?? '-',
                $gate['status'] ?? '-',
                $gate['detail'] ?? '-',
            ])->all(),
        );

        $actions = (array) data_get($payload, 'readiness.next_actions', []);
        if ($actions !== []) {
            $this->newLine();
            $this->line('Proximas acoes:');
            foreach ($actions as $action) {
                $this->line('  - '.$action);
            }
        }
    }

    private function workspace(): string
    {
        $workspace = (string) ($this->option('workspace') ?: getcwd() ?: config('atlas.ai.workdir'));
        $resolved = realpath($workspace);

        return $resolved && is_dir($resolved) ? $resolved : $workspace;
    }
}
