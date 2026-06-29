<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Services\Ai\SelfConstruction\MultiProject\AtlasProjectLaneRuntimeInstanceScheduler;
use Illuminate\Console\Command;

/**
 * Arms the dormant orphan {@see AtlasProjectLaneRuntimeInstanceScheduler::plan()} at the operator surface:
 * previews how project-lane runtime instances are scheduled — which tick now, which are held (safety stop,
 * stale heartbeat, missing isolation evidence, ...), and which are blocked (max-parallel / budget) — as facts.
 *
 * Pure + read-only: it plans and reports; it never ticks, dispatches, or mutates anything.
 */
final class AtlasLoopInstanceScheduleCommand extends Command
{
    protected $signature = 'atlas:loop:instance-schedule {--instances=} {--facts=} {--json}';

    protected $description = 'Read-only schedule plan for project-lane runtime instances (tick now / held / blocked).';

    public function handle(): int
    {
        $instances = $this->readJson('instances');
        if ($instances === null || ! array_is_list($instances)) {
            return $this->refuse('instance-schedule requires --instances=<JSON array of instances>');
        }
        $facts = $this->readJson('facts') ?? [];

        $plan = app(AtlasProjectLaneRuntimeInstanceScheduler::class)->plan($instances, $facts);

        if ($this->option('json')) {
            $this->line((string) json_encode($plan, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR));
        } else {
            $this->line('tick_now: '.count($plan['tick_now']).'  held: '.count($plan['held_lanes']).'  blocked: '.count($plan['blocked_lanes']));
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
