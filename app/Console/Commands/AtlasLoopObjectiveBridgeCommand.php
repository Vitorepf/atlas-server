<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Services\Ai\AutonomousEvolution\V4\AtlasLoopV4ObjectiveToWorkBridge;
use Illuminate\Console\Command;

/**
 * Arms the dormant orphan {@see AtlasLoopV4ObjectiveToWorkBridge::bridge()} at the operator surface: bridges a
 * meta-objective to concrete work by scoping the comprehension inventory to the symbols the objective text
 * names — refusing when the objective is not originated or grounds to no inventory symbol.
 *
 * Pure + read-only: it scopes and reports; it mutates nothing.
 */
final class AtlasLoopObjectiveBridgeCommand extends Command
{
    protected $signature = 'atlas:loop:objective-bridge {--meta-objective=} {--inventory=} {--json}';

    protected $description = 'Read-only meta-objective -> work bridge (scopes inventory to the objective symbols).';

    public function handle(): int
    {
        $metaObjective = $this->readJson('meta-objective');
        $inventory = $this->readJson('inventory');
        if ($metaObjective === null) {
            return $this->refuse('objective-bridge requires --meta-objective=<json object or path>');
        }
        if ($inventory === null || ! array_is_list($inventory)) {
            return $this->refuse('objective-bridge requires --inventory=<JSON array of symbols>');
        }

        $result = app(AtlasLoopV4ObjectiveToWorkBridge::class)->bridge($metaObjective, array_values($inventory));

        $facts = ['schema' => 'atlas.loop.v4_objective_bridge.v1'] + $result;

        if ($this->option('json')) {
            $this->line((string) json_encode($facts, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR));
        } else {
            $this->line('bridged: '.($result['bridged'] ? 'yes' : 'no').'  refuse_reason: '.($result['refuse_reason'] ?? '-'));
            $this->line('scoped_inventory: '.implode(', ', $result['scoped_inventory']));
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
