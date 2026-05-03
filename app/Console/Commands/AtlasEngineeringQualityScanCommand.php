<?php

namespace App\Console\Commands;

use App\Services\Engineering\EngineeringQualityScanService;
use Illuminate\Console\Command;

class AtlasEngineeringQualityScanCommand extends Command
{
    protected $signature = 'atlas:engineering:quality-scan
        {--workspace= : Target workspace path. Defaults to current directory}
        {--profile=auto : auto, fast, standard, release or deep}
        {--changed-only : Prefer changed files when a tool supports explicit targets}
        {--timeout=300 : Seconds allowed per tool}
        {--run-context-type= : Optional Atlas Tool Runtime context type}
        {--run-context-id= : Optional Atlas Tool Runtime context id}
        {--json : Print machine-readable JSON}';

    protected $description = 'Run an Atlas Engineering quality/security scan with audited tool detection, artifacts and normalized findings.';

    public function handle(EngineeringQualityScanService $qualityScan): int
    {
        $payload = $qualityScan->scan($this->workspace(), [
            'profile' => $this->option('profile') ?: 'auto',
            'changed_only' => (bool) $this->option('changed-only'),
            'timeout' => is_numeric($this->option('timeout')) ? (int) $this->option('timeout') : 300,
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
        $this->components->twoColumnDetail('<fg=bright-blue;options=bold>Atlas Engineering Quality Scan</>', (string) ($payload['status'] ?? 'unknown'));
        $this->components->twoColumnDetail('Profile', (string) ($payload['profile'] ?? '-'));
        $this->components->twoColumnDetail('Tools', (string) data_get($payload, 'summary.tool_count', 0));
        $this->components->twoColumnDetail('Findings', (string) data_get($payload, 'summary.finding_count', 0));
        $this->components->twoColumnDetail('Recommendations', (string) data_get($payload, 'summary.recommendation_count', 0));
        $this->components->twoColumnDetail('Artifact root hash', (string) ($payload['artifact_root_hash'] ?? '-'));

        $rows = collect((array) ($payload['tools'] ?? []))
            ->map(fn (array $tool): array => [
                $tool['slug'] ?? '-',
                $tool['status'] ?? '-',
                $tool['exit_code'] ?? '-',
                $tool['reason'] ?? data_get($tool, 'findings.0.title', '-'),
            ])
            ->all();

        if ($rows !== []) {
            $this->newLine();
            $this->table(['tool', 'status', 'exit', 'detail'], $rows);
        }

        $recommendations = collect((array) ($payload['recommendations'] ?? []))
            ->map(fn (array $recommendation): array => [
                $recommendation['tool'] ?? '-',
                $recommendation['priority'] ?? '-',
                $recommendation['install_hint'] ?? '-',
            ])
            ->all();

        if ($recommendations !== []) {
            $this->newLine();
            $this->table(['tool', 'priority', 'install'], $recommendations);
        }
    }

    private function workspace(): string
    {
        $workspace = (string) ($this->option('workspace') ?: getcwd() ?: base_path());
        $resolved = realpath($workspace);

        return $resolved && is_dir($resolved) ? $resolved : $workspace;
    }

    private function stringOption(string $name): ?string
    {
        $value = $this->option($name);
        if (! is_scalar($value)) {
            return null;
        }

        $value = trim((string) $value);

        return $value !== '' ? $value : null;
    }
}
