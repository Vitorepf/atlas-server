<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Services\Ai\SelfConstruction\MultiProject\AtlasProjectLaneRuntimeInstanceSoak;
use Illuminate\Console\Command;

/**
 * Arms the dormant orphan {@see AtlasProjectLaneRuntimeInstanceSoak::run()} at the operator surface: computes a
 * multi-project lane soak result from instances (+ optional scripts) — per-lane results, queue-namespace /
 * write-root leak attempts, isolated failures, dependency violations, and an overall passed verdict.
 *
 * Pure + read-only: zero IO/exec — it SIMULATES the soak and reports; it mutates nothing.
 */
final class AtlasLoopLaneSoakCommand extends Command
{
    protected $signature = 'atlas:loop:lane-soak {--instances=} {--options=} {--json}';

    protected $description = 'Read-only multi-project lane soak (pure simulation; leak + dependency + progress verdict).';

    public function handle(): int
    {
        $instances = $this->readJson('instances');
        if ($instances === null || ! array_is_list($instances)) {
            return $this->refuse('lane-soak requires --instances=<JSON array of instances>');
        }
        $options = $this->readJson('options') ?? [];

        $result = app(AtlasProjectLaneRuntimeInstanceSoak::class)->run(array_values($instances), $options);

        if ($this->option('json')) {
            $this->line((string) json_encode($result, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR));
        } else {
            $this->line('passed: '.($result['passed'] ? 'yes' : 'no').'  lanes: '.count($result['lane_results']).'  leaks: '.count($result['leak_attempts']));
        }

        return self::SUCCESS;
    }

    /** @return array<mixed>|null */
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
