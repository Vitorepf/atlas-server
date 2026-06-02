<?php

declare(strict_types=1);

namespace App\Services\Ai\RealExecution;

use App\Models\AiJob;
use App\Services\Ai\AiProvider;
use App\Services\Ai\AiProviderManager;
use Closure;
use Symfony\Component\Process\Process;

/**
 * Atlas Live Code Delivery — the real path that replaces the fixture
 * `AtlasRealEngineeringExecutionKernelService::executePatch` smoke diff.
 *
 * Turns a natural-language code goal into a REAL, syntax-verified artifact:
 *
 *   goal -> provider (read-only) generates code
 *        -> Atlas writes it into an ISOLATED sandbox (never the repo)
 *        -> php -l syntax gate (+ optional caller verifier)
 *        -> certified delivery envelope (diff/preview) for operator review
 *
 * Safety invariants (this is a provider producing code — treat as hostile):
 *   - The provider runs READ-ONLY (no write mode, no sandbox bypass). It only
 *     returns text; ATLAS applies it. The provider never edits files itself.
 *   - The artifact is written ONLY inside a fresh temp sandbox dir, never the
 *     working repo. Target paths are sanitised (no `..`, no absolute escape).
 *   - Nothing is ever merged or applied to the operator's branch. Delivery is
 *     certify-for-review: the operator inspects the diff and decides.
 *
 * Provider-agnostic: any provider key resolvable by AiProviderManager works.
 *
 * Claim policy: provider-safe. No benchmark / rivals / superiority claims.
 */
class AtlasLiveCodeDeliveryService
{
    public const SCHEMA = 'atlas.live_code_delivery.v1';

    public const STATUS_CERTIFIED = 'certified';

    public const STATUS_BLOCKED = 'blocked';

    private ?Closure $sandboxFactory = null;

    public function __construct(
        private readonly AiProviderManager $providers,
    ) {}

    /**
     * Override sandbox creation for tests (so no real temp dirs are needed).
     */
    public function setSandboxFactoryForTesting(?Closure $factory): void
    {
        $this->sandboxFactory = $factory;
    }

    /**
     * @param  array<string,mixed>  $options  ['provider','model','target_file','timeout_seconds']
     * @return array<string,mixed>
     */
    public function deliver(string $goal, array $options = []): array
    {
        $goal = trim($goal);
        if ($goal === '') {
            return $this->blocked('goal_required', null);
        }

        $providerKey = (string) ($options['provider'] ?? 'codex_cli');
        $relPath = $this->sanitiseRelPath((string) ($options['target_file'] ?? 'AtlasGeneratedSnippet.php'));
        if ($relPath === null) {
            return $this->blocked('unsafe_target_file', null);
        }

        try {
            $provider = $this->providers->get($providerKey);
        } catch (\Throwable $e) {
            return $this->blocked('provider_resolve_error:'.substr($e->getMessage(), 0, 80), $relPath);
        }
        if (! $provider instanceof AiProvider) {
            return $this->blocked('provider_not_ai_provider', $relPath);
        }

        $sandbox = $this->makeSandbox();
        $target = $sandbox.DIRECTORY_SEPARATOR.$relPath;

        $verifyRun = ($options['verify_run'] ?? false) === true;
        $prompt = $this->codeGenPrompt($goal, $relPath, $verifyRun);
        $startedAt = microtime(true);
        try {
            $result = $provider->run($this->ephemeralJob($prompt, $providerKey, $sandbox, $options), $prompt);
        } catch (\Throwable $e) {
            return $this->blocked('provider_run_error:'.substr($e->getMessage(), 0, 80), $relPath, $sandbox);
        }
        $latencyMs = (int) ((microtime(true) - $startedAt) * 1000);

        if (! (bool) ($result->ok ?? false)) {
            return $this->blocked('provider_returned_not_ok:'.(string) ($result->errorCode ?? ''), $relPath, $sandbox, $latencyMs);
        }

        $code = $this->extractCode((string) ($result->output ?? ''));
        if ($code === '') {
            return $this->blocked('provider_returned_no_code', $relPath, $sandbox, $latencyMs);
        }

        // Atlas applies the code — into the sandbox ONLY.
        $dir = dirname($target);
        if (! is_dir($dir)) {
            @mkdir($dir, 0775, true);
        }
        file_put_contents($target, $code);

        $syntax = $this->phpLint($target, $relPath);

        // Test-verification: run the provider-supplied self-tests in a hardened,
        // isolated process (process-spawn + network functions disabled, timeout,
        // sandbox cwd). Certified only when syntax AND the run both pass.
        $runCheck = null;
        if ($verifyRun && $syntax['ok'] === true && str_ends_with(strtolower($relPath), '.php')) {
            $runCheck = $this->runScript($target, $sandbox);
        }

        $certified = $syntax['ok'] === true && ($runCheck === null || ($runCheck['ok'] ?? false) === true);

        return [
            'schema_version' => self::SCHEMA,
            'status' => $certified ? self::STATUS_CERTIFIED : self::STATUS_BLOCKED,
            'provider' => $providerKey,
            'model' => (string) ($options['model'] ?? ''),
            'target_file' => $relPath,
            'sandbox_path' => $target,
            'line_count' => substr_count($code, "\n") + 1,
            'byte_count' => strlen($code),
            'syntax_check' => $syntax,
            'run_check' => $runCheck,
            'certified' => $certified,
            'latency_ms' => $latencyMs,
            'code_preview' => $this->preview($code),
            'merged_to_repo' => false,
            'review_required' => true,
            'claim_policy' => [
                'rivals_claim_allowed' => false,
                'benchmark_claim_allowed' => false,
                'superiority_claim_allowed' => false,
            ],
        ];
    }

