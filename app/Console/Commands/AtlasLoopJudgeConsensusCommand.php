<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Services\Ai\AutonomousEvolution\AtlasLoopJudgeConsensusGate;
use Illuminate\Console\Command;

/**
 * Arms the dormant {@see AtlasLoopJudgeConsensusGate::evaluate()} at the operator surface: reads per-judge
 * verdicts (+ an optional quorum policy) from a JSON file and emits the cross-model consensus verdict —
 * independence, lens coverage, quorum, and any dissents — as deterministic facts.
 *
 * Read-only + pure: the consensus arithmetic only; the judges (provider calls) are produced elsewhere and fed
 * in. No provider/DB/mutation.
 */
final class AtlasLoopJudgeConsensusCommand extends Command
{
    protected $signature = 'atlas:loop:judge-consensus {--input=} {--json}';

    protected $description = 'Read-only cross-model judge consensus verdict (independence + lens coverage + quorum).';

    public function handle(): int
    {
        $input = trim((string) $this->option('input'));
        if ($input === '' || ! is_file($input) || ! is_readable($input)) {
            return $this->refuse('judge-consensus requires --input=<path to a readable verdicts JSON>');
        }

        $decoded = json_decode((string) file_get_contents($input), true);
        if (! is_array($decoded)) {
            return $this->refuse('input file is not a JSON array/object');
        }
        $verdicts = isset($decoded['verdicts']) && is_array($decoded['verdicts']) ? $decoded['verdicts'] : $decoded;
        $quorum = isset($decoded['quorum']) && is_array($decoded['quorum']) ? $decoded['quorum'] : [];
        if (! array_is_list($verdicts)) {
            return $this->refuse('verdicts must be a JSON array');
        }

        $verdict = app(AtlasLoopJudgeConsensusGate::class)->evaluate(array_values($verdicts), $quorum);

        $facts = ['schema' => 'atlas.loop.judge_consensus.v1'] + $verdict;

        if ($this->option('json')) {
            $this->line((string) json_encode($facts, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR));
        } else {
            $this->line('consensus: '.($facts['consensus'] ? 'yes' : 'no').'  passed: '.$facts['passed'].'/'.$facts['total']);
            if ($facts['reason'] !== null) {
                $this->line('reason: '.$facts['reason']);
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
