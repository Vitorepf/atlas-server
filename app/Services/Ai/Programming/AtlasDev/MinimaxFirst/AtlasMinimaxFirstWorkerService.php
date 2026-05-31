<?php

declare(strict_types=1);

namespace App\Services\Ai\Programming\AtlasDev\MinimaxFirst;

use App\Services\Ai\Programming\AtlasMinimaxM27CliRuntimeExecutor;
use Symfony\Component\Process\Process;

/**
 * Orchestrates the Codex→MiniMax implementation cycle.
 *
 * Flow: pre-gate → Codex plan (optional, graceful fallback) →
 *       compile minimal context → MiniMax invoke → extract code →
 *       write files (strict boundary) → PHP syntax check →
 *       run validation → (repair loop if needed, max 2×) →
 *       return atlas.dev.senior_engineer_loop_execution.v1 output.
 *
 * This is the drop-in replacement for the senior loop when provider=minimax_m27_cli.
 * Its output format is compatible with what Ap786OwnerFlowExecutor and the
 * AP-790 runner expect from a senior-loop run.
 */
final class AtlasMinimaxFirstWorkerService
{
    public const SCHEMA = 'atlas.dev.senior_engineer_loop_execution.v1';

    public const PROVIDER_DIFF_QUALITY_BLOCKER = 'provider_diff_quality_gate_failed';

    private const DIFF_QUALITY_LARGE_PRODUCT_LINES_WITHOUT_TEST = 220;

    private const DIFF_QUALITY_PRODUCT_DELETIONS_WITHOUT_TEST = 80;

    private const DIFF_QUALITY_SINGLE_FILE_DELETIONS_WITHOUT_TEST = 80;

    private const DIFF_QUALITY_DELETION_RATIO_FLOOR = 3.0;

    private const DIFF_QUALITY_TEST_DELETIONS = 80;

    private const DIFF_QUALITY_TEST_DELETION_RATIO_FLOOR = 2.0;

    public function __construct(
        private readonly AtlasMinimaxM27CliRuntimeExecutor $minimaxExecutor,
        private readonly AtlasCodexPlannerService $codexPlanner,
        private readonly AtlasMinimaxContextCompilerService $contextCompiler,
    ) {}

