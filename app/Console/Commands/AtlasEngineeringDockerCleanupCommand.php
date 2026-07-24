<?php

namespace App\Console\Commands;

use App\Services\Engineering\EngineeringDockerHarnessService;
use Illuminate\Console\Command;
use App\Console\Concerns\EmitsCanonicalJson;

class AtlasEngineeringDockerCleanupCommand extends Command
{
    use EmitsCanonicalJson;

    protected $signature = 'atlas:engineering:docker-cleanup
        {--cache-retention-days= : Override Docker dependency cache retention window}
        {--artifact-retention-days= : Override Docker test artifact retention window}
        {--apply : Delete cleanup candidates. Default is dry-run}
        {--json : Print machine-readable JSON}';

    protected $description = 'Audit or delete old Atlas Engineering Docker caches and exported test artifacts.';

    public function handle(EngineeringDockerHarnessService $dockerHarness): int
    {
        $result = $dockerHarness->cleanup(! (bool) $this->option('apply'), [
            'cache_retention_days' => $this->positiveIntegerOption('cache-retention-days'),
            'artifact_retention_days' => $this->positiveIntegerOption('artifact-retention-days'),
        ]);

        if ((bool) $this->option('json')) {
            $this->line($this->encode($result));

            return self::SUCCESS;
        }

        $this->render($result);

        return self::SUCCESS;
    }

    /**
     * @param  array<string,mixed>  $result
     */
    private function render(array $result): void
    {
        $this->newLine();
        $this->components->twoColumnDetail(
            '<fg=bright-blue;options=bold>Atlas Docker Cleanup</>',
            (bool) ($result['dry_run'] ?? true) ? 'dry-run' : 'applied',
        );
        $this->components->twoColumnDetail('Candidates', (string) ($result['candidate_count'] ?? 0));
        $this->components->twoColumnDetail('Deleted', (string) ($result['deleted_count'] ?? 0));
        $this->components->twoColumnDetail('Cache retention days', (string) ($result['cache_retention_days'] ?? '-'));
        $this->components->twoColumnDetail('Artifact retention days', (string) ($result['artifact_retention_days'] ?? '-'));

        $rows = collect((array) ($result[(bool) ($result['dry_run'] ?? true) ? 'candidates' : 'deleted'] ?? []))
            ->map(fn (array $candidate): array => [
                $candidate['kind'] ?? '-',
                $candidate['bytes'] ?? 0,
                $candidate['mtime'] ?? '-',
                $candidate['path_hash'] ?? '-',
            ])
            ->all();

        if ($rows !== []) {
            $this->newLine();
            $this->table(['kind', 'bytes', 'mtime', 'path_hash'], $rows);
        }
    }

    private function positiveIntegerOption(string $name): ?int
    {
        $value = $this->option($name);
        if (! is_numeric($value)) {
            return null;
        }

        $value = (int) $value;

        return $value > 0 ? $value : null;
    }
}
