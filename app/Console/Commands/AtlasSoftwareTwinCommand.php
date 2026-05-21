<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Services\Engineering\AtlasSoftwareTwinRuntimeService;
use Illuminate\Console\Command;

final class AtlasSoftwareTwinCommand extends Command
{
    protected $signature = 'atlas:software-twin
        {action=twin : twin|impact|context-envelope|quality-score|snapshot}
        {--target= : Path, symbol or runtime target}
        {--task= : Task text for context envelope}
        {--json : Emit canonical JSON}
        {--strict : Exit non-zero unless ready}';

    protected $description = 'Operate ASTR, the Atlas Software Twin Runtime, as a read-only living system twin.';

    public function handle(AtlasSoftwareTwinRuntimeService $service): int
    {
        $action = (string) $this->argument('action');
        $target = (string) ($this->option('target') ?: '');
        $payload = match ($action) {
            'twin' => $service->twin($target),
            'impact' => $service->impact($target),
            'context-envelope' => $service->contextEnvelope((string) ($this->option('task') ?: $target ?: 'software twin context envelope'), $target),
            'quality-score' => $service->qualityScore(),
            'snapshot' => $service->snapshot($target),
            default => null,
        };

        if ($payload === null) {
            $this->error('Unknown action. Expected twin, impact, context-envelope, quality-score or snapshot.');

            return self::FAILURE;
        }

        if ((bool) $this->option('json')) {
            $this->line(json_encode($payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) ?: '{}');

            return $this->exitCode($payload);
        }

        $this->components->twoColumnDetail('Atlas Software Twin', (string) $payload['status']);
        $this->components->twoColumnDetail('Action', (string) ($payload['action'] ?? $action));
        $this->components->twoColumnDetail('Writes', $payload['writes'] ? 'yes' : 'no');
        $this->components->twoColumnDetail('Hash', (string) $payload['certification_hash']);

        return $this->exitCode($payload);
    }

    /**
     * @param  array<string,mixed>  $payload
     */
    private function exitCode(array $payload): int
    {
        return (bool) $this->option('strict') && $payload['status'] !== 'ready'
            ? self::FAILURE
            : self::SUCCESS;
    }
}
