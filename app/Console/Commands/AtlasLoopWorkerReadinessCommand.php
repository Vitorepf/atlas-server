<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Services\Ai\SelfConstruction\NativeWorker\AtlasNativeWorkerReadinessGate;
use Illuminate\Console\Command;

/**
 * Arms the dormant orphan {@see AtlasNativeWorkerReadinessGate::evaluate()} at the operator surface: decides
 * whether the Atlas-native worker swarm may execute packets continuously — emitting ready + the exact named
 * blockers (wrong runtime owner, no server-side verification, no rollback, missing/unverified components,
 * registry drift) — as deterministic facts.
 *
 * Read-only + facts-only: it evaluates observed readiness and reports; it never dispatches or mutates anything.
 */
final class AtlasLoopWorkerReadinessCommand extends Command
{
    protected $signature = 'atlas:loop:worker-readiness {--observed=} {--json}';

    protected $description = 'Read-only native-worker readiness verdict (ready + blockers) from observed component facts.';

    public function handle(): int
    {
        $raw = trim((string) $this->option('observed'));
        if ($raw === '') {
            return $this->refuse('worker-readiness requires --observed=<json object or path>');
        }
        if (is_file($raw) && is_readable($raw)) {
            $raw = (string) file_get_contents($raw);
        }
        $observed = json_decode($raw, true);
        if (! is_array($observed)) {
            return $this->refuse('--observed must be a JSON object');
        }

        $verdict = app(AtlasNativeWorkerReadinessGate::class)->evaluate($observed);

        if ($this->option('json')) {
            $this->line((string) json_encode($verdict, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR));
        } else {
            $this->line('ready: '.($verdict['ready'] ? 'yes' : 'no'));
            foreach ($verdict['blockers'] as $b) {
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