    /**
     * Run one implementation cycle.
     *
     * @param  array<string,mixed>  $input  {finding, allowed_files, validation_commands, worktree_path, repo_root, max_repairs}
     * @return array<string,mixed>          atlas.dev.senior_engineer_loop_execution.v1 shape
     */
    public function run(array $input): array
    {
        $finding        = (array) ($input['finding'] ?? []);
        $allowedFiles   = array_values(array_filter((array) ($input['allowed_files'] ?? []), 'is_string'));
        $validationCmds = array_values(array_filter((array) ($input['validation_commands'] ?? []), 'is_string'));
        $worktree       = (string) ($input['worktree_path'] ?? '');
        $repoRoot       = (string) ($input['repo_root'] ?? $worktree);
        $maxRepairs     = max(0, min(3, (int) ($input['max_repairs'] ?? 2)));

        // Pre-gate: basic sanity before touching the provider.
        $gate = $this->preGate($finding, $worktree);
        if (! $gate['ok']) {
            return $this->blocked($gate['blocker'] ?? 'pre_gate_failed', 0);
        }

        // Phase 1: Codex planning (graceful fallback when unavailable).
        $codexPlan = [];
        if ($this->codexPlanner->configured()['available']) {
            $codexPlan = (array) ($this->codexPlanner->plan($finding, $allowedFiles, $validationCmds, $repoRoot) ?? []);
        }

        // Phase 2: Compile minimal context.
        $compiled = $this->contextCompiler->compile($finding, $allowedFiles, $repoRoot, $codexPlan);
        $manifest = $compiled['manifest'];

        // Enrich the manifest with the execution-scope keys AtlasMinimaxM27CliRuntimeExecutor's
        // plan() requires (workspace + scope_contract.allowed_files + decision_receipt). The
        // worker only runs INSIDE the AP-786 owner flow (post AWIS/release/consumption gates),
        // so a deterministic decision receipt derived from the finding represents that
        // owner-flow authorization. Without these the executor fail-closed with
        // decision_receipt_required/workspace_required/allowed_files_required, so the loop
        // could never actually run MiniMax. The worktree is the cwd the adapter runs in.
        $manifest['workspace_path'] = $worktree;
        $manifest['cwd'] = $worktree;
        $manifest['scope_contract'] = [
            'allowed_files' => $allowedFiles,
            'forbidden_files' => ['.env', 'config/secrets', 'storage/secrets', 'vendor/', 'node_modules/'],
        ];
        $decisionId = 'minimax_worker_'.substr(hash('sha256', (string) ($finding['finding_id'] ?? '').'|'.implode(',', $allowedFiles)), 0, 16);
        $manifest['decision_receipt_id'] = $decisionId;
        $manifest['decision_receipt_hash'] = 'sha256:'.hash('sha256', $decisionId.'|owner_flow_authorized');
        // Real code generation on a non-trivial finding routinely exceeds the executor's 120s
        // default, surfacing as owner_runtime_minimax_invocation_failed ("process exceeded the
        // timeout") — a timeout, not a real failure. Give the provider a realistic ceiling.
        $manifest['timeout_seconds'] = max(120, (int) ($input['provider_timeout_seconds'] ?? 600));
        $manifest['max_output_chars'] = max(12000, (int) ($manifest['max_output_chars'] ?? 60000));

        $tokensUsed  = 0;
        $repairCount = 0;

        while (true) {
            // Phase 3: Invoke MiniMax.
            $response    = $this->minimaxExecutor->execute($manifest);
            $tokensUsed += (int) ($response['input_tokens'] ?? 0) + (int) ($response['output_tokens'] ?? 0);

            if (($response['status'] ?? '') !== 'completed') {
                return $this->blocked('minimax_invocation_failed', $tokensUsed, ['minimax_error' => $response['error'] ?? '']);
            }

            $minimaxText = (string) ($response['text'] ?? '');

            // Phase 4: Extract code blocks (strict allowed-files boundary).
            $codeBlocks = $this->extractCodeBlocks($minimaxText, $allowedFiles);
            if ($codeBlocks === []) {
                return $this->blocked('minimax_no_code_extracted', $tokensUsed);
            }

            // Phase 5: Write files.
            $written = $this->writeCode($codeBlocks, $worktree);
            if ($written['errors'] !== []) {
                return $this->blocked('write_failed: ' . implode('; ', $written['errors']), $tokensUsed, [], $repairCount);
            }

            $changedFiles = $this->changedFiles($worktree);
            if ($changedFiles === []) {
                $changedFiles = $written['written'];
            }

            $diffQuality = $this->providerDiffQualityGate($worktree, $changedFiles, $allowedFiles, $finding);
            if (($diffQuality['passed'] ?? false) !== true) {
                return $this->blocked(
                    self::PROVIDER_DIFF_QUALITY_BLOCKER,
                    $tokensUsed,
                    [
                        'blockers' => array_values((array) ($diffQuality['blockers'] ?? [self::PROVIDER_DIFF_QUALITY_BLOCKER])),
                        'files_modified' => $changedFiles,
                        'diff_quality_gate' => $diffQuality,
                    ],
                    $repairCount,
                );
            }

            // Phase 6: PHP syntax check.
            $syntax = $this->phpSyntaxCheck($changedFiles, $worktree);
            if (! $syntax['ok']) {
                if ($repairCount >= $maxRepairs) {
                    return $this->failed('php_syntax_error_after_max_repairs', $tokensUsed, $syntax['errors'], $repairCount);
                }
                $manifest = $this->buildRepairManifest($manifest, implode("\n", $syntax['errors']), 'php syntax error');
                $repairCount++;
                continue;
            }

            // Phase 7: Run validation commands.
            $validation = $this->runValidation($validationCmds, $worktree);
            if ($validation['ok']) {
                return $this->completed($tokensUsed, $changedFiles, $repairCount);
            }

            if ($repairCount >= $maxRepairs) {
                return $this->failed('validation_failed_after_max_repairs', $tokensUsed, [$validation['output']], $repairCount);
            }

            $manifest = $this->buildRepairManifest($manifest, $validation['output'], 'test failure');
            $repairCount++;
        }
    }

    // ─────────────────────────────────────────────────────────
    // Pre-gate
    // ─────────────────────────────────────────────────────────

    /** @return array{ok: bool, blocker: string|null} */
    private function preGate(array $finding, string $worktree): array
    {
        if ($finding === []) {
            return ['ok' => false, 'blocker' => 'empty_finding'];
        }
        if ($worktree === '' || ! is_dir($worktree)) {
            return ['ok' => false, 'blocker' => 'worktree_not_found'];
        }

        return ['ok' => true, 'blocker' => null];
    }

    // ─────────────────────────────────────────────────────────
    // Code extraction — SECURITY: strict allowed-files boundary
    // ─────────────────────────────────────────────────────────

