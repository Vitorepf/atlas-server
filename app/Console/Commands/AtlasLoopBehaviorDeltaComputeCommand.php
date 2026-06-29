<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Services\Ai\AutonomousEvolution\BehaviorDelta\AtlasLoopBehaviorDeltaComputer;
use Illuminate\Console\Command;

/**
 * Arms the dormant {@see AtlasLoopBehaviorDeltaComputer::compute()} at the operator surface: reads a before and
 * an after behavior snapshot from a JSON file and emits the TYPED behavior delta (symbols added/removed, public-
 * API signatures changed, caller edges added/removed, and the net structural-change count) as facts.
 *
 * Read-only + pure + deterministic: same (before, after) ⇒ byte-identical output. net_behavior_delta is a COUNT
 * of real structural changes (re-derivable from two commits), never an LLM score. No provider/DB/mutation.
 */
final class AtlasLoopBehaviorDeltaComputeCommand extends Command
{
    protected $signature = 'atlas:loop:behavior-delta-compute {--input=} {--json}';

    protected $description = 'Read-only typed behavior delta between two behavior snapshots (net structural change count).';

    public function handle(): int
    {
        $input = trim((string) $this->option('input'));
        if ($input === '' || ! is_file($input) || ! is_readable($input)) {
            return $this->refuse('behavior-delta-compute requires --input=<path to a readable {before, after} JSON>');
        }

        $decoded = json_decode((string) file_get_contents($input), true);
        if (! is_array($decoded) || ! is_array($decoded['before'] ?? null) || ! is_array($decoded['after'] ?? null)) {
            return $this->refuse('input JSON must be an object with `before` and `after` snapshot objects');
        }

        $delta = app(AtlasLoopBehaviorDeltaComputer::class)->compute($decoded['before'], $decoded['after']);

        if ($this->option('json')) {
            $this->line((string) json_encode($delta, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR));
        } else {
            $this->line('net_behavior_delta: '.$delta['net_behavior_delta']);
            $this->line('added: '.count($delta['symbols_added']).'  removed: '.count($delta['symbols_removed']).'  sig_changed: '.count($delta['api_signature_changed']));
            $this->line('caller_edges +'.$delta['caller_edges_added'].' -'.$delta['caller_edges_removed']);
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
