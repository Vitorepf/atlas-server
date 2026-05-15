<?php

declare(strict_types=1);

namespace App\Services\Ai\Programming\ForgeRivals\Corpus;

use App\Services\Ai\Programming\WorkspaceHygieneService;
use FilesystemIterator;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use RuntimeException;

/**
 * Atlas Forge Rivals · Corpus Fixture Runner (v1).
 *
 * Prepares an isolated working copy of a corpus case's seed directory into a
 * per-run, per-case sandbox under
 *   <runs_root>/<run_id>/corpus/<case_id>/workspace
 * so that arms can attempt the task without ever mutating the source repo
 * or the battery's primary worktrees.
 *
 * Safety rules (never relaxed):
 *   - Seed dir MUST exist before prepare is called — otherwise we honestly
 *     blocker the case (no implicit creation).
 *   - allowed_files_scope is the only writeable surface; any other path is
 *     a scope_violation hard blocker.
 *   - forbidden_files_scope is a deny-first guard even if the path matches
 *     allowed_files_scope (defence in depth).
 *   - Workspace hygiene refuses tracked python bytecode in the source repo
 *     before any case is prepared (delegates to existing checker).
 *   - cleanup() removes only the case-scoped workspace; evidence digest is
 *     preserved separately by the battery layer.
 *
 * This service never calls a provider, never touches Voice or Cartografia
 * paths, and never unlocks `external_rivals_certification`.
 *
 * Schema: atlas.forge.rivals.provider_arena_corpus_fixture.v1
 */
final class AtlasForgeRivalsCorpusFixtureRunnerService
{
    public const SCHEMA_VERSION = 'atlas.forge.rivals.provider_arena_corpus_fixture.v1';

    public const RUNS_ROOT_DEFAULT_ENV = 'ATLAS_RIVALS_CORPUS_RUNS_ROOT';

    public function __construct(
        private readonly AtlasForgeRivalsProviderArenaCorpusService $corpus,
        private readonly WorkspaceHygieneService $hygiene,
        private readonly ?string $repoRootOverride = null,
        private readonly ?string $runsRootOverride = null,
    ) {}

    /**
     * Prepare the workspace for a single case. Returns the workspace path or
     * a structured blocker payload — never throws on caller-facing errors.
     *
     * @return array<string,mixed>
     */
    public function prepare(string $runId, string $caseId): array
    {
        $runId = trim($runId);
        $caseId = trim($caseId);
        $blockers = [];

        if ($runId === '') {
            $blockers[] = 'run_id_required';
        }
        if ($caseId === '') {
            $blockers[] = 'case_id_required';
        }

        if ($blockers !== []) {
            return $this->terminal($blockers, runId: $runId, caseId: $caseId);
        }

        try {
            $case = $this->corpus->case($caseId);
        } catch (\InvalidArgumentException $e) {
            return $this->terminal([$e->getMessage()], runId: $runId, caseId: $caseId);
        }

        $manifestErrors = $this->corpus->validateManifest($case);
        if ($manifestErrors !== []) {
            return $this->terminal(
                array_map(static fn (string $err): string => 'invalid_manifest:'.$err, $manifestErrors),
                runId: $runId,
                caseId: $caseId,
            );
        }

        $repoRoot = $this->repoRoot();
        $seedRelative = (string) ($case['setup_fixture']['seed_dir'] ?? '');
        $seedAbs = $this->joinPath($repoRoot, $seedRelative);
        if (! is_dir($seedAbs)) {
            return $this->terminal(['seed_dir_missing:'.$seedRelative], runId: $runId, caseId: $caseId);
        }

        $bytecodeBlocker = $this->detectTrackedPythonBytecode($repoRoot);
        if ($bytecodeBlocker !== null) {
            return $this->terminal([$bytecodeBlocker], runId: $runId, caseId: $caseId);
        }

        $workspace = $this->workspacePath($runId, $caseId);
        try {
            $this->resetDirectory($workspace);
            $this->copyTree($seedAbs, $workspace);
        } catch (RuntimeException $e) {
            return $this->terminal(['workspace_preparation_failed:'.$e->getMessage()], runId: $runId, caseId: $caseId);
        }

        $copied = $this->relativeListing($workspace);

        return [
            'status' => 'ok',
            'schema_version' => self::SCHEMA_VERSION,
            'run_id' => $runId,
            'case_id' => $caseId,
            'workspace' => $workspace,
            'seed_dir' => $seedRelative,
            'seed_abs' => $seedAbs,
            'files_copied' => $copied,
            'allowed_files_scope' => array_values(array_map(
                static fn ($g): string => (string) $g,
                (array) ($case['allowed_files_scope'] ?? []),
            )),
            'forbidden_files_scope' => array_values(array_map(
                static fn ($g): string => (string) $g,
                (array) ($case['forbidden_files_scope'] ?? []),
            )),
            'external_provider_call' => false,
            'separated_from_external_rivals_certification' => true,
        ];
    }

