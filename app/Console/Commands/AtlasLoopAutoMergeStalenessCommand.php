<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Services\Ai\AutonomousEvolution\Merge\AtlasLoopAutoMergeStalenessRefuser;
use Illuminate\Console\Command;

/**
 * Arms the dormant {@see AtlasLoopAutoMergeStalenessRefuser::check()} at the operator surface: emits whether a
 * base SHA is too stale to auto-merge — the commits-behind-main count is a real FACT (`git rev-list --count
 * base..main`), refused when it exceeds the configured ceiling. Fail-closed on unresolvable refs / git failure.
 *
 * Read-only: it computes the staleness verdict and reports; it never merges, fetches, or mutates git.
 */
final class AtlasLoopAutoMergeStalenessCommand extends Command
{
    protected $signature = 'atlas:loop:auto-merge-staleness {--base=} {--repo-root=} {--json}';

    protected $description = 'Read-only auto-merge staleness check: is the base SHA too far behind main?';

    public function handle(): int
    {
        $base = trim((string) $this->option('base'));
        if ($base === '') {
            $this->line((string) json_encode([
                'outcome' => 'refused',
                'reason' => 'usage_error',
                'message' => 'auto-merge-staleness requires --base=<sha>',
            ], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR));

            return self::FAILURE;
        }
        $repoRoot = trim((string) $this->option('repo-root'));
        if ($repoRoot === '') {
            $repoRoot = base_path();
        }

        $verdict = app(AtlasLoopAutoMergeStalenessRefuser::class)->check($base, $repoRoot);

        if ($this->option('json')) {
            $this->line((string) json_encode($verdict, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR));
        } else {
            $this->line('allow: '.($verdict['allow'] ? 'yes' : 'no').'  commits_behind: '.$verdict['commits_behind'].'/'.$verdict['max_commits_behind'].'  reason: '.($verdict['reason'] ?? '-'));
        }

        return self::SUCCESS;
    }
}
