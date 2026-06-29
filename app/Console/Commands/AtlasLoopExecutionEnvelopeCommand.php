<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Services\Ai\SelfConstruction\WorkerSwarm\AtlasSelfConstructionWorkerScopedExecutionEnvelope;
use Illuminate\Console\Command;

/**
 * Arms the dormant orphan {@see AtlasSelfConstructionWorkerScopedExecutionEnvelope::compose()} at the operator
 * surface: composes the scoped execution envelope a worker would run under (allowed/forbidden files, gates,
 * evidence requirements, rollback plan) and emits it with a validity verdict + blockers.
 *
 * Pure + read-only: it composes the envelope and reports; it executes nothing and mutates nothing.
 */
final class AtlasLoopExecutionEnvelopeCommand extends Command
{
    protected $signature = 'atlas:loop:execution-envelope {--input=} {--json}';

    protected $description = 'Read-only scoped worker execution envelope composer (allowed paths + guards + validity).';

    public function handle(): int
    {
        $raw = trim((string) $this->option('input'));
        if ($raw === '') {
            return $this->refuse('execution-envelope requires --input=<json object or path>');
        }
        if (is_file($raw) && is_readable($raw)) {
            $raw = (string) file_get_contents($raw);
        }
        $input = json_decode($raw, true);
        if (! is_array($input)) {
            return $this->refuse('--input must be a JSON object');
        }

        $envelope = app(AtlasSelfConstructionWorkerScopedExecutionEnvelope::class)->compose($input);

        if ($this->option('json')) {
            $this->line((string) json_encode($envelope, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR));
        } else {
            $this->line('valid: '.($envelope['valid'] ? 'yes' : 'no').'  allowed_files: '.implode(',', $envelope['allowed_files']));
            foreach ($envelope['blockers'] as $b) {
                $this->line('  blocker: '.$b);
            }
        }

        return self::SUCCESS;
    }

    private function refuse(string $message): int
    {
        $this->line((string) json_encode([
            'outcome' => 'refused',
            'reason' => 'usage_error',
            'message' => $message,
        ], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR));

        return self::FAILURE;
    }
}