    /**
     * @param  list<string>          $allowedFiles  relative paths
     * @return array<string,string>                 relative_path => file_content
     */
    private function extractCodeBlocks(string $minimaxText, array $allowedFiles): array
    {
        $blocks           = [];
        $normalizedAllowed = array_map(static fn ($f) => ltrim($f, '/'), $allowedFiles);

        preg_match_all(
            '/\/\/\s*FILE:\s*([^\n]+)\n([\s\S]+?)(?=\/\/\s*FILE:|$)/i',
            $minimaxText,
            $matches,
            PREG_SET_ORDER,
        );

        foreach ($matches as $match) {
            $path    = trim($match[1]);
            $content = trim($match[2]);

            $content = (string) preg_replace('/^```(?:php)?\n?/i', '', $content);
            $content = (string) preg_replace('/\n?```$/', '', $content);
            $content = trim($content);

            if ($content === '' || ! str_ends_with($path, '.php')) {
                continue;
            }

            $normalized = ltrim($path, '/');

            // SECURITY: only write files explicitly in allowedFiles.
            if (! in_array($normalized, $normalizedAllowed, true)) {
                continue;
            }

            $blocks[$normalized] = $content;
        }

        // Fallback for models that emit fenced ```php blocks WITHOUT the // FILE: marker.
        // Associate each fenced block with an allowed file by a path token near the fence, or
        // — when there is exactly one allowed .php file and one block — map it directly. The
        // allowlist gate below still holds (only allowed files are ever written), so loosening
        // the FORMAT never loosens the SCOPE. Never fabricates a path.
        if ($blocks === []) {
            $allowedPhp = array_values(array_filter($normalizedAllowed, static fn (string $f): bool => str_ends_with($f, '.php')));
            preg_match_all('/```(?:php)?\s*\n([\s\S]+?)\n```/i', $minimaxText, $fenced, PREG_SET_ORDER);
            foreach ($fenced as $fb) {
                $content = trim($fb[1]);
                if ($content === '' || ! str_contains($content, '<?php')) {
                    continue;
                }
                $offset = (int) strpos($minimaxText, $fb[0]);
                $preamble = substr($minimaxText, max(0, $offset - 200), 200);
                $target = null;
                foreach ($allowedPhp as $f) {
                    if (str_contains($preamble, $f) || str_contains($preamble, basename($f))) {
                        $target = $f;
                        break;
                    }
                }
                if ($target === null && count($allowedPhp) === 1 && count($fenced) === 1) {
                    $target = $allowedPhp[0];
                }
                if ($target !== null && ! isset($blocks[$target])) {
                    $blocks[$target] = $content;
                }
            }
        }

        return $blocks;
    }

    // ─────────────────────────────────────────────────────────
    // File writing — boundary enforced a second time here
    // ─────────────────────────────────────────────────────────

    /**
     * @param  array<string,string>  $codeBlocks  relative_path => content
     * @return array{written: list<string>, errors: list<string>}
     */
    private function writeCode(array $codeBlocks, string $worktree): array
    {
        $written = [];
        $errors  = [];
        $worktreeRoot = $this->canonicalPath($worktree);

        foreach ($codeBlocks as $relativePath => $content) {
            $relativePath = str_replace('\\', '/', $relativePath);
            if ($relativePath === '' || str_starts_with($relativePath, '/') || preg_match('#(^|/)\.\.(/|$)#', $relativePath)) {
                $errors[] = "path_escape_attempt: {$relativePath}";
                continue;
            }

            $absPath = $worktreeRoot.'/'.$relativePath;
            $dir     = dirname($absPath);

            // Prevent path-traversal: resolved dir must be inside worktree.
            $nearestExistingDir = $this->nearestExistingDirectory($dir);
            $resolvedDir = $this->canonicalPath($nearestExistingDir);
            if (! $this->pathIsInside($resolvedDir, $worktreeRoot)) {
                $errors[] = "path_escape_attempt: {$relativePath}";
                continue;
            }

            if (! is_dir($dir)) {
                @mkdir($dir, 0755, true);
            }

            $resolvedCreatedDir = realpath($dir);
            if ($resolvedCreatedDir === false || ! $this->pathIsInside($this->canonicalPath($resolvedCreatedDir), $worktreeRoot)) {
                $errors[] = "path_escape_attempt: {$relativePath}";
                continue;
            }

            if (file_put_contents($absPath, $content) === false) {
                $errors[] = "write_failed: {$relativePath}";
                continue;
            }

            $written[] = $relativePath;
        }

        return ['written' => $written, 'errors' => $errors];
    }

    private function canonicalPath(string $path): string
    {
        $resolved = realpath($path) ?: $path;

        return rtrim(str_replace('\\', '/', $resolved), '/');
    }

    private function nearestExistingDirectory(string $dir): string
    {
        $current = $dir;
        while ($current !== '' && ! is_dir($current)) {
            $parent = dirname($current);
            if ($parent === $current) {
                break;
            }
            $current = $parent;
        }

        return $current;
    }

    private function pathIsInside(string $path, string $root): bool
    {
        return $path === $root || str_starts_with($path, $root.'/');
    }

