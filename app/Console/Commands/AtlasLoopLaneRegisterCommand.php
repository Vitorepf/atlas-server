<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Services\Ai\SelfConstruction\MultiProject\AtlasProjectLaneRegistry;
use Illuminate\Console\Command;
use RuntimeException;

/**
 * Arms the dormant orphan {@see AtlasProjectLaneRegistry::register()} at the operator surface: computes the
 * project-lane registration record from a supplied lane record (provider-safe — secret/exec keys are stripped),
 * refusing a record without project_id + repo_root.
 *
 * Pure + read-only (functional): it returns the sanitized registration record; it mutates nothing persistent.
 */
final class AtlasLoopLaneRegisterCommand extends Command
{
    protected $signature = 'atlas:loop:lane-register {--record=} {--json}';

    protected $description = 'Read-only project-lane registration record from a lane record (provider-safe).';

    public function handle(): int
    {
        $raw = trim((string) $this->option('record'));
        if ($raw === '') {
            return $this->refuse('lane-register requires --record=<json object or path>');
        }
        if (is_file($raw) && is_readable($raw)) {
            $raw = (string) file_get_contents($raw);
        }
        $record = json_decode($raw, true);
        if (! is_array($record)) {
            return $this->refuse('--record must be a JSON object');
        }

        try {
            $registration = app(AtlasProjectLaneRegistry::class)->register($record);
        } catch (RuntimeException $e) {
            $this->line((string) json_encode([
                'outcome' => 'refused',
                'reason' => 'register_refused',
                'message' => $e->getMessage(),
            ], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR));

            return self::FAILURE;
        }

        $facts = ['schema' => 'atlas.loop.lane_register.v1', 'registration' => $registration];

        if ($this->option('json')) {
            $this->line((string) json_encode($facts, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR));
        } else {
            $this->line('project_id: '.($registration['project_id'] ?? '-').'  repo_root: '.($registration['repo_root'] ?? '-'));
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
