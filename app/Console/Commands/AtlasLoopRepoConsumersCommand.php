<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Services\Ai\AutonomousEvolution\RepoWide\AtlasLoopRepoWideCallerResolver;
use App\Services\Ai\AutonomousEvolution\RepoWide\AtlasLoopRepoWideConsumersProvider;
use Illuminate\Console\Command;

/**
 * Arms the dormant orphan {@see AtlasLoopRepoWideConsumersProvider::consumersOf()} at the operator surface:
 * lists the repo-wide consumers (caller fqcns) of a given FQCN from a supplied repo-wide model, using the
 * container-resolved caller resolver.
 *
 * Pure + read-only: it resolves callers over the supplied model and reports; it mutates nothing.
 */
final class AtlasLoopRepoConsumersCommand extends Command
{
    protected $signature = 'atlas:loop:repo-consumers {--fqcn=} {--model=} {--json}';

    protected $description = 'Read-only repo-wide consumers (caller fqcns) of a given FQCN from a supplied model.';

    public function handle(): int
    {
        $fqcn = trim((string) $this->option('fqcn'));
        if ($fqcn === '') {
            return $this->refuse('repo-consumers requires --fqcn=<class>');
        }
        $model = $this->readJson('model');
        if ($model === null) {
            return $this->refuse('repo-consumers requires --model=<repo-wide model JSON object or path>');
        }

        $provider = new AtlasLoopRepoWideConsumersProvider($model, app(AtlasLoopRepoWideCallerResolver::class));
        $consumers = $provider->consumersOf($fqcn);

        $facts = [
            'schema' => 'atlas.loop.repo_consumers.v1',
            'fqcn' => ltrim($fqcn, '\\'),
            'consumer_count' => count($consumers),
            'consumers' => $consumers,
        ];

        if ($this->option('json')) {
            $this->line((string) json_encode($facts, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR));
        } else {
            $this->line($facts['fqcn'].' consumed by '.$facts['consumer_count'].': '.implode(', ', $consumers));
        }

        return self::SUCCESS;
    }

    /** @return array<string,mixed>|null */
    private function readJson(string $option): ?array
    {
        $raw = trim((string) $this->option($option));
        if ($raw === '') {
            return null;
        }
        if (is_file($raw) && is_readable($raw)) {
            $raw = (string) file_get_contents($raw);
        }
        $decoded = json_decode($raw, true);

        return is_array($decoded) ? $decoded : null;
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
