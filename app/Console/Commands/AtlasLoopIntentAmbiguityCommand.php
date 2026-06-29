<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Services\Ai\AutonomousEvolution\Quaternity\CortexIntentMeaning\AtlasCortexIntentMeaningAmbiguityDetector;
use Illuminate\Console\Command;

/**
 * Arms the dormant {@see AtlasCortexIntentMeaningAmbiguityDetector::detect()} at the operator surface: runs the
 * intent-meaning ambiguity detector over supplied triangulation facts and emits the verdict — does the intent
 * ground to EXACTLY ONE (symbol,file) site, or is it ambiguous / unclarified — as deterministic facts.
 *
 * Pure + read-only + anti-Goodhart (count-of-distinct-sites only): the detector spawns nothing; the facts are
 * supplied as input. It reports; it mutates nothing.
 */
final class AtlasLoopIntentAmbiguityCommand extends Command
{
    protected $signature = 'atlas:loop:intent-ambiguity {--facts=} {--json}';

    protected $description = 'Read-only intent-meaning ambiguity verdict over supplied triangulation facts.';

    public function handle(): int
    {
        $raw = trim((string) $this->option('facts'));
        if ($raw === '') {
            return $this->refuse('intent-ambiguity requires --facts=<JSON array of triangulation facts or a path>');
        }
        if (is_file($raw) && is_readable($raw)) {
            $raw = (string) file_get_contents($raw);
        }
        $decoded = json_decode($raw, true);
        if (! is_array($decoded)) {
            return $this->refuse('--facts must be a JSON array/object');
        }
        $facts = isset($decoded['triangulation_facts']) && is_array($decoded['triangulation_facts'])
            ? $decoded['triangulation_facts']
            : $decoded;
        if (! array_is_list($facts)) {
            return $this->refuse('--facts must be a JSON array of triangulation facts');
        }

        $verdict = app(AtlasCortexIntentMeaningAmbiguityDetector::class)->detect(array_values($facts));

        $payload = ['schema' => 'atlas.loop.intent_ambiguity.v1'] + $verdict;

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