    // ─────────────────────────────────────────────────────────
    // Provider diff quality gate
    // ─────────────────────────────────────────────────────────

    /**
     * @param  list<string>  $changedFiles
     * @param  list<string>  $allowedFiles
     * @param  array<string,mixed>  $finding
     * @return array<string,mixed>
     */
    private function providerDiffQualityGate(string $worktree, array $changedFiles, array $allowedFiles, array $finding): array
    {
        $changedFiles = array_values(array_unique(array_filter($changedFiles, 'is_string')));
        if ($changedFiles === []) {
            return [
                'schema_version' => 'atlas.dev.minimax_first.provider_diff_quality_gate.v1',
                'passed' => true,
                'blockers' => [],
                'reason' => 'no_written_files_to_score',
            ];
        }

        $numstat = $this->git($worktree, array_merge(['diff', '--numstat', '--'], $changedFiles));
        if (! $numstat['ok']) {
            return [
                'schema_version' => 'atlas.dev.minimax_first.provider_diff_quality_gate.v1',
                'passed' => false,
                'blockers' => [self::PROVIDER_DIFF_QUALITY_BLOCKER, 'diff_stats_unavailable'],
                'reason' => 'diff_stats_unavailable',
                'git' => $numstat,
            ];
        }

        $stats = $this->withUntrackedFileStats($worktree, $changedFiles, $this->parseDiffNumstat((string) $numstat['out']));
        if ($stats === []) {
            return [
                'schema_version' => 'atlas.dev.minimax_first.provider_diff_quality_gate.v1',
                'passed' => false,
                'blockers' => [self::PROVIDER_DIFF_QUALITY_BLOCKER, 'no_effective_diff'],
                'reason' => 'no_effective_diff',
                'changed_files' => $changedFiles,
                'allowed_files' => $allowedFiles,
                'stats' => [],
            ];
        }

        $semanticSummary = $this->semanticDiffSummary($worktree, $stats);
        $testChanged = false;
        $testInsertions = 0;
        $testDeletions = 0;
        $productInsertions = 0;
        $productDeletions = 0;
        $productChanged = [];
        $largeDeletedFiles = [];
        $largeDeletedTestFiles = [];

        foreach ($stats as $row) {
            $file = (string) ($row['file'] ?? '');
            $insertions = (int) ($row['insertions'] ?? 0);
            $deletions = (int) ($row['deletions'] ?? 0);

            if ($this->isTestFile($file)) {
                $testChanged = true;
                $testInsertions += $insertions;
                $testDeletions += $deletions;
                if ($deletions >= self::DIFF_QUALITY_TEST_DELETIONS
                    && ($deletions / max(1, $insertions)) >= self::DIFF_QUALITY_TEST_DELETION_RATIO_FLOOR) {
                    $largeDeletedTestFiles[] = [
                        'file' => $file,
                        'insertions' => $insertions,
                        'deletions' => $deletions,
                    ];
                }

                continue;
            }

            if ($this->isDocumentationFile($file)) {
                continue;
            }

            $productChanged[] = $file;
            $productInsertions += $insertions;
            $productDeletions += $deletions;
            if ($deletions >= self::DIFF_QUALITY_SINGLE_FILE_DELETIONS_WITHOUT_TEST) {
                $largeDeletedFiles[] = ['file' => $file, 'deletions' => $deletions];
            }
        }

        $productLineDelta = $productInsertions + $productDeletions;
        $semanticChangedFiles = $semanticSummary['semantic_changed_files'];
        $productSemanticChangedFiles = $semanticSummary['product_semantic_changed_files'];
        $testSemanticChangedFiles = $semanticSummary['test_semantic_changed_files'];
        $reasons = [];
        if ($semanticChangedFiles === []) {
            $reasons[] = 'comment_or_whitespace_only_diff';
        }
        if ($productChanged !== [] && $productSemanticChangedFiles === []) {
            $reasons[] = 'product_comment_or_whitespace_only_diff';
        }
        if ($testChanged && $testSemanticChangedFiles === [] && $this->findingRequiresTestUpdate($finding)) {
            $reasons[] = 'test_comment_or_whitespace_only_diff';
        }
        if ($largeDeletedTestFiles !== []) {
            $reasons[] = 'large_test_deletion';
        }
        if ($productChanged !== [] && ! $testChanged) {
            if ($this->findingRequiresTestUpdate($finding)) {
                $reasons[] = 'required_test_update_missing';
            }
            if ($productLineDelta >= self::DIFF_QUALITY_LARGE_PRODUCT_LINES_WITHOUT_TEST) {
                $reasons[] = 'large_product_diff_without_test_update';
            }
            if ($productDeletions >= self::DIFF_QUALITY_PRODUCT_DELETIONS_WITHOUT_TEST) {
                $reasons[] = 'large_product_deletion_without_test_update';
            }
            if ($largeDeletedFiles !== []) {
                $reasons[] = 'large_single_file_deletion_without_test_update';
            }
            if ($productDeletions >= 30 && $productInsertions > 0 && ($productDeletions / max(1, $productInsertions)) >= self::DIFF_QUALITY_DELETION_RATIO_FLOOR) {
                $reasons[] = 'deletion_heavy_product_diff_without_test_update';
            }
        }

        $reasons = array_values(array_unique($reasons));
        $passed = $reasons === [];

        return [
            'schema_version' => 'atlas.dev.minimax_first.provider_diff_quality_gate.v1',
            'passed' => $passed,
            'blockers' => $passed ? [] : array_values(array_unique([self::PROVIDER_DIFF_QUALITY_BLOCKER, ...$reasons])),
            'reason' => $passed ? 'diff_quality_acceptable' : $reasons[0],
            'changed_files' => $changedFiles,
            'allowed_files' => $allowedFiles,
            'stats' => $stats,
            'summary' => [
                'product_changed_files' => array_values(array_unique($productChanged)),
                'test_changed' => $testChanged,
                'test_insertions' => $testInsertions,
                'test_deletions' => $testDeletions,
                'product_insertions' => $productInsertions,
                'product_deletions' => $productDeletions,
                'product_line_delta' => $productLineDelta,
                'large_deleted_files' => $largeDeletedFiles,
                'large_deleted_test_files' => $largeDeletedTestFiles,
                'finding_requires_test_update' => $this->findingRequiresTestUpdate($finding),
                'semantic_changed_files' => $semanticChangedFiles,
                'product_semantic_changed_files' => $productSemanticChangedFiles,
                'test_semantic_changed_files' => $testSemanticChangedFiles,
                'comment_or_whitespace_only_files' => $semanticSummary['comment_or_whitespace_only_files'],
            ],
            'thresholds' => [
                'large_product_lines_without_test' => self::DIFF_QUALITY_LARGE_PRODUCT_LINES_WITHOUT_TEST,
                'product_deletions_without_test' => self::DIFF_QUALITY_PRODUCT_DELETIONS_WITHOUT_TEST,
                'single_file_deletions_without_test' => self::DIFF_QUALITY_SINGLE_FILE_DELETIONS_WITHOUT_TEST,
                'deletion_ratio_floor' => self::DIFF_QUALITY_DELETION_RATIO_FLOOR,
                'test_deletions' => self::DIFF_QUALITY_TEST_DELETIONS,
                'test_deletion_ratio_floor' => self::DIFF_QUALITY_TEST_DELETION_RATIO_FLOOR,
            ],
        ];
    }

