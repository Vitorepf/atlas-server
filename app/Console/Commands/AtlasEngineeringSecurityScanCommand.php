<?php

namespace App\Console\Commands;

use App\Console\Commands\Concerns\ReadsNonEmptyStringOption;
use App\Services\Engineering\EngineeringQualityScanService;
use Illuminate\Console\Command;

class AtlasEngineeringSecurityScanCommand extends Command
{
    protected $signature = 'atlas:engineering:security-scan
        {--workspace= : Target workspace path. Defaults to current directory}
        {--profile=release : standard, release or deep}
        {--changed-only : Prefer changed files when a tool supports explicit targets}
        {--timeout=300 : Seconds allowed per tool}
        {--run-context-type= : Optional Atlas Tool Runtime context type}
        {--run-context-id= : Optional Atlas Tool Runtime context id}
        {--json : Print machine-readable JSON}';

    protected $description = 'Run Atlas Engineering security and vulnerability scanners through the generic tool runtime.';

    public function handle(EngineeringQualityScanService $qualityScan): int
    {
        $payload = $qualityScan->scan($this->workspace(), [
            'profile' => $this->profile(),
            'changed_only' => (bool) $this->option('changed-only'),
            'timeout' => is_numeric($this->option('timeout')) ? (int) $this->option('timeout') : 300,
            'run_context_type' => $this->stringOption('run-context-type'),
            'run_context_id' => $this->stringOption('run-context-id'),
            'include_tools' => ['gitleaks', 'semgrep', 'osv_scanner', 'trivy', 'grype'],
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
        $this->components->twoColumnDetail('<fg=bright-blue;options=bold>Atlas Engineering Security Scan</>', (string) ($payload['status'] ?? 'unknown'));
        $this->components->twoColumnDetail('Profile', (string) ($payload['profile'] ?? '-'));
        $this->components->twoColumnDetail('Tools', (string) data_get($payload, 'summary.tool_count', 0));
        $this->components->twoColumnDetail('Findings', (string) data_get($payload, 'summary.finding_count', 0));
        $this->components->twoColumnDetail('Blocking findings', (string) data_get($payload, 'summary.blocking_finding_count', 0));
        $this->components->twoColumnDetail('Artifact root hash', (string) ($payload['artifact_root_hash'] ?? '-'));

        $rows = collect((array) ($payload['tools'] ?? []))
            ->map(fn (array $tool): array => [
                $tool['slug'] ?? '-',
                $tool['category'] ?? '-',
                $tool['status'] ?? '-',
                $tool['reason'] ?? data_get($tool, 'findings.0.title', '-'),
            ])
            ->all();

        if ($rows !== []) {
            $this->newLine();
            $this->table(['tool', 'category', 'status', 'detail'], $rows);
        }
    }

    private function profile(): string
    {
        $profile = strtolower(trim((string) ($this->option('profile') ?: 'release')));

        return in_array($profile, ['standard', 'release', 'deep'], true) ? $profile : 'release';
    }

    private function workspace(): string
    {
        $workspace = (string) ($this->option('workspace') ?: getcwd() ?: base_path());
        $resolved = realpath($workspace);

        return $resolved && is_dir($resolved) ? $resolved : $workspace;
    }

}
