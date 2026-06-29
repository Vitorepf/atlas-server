<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Services\Ai\AutonomousEvolution\CausalGraph\AtlasLoopPreHocBlastRadiusPredictor;
use Illuminate\Console\Command;

/**
 * Arms the dormant {@see AtlasLoopPreHocBlastRadiusPredictor::predict()} at the operator surface: reads a target
 * fqcn, a proposed API change, and its consumers from a JSON file and emits the predicted blast radius — which
 * consumers would break and how (removed_member | signature_changed) — as advisory facts.
 *
 * Read-only + advisory: no files touched, no edit materialized, no risk score invented. No provider/DB/mutation.
 */
final class AtlasLoopPreHocBlastRadiusCommand extends Command
{
    protected $signature = 'atlas:loop:pre-hoc-blast-radius {--input=} {--json}';

    protected $description = 'Read-only pre-materialization blast-radius prediction for a proposed API change.';

    public function handle(): int
    {
        $input = trim((string) $this->option('input'));
        if ($input === '' || ! is_file($input) || ! is_readable($input)) {
            return $this->refuse('pre-hoc-blast-radius requires --input=<path to a readable JSON>');
        }

        $decoded = json_decode((string) file_get_contents($input), true);
        if (! is_array($decoded)) {
            return $this->refuse('input file is not a JSON object');
        }
        $fqcn = trim((string) ($decoded['fqcn'] ?? ''));
        if ($fqcn === '') {
            return $this->refuse('input must carry a non-empty `fqcn`');
        }
        $change = is_array($decoded['proposed_api_change'] ?? null) ? $decoded['proposed_api_change']
            : (is_array($decoded['api_change'] ?? null) ? $decoded['api_change'] : []);
        $consumers = is_array($decoded['consumers'] ?? null) ? array_values($decoded['consumers'])
            : (is_array($decoded['consumer_fqcns'] ?? null) ? array_values($decoded['consumer_fqcns']) : []);

        $prediction = app(AtlasLoopPreHocBlastRadiusPredictor::class)->predict($fqcn, $change, $consumers);

        $facts = ['fqcn' => $fqcn, 'break_count' => count($prediction['would_break'])] + $prediction;

        if ($this->option('json')) {
            $this->line((string) json_encode($facts, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR));
        } else {
            $this->line('safe: '.($facts['safe'] ? 'yes' : 'no').'  break_count: '.$facts['break_count']);
            foreach ($prediction['would_break'] as $b) {
                $this->line('  '.$b['consumer_fqcn'].'  '.$b['break_kind']);
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