    /**
     * @param  list<string>  $argv
     * @return array{ok: bool, out: string, err: string, exit_code: int|null}
     */
    private function git(string $worktree, array $argv): array
    {
        $process = new Process(array_merge(['git'], $argv), $worktree, null, null, 30.0);
        $process->run();

        return [
            'ok' => $process->isSuccessful(),
            'out' => (string) $process->getOutput(),
            'err' => (string) $process->getErrorOutput(),
            'exit_code' => $process->getExitCode(),
        ];
    }

    /**
     * @return list<array{file:string,insertions:int,deletions:int,binary:bool}>
     */
    private function parseDiffNumstat(string $raw): array
    {
        $rows = [];
        foreach (preg_split('/\R/', trim($raw)) ?: [] as $line) {
            if ($line === '') {
                continue;
            }

            $parts = preg_split('/\t+/', $line);
            if (! is_array($parts) || count($parts) < 3) {
                continue;
            }

            $binary = $parts[0] === '-' || $parts[1] === '-';
            $file = (string) $parts[2];
            if (str_contains($file, ' => ')) {
                $file = (string) preg_replace('/.* => /', '', $file);
                $file = trim($file, '{} ');
            }

            $rows[] = [
                'file' => $file,
                'insertions' => $binary ? 0 : max(0, (int) $parts[0]),
                'deletions' => $binary ? 0 : max(0, (int) $parts[1]),
                'binary' => $binary,
            ];
        }

        return $rows;
    }

