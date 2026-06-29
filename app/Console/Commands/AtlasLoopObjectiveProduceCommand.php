<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Services\Ai\AutonomousEvolution\Discovery\AtlasLoopObjectiveProducer;
use Illuminate\Console\Command;

/**
 * Arms the dormant {@see AtlasLoopObjectiveProducer::select()} at the operator surface: reads candidate signal
 * packets from a JSON file (a list), scores + ranks them by leverage, and emits the chosen objective (the
 * top candidate clearing the ambition floor, or none) as deterministic facts. Read-only and pure — it only
 * selects; it never enqueues or builds a goal.
 */
final class AtlasLoopObjectiveProduceCommand extends Command
{
    protected $signature = 'atlas:loop:objective-produce {--input=} {--json}';

    protected $description = 'Read-only: select the highest-leverage objective from candidate packets (JSON list).';

    public function handle(AtlasLoopObjectiveProducer $producer): int
    {
        $input = trim((string) $this->option('input'));
        if ($input === '') {
            $this->line((string) json_encode(['status' => 'usage_error', 'reason' => 'input_required'], JSON_UNESCAPED_SLASHES));

            return self::INVALID;
        }
        if (! is_file($input)) {
            $this->line((string) json_encode(['status' => 'input_not_found', 'input' => $input], JSON_UNESCAPED_SLASHES));

            return self::INVALID;
        }

        $packets = json_decode((string) file_get_contents($input), true);
        if (! is_array($packets)) {
            $this->line((string) json_encode(['status' => 'invalid_json', 'input' => $input], JSON_UNESCAPED_SLASHES));

            return self::INVALID;
        }

        $selected = $producer->select(array_values(array_filter($packets, 'is_array')));

        $this->line((string) json_encode([
            'schema' => 'atlas.loop.objective_produce.v1',
            'has_objective' => $selected !== null,
            'selected' => $selected,
        ], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR));

        return self::SUCCESS;
    }
}
