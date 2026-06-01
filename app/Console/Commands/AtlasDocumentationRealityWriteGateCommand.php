<?php

namespace App\Console\Commands;

use App\Services\Engineering\AtlasDocumentationRealityWriteGateService;
use App\Services\Semantic\CanonicalDocsFrontmatterParser;
use Illuminate\Console\Command;
use Symfony\Component\Process\Process;
use Throwable;

/**
 * Write-bound entrypoint for the ADRS L0 enforcement gate. Resolves the touched
 * paths (staged git diff or an explicit --paths list), the partially-staged set
 * (worktree != index), and each touched canonical doc's frontmatter, then hands
 * them to the pure {@see AtlasDocumentationRealityWriteGateService} decider and
 * prints the verdict.
 *
 * Fail-CLOSED: with --strict it exits non-zero on BOTH a blocked AND a
 * needs_review decision, so a degraded collaborator or an untrustworthy split
 * view stops the commit rather than sailing through. Auto-discovered from
 * app/Console/Commands (same as atlas:documentation-reality).
 *
 * Staged resolution uses --name-status with rename/copy detection so that
 * RENAMES and DELETES of canonical docs are NOT invisible (a removed required
 * doc is itself a NEW docs-health blocker on its old path).
 */
class AtlasDocumentationRealityWriteGateCommand extends Command
{
    protected $signature = 'atlas:documentation-reality-write-gate
        {--staged : Resolve touched paths from the staged git diff}
        {--paths= : Comma-separated paths to evaluate}
        {--json : Emit canonical JSON}
        {--strict : Exit non-zero when the decision is blocked or needs_review}';

    protected $description = 'Write-bound ADRS gate: one fail-closed verdict over a proposed change at the commit boundary.';

    private const CANONICAL_DOC_PREFIX = 'docs/engineering-knowledge-base/';

    public function handle(AtlasDocumentationRealityWriteGateService $gate, CanonicalDocsFrontmatterParser $frontmatter): int
    {
        $staged = (bool) $this->option('staged');
        $paths = $this->resolveTouchedPaths();
        $partiallyStaged = $staged ? $this->partiallyStagedPaths() : [];

        $verdict = $gate->decide([
            'touched_paths' => $paths,
            'is_mutating' => true,
            'touched_frontmatter' => $this->collectFrontmatter($paths, $frontmatter),
            'partially_staged_paths' => $partiallyStaged,
        ]);

        if ((bool) $this->option('json')) {
            $this->line(json_encode($verdict, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));

            return $this->exitCode($verdict);
        }

        $this->renderTable($verdict, $paths);

        return $this->exitCode($verdict);
    }

    /**
     * @return array<int,string>
     */
    private function resolveTouchedPaths(): array
    {
        if ((bool) $this->option('staged')) {
            return $this->stagedPaths();
        }

        $raw = (string) ($this->option('paths') ?? '');
        if (trim($raw) === '') {
            return [];
        }

        return array_values(array_filter(
            array_map('trim', explode(',', $raw)),
            static fn (string $p): bool => $p !== '',
        ));
    }

    /**
     * Staged paths from the repo root, with rename (R) and copy (C) detection so
     * BOTH sides of a rename are seen, and DELETES (D) are included — a renamed or
     * removed required canonical doc is a NEW docs-health blocker on its old path
     * and must not be invisible to the gate.
     *
     * @return array<int,string>
     */
    private function stagedPaths(): array
    {
        $process = new Process(
            ['git', 'diff', '--cached', '--name-status', '-M', '-C'],
            base_path(),
        );
        $process->run();

        if (! $process->isSuccessful()) {
            $this->warn('Could not read the staged git diff; treating the change set as empty.');

            return [];
        }

        $paths = [];
        foreach (preg_split('/\R/', $process->getOutput()) ?: [] as $line) {
            $line = trim($line);
            if ($line === '') {
                continue;
            }
            $parts = preg_split('/\t/', $line) ?: [];
            $status = (string) ($parts[0] ?? '');
            $code = $status[0] ?? '';

            if ($code === 'R' || $code === 'C') {
                // "R<score>\t<old>\t<new>" — both sides matter.
                if (isset($parts[1])) {
                    $paths[] = trim($parts[1]);
                }
                if (isset($parts[2])) {
                    $paths[] = trim($parts[2]);
                }

                continue;
            }

            // A / M / D / T / U: single path in field 1 (deletes included).
            if (isset($parts[1])) {
                $paths[] = trim($parts[1]);
            }
        }

        return array_values(array_filter(array_unique($paths), static fn (string $p): bool => $p !== ''));
    }