    /**
     * git diff --numstat does not report untracked files. MiniMax writes files
     * before AP-786 stages anything, so new product/test files must be scored
     * explicitly or the pre-repair gate can miss product-only new-file output.
     *
     * @param  list<string>  $changedFiles
     * @param  list<array{file:string,insertions:int,deletions:int,binary:bool}>  $stats
     * @return list<array{file:string,insertions:int,deletions:int,binary:bool}>
     */
    private function withUntrackedFileStats(string $worktree, array $changedFiles, array $stats): array
    {
        $seen = array_fill_keys(array_map(static fn (array $row): string => (string) $row['file'], $stats), true);

        foreach ($changedFiles as $file) {
            if (isset($seen[$file]) || $this->isTrackedFile($worktree, $file)) {
                continue;
            }

            $path = rtrim($worktree, '/').'/'.$file;
            if (! is_file($path)) {
                continue;
            }

            $lines = @file($path);
            $stats[] = [
                'file' => $file,
                'insertions' => is_array($lines) ? count($lines) : 0,
                'deletions' => 0,
                'binary' => false,
            ];
        }

        return $stats;
    }

    /**
     * @param  list<array{file:string,insertions:int,deletions:int,binary:bool}>  $stats
     * @return array{
     *     semantic_changed_files:list<string>,
     *     product_semantic_changed_files:list<string>,
     *     test_semantic_changed_files:list<string>,
     *     comment_or_whitespace_only_files:list<string>
     * }
     */
    private function semanticDiffSummary(string $worktree, array $stats): array
    {
        $semanticChanged = [];
        $productSemanticChanged = [];
        $testSemanticChanged = [];
        $commentOrWhitespaceOnly = [];

        foreach ($stats as $row) {
            $file = (string) ($row['file'] ?? '');
            if ($file === '' || $this->isDocumentationFile($file)) {
                continue;
            }

            if ((bool) ($row['binary'] ?? false)) {
                $semanticChanged[] = $file;
                if ($this->isTestFile($file)) {
                    $testSemanticChanged[] = $file;
                } else {
                    $productSemanticChanged[] = $file;
                }

                continue;
            }

            $before = $this->headFileContent($worktree, $file);
            $afterPath = rtrim($worktree, '/').'/'.$file;
            $after = is_file($afterPath) ? (string) file_get_contents($afterPath) : null;

            if ($this->semanticComparableContent($file, $before) === $this->semanticComparableContent($file, $after)) {
                $commentOrWhitespaceOnly[] = $file;

                continue;
            }

            $semanticChanged[] = $file;
            if ($this->isTestFile($file)) {
                $testSemanticChanged[] = $file;
            } else {
                $productSemanticChanged[] = $file;
            }
        }

        return [
            'semantic_changed_files' => array_values(array_unique($semanticChanged)),
            'product_semantic_changed_files' => array_values(array_unique($productSemanticChanged)),
            'test_semantic_changed_files' => array_values(array_unique($testSemanticChanged)),
            'comment_or_whitespace_only_files' => array_values(array_unique($commentOrWhitespaceOnly)),
        ];
    }

    private function headFileContent(string $worktree, string $file): ?string
    {
        $show = $this->git($worktree, ['show', 'HEAD:'.$file]);

        return $show['ok'] ? (string) $show['out'] : null;
    }

    private function semanticComparableContent(string $file, ?string $content): string
    {
        if ($content === null) {
            return '';
        }

        if (str_ends_with($file, '.php')) {
            $parts = [];
            foreach (token_get_all($content) as $token) {
                if (is_array($token)) {
                    if (in_array($token[0], [T_COMMENT, T_DOC_COMMENT, T_WHITESPACE], true)) {
                        continue;
                    }
                    $parts[] = $token[1];

                    continue;
                }

                $parts[] = $token;
            }

            return implode('', $parts);
        }

        return (string) preg_replace('/\s+/', '', $content);
    }

    private function isTrackedFile(string $worktree, string $file): bool
    {
        return $this->git($worktree, ['ls-files', '--error-unmatch', '--', $file])['ok'];
    }

    /**
     * MiniMax's runtime may edit files directly in the sandbox while also
     * returning a partial text payload. Score and report the real worktree diff,
     * not just the files we rewrote from extracted FILE markers.
     *
     * @return list<string>
     */
    private function changedFiles(string $worktree): array
    {
        $status = $this->git($worktree, ['status', '--porcelain', '--untracked-files=all']);
        if (! $status['ok']) {
            return [];
        }

        $files = [];
        foreach (preg_split('/\R/', rtrim((string) $status['out'], "\r\n")) ?: [] as $line) {
            if ($line === '') {
                continue;
            }

            $path = strlen($line) >= 4 && ctype_space($line[2])
                ? substr($line, 3)
                : preg_replace('/\A[ MADRCU?!]{1,2}\s+/', '', $line);
            $path = trim((string) $path);
            if ($path === '') {
                continue;
            }
            if (str_contains($path, ' -> ')) {
                $parts = explode(' -> ', $path);
                $path = trim((string) end($parts));
            }

            $files[] = $path;
        }

        return $this->productChangedFiles(array_values(array_filter($files)));
    }

