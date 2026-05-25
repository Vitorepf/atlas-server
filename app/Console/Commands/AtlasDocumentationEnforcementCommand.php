<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Services\Engineering\AtlasDocumentationEnforcementService;
use Illuminate\Console\Command;

final class AtlasDocumentationEnforcementCommand extends Command
{
    protected $signature = 'atlas:documentation:enforce
        {--task= : Human task or implementation objective}
        {--feature= : Feature/runtime/doc capability being changed}
        {--target=* : Optional target files, commands or runtimes}
        {--workspace=atlas-server : Workspace/repo name}
        {--json : Emit canonical JSON}
        {--strict : Exit non-zero unless status is ready}';

    protected $description = 'Unified pre-implementation documentation enforcement gate for AI/provider sessions.';

    public function handle(AtlasDocumentationEnforcementService $service): int
    {
        $payload = $service->report(
            task: (string) ($this->option('task') ?? ''),
            feature: (string) ($this->option('feature') ?? ''),
            targets: (array) ($this->option('target') ?? []),
            workspace: (string) ($this->option('workspace') ?? 'atlas-server'),
        );

        if ((bool) $this->option('json')) {
            $this->line(json_encode($payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) ?: '{}');

            return $this->exitCode($payload);
        }

        $this->components->twoColumnDetail('Atlas Documentation Enforcement', (string) $payload['status']);
        $this->components->twoColumnDetail('Score', (string) $payload['score'].'/10');
        $this->components->twoColumnDetail('Grade', (string) $payload['grade']);
        $this->components->twoColumnDetail('Blockers', (string) data_get($payload, 'summary.blockers_count', 0));
        $this->components->twoColumnDetail('Review', (string) data_get($payload, 'summary.review_count', 0));
        $this->components->twoColumnDetail('Warnings', (string) data_get($payload, 'summary.warnings_count', 0));
        $this->components->twoColumnDetail('Hash', (string) $payload['certification_hash']);

        if (($payload['status'] ?? null) !== 'ready') {
            $this->newLine();
            $this->warn('Documentation enforcement is not ready. Run with --json to inspect blockers/review_items/warnings.');
        }

        return $this->exitCode($payload);
    }

    /**
     * @param  array<string,mixed>  $payload
     */
    private function exitCode(array $payload): int
    {
        return (bool) $this->option('strict') && ($payload['status'] ?? null) !== 'ready'
            ? self::FAILURE
            : self::SUCCESS;
    }
}
