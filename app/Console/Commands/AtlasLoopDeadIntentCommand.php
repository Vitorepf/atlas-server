<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Services\Ai\AutonomousEvolution\CausalGraph\AtlasLoopDeadIntentDetector;
use Illuminate\Console\Command;

/**
 * Arms the dormant {@see AtlasLoopDeadIntentDetector::detect()} at the operator surface: flags inventory-grounded
 * symbols whose original intent no live consumer needs anymore (zero live consumers) — removal candidates
 * distinct from unwired orphans — as deterministic facts.
 *
 * Pure + read-only + fail-closed: a bare FQCN, unknown consumer count, or forbidden/pétreo target is never
 * flagged. It detects only; it deletes nothing and mutates nothing.
 */
final class AtlasLoopDeadIntentCommand extends Command
{
    protected $signature = 'atlas:loop:dead-intent {--symbols=} {--json}';

    protected $description = 'Read-only dead-intent detection (grounded symbols with zero live consumers); never deletes.';

    public function handle(): int
    {
        $raw = trim((string) $this->option('symbols'));
        if ($raw === '') {
            return $this->refuse('dead-intent requires --symbols=<JSON array of symbols or a path>');
        }
        if (is_file($raw) && is_readable($raw)) {
            $raw = (string) file_get_contents($raw);
        }
        $decoded = json_decode($raw, true);
        if (! is_array($decoded)) {
            return $this->refuse('--symbols must be a JSON array/object');
        }
        $symbols = isset($decoded['symbols']) && is_array($decoded['symbols']) ? $decoded['symbols'] : $decoded;
        if (! array_is_list($symbols)) {
            return $this->refuse('--symbols must be a JSON array of symbols');
        }

        $result = app(AtlasLoopDeadIntentDetector::class)->detect(array_values($symbols));
        $result['dead_intent_count'] = count($result['dead_intent']);

        if ($this->option('json')) {
            $this->line((string) json_encode($result, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR));
        } else {
            $this->line('dead_intent_count: '.$result['dead_intent_count']);
            foreach ($result['dead_intent'] as $d) {
                $this->line('  '.$d['fqcn'].'  '.$d['reason']);
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
