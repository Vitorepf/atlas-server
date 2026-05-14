<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Services\Ai\SelfImprovement\AtlasSelfImprovementInvariantLockService;
use Illuminate\Console\Command;
use Throwable;

/**
 * Atlas Self-Improvement Invariant Lock CLI.
 *
 * Reads --after-snapshot, --diff, --proposal and runs the canonical invariant
 * lock. Read-model only.
 */
final class AtlasSelfImprovementInvariantLockCommand extends Command
{
    protected $signature = 'atlas:self-improvement:invariant-lock
        {--after-snapshot= : Inline JSON or @path with after snapshot (audit + system state)}
        {--diff= : Inline JSON or @path with the implementation diff descriptor}
        {--proposal= : Inline JSON or @path with the proposal packet}
        {--json}
        {--strict : Exit non-zero unless all invariants pass}';

    protected $description = 'Atlas Self-Improvement Invariant Lock — protects sacred rules. Read-model.';

    public function handle(AtlasSelfImprovementInvariantLockService $service): int
    {
        $after = $this->resolveJsonOption('after-snapshot') ?? [];
        $diff = $this->resolveJsonOption('diff') ?? [];
        $proposal = $this->resolveJsonOption('proposal') ?? [];

        $report = $service->evaluate($after, $diff, $proposal);
        $this->emit($report);

        if (! (bool) $this->option('strict')) {
            return self::SUCCESS;
        }

        return $report['status'] === AtlasSelfImprovementInvariantLockService::STATUS_PASSED
            ? self::SUCCESS
            : self::FAILURE;
    }

    /**
     * @return array<string,mixed>|null
     */
    private function resolveJsonOption(string $key): ?array
    {
        $raw = $this->option($key);
        if (! is_string($raw) || trim($raw) === '') {
            return null;
        }
        $raw = trim($raw);
        if (str_starts_with($raw, '@')) {
            $path = substr($raw, 1);
            if (! is_file($path)) {
                return null;
            }
            $raw = (string) file_get_contents($path);
        }
        try {
            $decoded = json_decode($raw, true, 512, JSON_THROW_ON_ERROR);
        } catch (Throwable) {
            return null;
        }

        return is_array($decoded) ? $decoded : null;
    }

    /**
     * @param  array<string,mixed>  $payload
     */
    private function emit(array $payload): void
    {
        if ((bool) $this->option('json')) {
            $this->line((string) json_encode(
                $payload,
                JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE,
            ));

            return;
        }
        $this->components->twoColumnDetail('schema_version', (string) ($payload['schema_version'] ?? '—'));
        $this->components->twoColumnDetail('status', (string) ($payload['status'] ?? '—'));
        $violations = $payload['violations'] ?? [];
        if (is_array($violations) && $violations !== []) {
            $this->components->bulletList(array_map(static fn ($v) => is_array($v) ? (string) ($v['invariant'] ?? '?') : (string) $v, $violations));
        }
    }
}