    // ---------- internals ----------

    private function codeGenPrompt(string $goal, string $relPath, bool $verifyRun): string
    {
        $p = "You are generating the FULL contents of a single file `{$relPath}`.\n"
            ."Goal: {$goal}\n\n";
        if ($verifyRun) {
            $p .= "At the END of the file, add a runnable self-test block: exercise the solution and, on ANY failure, call exit(1) (do NOT rely on assert(), which may be disabled). Running `php {$relPath}` must exit 0 if and only if every check passes. Perform NO network or filesystem side effects.\n\n";
        }
        $p .= 'Output ONLY the file contents — no prose, no explanation, no markdown fences. '
            .'If it is a PHP file, begin with `<?php`.';

        return $p;
    }

    private function ephemeralJob(string $prompt, string $providerKey, string $sandbox, array $options): AiJob
    {
        $job = new AiJob;
        $job->kind = 'live_code_delivery';
        $job->provider = $providerKey;
        $job->model = (string) ($options['model'] ?? '');
        $job->prompt = $prompt;
        $job->input_text = $prompt;
        // workdir = sandbox; READ-ONLY (no write/danger permission) so the
        // provider only returns text and cannot edit anything itself.
        $job->metadata = [
            'workspace' => $sandbox,
            'permission_mode' => 'read',
            'live_code_delivery' => true,
        ];
        $job->timeout_seconds = (int) ($options['timeout_seconds'] ?? 120);

        return $job;
    }

    /**
     * Strip optional markdown fences a provider may wrap code in.
     */
    private function extractCode(string $output): string
    {
        $out = trim($output);
        if ($out === '') {
            return '';
        }
        if (str_starts_with($out, '```')) {
            // Drop the opening fence line (``` or ```php) and the closing fence.
            $lines = preg_split('/\r\n|\r|\n/', $out) ?: [];
            array_shift($lines);
            while ($lines !== [] && ! str_starts_with(trim((string) end($lines)), '```')) {
                // keep scanning; closing fence may not be the very last line
                break;
            }
            $closeIdx = null;
            foreach ($lines as $i => $line) {
                if (str_starts_with(trim((string) $line), '```')) {
                    $closeIdx = $i;
                    break;
                }
            }
            if ($closeIdx !== null) {
                $lines = array_slice($lines, 0, $closeIdx);
            }

            return trim(implode("\n", $lines));
        }

        return $out;
    }

