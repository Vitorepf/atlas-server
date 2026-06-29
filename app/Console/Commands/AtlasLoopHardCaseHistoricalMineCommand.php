<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Services\Ai\AutonomousEvolution\HardCaseBench\AtlasLoopHardCaseDatasetRegistry;
use App\Services\Ai\AutonomousEvolution\HardCaseBench\AtlasLoopHardCaseHistoricalFailureMiner;
use App\Services\Ai\AutonomousEvolution\HardCaseBench\HardCaseCandidate;
use Illuminate\Console\Command;

/**
 * Arms the dormant {@see AtlasLoopHardCaseHistoricalFailureMiner::mineCandidates()} at the operator surface:
 * mines historical Loop failures (give_back/cancellation/judge_reject/timeout) from the injected event source
 * and emits the deduped hard-case candidates as deterministic facts.
 *
 * ADVISORY only: the miner NEVER writes to the registry (auto-registering would let the loop quietly drop cases
 * it can't pass), and this command performs no queue or git mutation — it only reports proposals the operator
 * may later promote.
 */
final class AtlasLoopHardCaseHistoricalMineCommand extends Command
{
    protected $signature = 'atlas:loop:hard-case-historical-mine {--since-days=90} {--json}';

    protected $description = 'Read-only mine of historical Loop failures into hard-case candidates (never registers them).';

    public function handle(): int
    {
        $sinceDays = max(1, (int) $this->option('since-days'));

        $candidates = $this->miner()->mineCandidates($sinceDays);

        $facts = [
            'schema' => 'atlas.loop.hard_case_historical_mine.v1',
            'since_days' => $sinceDays,
            'candidate_count' => count($candidates),
            'candidates' => array_map(static fn (HardCaseCandidate $c): array => $c->toRegistryShape(), $candidates),
        ];

        if ($this->option('json')) {
            $this->line((string) json_encode($facts, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR));
        } else {
            $this->line('candidate_count: '.$facts['candidate_count']);
            foreach ($candidates as $c) {
                $this->line($c->caseId.'  '.$c->source.'  '.$c->scopeRoot);
            }
        }

        return self::SUCCESS;
    }

    private function miner(): AtlasLoopHardCaseHistoricalFailureMiner
    {
        if (app()->bound(AtlasLoopHardCaseHistoricalFailureMiner::class)) {
            return app(AtlasLoopHardCaseHistoricalFailureMiner::class);
        }

        // The miner's event source is a `callable` (not autowireable). An optional bound source feeds the
        // historical ledger; absent one, mining is an empty no-op (advisory surface, never fabricates events).
        $eventsKey = 'atlas.loop.hard_case.events_source';
        $events = app()->bound($eventsKey) ? app($eventsKey) : static fn (int $sinceDays): iterable => [];

        return new AtlasLoopHardCaseHistoricalFailureMiner(app(AtlasLoopHardCaseDatasetRegistry::class), $events);
    }
}
