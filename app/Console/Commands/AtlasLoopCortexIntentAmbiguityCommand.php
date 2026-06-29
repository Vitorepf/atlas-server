<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Services\Ai\AutonomousEvolution\Quaternity\CortexIntentMeaning\AtlasCortexIntentMeaningAmbiguityDetector;
use Illuminate\Console\Command;

/**
 * Arms the dormant {@see AtlasCortexIntentMeaningAmbiguityDetector::detect()} at the operator surface: reads the
 * triangulation facts (grounded {intent, symbol, file} rows) from a JSON file and emits the ambiguity verdict —
 * does the intent ground to EXACTLY ONE real (symbol,file) site, or does it need clarification — as facts.
 *
 * Read-only + pure + anti-Goodhart: no knobs, no scores; the verdict is purely the count of distinct sites
 * (0 ⇒ clarify, 1 ⇒ proceed, ≥2 ⇒ ambiguous). No provider/DB/mutation.
 */
final class AtlasLoopCortexIntentAmbiguityCommand extends Command
{
    protected $signature = 'atlas:loop:cortex-intent-ambiguity {--input=} {--json}';

    protected $description = 'Read-only intent-meaning ambiguity verdict over Cortex triangulation facts (distinct-site count).';

    public function handle(): int
    {
        $input = trim((string) $this->option('input'));
        if ($input === '' || ! is_file($input) || ! is_readable($input)) {
            return $this->refuse('cortex-intent-ambiguity requires --input=<path to a readable triangulation JSON>');
        }

        $decoded = json_decode((string) file_get_contents($input), true);
        if (! is_array($decoded)) {
            return $this->refuse('input file is not a JSON array/object');
        }
        $facts = isset($decoded['triangulation_facts']) && is_array($decoded['triangulation_facts'])
            ? $decoded['triangulation_facts']
            : $decoded;

        $verdict = app(AtlasCortexIntentMeaningAmbiguityDetector::class)->detect(array_values($facts));

        $payload = ['schema' => 'atlas.loop.cortex_intent_ambiguity.v1'] + $verdict;

        if ($this->option('json')) {
            $this->line((string) json_encode($payload, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR));
        } else {
            $this->line('ambiguous: '.($verdict['ambiguous'] ? 'yes' : 'no').'  sites: '.$verdict['site_count'].'  reason: '.$verdict['reason']);
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