    /**
     * @param  list<string>  $files
     * @return list<string>
     */
    private function productChangedFiles(array $files): array
    {
        return array_values(array_unique(array_filter(
            $files,
            static fn (string $file): bool => ! str_starts_with($file, '.atlas/')
                && $file !== '.atlas'
        )));
    }

    private function findingRequiresTestUpdate(array $finding): bool
    {
        if (trim((string) data_get($finding, 'expected_test_path', '')) !== ''
            || trim((string) data_get($finding, 'focused_test_path', '')) !== ''
            || trim((string) data_get($finding, 'test_path', '')) !== '') {
            return true;
        }

        $signals = [
            $finding['finding_id'] ?? '',
            $finding['id'] ?? '',
            $finding['title'] ?? '',
            $finding['description'] ?? '',
            data_get($finding, 'spec_seed.candidate_id', ''),
        ];

        $haystack = strtolower(implode(' ', array_map(static fn (mixed $value): string => is_scalar($value) ? (string) $value : '', $signals)));

        return str_contains($haystack, 'missing_test')
            || str_contains($haystack, 'missing test')
            || str_contains($haystack, 'expected_test_path')
            || str_contains($haystack, 'focused_test_path')
            || str_contains($haystack, 'create test')
            || str_contains($haystack, 'add test')
            || str_contains($haystack, 'unit test');
    }

    private function isTestFile(string $file): bool
    {
        return str_starts_with($file, 'tests/') || str_ends_with($file, 'Test.php');
    }

    private function isDocumentationFile(string $file): bool
    {
        return str_starts_with($file, 'docs/') || preg_match('/\.(md|mdx|rst|txt)\z/i', $file) === 1;
    }

    // ─────────────────────────────────────────────────────────
    // Validation
    // ─────────────────────────────────────────────────────────

    /** @return array{ok: bool, errors: list<string>} */
    private function phpSyntaxCheck(array $relativePaths, string $worktree): array
    {
        $errors = [];
        foreach ($relativePaths as $rel) {
            $abs     = rtrim($worktree, '/') . '/' . $rel;
            $process = new Process([PHP_BINARY, '-l', $abs], $worktree, null, null, 30.0);
            $process->run();
            if (! $process->isSuccessful()) {
                $errors[] = $process->getOutput() . $process->getErrorOutput();
            }
        }

        return ['ok' => $errors === [], 'errors' => $errors];
    }

    /** @return array{ok: bool, output: string, exit_code: int} */
    private function runValidation(array $commands, string $worktree): array
    {
        if ($commands === []) {
            return ['ok' => true, 'output' => 'no_validation_commands', 'exit_code' => 0];
        }

        // The sandbox is a git worktree whose vendor/ is symlinked to the main repo, so
        // composer's PSR-4 `App\` prefix resolves to the MAIN repo's app/ — newly generated
        // classes that live ONLY in the worktree are "class not found" during validation.
        // That made every NEW-class build-slice fail validation (missing-test slices passed
        // only because their class already existed in main). Prepend a worktree-scoped
        // autoloader so the generated code is actually loadable when its test runs.
        $bootstrap = $this->ensureWorktreeAutoloadBootstrap($worktree);

        foreach ($commands as $cmd) {
            $parts = array_values(array_filter(explode(' ', (string) $cmd)));
            if ($bootstrap !== '' && $this->isPhpunitCommand($parts) && ! $this->hasBootstrapFlag($parts)) {
                array_splice($parts, 1, 0, ['--bootstrap='.$bootstrap]);
            }
            $process = new Process($parts, $worktree, null, null, 120.0);
            $process->run();
            if (! $process->isSuccessful()) {
                return [
                    'ok'        => false,
                    'output'    => mb_substr($process->getOutput() . $process->getErrorOutput(), 0, 4_000),
                    'exit_code' => $process->getExitCode() ?? 1,
                ];
            }
        }

        return ['ok' => true, 'output' => 'passed', 'exit_code' => 0];
    }