    /**
     * Files with UNSTAGED worktree changes (index != worktree). A canonical doc
     * here is being committed from the index while the worktree differs, so the
     * worktree-reading analyzers cannot be trusted for it (TOCTOU).
     *
     * @return array<int,string>
     */
    private function partiallyStagedPaths(): array
    {
        $process = new Process(['git', 'diff', '--name-only'], base_path());
        $process->run();

        if (! $process->isSuccessful()) {
            return [];
        }

        return array_values(array_filter(
            array_map('trim', preg_split('/\R/', $process->getOutput()) ?: []),
            static fn (string $p): bool => $p !== '',
        ));
    }

    /**
     * Parse each touched canonical doc's frontmatter (implementation_state +
     * evidence_refs) so the decider can detect a naked over-claim. Deleted /
     * renamed-away docs (absent on disk) are skipped — docs-health covers absence.
     *
     * @param  array<int,string>  $paths
     * @return array<string,array{implementation_state:string, evidence_refs:array<int,mixed>}>
     */
    private function collectFrontmatter(array $paths, CanonicalDocsFrontmatterParser $parser): array
    {
        $map = [];
        foreach ($paths as $path) {
            $rel = ltrim(str_replace('\\', '/', (string) $path), '/');
            if (stripos($rel, self::CANONICAL_DOC_PREFIX) === false || ! str_ends_with(strtolower($rel), '.md')) {
                continue;
            }
            $abs = base_path($rel);
            if (! is_file($abs)) {
                continue;
            }
            try {
                $parsed = $parser->parse((string) file_get_contents($abs));
            } catch (Throwable) {
                continue;
            }
            $fm = is_array($parsed['frontmatter'] ?? null) ? $parsed['frontmatter'] : [];
            $map[$rel] = [
                'implementation_state' => (string) ($fm['implementation_state'] ?? ''),
                'evidence_refs' => is_array($fm['evidence_refs'] ?? null) ? array_values($fm['evidence_refs']) : [],
            ];
        }

        return $map;
    }

    /**
     * @param  array<string,mixed>  $verdict
     * @param  array<int,string>  $paths
     */
    private function renderTable(array $verdict, array $paths): void
    {
        $this->components->twoColumnDetail('ADRS Write Gate', (string) ($verdict['decision'] ?? ''));
        $this->components->twoColumnDetail('Reason', (string) ($verdict['reason'] ?? ''));
        $this->components->twoColumnDetail('Touched paths', (string) count($paths));
        $this->components->twoColumnDetail('Canonical docs touched', (string) ($verdict['touched_doc_count'] ?? 0));

        $newBlockers = (array) ($verdict['new_docs_health_blockers'] ?? []);
        $driftBlockers = (array) ($verdict['drift_blockers'] ?? []);

        if ($newBlockers !== []) {
            $this->newLine();
            $this->warn('New docs-health blockers on touched docs:');
            foreach ($newBlockers as $blocker) {
                $this->line('  - '.(string) $blocker);
            }
        }

        if ($driftBlockers !== []) {
            $this->newLine();
            $this->warn('Over-claim on touched docs:');
            foreach ($driftBlockers as $row) {
                $this->line(sprintf(
                    '  - %s (claimed=%s computed=%s via %s)',
                    (string) ($row['owner_doc'] ?? ''),
                    (string) ($row['claimed_state'] ?? ''),
                    (string) ($row['computed_state'] ?? ''),
                    (string) ($row['source'] ?? ''),
                ));
            }
        }
    }

    /**
     * Fail CLOSED: with --strict, BOTH an explicit block AND a needs_review
     * (internal error / untrustworthy split view) stop the commit. Only a clean
     * allow passes.
     *
     * @param  array<string,mixed>  $verdict
     */
    private function exitCode(array $verdict): int
    {
        if (! (bool) $this->option('strict')) {
            return self::SUCCESS;
        }

        return in_array($verdict['decision'] ?? null, [
            AtlasDocumentationRealityWriteGateService::DECISION_BLOCKED,
            AtlasDocumentationRealityWriteGateService::DECISION_NEEDS_REVIEW,
        ], true) ? self::FAILURE : self::SUCCESS;
    }
}
