<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Services\Ai\AutonomousEvolution\HardCaseBench\AtlasLoopHardCaseDatasetRegistry;
use App\Services\Ai\AutonomousEvolution\HardCaseBench\AtlasLoopHardCaseHistoricalFailureMiner;
use App\Services\Ai\AutonomousEvolution\HardCaseBench\HardCaseCandidate;
use Illuminate\Console\Command;

/**
 * Arms the dormant {@see AtlasLoopHardCaseHistoricalFailureMiner::mineCandidates()} at the operator surface:
 * mines hard-case candidates from a supplied historical-failure source (--records as the injected events) over
 * the recent window and emits the deduped candidates as facts. Candidates already frozen in the registry are
 * excluded.
 *
 * Pure + read-only: the miner NEVER writes to the registry (auto-registering would let the loop quietly drop
 * cases it can't pass); this command only reports proposals.
 */
final class AtlasLoopHardCaseMineCommand extends Command
{
    protected $signature = 'atlas:loop:hardcase-mine {--since-days=90} {--records=} {--json}';

    protected $description = 'Read-only mine of hard-case candidates from a supplied historical-failure source.';

    public function handle(): int
    {
        $sinceDays = max(1, (int) $this->option('since-days'));

        $records = [];
        $raw = trim((string) $this->option('records'));
        if ($raw !== '') {
            if (is_file($raw) && is_readable($raw)) {
                $raw = (string) file_get_contents($raw);
            }
            $decoded = json_decode($raw, true);
            if (! is_array($decoded) || ! array_is_list($decoded)) {
                $this->line((string) json_encode([
                    'outcome' => 'refused',
                    'reason' => 'usage_error',
                    'message' => '--records must be a JSON array of failure events',
                ], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR));

                return self::FAILURE;
            }
            $records = $decoded;
        }

        // The injected failure source (a callable the miner consumes); empty by default = nothing to mine.
        $eventsSource = static fn (int $window): iterable => $records;
        $miner = new AtlasLoopHardCaseHistoricalFailureMiner(app(AtlasLoopHardCaseDatasetRegistry::class), $eventsSource);

        $candidates = $miner->mineCandidates($sinceDays);

        $facts = [
            'schema' => 'atlas.loop.hardcase_mine.v1',
            'since_days' => $sinceDays,
            'candidate_count' => count($candidates),
            'candidates' => array_map(static fn (HardCaseCandidate $c): array => $c->toRegistryShape(), $candidates),
        ];

        if ($this->option('json')) {
            $this->line((string) json_encode($facts, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR));
        } else {
            $this->line('candidate_count: '.$facts['candidate_count']);
            foreach ($candidates as $c) {
                $this->line('  '.$c->caseId.'  '.$c->source.'  '.$c->scopeRoot);
            }
        }

        return self::SUCCESS;
    }
}
