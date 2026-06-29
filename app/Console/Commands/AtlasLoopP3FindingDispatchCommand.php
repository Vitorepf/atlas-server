<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Services\Ai\AutonomousEvolution\Verify\AtlasP3FindingDispatcher;
use Illuminate\Console\Command;

/**
 * Arms the dormant {@see AtlasP3FindingDispatcher::scan()} at the operator surface: scans the repo root and
 * emits the dispatched P3 findings — auto-loop (dead code / doc-structure) and flags (fake-implemented +
 * signal modes) plus the summary — as deterministic facts. Read-only — it only analyses files; it dispatches
 * nothing. The scope flags (repo-root / code-roots / docs-roots / max-files) are optional, defaulting to a
 * full repo scan.
 */
final class AtlasLoopP3FindingDispatchCommand extends Command
{
    protected $signature = 'atlas:loop:p3-finding-dispatch {--repo-root=} {--code-roots=} {--docs-roots=} {--max-files=} {--json}';

    protected $description = 'Read-only P3 finding dispatch scan (dead code / doc structure / signal flags) over the repo.';

    public function handle(AtlasP3FindingDispatcher $dispatcher): int
    {
        $repoRoot = trim((string) $this->option('repo-root'));
        if ($repoRoot === '') {
            $repoRoot = base_path();
        }

        $options = [];
        foreach (['code-roots' => 'code_roots', 'docs-roots' => 'docs_roots'] as $opt => $key) {
            $value = $this->option($opt);
            if ($value !== null) {
                $options[$key] = array_values(array_filter(array_map('trim', explode(',', (string) $value)), static fn (string $s): bool => $s !== ''));
            }
        }
        $maxFiles = $this->option('max-files');
        if ($maxFiles !== null && trim((string) $maxFiles) !== '') {
            $options['max_files'] = max(1, (int) $maxFiles);
            $options['signal_max_files'] = max(1, (int) $maxFiles);
        }

        $this->line((string) json_encode(
            $dispatcher->scan($repoRoot, $options),
            JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR,
        ));

        return self::SUCCESS;
    }
}