    /**
     * Validate that every candidate path under `pathsTouched` lies inside the
     * allowed_files_scope globs AND outside the forbidden_files_scope globs
     * of the case. Returns a list of scope_violation blockers (empty list ⇒
     * scope is clean).
     *
     * @param  list<string>  $pathsTouched
     * @return list<string>
     */
    public function checkScope(string $caseId, array $pathsTouched): array
    {
        $case = $this->corpus->case($caseId);
        $allowed = array_values(array_map(
            static fn ($g): string => (string) $g,
            (array) ($case['allowed_files_scope'] ?? []),
        ));
        $forbidden = array_values(array_map(
            static fn ($g): string => (string) $g,
            (array) ($case['forbidden_files_scope'] ?? []),
        ));

        $violations = [];
        foreach ($pathsTouched as $path) {
            $p = trim((string) $path);
            if ($p === '') {
                continue;
            }
            if ($this->matchesAny($p, $forbidden)) {
                $violations[] = 'forbidden_path_touched:'.$p;

                continue;
            }
            if (! $this->matchesAny($p, $allowed)) {
                $violations[] = 'scope_violation:'.$p;
            }
        }

        return $violations;
    }

    /**
     * Tear down the per-case workspace; evidence (preserved by battery) is
     * untouched because it lives outside this directory tree.
     */
    public function cleanup(string $runId, string $caseId): array
    {
        $workspace = $this->workspacePath(trim($runId), trim($caseId));
        if (! is_dir($workspace)) {
            return [
                'status' => 'ok',
                'schema_version' => self::SCHEMA_VERSION,
                'run_id' => $runId,
                'case_id' => $caseId,
                'workspace' => $workspace,
                'removed' => false,
                'note' => 'workspace did not exist; nothing to clean',
            ];
        }

        $this->removeTree($workspace);

        return [
            'status' => 'ok',
            'schema_version' => self::SCHEMA_VERSION,
            'run_id' => $runId,
            'case_id' => $caseId,
            'workspace' => $workspace,
            'removed' => true,
        ];
    }

    /**
     * @param  list<string>  $blockers
     * @return array<string,mixed>
     */
    private function terminal(array $blockers, string $runId, string $caseId): array
    {
        return [
            'status' => 'blocked',
            'schema_version' => self::SCHEMA_VERSION,
            'run_id' => $runId,
            'case_id' => $caseId,
            'workspace' => null,
            'blockers' => array_values(array_unique(array_map(static fn ($b): string => (string) $b, $blockers))),
            'external_provider_call' => false,
            'separated_from_external_rivals_certification' => true,
        ];
    }

    private function workspacePath(string $runId, string $caseId): string
    {
        return $this->runsRoot().'/'.$runId.'/corpus/'.$caseId.'/workspace';
    }

    private function runsRoot(): string
    {
        if ($this->runsRootOverride !== null && $this->runsRootOverride !== '') {
            return rtrim($this->runsRootOverride, '/');
        }
        $env = getenv(self::RUNS_ROOT_DEFAULT_ENV);
        if (is_string($env) && trim($env) !== '') {
            return rtrim(trim($env), '/');
        }

        return rtrim(sys_get_temp_dir(), '/').'/atlas-rivals-corpus-runs';
    }

    private function repoRoot(): string
    {
        if ($this->repoRootOverride !== null && $this->repoRootOverride !== '') {
            return rtrim($this->repoRootOverride, '/');
        }
        if (function_exists('base_path')) {
            return rtrim(base_path(), '/');
        }

        return rtrim(dirname(__DIR__, 6), '/');
    }

    private function joinPath(string $base, string $relative): string
    {
        return rtrim($base, '/').'/'.ltrim($relative, '/');
    }

