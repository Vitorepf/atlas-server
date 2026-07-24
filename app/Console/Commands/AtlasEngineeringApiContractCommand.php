<?php

namespace App\Console\Commands;

use App\Console\Commands\Concerns\ReadsNonEmptyStringOption;
use App\Services\Engineering\EngineeringApiContractService;
use Illuminate\Console\Command;

class AtlasEngineeringApiContractCommand extends Command
{
    protected $signature = 'atlas:engineering:api-contract
        {--workspace= : Target workspace path. Defaults to current directory}
        {--spec= : OpenAPI JSON/YAML path relative to workspace}
        {--strict : Treat undocumented Laravel API routes as blocking findings}
        {--run-context-type= : Optional Atlas Tool Runtime context type}
        {--run-context-id= : Optional Atlas Tool Runtime context id}
        {--json : Print machine-readable JSON}';

    protected $description = 'Validate API contracts through the Atlas Super Tool Runtime evidence layer.';

    public function handle(EngineeringApiContractService $contracts): int
    {
        $payload = $contracts->validate($this->workspace(), [
            'spec' => $this->stringOption('spec'),
            'strict' => (bool) $this->option('strict'),
            'run_context_type' => $this->stringOption('run-context-type'),
            'run_context_id' => $this->stringOption('run-context-id'),
        ]);

        if ((bool) $this->option('json')) {
            $this->line(json_encode($payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));

            return $payload['status'] === 'failed' ? self::FAILURE : self::SUCCESS;
        }

        $this->render($payload);

        return $payload['status'] === 'failed' ? self::FAILURE : self::SUCCESS;
    }

    /**
     * @param  array<string,mixed>  $payload
     */
    private function render(array $payload): void
    {
        $this->newLine();
        $this->components->twoColumnDetail('<fg=bright-blue;options=bold>Atlas Engineering API Contract</>', (string) ($payload['status'] ?? 'unknown'));
        $this->components->twoColumnDetail('Spec', (string) ($payload['spec_path'] ?? '-'));
        $this->components->twoColumnDetail('Routes', (string) data_get($payload, 'summary.route_count', 0));
        $this->components->twoColumnDetail('Documented paths', (string) data_get($payload, 'summary.documented_path_count', 0));
        $this->components->twoColumnDetail('Findings', (string) data_get($payload, 'summary.finding_count', 0));
        $this->components->twoColumnDetail('Blocking findings', (string) data_get($payload, 'summary.blocking_finding_count', 0));
        $this->components->twoColumnDetail('Artifact root hash', (string) ($payload['artifact_root_hash'] ?? '-'));

        $rows = collect((array) ($payload['findings'] ?? []))
            ->take(20)
            ->map(fn (array $finding): array => [
                $finding['severity'] ?? '-',
                $finding['rule_id'] ?? '-',
                $finding['title'] ?? '-',
            ])
            ->all();

        if ($rows !== []) {
            $this->newLine();
            $this->table(['severity', 'rule', 'title'], $rows);
        }
    }

    private function workspace(): string
    {
        $workspace = (string) ($this->option('workspace') ?: getcwd() ?: base_path());
        $resolved = realpath($workspace);

        return $resolved && is_dir($resolved) ? $resolved : $workspace;
    }

}
