<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Services\Ai\AutonomousEvolution\CausalGraph\AtlasLoopDeadIntentDetector;
use Illuminate\Console\Command;

/**
 * Arms the dormant {@see AtlasLoopDeadIntentDetector::detect()} at the operator surface: reads an inventory-
 * grounded symbols list from a JSON file and emits the symbols whose original intent no live consumer needs
 * anymore (zero live consumers) as deterministic facts.
 *
 * Read-only + fact-only + fail-closed: a bare FQCN, unknown consumer count, or forbidden/pétreo target is never
 * harvested as dead intent. No provider/DB/mutation.
 */
final class AtlasLoopDeadIntentDetectCommand extends Command
{
    protected $signature = 'atlas:loop:dead-intent-detect {--input=} {--json}';

    protected $description = 'Read-only detection of dead-intent symbols (inventory-grounded, zero live consumers).';

    public function handle(): int
    {
        $input = trim((string) $this->option('input'));
        if ($input === '' || ! is_file($input) || ! is_readable($input)) {
            $this->line((string) json_encode([
                'outcome' => 'refused',
                'reason' => 'usage_error',
                'message' => 'dead-intent-detect requires --input=<path to a readable symbols JSON>',
            ], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR));

            return self::FAILURE;
        }

        $decoded = json_decode((string) file_get_contents($input), true);
        if (! is_array($decoded)) {
            $this->line((string) json_encode([
                'outcome' => 'refused',
                'reason' => 'usage_error',
                'message' => 'input file is not a JSON array/object',
            ], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR));

            return self::FAILURE;
        }
        $symbols = isset($decoded['symbols']) && is_array($decoded['symbols']) ? $decoded['symbols'] : $decoded;

        $result = app(AtlasLoopDeadIntentDetector::class)->detect(array_values($symbols));

        $facts = $result + ['dead_intent_count' => count($result['dead_intent'])];

        if ($this->option('json')) {
            $this->line((string) json_encode($facts, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR));
        } else {
            $this->line('dead_intent_count: '.$facts['dead_intent_count']);
            foreach ($facts['dead_intent'] as $d) {
                $this->line($d['fqcn'].'  '.$d['reason']);
            }
        }

        return self::SUCCESS;
    }
}
