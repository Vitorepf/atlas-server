<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Services\Ai\SelfConstruction\AtlasSelfConstructionRealProviderSmokeClosureExecutionPackService;
use Illuminate\Console\Command;

/**
 * Arms the dormant orphan {@see AtlasSelfConstructionRealProviderSmokeClosureExecutionPackService::build()} at
 * the operator surface: builds the read-only real-provider smoke closure execution pack (runbook, offline
 * harness, evidence dossier, certification, operator checklist, exact commands, contracts) from options.
 *
 * Pure + read-only (MODE read_only_real_provider_smoke_closure_execution_pack): it BUILDS the pack structure
 * only — it executes nothing, calls no provider, and mutates nothing.
 */
final class AtlasLoopSmokePackCommand extends Command
{
    protected $signature = 'atlas:loop:smoke-pack {--options=} {--json}';

    protected $description = 'Read-only build of the real-provider smoke closure execution pack (executes nothing).';

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

        $pack = app(AtlasSelfConstructionRealProviderSmokeClosureExecutionPackService::class)->build($options);

        if ($this->option('json')) {
            $this->line((string) json_encode($pack, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR));
        } else {
            $this->line('schema_version: '.$pack['schema_version']);
            $this->line('status: '.$pack['status'].'  blocker_id: '.$pack['blocker_id']);
        }

        return self::SUCCESS;
    }
}