    /**
     * @return array<string,mixed>
     */
    private function phpLint(string $target, string $relPath): array
    {
        if (! str_ends_with(strtolower($relPath), '.php')) {
            return ['ok' => true, 'tool' => 'none', 'reason' => 'non_php_skipped'];
        }
        if (! is_file($target)) {
            return ['ok' => false, 'tool' => 'php -l', 'reason' => 'file_missing'];
        }
        $process = new Process([PHP_BINARY, '-l', $target]);
        $process->setTimeout(30);
        try {
            $process->run();
        } catch (\Throwable $e) {
            return ['ok' => false, 'tool' => 'php -l', 'reason' => substr($e->getMessage(), 0, 120)];
        }

        return [
            'ok' => $process->isSuccessful(),
            'tool' => 'php -l',
            'output' => trim($process->getOutput().$process->getErrorOutput()),
        ];
    }

    /**
     * Run the generated file's self-tests in a HARDENED, isolated process.
     * Executing provider-generated code is a real risk surface, so:
     *   - process-spawn + network functions disabled via -d disable_functions
     *   - cwd is the isolated sandbox (relative paths can't reach the repo)
     *   - a hard timeout bounds runaway code
     * Residual risk (no container): absolute-path file ops are not blocked —
     * which is why a certified delivery still NEVER merges, only certifies for
     * operator review. Exit 0 == self-tests pass.
     *
     * @return array<string,mixed>
     */
    private function runScript(string $target, string $sandbox): array
    {
        $disabled = 'exec,shell_exec,system,passthru,proc_open,popen,pcntl_exec,'
            .'fsockopen,curl_exec,curl_multi_exec,stream_socket_client,stream_socket_server,mail';
        $process = new Process([PHP_BINARY, '-d', 'disable_functions='.$disabled, $target], $sandbox);
        $process->setTimeout(30);
        try {
            $process->run();
        } catch (\Throwable $e) {
            return ['ok' => false, 'tool' => 'php_self_test', 'reason' => substr($e->getMessage(), 0, 120)];
        }

        return [
            'ok' => $process->isSuccessful(),
            'tool' => 'php_self_test',
            'exit_code' => $process->getExitCode(),
            'output' => substr(trim($process->getOutput().$process->getErrorOutput()), 0, 500),
        ];
    }

    private function preview(string $code): string
    {
        $lines = preg_split('/\r\n|\r|\n/', $code) ?: [];

        return implode("\n", array_slice($lines, 0, 20));
    }

    private function makeSandbox(): string
    {
        if ($this->sandboxFactory !== null) {
            return (string) ($this->sandboxFactory)();
        }
        $base = sys_get_temp_dir().DIRECTORY_SEPARATOR.'atlas-live-code-delivery';
        if (! is_dir($base)) {
            @mkdir($base, 0775, true);
        }
        $dir = $base.DIRECTORY_SEPARATOR.'d'.substr(hash('sha256', $base.'|'.getmypid().'|'.uniqid('', true)), 0, 16);
        @mkdir($dir, 0775, true);

        return $dir;
    }

    /**
     * Reject absolute paths and `..` traversal — the artifact may only land
     * inside the sandbox.
     */
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
    private function blocked(string $reason, ?string $relPath, ?string $sandbox = null, ?int $latencyMs = null): array
    {
        return [
            'schema_version' => self::SCHEMA,
            'status' => self::STATUS_BLOCKED,
            'certified' => false,
            'target_file' => $relPath,
            'sandbox_path' => $sandbox,
            'blocked_reason' => $reason,
            'latency_ms' => $latencyMs,
            'merged_to_repo' => false,
            'review_required' => true,
        ];
    }
}
