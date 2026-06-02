<?php

declare(strict_types=1);

namespace App\Services\Ai\RealExecution;

use App\Models\AiJob;
use App\Services\Ai\AiProvider;
use App\Services\Ai\AiProviderManager;
use Closure;
use Symfony\Component\Process\Process;

/**
 * Atlas Repo-Verified Delivery — the gold-standard verification: a provider
 * generates an implementation + a PHPUnit test, and Atlas runs the project's
 * REAL `php artisan test` against them inside an ISOLATED git worktree.
 *
 *   goal -> provider (read-only) generates impl + test (multi-file)
 *        -> isolated git worktree (detached HEAD) + symlinked vendor
 *        -> ISOLATED sqlite DB (never the real test DB)
 *        -> php artisan test <generated test>  (the project's real harness)
 *        -> certified for review iff the suite passes — worktree removed, NEVER merged
 *
 * Safety (this runs real, AI-generated tests through the real harness):
 *   - Throwaway detached worktree, removed in a finally block.
 *   - A dedicated temp sqlite DB — the operator's data is never touched.
 *   - Only the generated test is run (targeted), with a timeout.
 *   - Nothing is ever merged: certify-for-review only.
 *   - The provider runs READ-ONLY (returns text; Atlas writes the files).
 *
 * Provider-agnostic. Claim policy: provider-safe; no benchmark/rivals claims.
 */
class AtlasRepoVerifiedDeliveryService
{
    public const SCHEMA = 'atlas.repo_verified_delivery.v1';

    public const STATUS_CERTIFIED = 'certified';

    public const STATUS_BLOCKED = 'blocked';

    private ?Closure $worktreeFactory = null;

    public function __construct(
        private readonly AiProviderManager $providers,
    ) {}

    /**
     * Override worktree provisioning for tests: fn(): array{path:string, cleanup:callable}.
     */
    public function setWorktreeFactoryForTesting(?Closure $factory): void
    {
        $this->worktreeFactory = $factory;
    }

