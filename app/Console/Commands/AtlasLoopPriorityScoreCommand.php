<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Services\Ai\SelfConstruction\SelfConstructionImplementationPriorityScorer;
use Illuminate\Console\Command;

/**
 * Arms the dormant orphan {@see SelfConstructionImplementationPriorityScorer::score()} at the operator surface:
 * reads an additive/subtractive factors JSON and emits the deterministic priority verdict (score, p_level,
 * dominant/weakest factor, additive/subtractive totals).
 *
 * Pure + read-only: it computes and reports a priority — it mutates nothing, calls no provider/DB.
 */
final class AtlasLoopPriorityScoreCommand extends Command
{
    protected $signature = 'atlas:loop:priority-score {--factors=} {--json}';

    protected $description = 'Read-only deterministic implementation-priority score (P0..P3) from additive/subtractive factors.';

    public function handle(): int
    {
        $raw = trim((string) $this->option('factors'));
        if ($raw === '') {
            return $this->refuse('priority-score requires --factors=<json object or path>');
        }
        if (is_file($raw) && is_readable($raw)) {
            $raw = (string) file_get_contents($raw);
        }

        $factors = json_decode($raw, true);
        if (! is_array($factors)) {
            return $this->refuse('--factors must be a JSON object');
        }

        $verdict = app(SelfConstructionImplementationPriorityScorer::class)->score($factors);

        if ($this->option('json')) {
            $this->line((string) json_encode($verdict, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR));
        } else {
            $this->line('p_level: '.$verdict['p_level'].'  score: '.$verdict['score'].'  mode: '.$verdict['mode']);
            $this->line('dominant: '.$verdict['dominant_factor'].'  weakest: '.$verdict['weakest_factor']);
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
