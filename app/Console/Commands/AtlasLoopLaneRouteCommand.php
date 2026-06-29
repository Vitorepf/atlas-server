<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Services\Ai\SelfConstruction\MultiProject\AtlasProjectLaneTaskFabricRouter;
use Illuminate\Console\Command;
use RuntimeException;

/**
 * Arms the dormant orphan {@see AtlasProjectLaneTaskFabricRouter::route()} at the operator surface: previews how
 * a candidate task spec routes into a lane task-fabric packet (task_packet_id, objective, allowed_files, scope-
 * locked workspace policy), refusing any candidate whose paths escape the lane's allowed scope roots.
 *
 * Pure + read-only: it never calls providers/shells/git/workers — it only routes and reports.
 */
final class AtlasLoopLaneRouteCommand extends Command
{
    protected $signature = 'atlas:loop:lane-route {--lane=} {--candidate=} {--json}';

    protected $description = 'Read-only preview of a candidate routed into a lane task-fabric packet (scope-locked).';

    public function handle(): int
    {
        $lane = $this->readJson('lane');
        $candidate = $this->readJson('candidate');
        if ($lane === null) {
            return $this->refuse('lane-route requires --lane=<json object or path>');
        }
        if ($candidate === null) {
            return $this->refuse('lane-route requires --candidate=<json object or path>');
        }

        try {
            $packet = app(AtlasProjectLaneTaskFabricRouter::class)->route($lane, $candidate);
        } catch (RuntimeException $e) {
            $this->line((string) json_encode([
                'outcome' => 'refused',
                'reason' => 'route_refused',
                'message' => $e->getMessage(),
            ], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR));

            return self::FAILURE;
        }

        if ($this->option('json')) {
            $this->line((string) json_encode($packet, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR));
        } else {
            $this->line('task_packet_id: '.$packet['task_packet_id']);
            $this->line('objective: '.$packet['objective']);
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