    /**
     * @param  array<string,mixed>  $options  ['provider','model','impl_file','test_file','timeout_seconds']
     * @return array<string,mixed>
     */
    public function deliver(string $goal, array $options = []): array
    {
        $goal = trim($goal);
        if ($goal === '') {
            return $this->blocked('goal_required');
        }

        $implRel = $this->sanitiseRelPath((string) ($options['impl_file'] ?? 'app/Services/Ai/Generated/AtlasGeneratedArtifact.php'));
        $testRel = $this->sanitiseRelPath((string) ($options['test_file'] ?? 'tests/Unit/Generated/AtlasGeneratedArtifactTest.php'));
        if ($implRel === null || $testRel === null) {
            return $this->blocked('unsafe_target_path');
        }

        $providerKey = (string) ($options['provider'] ?? 'codex_cli');
        try {
            $provider = $this->providers->get($providerKey);
        } catch (\Throwable $e) {
            return $this->blocked('provider_resolve_error:'.substr($e->getMessage(), 0, 80));
        }
        if (! $provider instanceof AiProvider) {
            return $this->blocked('provider_not_ai_provider');
        }

        $worktree = $this->makeWorktree();
        if ($worktree === null) {
            return $this->blocked('worktree_setup_failed');
        }
        $path = (string) $worktree['path'];
        $cleanup = $worktree['cleanup'];

        try {
            // Self-repair loop: generate -> write -> run the REAL suite; on
            // failure, feed the failing output back and regenerate, up to
            // max_attempts. Default 1 (one-shot); opt-in higher for agentic
            // iterate-to-green.
            $maxAttempts = max(1, min(5, (int) ($options['max_attempts'] ?? 1)));
            $attempts = [];
            $files = [];
            $testRun = ['ok' => false, 'reason' => 'no_attempt'];
            $latencyMs = 0;
            $lastFiles = [];
            $lastOutput = '';

            for ($attempt = 1; $attempt <= $maxAttempts; $attempt++) {
                $prompt = $attempt === 1
                    ? $this->prompt($goal, $implRel, $testRel)
                    : $this->repairPrompt($goal, $implRel, $testRel, $lastFiles, $lastOutput);

                $startedAt = microtime(true);
                try {
                    $result = $provider->run($this->ephemeralJob($prompt, $providerKey, $path, $options), $prompt);
                } catch (\Throwable $e) {
                    $attempts[] = ['attempt' => $attempt, 'ok' => false, 'reason' => 'provider_run_error'];
                    break;
                }
                $latencyMs += (int) ((microtime(true) - $startedAt) * 1000);

                if (! (bool) ($result->ok ?? false)) {
                    $attempts[] = ['attempt' => $attempt, 'ok' => false, 'reason' => 'provider_not_ok'];
                    break;
                }

                $parsed = $this->parseFiles((string) ($result->output ?? ''), $implRel, $testRel);
                if (! isset($parsed[$implRel]) || ! isset($parsed[$testRel])) {
                    $attempts[] = ['attempt' => $attempt, 'ok' => false, 'reason' => 'missing_impl_or_test'];
                    $lastOutput = 'Your previous output did not include BOTH the impl and test FILE blocks.';

                    continue;
                }
                $files = $parsed;

                foreach ($files as $rel => $content) {
                    $abs = $path.DIRECTORY_SEPARATOR.$rel;
                    $dir = dirname($abs);
                    if (! is_dir($dir)) {
                        @mkdir($dir, 0775, true);
                    }
                    file_put_contents($abs, $content);
                }

                $testRun = $this->runRepoTest($path, $testRel);
                $attempts[] = ['attempt' => $attempt, 'ok' => ($testRun['ok'] ?? false) === true, 'exit_code' => $testRun['exit_code'] ?? null];

                if (($testRun['ok'] ?? false) === true) {
                    break;
                }
                $lastFiles = $files;
                $lastOutput = (string) ($testRun['output'] ?? '');
            }

            if ($files === []) {
                return $this->blocked('provider_did_not_return_impl_and_test', $latencyMs);
            }

            $certified = ($testRun['ok'] ?? false) === true;

            // Auto-merge-after-review (opt-in): land a CERTIFIED delivery on a
            // dedicated review branch in the shared repo — never main, never
            // pushed, never auto-merged. The operator reviews + merges.
            $review = null;
            if ($certified && ($options['apply_to_branch'] ?? false) === true) {
                $review = $this->commitToReviewBranch($path, array_keys($files), $goal, $options);
            }

            return [
                'schema_version' => self::SCHEMA,
                'status' => $certified ? self::STATUS_CERTIFIED : self::STATUS_BLOCKED,
                'provider' => $providerKey,
                'model' => (string) ($options['model'] ?? ''),
                'impl_file' => $implRel,
                'test_file' => $testRel,
                'worktree_path' => $path,
                'attempts' => $attempts,
                'attempt_count' => count($attempts),
                'test_run' => $testRun,
                'certified' => $certified,
                'review_branch' => $review,
                'latency_ms' => $latencyMs,
                'impl_preview' => $this->preview($files[$implRel] ?? ''),
                'merged_to_repo' => false,
                'review_required' => true,
                'claim_policy' => [
                    'rivals_claim_allowed' => false,
                    'benchmark_claim_allowed' => false,
                    'superiority_claim_allowed' => false,
                ],
            ];
        } finally {
            if (is_callable($cleanup)) {
                try {
                    $cleanup();
                } catch (\Throwable) {
                    // cleanup is best-effort
                }
            }
        }
    }

    // ---------- internals ----------

    private function prompt(string $goal, string $implRel, string $testRel): string
    {
        return "Generate exactly TWO files to accomplish this goal, verified by the project's PHPUnit suite.\n"
            ."Goal: {$goal}\n\n"
            ."Emit each as a block: a line `=== FILE: <path> ===` then the full file contents.\n"
            ."File 1 path MUST be `{$implRel}` — the implementation (PSR-4: namespace derives from the path under app/).\n"
            ."File 2 path MUST be `{$testRel}` — a PHPUnit test that extends `Tests\\TestCase`, `require_once`s the implementation by a relative path from the test file, and asserts correct behaviour. It must PASS only if the implementation is correct. Do NOT touch the database or the network.\n\n"
            .'Output ONLY the two file blocks — no prose, no markdown fences.';
    }

