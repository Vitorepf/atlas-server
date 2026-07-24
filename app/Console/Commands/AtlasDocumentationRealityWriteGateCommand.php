<?php

namespace App\Console\Commands;

use App\Services\Engineering\AtlasDocumentationRealityWriteGateService;
use App\Services\Semantic\CanonicalDocsFrontmatterParser;
use Illuminate\Console\Command;
use Symfony\Component\Process\Process;
use Throwable;
use App\Console\Concerns\EmitsCanonicalJson;

/**
 * Write-bound entrypoint for the ADRS L0 enforcement gate. Resolves the touched
 * paths (staged git diff or an explicit --paths list), the untrustworthy set
 * (worktree != index: unstaged edits, skip-worktree/assume-unchanged, or a staged
 * delete whose worktree copy survives), and each touched canonical doc's raw
 * frontmatter, then hands them to the pure
 * {@see AtlasDocumentationRealityWriteGateService} decider and prints the verdict.
 *
 * Fail-CLOSED: with --strict it exits non-zero on BOTH a blocked AND a
 * needs_review decision, so a degraded collaborator or an untrustworthy split
 * view stops the commit rather than sailing through.
 *
 * Git is read with `-z` (NUL-delimited, no core.quotePath C-quoting) so that
 * unicode / whitespace canonical doc paths are not silently dropped, and with
 * `--name-status -M -C` so RENAMES and DELETES are not invisible.
 *
 * NOTE: the analyzers (docs-health, the truth index) read the WORKTREE, while a
 * commit writes the INDEX. Full commit-boundary soundness comes from the
 * pre-commit hook running `git stash --keep-index` so the worktree IS the staged
 * content during the gate. Ad-hoc invocations here are best-effort: this command
 * additionally refuses (needs_review) any touched doc whose worktree is known to
 * diverge from the index, but cannot see every divergence the stash closes.
 */
class AtlasDocumentationRealityWriteGateCommand extends Command
{
    use EmitsCanonicalJson;

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
        $partiallyStaged = $staged ? $this->untrustworthyWorktreePaths() : [];

        $verdict = $gate->decide([
            'touched_paths' => $paths,
            'is_mutating' => true,
            'touched_frontmatter' => $this->collectFrontmatter($paths, $frontmatter),
            'partially_staged_paths' => $partiallyStaged,
        ]);

        if ((bool) $this->option('json')) {
            $this->line($this->encode($verdict));

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
     * Staged paths from the repo root, NUL-delimited (no C-quoting) with rename
     * (R) and copy (C) detection so BOTH sides of a rename are seen and DELETES
     * (D) are included.
     *
     * @return array<int,string>
     */
    private function stagedPaths(): array
    {
        $out = $this->git(['diff', '--cached', '--name-status', '-M', '-C', '-z']);
        if ($out === null) {
            $this->warn('Could not read the staged git diff; treating the change set as empty.');

            return [];
        }

        $paths = [];
        foreach ($this->parseNameStatusZ($out) as [$status, $pathList]) {
            foreach ($pathList as $p) {
                $paths[] = $p;
            }
        }

        return array_values(array_filter(array_unique($paths), static fn (string $p): bool => trim($p) !== ''));
    }

    /**
     * Paths whose worktree is NOT a faithful copy of the index, so the worktree-
     * reading analyzers cannot be trusted for them: (1) unstaged tracked edits,
     * (2) skip-worktree / assume-unchanged (which silence the unstaged diff), and
     * (3) a staged DELETE whose worktree file still exists (git rm --cached). The
     * stashing hook removes (1)-(3) at the commit boundary; this is the ad-hoc net.
     *
     * @return array<int,string>
     */
    private function untrustworthyWorktreePaths(): array
    {
        $paths = [];

        // (1) Unstaged worktree edits to tracked files.
        $unstaged = $this->git(['diff', '--name-only', '-z']);
        if ($unstaged !== null) {
            foreach (explode("\0", $unstaged) as $p) {
                if (trim($p) !== '') {
                    $paths[] = $p;
                }
            }
        }

        // (2) skip-worktree ('S') / assume-unchanged (lowercase tag) files.
        $lsv = $this->git(['ls-files', '-v', '-z']);
        if ($lsv !== null) {
            foreach (explode("\0", $lsv) as $entry) {
                if ($entry === '') {
                    continue;
                }
                $tag = $entry[0] ?? '';
                $path = substr($entry, 2); // "<tag> <path>"
                if ($path !== '' && ($tag === 'S' || ctype_lower($tag))) {
                    $paths[] = $path;
                }
            }
        }

        // (3) Staged deletes whose worktree copy survives (git rm --cached).
        $out = $this->git(['diff', '--cached', '--name-status', '-M', '-C', '-z']);
        if ($out !== null) {
            foreach ($this->parseNameStatusZ($out) as [$status, $pathList]) {
                if (($status[0] ?? '') !== 'D') {
                    continue;
                }
                foreach ($pathList as $p) {
                    if ($p !== '' && is_file(base_path($p))) {
                        $paths[] = $p;
                    }
                }
            }
        }

        return array_values(array_unique($paths));
    }

    /**
     * Parse `git ... --name-status -z` output into [status, [paths]] records.
     * Fields are NUL-delimited: a status token, then 1 path (A/M/D/T/U) or 2 (R/C:
     * old, new). -z means unicode/space/tab paths arrive verbatim (no quoting).
     *
     * @return array<int,array{0:string,1:array<int,string>}>
     */
    private function parseNameStatusZ(string $out): array
    {
        $tokens = explode("\0", $out);
        $records = [];
        $i = 0;
        $n = count($tokens);

        while ($i < $n) {
            $status = trim($tokens[$i]);
            if ($status === '') {
                $i++;

                continue;
            }
            $code = $status[0] ?? '';
            if ($code === 'R' || $code === 'C') {
                $old = $tokens[$i + 1] ?? '';
                $new = $tokens[$i + 2] ?? '';
                $records[] = [$status, array_values(array_filter([$old, $new], static fn (string $p): bool => $p !== ''))];
                $i += 3;

                continue;
            }
            $path = $tokens[$i + 1] ?? '';
            $records[] = [$status, $path === '' ? [] : [$path]];
            $i += 2;
        }

        return $records;
    }

    /**
     * Run a git command from the repo root, returning stdout or null on failure.
     *
     * @param  array<int,string>  $args
     */
    private function git(array $args): ?string
    {
        $process = new Process(array_merge(['git'], $args), base_path());
        $process->run();

        return $process->isSuccessful() ? $process->getOutput() : null;
    }

    /**
     * Parse each touched canonical doc's raw frontmatter (implementation_state +
     * evidence_refs AS AUTHORED — the decider normalizes) so the gate can detect a
     * naked or junk-evidence over-claim. Deleted / renamed-away docs (absent on
     * disk) are skipped — docs-health covers absence.
     *
     * @param  array<int,string>  $paths
     * @return array<string,array{implementation_state:string, evidence_refs:mixed}>
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
                'evidence_refs' => $fm['evidence_refs'] ?? null,
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
