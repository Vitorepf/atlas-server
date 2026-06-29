<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Services\Ai\AutonomousEvolution\Discovery\AtlasLoopBacklogIntentSource;
use Illuminate\Console\Command;

/**
 * Arms the dormant {@see AtlasLoopBacklogIntentSource::candidates()} at the operator surface: emits the
 * backlog-derived intent candidates (each a named, addressable objective from the curated manifest / failure
 * corpus) as deterministic facts — the material-fuel feed the discovery ranker promotes when its flag is ON.
 *
 * Read-only + fail-open: a missing/unreadable manifest yields an empty candidate list; the command never
 * proposes, ranks, or mutates anything.
 */
final class AtlasLoopBacklogIntentCommand extends Command
{
    protected $signature = 'atlas:loop:backlog-intent {--repo-root=} {--limit=12} {--json}';

    protected $description = 'Read-only backlog-derived intent candidates (named objectives) for the discovery feed.';

    public function handle(): int
    {
        $repoRoot = trim((string) $this->option('repo-root'));
        if ($repoRoot === '') {
            $repoRoot = base_path();
        }
        $limit = max(1, (int) $this->option('limit'));

        $candidates = app(AtlasLoopBacklogIntentSource::class)->candidates($repoRoot, $limit);

        $facts = [
            'schema' => AtlasLoopBacklogIntentSource::SCHEMA,
            'limit' => $limit,
            'candidate_count' => count($candidates),
            'candidates' => $candidates,
        ];

        if ($this->option('json')) {
            $this->line((string) json_encode($facts, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR));
        } else {
            $this->line('candidate_count: '.$facts['candidate_count']);
            foreach ($candidates as $c) {
                $this->line($c['priority'].'  '.$c['path'].'  ['.$c['source'].']  '.$c['objective']);
            }
        }

        return self::SUCCESS;
    }
}