    /**
     * Repair prompt: the previous attempt failed the real suite — feed the
     * failing output + the previous files back so the provider fixes it.
     *
     * @param  array<string,string>  $lastFiles
     */
    private function repairPrompt(string $goal, string $implRel, string $testRel, array $lastFiles, string $failureOutput): string
    {
        return "Your previous attempt FAILED the project's PHPUnit suite. Fix it so the suite passes.\n\n"
            ."Goal: {$goal}\n\n"
            ."Failing test output (tail):\n".substr($failureOutput, -1500)."\n\n"
            ."Previous {$implRel}:\n".substr((string) ($lastFiles[$implRel] ?? ''), 0, 2000)."\n\n"
            ."Previous {$testRel}:\n".substr((string) ($lastFiles[$testRel] ?? ''), 0, 2000)."\n\n"
            ."Re-emit BOTH corrected files as `=== FILE: <path> ===` blocks at the SAME paths. The implementation must make the test pass; keep the test meaningful and correct. No prose, no fences.";
    }

    private function ephemeralJob(string $prompt, string $providerKey, string $workspace, array $options): AiJob
    {
        $job = new AiJob;
        $job->kind = 'repo_verified_delivery';
        $job->provider = $providerKey;
        $job->model = (string) ($options['model'] ?? '');
        $job->prompt = $prompt;
        $job->input_text = $prompt;
        $job->metadata = ['workspace' => $workspace, 'permission_mode' => 'read', 'repo_verified_delivery' => true];
        $job->timeout_seconds = (int) ($options['timeout_seconds'] ?? 180);

        return $job;
    }

    /**
     * @return array<string,string>  rel-path => content
     */
    private function parseFiles(string $output, string $implRel, string $testRel): array
    {
        $files = [];
        if (preg_match('/^===\s*FILE:\s*.+===\s*$/m', $output) === 1) {
            $parts = preg_split('/^===\s*FILE:\s*(.+?)\s*===\s*$/m', $output, -1, PREG_SPLIT_DELIM_CAPTURE);
            if (is_array($parts)) {
                for ($i = 1; $i < count($parts); $i += 2) {
                    $rel = $this->sanitiseRelPath(trim((string) $parts[$i]));
                    $content = $this->stripFences((string) ($parts[$i + 1] ?? ''));
                    if ($rel !== null && $content !== '') {
                        $files[$rel] = $content;
                    }
                }
            }
        }

        return $files;
    }

    /**
     * @return array<string,mixed>
     */
    private function runRepoTest(string $worktree, string $testRel): array
    {
        // The project's real harness, inside the isolated worktree (own sqlite).
        $process = new Process([PHP_BINARY, 'artisan', 'test', $testRel], $worktree);
        $process->setTimeout(180);
        try {
            $process->run();
        } catch (\Throwable $e) {
            return ['ok' => false, 'tool' => 'php artisan test', 'reason' => substr($e->getMessage(), 0, 160)];
        }

        return [
            'ok' => $process->isSuccessful(),
            'tool' => 'php artisan test',
            'exit_code' => $process->getExitCode(),
            'output' => substr(trim($process->getOutput().$process->getErrorOutput()), -800),
        ];
    }

    /**
     * Commit a CERTIFIED delivery to a dedicated review branch in the shared
     * repo (from the worktree's detached HEAD). NEVER main, NEVER pushed, NEVER
     * auto-merged — the operator reviews + merges. The branch persists after
     * the worktree is removed.
     *
     * @param  list<string>  $relPaths
     * @param  array<string,mixed>  $options
     * @return array<string,mixed>
     */
    private function commitToReviewBranch(string $worktree, array $relPaths, string $goal, array $options): array
    {
        $prefix = (string) ($options['branch_prefix'] ?? 'atlas/delivery/');
        $branch = $prefix.substr(hash('sha256', $goal.'|'.implode(',', $relPaths).'|'.getmypid()), 0, 10);

        $steps = [
            ['git', 'checkout', '-b', $branch],
            array_merge(['git', 'add', '--'], $relPaths),
            ['git', '-c', 'user.email=atlas@local', '-c', 'user.name=Atlas', 'commit', '-m', 'Atlas verified delivery: '.substr($goal, 0, 80)],
        ];
        foreach ($steps as $cmd) {
            $p = new Process($cmd, $worktree);
            $p->setTimeout(60);
            $p->run();
            if (! $p->isSuccessful()) {
                return [
                    'applied' => false,
                    'reason' => 'git_step_failed:'.($cmd[1] ?? ''),
                    'output' => substr(trim($p->getErrorOutput().$p->getOutput()), 0, 200),
                ];
            }
        }

        $rev = new Process(['git', 'rev-parse', 'HEAD'], $worktree);
        $rev->run();

        return [
            'applied' => true,
            'branch' => $branch,
            'commit' => trim($rev->getOutput()),
            'files' => $relPaths,
            'merged_to_main' => false,
            'review_hint' => 'git checkout '.$branch,
        ];
    }

