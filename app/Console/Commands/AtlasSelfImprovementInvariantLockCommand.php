<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Console\Commands\Concerns\ResolvesSilentJsonOption;
use App\Services\Ai\SelfImprovement\AtlasSelfImprovementInvariantLockService;
use Illuminate\Console\Command;

/**
 * Atlas Self-Improvement Invariant Lock CLI.
 *
 * Reads --after-snapshot, --diff, --proposal and runs the canonical invariant
 * lock. Read-model only.
 */
final class AtlasSelfImprovementInvariantLockCommand extends Command
{
    use ResolvesSilentJsonOption;

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
