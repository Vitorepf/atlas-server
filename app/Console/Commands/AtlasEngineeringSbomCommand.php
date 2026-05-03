<?php

namespace App\Console\Commands;

use App\Services\Engineering\EngineeringQualityScanService;
use Illuminate\Console\Command;

class AtlasEngineeringSbomCommand extends Command
{
    protected $signature = 'atlas:engineering:sbom
        {--workspace= : Target workspace path. Defaults to current directory}
        {--profile=release : release or deep}
        {--timeout=300 : Seconds allowed per tool}
        {--run-context-type= : Optional Atlas Tool Runtime context type}
        {--run-context-id= : Optional Atlas Tool Runtime context id}
        {--json : Print machine-readable JSON}';

    protected $description = 'Generate a local SBOM evidence run through the generic Atlas tool runtime.';

    public function handle(EngineeringQualityScanService $qualityScan): int
    {
        $payload = $qualityScan->scan($this->workspace(), [
            'profile' => $this->profile(),
            'timeout' => is_numeric($this->option('timeout')) ? (int) $this->option('timeout') : 300,
            'run_context_type' => $this->stringOption('run-context-type'),
            'run_context_id' => $this->stringOption('run-context-id'),
            'include_tools' => ['syft'],
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
        $syft = collect((array) ($payload['tools'] ?? []))->firstWhere('slug', 'syft');

        $this->newLine();
        $this->components->twoColumnDetail('<fg=bright-blue;options=bold>Atlas Engineering SBOM</>', (string) ($payload['status'] ?? 'unknown'));
        $this->components->twoColumnDetail('Profile', (string) ($payload['profile'] ?? '-'));
        $this->components->twoColumnDetail('Syft status', (string) data_get($syft, 'status', 'missing'));
        $this->components->twoColumnDetail('Package count', (string) data_get($syft, 'metrics.package_count', 0));
        $this->components->twoColumnDetail('Artifact root hash', (string) ($payload['artifact_root_hash'] ?? '-'));

        if (is_array($syft) && ($syft['status'] ?? null) === 'skipped') {
            $this->components->warn((string) ($syft['reason'] ?? 'syft_not_available'));
        }
    }

    private function profile(): string
    {
        $profile = strtolower(trim((string) ($this->option('profile') ?: 'release')));

        return in_array($profile, ['release', 'deep'], true) ? $profile : 'release';
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