    /**
     * @return array{path:string, cleanup:callable}|null
     */
    private function makeWorktree(): ?array
    {
        if ($this->worktreeFactory !== null) {
            $made = ($this->worktreeFactory)();

            return is_array($made) ? $made : null;
        }

        $root = function_exists('base_path') ? base_path() : getcwd();
        $wt = rtrim(sys_get_temp_dir(), '/').'/atlas-repo-verify-'.substr(hash('sha256', (string) getmypid().'|'.uniqid('', true)), 0, 12);

        $add = new Process(['git', 'worktree', 'add', '--detach', $wt, 'HEAD'], $root);
        $add->setTimeout(120);
        $add->run();
        if (! $add->isSuccessful() || ! is_dir($wt)) {
            return null;
        }

        // Share deps + provide an isolated env/DB and the writable runtime dirs.
        @symlink($root.'/vendor', $wt.'/vendor');
        foreach (['storage/framework/cache/data', 'storage/framework/views', 'storage/framework/sessions', 'storage/logs', 'bootstrap/cache', 'database'] as $d) {
            @mkdir($wt.'/'.$d, 0775, true);
        }
        if (is_file($root.'/.env')) {
            @copy($root.'/.env', $wt.'/.env');
        }
        $sqlite = $wt.'/database/atlas_repo_verify.sqlite';
        @file_put_contents($sqlite, '');
        @file_put_contents($wt.'/.env', "\nDB_CONNECTION=sqlite\nDB_DATABASE={$sqlite}\nCACHE_STORE=array\nSESSION_DRIVER=array\nQUEUE_CONNECTION=sync\n", FILE_APPEND);

        return [
            'path' => $wt,
            'cleanup' => function () use ($wt, $root): void {
                $rm = new Process(['git', 'worktree', 'remove', '--force', $wt], $root);
                $rm->setTimeout(60);
                $rm->run();
                if (is_dir($wt)) {
                    (new Process(['rm', '-rf', $wt]))->run();
                }
            },
        ];
    }

    private function stripFences(string $content): string
    {
        $c = trim($content);
        if (str_starts_with($c, '```')) {
            $lines = preg_split('/\r\n|\r|\n/', $c) ?: [];
            array_shift($lines);
            $close = null;
            foreach ($lines as $i => $l) {
                if (str_starts_with(trim((string) $l), '```')) {
                    $close = $i;
                    break;
                }
            }
            if ($close !== null) {
                $lines = array_slice($lines, 0, $close);
            }

            return trim(implode("\n", $lines));
        }

        return $c;
    }

    private function preview(string $code): string
    {
        return implode("\n", array_slice(preg_split('/\r\n|\r|\n/', $code) ?: [], 0, 20));
    }

    private function sanitiseRelPath(string $path): ?string
    {
        $path = trim($path);
        if ($path === '' || str_starts_with($path, '/') || str_contains($path, '..') || preg_match('/^[A-Za-z]:[\\\\\/]/', $path) === 1) {
            return null;
        }

        return ltrim($path, '/');
    }

    /**
     * @return array<string,mixed>
     */
    private function blocked(string $reason, ?int $latencyMs = null): array
    {
        return [
            'schema_version' => self::SCHEMA,
            'status' => self::STATUS_BLOCKED,
            'certified' => false,
            'blocked_reason' => $reason,
            'latency_ms' => $latencyMs,
            'merged_to_repo' => false,
            'review_required' => true,
        ];
    }
}
