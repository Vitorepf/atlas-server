<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Services\Ai\SelfConstruction\NativeWorker\AtlasNativeWorkerPoolSupervisor;
use Illuminate\Console\Command;

/**
 * Arms the dormant orphan {@see AtlasNativeWorkerPoolSupervisor::run()} at the operator surface: previews the
 * native-worker pool supervisor plan (planned cycles, parallelism, blocked actions) WITHOUT dispatching.
 *
 * Strictly preview/read-only: the command FORCES apply=false (dry-run) regardless of the supplied options, so
 * no cycle is ever invoked and nothing is dispatched or mutated.
 */
final class AtlasLoopPoolSupervisorCommand extends Command
{
    protected $signature = 'atlas:loop:pool-supervisor {--options=} {--json}';

    protected $description = 'Read-only native-worker pool supervisor plan (dry-run forced; dispatches nothing).';

    public function handle(): int
    {
        $options = [];
        $raw = trim((string) $this->option('options'));
        if ($raw !== '') {
            if (is_file($raw) && is_readable($raw)) {
                $raw = (string) file_get_contents($raw);
            }
            $decoded = json_decode($raw, true);
            if (! is_array($decoded)) {
                $this->line((string) json_encode([
                    'outcome' => 'refused',
                    'reason' => 'usage_error',
                    'message' => '--options must be a JSON object',
                ], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR));

                return self::FAILURE;
            }
            $options = $decoded;
        }

        // PREVIEW ONLY: force dry-run so no cycle is invoked and nothing is dispatched, no matter what was asked.
        $options['apply'] = false;
        unset($options['cycle_callback']);

        $plan = app(AtlasNativeWorkerPoolSupervisor::class)->run($options);

        if ($this->option('json')) {
            $this->line((string) json_encode($plan, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR));
        } else {
            $this->line('dry_run: '.($plan['dry_run'] ? 'yes' : 'no').'  cycles_executed: '.$plan['cycle_count'].'  stop_reason: '.($plan['stop_reason'] ?? '-'));
        }

        return self::SUCCESS;
    }
}
