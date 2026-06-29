<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Services\Ai\AutonomousEvolution\AtlasLoopProviderRollbackPolicy;
use Illuminate\Console\Command;

/**
 * Arms the dormant orphan {@see AtlasLoopProviderRollbackPolicy::decisionFor()} at the operator surface:
 * converts deterministic runtime signals into a routing intent — keep the current provider, roll back to the
 * previous stable, or escalate to the hard provider (when a rollback signal fires during an architect phase).
 *
 * Pure + read-only: it DECIDES a routing intent only; provider execution stays owned by the router/next cycle.
 */
final class AtlasLoopProviderRollbackCommand extends Command
{
    protected $signature = 'atlas:loop:provider-rollback {--signals=} {--json}';

    protected $description = 'Read-only provider rollback decision (keep|rollback|escalate) from runtime signals.';

    public function handle(): int
    {
        $raw = trim((string) $this->option('signals'));
        if ($raw === '') {
            return $this->refuse('provider-rollback requires --signals=<json object or path>');
        }
        if (is_file($raw) && is_readable($raw)) {
            $raw = (string) file_get_contents($raw);
        }
        $signals = json_decode($raw, true);
        if (! is_array($signals)) {
            return $this->refuse('--signals must be a JSON object');
        }

        $decision = app(AtlasLoopProviderRollbackPolicy::class)->decisionFor($signals);

        $facts = [
            'schema' => 'atlas.loop.provider_rollback.v1',
            'policy_version' => AtlasLoopProviderRollbackPolicy::POLICY_VERSION,
            'decision' => $decision,
        ];

        if ($this->option('json')) {
            $this->line((string) json_encode($facts, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR));
        } else {
            $this->line('decision: '.$decision);
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
