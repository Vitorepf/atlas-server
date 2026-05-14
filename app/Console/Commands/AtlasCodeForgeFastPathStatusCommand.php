<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Models\AtlasProject;
use App\Services\Ai\Programming\AtlasCodeForgeFastPathStatusService;
use Illuminate\Console\Command;

/**
 * Atlas Code Forge Fast Path · CLI status/resume.
 *
 * php artisan atlas:code:forge-fast-path-status --obra=<uuid> --run=<id> --json --strict
 *
 * Doc: docs/engineering-knowledge-base/atlas-code-forge-fast-path-v1.md
 */
final class AtlasCodeForgeFastPathStatusCommand extends Command
{
    protected $signature = 'atlas:code:forge-fast-path-status
        {--obra= : UUID da Obra (obrigatorio)}
        {--run= : Fast Path run id (obrigatorio)}
        {--resume : Tratar como resume (idempotente; nao recomeca do zero)}
        {--json : Imprime resultado em JSON canonico}
        {--strict : Exit non-zero quando status nao for ok}';

    protected $description = 'Status/resume canonico de um Atlas Code Forge Fast Path run.';

    public function handle(AtlasCodeForgeFastPathStatusService $service): int
    {
        $obraId = $this->stringOption('obra');
        $runId = $this->stringOption('run');

        if ($obraId === null || $runId === null) {
            $payload = [
                'schema_version' => AtlasCodeForgeFastPathStatusService::SCHEMA_VERSION,
                'status' => 'blocked',
                'blocker' => $obraId === null ? 'obra_required' : 'fast_path_run_id_required',
                'reason' => 'atlas:code:forge-fast-path-status exige --obra e --run.',
                'external_provider_call' => false,
            ];

            $this->emit($payload);

            return (bool) $this->option('strict') ? self::FAILURE : self::SUCCESS;
        }

        $project = AtlasProject::query()->whereKey($obraId)->first();
        if ($project === null) {
            $payload = [
                'schema_version' => AtlasCodeForgeFastPathStatusService::SCHEMA_VERSION,
                'status' => 'blocked',
                'blocker' => 'obra_not_found',
                'obra_id' => $obraId,
                'fast_path_run_id' => $runId,
                'external_provider_call' => false,
            ];

            $this->emit($payload);

            return (bool) $this->option('strict') ? self::FAILURE : self::SUCCESS;
        }

        $payload = (bool) $this->option('resume')
            ? $service->resume($project, $runId)
            : $service->status($project, $runId);

        unset($payload['http_status']);

        $this->emit($payload);

        return $this->resolveExit($payload, (bool) $this->option('strict'));
    }

    /**
     * @param  array<string,mixed>  $payload
     */
    private function emit(array $payload): void
    {
        if ((bool) $this->option('json')) {
            $this->line($this->encode($payload));

            return;
        }

        $this->components->twoColumnDetail('<fg=bright-blue;options=bold>Atlas Code Forge Fast Path · Run Status</>', (string) ($payload['schema_version'] ?? ''));
        $this->components->twoColumnDetail('Run', (string) ($payload['fast_path_run_id'] ?? '—'));
        $this->components->twoColumnDetail('Obra', (string) ($payload['obra_id'] ?? '—'));
        $this->components->twoColumnDetail('Status', (string) ($payload['status'] ?? '—'));
        $this->components->twoColumnDetail('Stage', (string) ($payload['current_stage'] ?? '—'));
        $this->components->twoColumnDetail('Progress', (string) ($payload['progress_percent'] ?? 0).'%');
        $this->components->twoColumnDetail('Next action', (string) ($payload['next_action'] ?? '—'));

        if (! ($payload['run_found'] ?? true)) {
            $this->components->error((string) ($payload['blocker'] ?? 'fast_path_run_not_found'));
        }
    }

    /**
     * @param  array<string,mixed>  $payload
     */
    private function resolveExit(array $payload, bool $strict): int
    {
        if (! $strict) {
            return self::SUCCESS;
        }

        $status = (string) ($payload['status'] ?? '');

        return in_array($status, ['queued', 'running', 'passed', 'review_required', 'completed', 'prepared', 'degraded'], true)
            ? self::SUCCESS
            : self::FAILURE;
    }

    private function stringOption(string $key): ?string
    {
        $value = $this->option($key);
        if (! is_string($value)) {
            return null;
        }

        $value = trim($value);

        return $value !== '' ? $value : null;
    }

    /**
     * @param  array<string,mixed>  $payload
     */
    private function encode(array $payload): string
    {
        return (string) json_encode(
            $payload,
            JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE,
        );
    }
}
