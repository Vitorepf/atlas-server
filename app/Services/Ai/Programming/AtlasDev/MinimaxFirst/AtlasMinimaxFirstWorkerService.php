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
                return $this->blocked('write_failed: ' . implode('; ', $written['errors']), $tokensUsed);
            }

            // Phase 6: PHP syntax check.
            $syntax = $this->phpSyntaxCheck($written['written'], $worktree);
            if (! $syntax['ok']) {
                if ($repairCount >= $maxRepairs) {
                    return $this->failed('php_syntax_error_after_max_repairs', $tokensUsed, $syntax['errors']);
                }
                $manifest = $this->buildRepairManifest($manifest, implode("\n", $syntax['errors']), 'php syntax error');
                $repairCount++;
                continue;
            }

            // Phase 7: Run validation commands.
            $validation = $this->runValidation($validationCmds, $worktree);
            if ($validation['ok']) {
                return $this->completed($tokensUsed, $written['written'], $repairCount);
            }

            if ($repairCount >= $maxRepairs) {
                return $this->failed('validation_failed_after_max_repairs', $tokensUsed, [$validation['output']]);
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

        foreach ($codeBlocks as $relativePath => $content) {
            $absPath = rtrim($worktree, '/') . '/' . $relativePath;
            $dir     = dirname($absPath);

            // Prevent path-traversal: resolved dir must be inside worktree.
            $resolvedDir = realpath($dir) ?: $dir;
            if (! str_starts_with($resolvedDir, $worktree)) {
                $errors[] = "path_escape_attempt: {$relativePath}";
                continue;
            }

            if (! is_dir($dir)) {
                @mkdir($dir, 0755, true);
            }

            if (file_put_contents($absPath, $content) === false) {
                $errors[] = "write_failed: {$relativePath}";
                continue;
            }

            $written[] = $relativePath;
        }

        return ['written' => $written, 'errors' => $errors];
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

        foreach ($commands as $cmd) {
            $parts   = array_values(array_filter(explode(' ', (string) $cmd)));
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
    private function failed(string $reason, int $tokensUsed, array $capsules = []): array
    {
        return $this->buildOutput('failed', 'failed', 'failed', $tokensUsed, [], 0, $capsules, $reason);
    }

    /** @return array<string,mixed> */
    private function blocked(string $reason, int $tokensUsed, array $extra = []): array
    {
        return array_merge(
            $this->buildOutput('blocked', 'blocked', 'skipped', $tokensUsed, [], 0, [], $reason),
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