    /**
     * Delegates to the canonical WorkspaceHygieneService which uses
     * `git ls-files` against PYTHON_BYTECODE_PATTERNS — the same check
     * every other ForgeRivals stage uses, so the corpus pipeline cannot
     * disagree with run-real / preflight on what counts as tracked
     * bytecode.
     */
    private function detectTrackedPythonBytecode(string $repoRoot): ?string
    {
        $snapshot = $this->hygiene->trackedPythonBytecode($repoRoot);
        $count = (int) ($snapshot['tracked_count'] ?? 0);
        if ($count === 0) {
            return null;
        }

        $sample = (array) ($snapshot['tracked_sample'] ?? []);
        $first = $sample !== [] ? (string) reset($sample) : 'unknown';

        return 'blocked_tracked_python_bytecode:'.$first.' (count='.$count.')';
    }

    private function resetDirectory(string $dir): void
    {
        if (is_dir($dir)) {
            $this->removeTree($dir);
        }
        if (! @mkdir($dir, 0o755, true) && ! is_dir($dir)) {
            throw new RuntimeException("cannot_create_workspace:{$dir}");
        }
    }

    private function removeTree(string $dir): void
    {
        if (! is_dir($dir)) {
            return;
        }
        $iter = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($dir, FilesystemIterator::SKIP_DOTS),
            RecursiveIteratorIterator::CHILD_FIRST,
        );
        foreach ($iter as $file) {
            $path = (string) $file;
            if (is_dir($path)) {
                @rmdir($path);
            } else {
                @unlink($path);
            }
        }
        @rmdir($dir);
    }

    private function copyTree(string $source, string $dest): void
    {
        if (! is_dir($source)) {
            throw new RuntimeException("seed_not_a_directory:{$source}");
        }
        $iter = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($source, FilesystemIterator::SKIP_DOTS),
            RecursiveIteratorIterator::SELF_FIRST,
        );
        foreach ($iter as $file) {
            $srcPath = (string) $file;
            $relPath = ltrim(substr($srcPath, strlen($source)), '/');
            $destPath = $dest.'/'.$relPath;
            if (is_dir($srcPath)) {
                if (! is_dir($destPath) && ! @mkdir($destPath, 0o755, true) && ! is_dir($destPath)) {
                    throw new RuntimeException("cannot_create_subdir:{$destPath}");
                }

                continue;
            }
            $parent = dirname($destPath);
            if (! is_dir($parent) && ! @mkdir($parent, 0o755, true) && ! is_dir($parent)) {
                throw new RuntimeException("cannot_create_parent:{$parent}");
            }
            if (! @copy($srcPath, $destPath)) {
                throw new RuntimeException("cannot_copy:{$srcPath}");
            }
        }
    }

    /**
     * @return list<string>
     */
    private function relativeListing(string $root): array
    {
        if (! is_dir($root)) {
            return [];
        }
        $iter = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($root, FilesystemIterator::SKIP_DOTS),
            RecursiveIteratorIterator::SELF_FIRST,
        );
        $out = [];
        foreach ($iter as $file) {
            if (is_dir((string) $file)) {
                continue;
            }
            $out[] = ltrim(substr((string) $file, strlen($root)), '/');
        }
        sort($out);

        return $out;
    }

    /**
     * @param  list<string>  $globs
     */
    private function matchesAny(string $path, array $globs): bool
    {
        $normalized = ltrim($path, '/');
        foreach ($globs as $glob) {
            $pattern = $this->globToRegex(trim($glob));
            if ($pattern !== '' && preg_match($pattern, $normalized) === 1) {
                return true;
            }
        }

        return false;
    }

    private function globToRegex(string $glob): string
    {
        if ($glob === '') {
            return '';
        }
        $regex = '';
        $len = strlen($glob);
        for ($i = 0; $i < $len; $i++) {
            $ch = $glob[$i];
            if ($ch === '*' && isset($glob[$i + 1]) && $glob[$i + 1] === '*') {
                $regex .= '.*';
                $i++;
                if (isset($glob[$i + 1]) && $glob[$i + 1] === '/') {
                    $i++;
                }

                continue;
            }
            if ($ch === '*') {
                $regex .= '[^/]*';

                continue;
            }
            if ($ch === '?') {
                $regex .= '[^/]';

                continue;
            }
            if (in_array($ch, ['.', '+', '(', ')', '|', '^', '$', '[', ']', '{', '}', '\\'], true)) {
                $regex .= '\\'.$ch;

                continue;
            }
            $regex .= $ch;
        }

        return '#^'.$regex.'$#';
    }
}