    /**
     * Write (once) a worktree-scoped PHPUnit bootstrap that loads the symlinked vendor
     * autoloader THEN registers a prepended PSR-4 resolver mapping `App\` to the worktree's
     * own app/ directory, so classes generated in this worktree are loadable during
     * validation. Returns the bootstrap path, or '' if the worktree autoloader is absent.
     */
    private function ensureWorktreeAutoloadBootstrap(string $worktree): string
    {
        $worktree = rtrim($worktree, '/');
        if ($worktree === '' || ! is_file($worktree.'/vendor/autoload.php')) {
            return '';
        }
        // Write the bootstrap OUTSIDE the worktree (system temp) so it never appears in the
        // worktree's git diff / changed_files — otherwise the scope/merge gate would reject the
        // slice as touching a file outside allowed_files. Reference the worktree by absolute path.
        $vendorAutoload = $worktree.'/vendor/autoload.php';
        $appDir = $worktree.'/app';
        $path = sys_get_temp_dir().'/atlas_wt_autoload_'.substr(hash('sha256', $worktree), 0, 16).'.php';
        $contents = "<?php\n"
            ."require ".var_export($vendorAutoload, true).";\n"
            ."\$__atlas_app = ".var_export($appDir, true).";\n"
            ."spl_autoload_register(static function (string \$class) use (\$__atlas_app): void {\n"
            ."    if (str_starts_with(\$class, 'App\\\\')) {\n"
            ."        \$file = \$__atlas_app.'/'.str_replace('\\\\', '/', substr(\$class, 4)).'.php';\n"
            ."        if (is_file(\$file)) { require \$file; }\n"
            ."    }\n"
            ."}, true, true);\n";
        @file_put_contents($path, $contents);

        return is_file($path) ? $path : '';
    }

    /** @param list<string> $parts */
    private function isPhpunitCommand(array $parts): bool
    {
        foreach ($parts as $p) {
            if (str_contains($p, 'phpunit')) {
                return true;
            }
        }

        return false;
    }

    /** @param list<string> $parts */
    private function hasBootstrapFlag(array $parts): bool
    {
        foreach ($parts as $p) {
            if (str_starts_with($p, '--bootstrap')) {
                return true;
            }
        }

        return false;
    }

    // ─────────────────────────────────────────────────────────
    // Repair
    // ─────────────────────────────────────────────────────────

    /** @param  array<string,mixed>  $originalManifest */
    private function buildRepairManifest(array $originalManifest, string $failureOutput, string $failureKind): array
    {
        $original = $originalManifest['messages'][0]['content'] ?? '';
        $repair   = "\n\n--- REPAIR REQUIRED ({$failureKind}) ---\n"
            . "Previous attempt failed. Error output:\n{$failureOutput}\n\n"
            . "Fix ONLY the failing issue. Do not rewrite unrelated code.\n"
            . "Output the complete corrected file(s) with // FILE: markers.";

        $manifest                           = $originalManifest;
        $manifest['messages'][0]['content'] = mb_substr($original, 0, 20_000) . $repair;

        return $manifest;
    }

    // ─────────────────────────────────────────────────────────
    // Output builders
    // ─────────────────────────────────────────────────────────

    /** @return array<string,mixed> */
    private function completed(int $tokensUsed, array $filesWritten, int $repairCount): array
    {
        return $this->buildOutput('completed', 'passed', 'passed', $tokensUsed, $filesWritten, $repairCount, [], '');
    }

    /** @return array<string,mixed> */
    private function failed(string $reason, int $tokensUsed, array $capsules = [], int $repairCount = 0): array
    {
        return $this->buildOutput('failed', 'failed', 'failed', $tokensUsed, [], $repairCount, $capsules, $reason);
    }

    /** @return array<string,mixed> */
    private function blocked(string $reason, int $tokensUsed, array $extra = [], int $repairCount = 0): array
    {
        return array_merge(
            $this->buildOutput('blocked', 'blocked', 'skipped', $tokensUsed, [], $repairCount, [], $reason),
            $extra,
        );
    }

    /** @return array<string,mixed> */
    private function buildOutput(
        string $status,
        string $completionState,
        string $verificationStatus,
        int $tokensUsed,
        array $filesWritten,
        int $repairCount,
        array $failureCapsules,
        string $reason,
    ): array {
        return [
            'schema_version' => self::SCHEMA,
            'status'         => $status,
            'run_id'         => 'mmfirst_' . bin2hex(random_bytes(8)),
            'run_summary'    => [
                'completion_state'    => $completionState,
                'verification_status' => $verificationStatus,
                'scope_guard_status'  => 'passed',
                'provider_call'       => [
                    'provider'    => 'minimax_m27_cli',
                    'model'       => 'MiniMax-M2.7',
                    'tokens_used' => $tokensUsed,
                    // Real provider invocations this run = initial call + one per repair. The
                    // AP-759 runner maps this to owner_cli_provider_calls; without it the gate
                    // saw provider_calls=0 with changed files and rejected real MiniMax code as
                    // "scaffold_without_provider". Only count when the provider actually ran
                    // (tokens spent), so a pre-provider block honestly reports 0.
                    'provider_calls' => $tokensUsed > 0 ? $repairCount + 1 : 0,
                ],
                'repair_count' => $repairCount,
            ],
            'debug_loop'    => ['reason' => $reason, 'failure_capsules' => $failureCapsules],
            'blockers'      => $status === 'blocked' ? [$reason] : [],
            'files_modified' => $filesWritten,
        ];
    }
}
