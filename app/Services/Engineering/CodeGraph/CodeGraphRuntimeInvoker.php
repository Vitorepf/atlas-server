<?php

namespace App\Services\Engineering\CodeGraph;

use Illuminate\Support\Facades\File;
use Symfony\Component\Process\ExecutableFinder;
use Symfony\Component\Process\Process;
use Throwable;

/**
 * Governed PHP -> python_ai_data invoker for the code-graph runtime (AP-812 §9.2).
 *
 * This is the Kernel-side seam that hands a *signed* runtime.invoke payload to the
 * heavy graph analytics that, per atlas-ai-runtime-language-boundaries.md, do NOT
 * belong in PHP (Brandes betweenness, and later centrality-at-scale / Leiden). The
 * cheap degree/blast-radius analytics stay in {@see CodeGraphAnalytics}; only the
 * expensive ops cross this boundary.
 *
 * Sovereignty contract (NON-NEGOTIABLE):
 *  - The python runtime is a *muscle*: it computes a number over edges it is handed
 *    and returns it. It NEVER chooses provider, model, domain, flow or policy, and
 *    it NEVER writes Memory/Context/Policy. Those stay in the Atlas brain (PHP).
 *  - This invoker only *transports* and *shapes*; it adds no decision of its own.
 *
 * Build/test-only posture (anti-over-claim):
 *  - GATED behind config('atlas.code_graph.real_edges') (default false). With the
 *    flag off — or python3 absent — it returns status 'blocked' WITHOUT spawning a
 *    process. Nothing here is wired into Dev/Forge/production; promotion is a
 *    separate, human-reviewed step (runtime_promotion_policy.v1).
 *
 * Conventions deliberately mirror {@see \App\Services\Ai\Programming\ProgrammingPythonRuntimeExecutor}:
 * explicit argv (never shell=true), PYTHONPATH env, a bounded timeout, a temp
 * manifest written then best-effort deleted, and a parsed {ok,result} stdout.
 */
class CodeGraphRuntimeInvoker
{
    public const INVOKE_SCHEMA = 'atlas.runtime.invoke.v1';

    public const RESULT_SCHEMA = 'atlas.runtime.result.v1';

    public const PAYLOAD_SCHEMA_VERSION = '1';

    public const STATUS_SUCCEEDED = 'succeeded';

    public const STATUS_FAILED = 'failed';

    public const STATUS_BLOCKED = 'blocked';

    private const DOMAIN_ID = 'programming';

    private const FLOW_ID = 'programming.dev';

    private const RUNTIME = 'python_ai_data';

    private const RUNTIME_ROOT = 'runtimes/python/code_graph';

    private const ENTRYPOINT = 'runtimes/python/code_graph/main.py';

    private const DEFAULT_TIMEOUT_SECONDS = 30;

    /**
     * Invoke a code-graph python op behind the flag.
     *
     * @param  string  $op  the runtime op (e.g. 'betweenness'); passed through to main.py.
     * @param  array<string,mixed>  $input  op input (e.g. {edges:[...], limit:20, normalized:true}).
     * @param  array<string,mixed>  $limits  optional runtime limits; 'timeout_seconds' bounds the process.
     * @return array{schema_version:string, status:string, artifacts:array<int,mixed>, metrics:array<string,mixed>, findings:array<int,array<string,mixed>>}
     *   an atlas.runtime.result.v1-shaped array.
     */
    public function invoke(string $op, array $input, array $limits = [], string $decisionReceiptHash = ''): array
    {
        // --- Gate 1: feature flag. Default-false; never run otherwise. ---
        if (! (bool) config('atlas.code_graph.real_edges', false)) {
            return $this->blocked('code_graph_real_edges_flag_disabled', $op, [
                'flag' => 'atlas.code_graph.real_edges',
            ]);
        }

        // --- Gate 2: Decision Receipt (AP-815 A2). The brain (PHP) authorizes this
        // exact op+input with a sha256 receipt minted via mintReceipt(); without a
        // valid one the muscle (python) NEVER runs. Closes the null-receipt stub. ---
        $receipt = $decisionReceiptHash !== ''
            ? $decisionReceiptHash
            : (string) ($limits['decision_receipt_hash'] ?? '');
        if (preg_match('/^[a-f0-9]{64}$/', $receipt) !== 1) {
            return $this->blocked('decision_receipt_required', $op, [
                'hint' => 'mint via CodeGraphRuntimeInvoker::mintReceipt($op, $input, $actor)',
            ]);
        }

        // --- Gate 3: python3 must actually exist. No process, structured block. ---
        $python = $this->pythonBinary();
        if ($python === null) {
            return $this->blocked('python3_unavailable', $op, [
                'looked_for' => 'python3',
            ]);
        }

        $payload = $this->buildInvokePayload($op, $input, $limits, $receipt);
        $manifest = $payload['input'];
        // The runtime's main.py expects the op alongside its input in the manifest.
        $manifest['op'] = $op;

        $manifestPath = storage_path(
            'app/atlas-code-graph-runtime-'.hash('sha256', json_encode($manifest) ?: '').'.json'
        );

        File::ensureDirectoryExists(dirname($manifestPath));
        File::put(
            $manifestPath,
            json_encode($manifest, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE)
        );

        $timeout = $this->timeoutSeconds($limits);

        $process = new Process(
            [$python, base_path(self::ENTRYPOINT), $manifestPath],
            base_path(),
            ['PYTHONPATH' => base_path(self::RUNTIME_ROOT)],
        );
        $process->setTimeout($timeout);
        $process->run();

        $decoded = json_decode($process->getOutput(), true);
        $ok = $process->isSuccessful()
            && is_array($decoded)
            && ($decoded['ok'] ?? false) === true;

        try {
            File::delete($manifestPath);
        } catch (Throwable) {
            // Best-effort cleanup; the result already records execution status.
        }

        if (! $ok) {
            $error = is_array($decoded) && isset($decoded['error']) && is_string($decoded['error'])
                ? $decoded['error']
                : ($process->getErrorOutput() !== '' ? trim($process->getErrorOutput()) : 'runtime_failed');

            return $this->failed($op, $error, $process->getExitCode(), $payload);
        }

        $result = is_array($decoded['result'] ?? null) ? $decoded['result'] : [];

        return [
            'schema_version' => self::RESULT_SCHEMA,
            'status' => self::STATUS_SUCCEEDED,
            'artifacts' => [
                [
                    'type' => 'code_graph.runtime_result',
                    'op' => $op,
                    'result' => $result,
                ],
            ],
            'metrics' => [
                'op' => $op,
                'exit_code' => $process->getExitCode(),
                'timeout_seconds' => $timeout,
                'node_count' => $result['node_count'] ?? null,
                'edge_count' => $result['edge_count'] ?? null,
                'ranked_count' => is_array($result['ranked'] ?? null) ? count($result['ranked']) : null,
                'stdout_hash' => hash('sha256', $process->getOutput()),
                'stderr_hash' => hash('sha256', $process->getErrorOutput()),
                'decision_receipt_hash' => $receipt,
            ],
            'findings' => [],
        ];
    }

    /**
     * Build the atlas.runtime.invoke.v1 payload that the brain (PHP) would sign and
     * hand to the muscle (python). The Kernel fills decision_receipt_hash on the
     * promotion path; it is intentionally nullable here.
     *
     * @param  array<string,mixed>  $input
     * @param  array<string,mixed>  $limits
     * @return array{schema_version:string, runtime:string, domain_id:string, flow_id:string, op:string, input:array<string,mixed>, limits:array<string,mixed>, decision_receipt_hash:?string}
     */
    public function buildInvokePayload(string $op, array $input, array $limits = [], string $decisionReceiptHash = ''): array
    {
        $receipt = $decisionReceiptHash !== ''
            ? $decisionReceiptHash
            : (string) ($limits['decision_receipt_hash'] ?? '');

        return [
            'schema_version' => self::PAYLOAD_SCHEMA_VERSION,
            // AP-815 A2: the brain authorizes each op with a Decision Receipt minted via
            // mintReceipt(); invoke() blocks execution unless this is a valid sha256.
            'decision_receipt_hash' => $receipt !== '' ? $receipt : null,
            'runtime' => self::RUNTIME,
            'domain_id' => self::DOMAIN_ID,
            'flow_id' => self::FLOW_ID,
            'op' => $op,
            'input' => $input,
            'limits' => $limits,
        ];
    }

    /**
     * Mint a deterministic Decision Receipt that binds this exact op + input + actor.
     * The brain (PHP) calls this to authorize a runtime op; {@see invoke()} blocks
     * unless a receipt of this shape (sha256 hex) is supplied. Deterministic (no
     * clock) so the same authorized call is reproducible and testable.
     *
     * @param  array<string,mixed>  $input
     */
    public static function mintReceipt(string $op, array $input, string $actor): string
    {
        return hash('sha256', json_encode([
            'schema' => self::INVOKE_SCHEMA,
            'runtime' => self::RUNTIME,
            'domain_id' => self::DOMAIN_ID,
            'flow_id' => self::FLOW_ID,
            'op' => $op,
            'input_hash' => hash('sha256', json_encode($input, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) ?: ''),
            'actor' => $actor,
            'authorized' => true,
        ], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) ?: '');
    }

    /**
     * @param  array<string,mixed>  $detail
     * @return array{schema_version:string, status:string, artifacts:array<int,mixed>, metrics:array<string,mixed>, findings:array<int,array<string,mixed>>}
     */
    private function blocked(string $reason, string $op, array $detail = []): array
    {
        return [
            'schema_version' => self::RESULT_SCHEMA,
            'status' => self::STATUS_BLOCKED,
            'artifacts' => [],
            'metrics' => ['op' => $op],
            'findings' => [
                array_merge([
                    'kind' => 'runtime_blocked',
                    'reason' => $reason,
                    'op' => $op,
                ], $detail),
            ],
        ];
    }

    /**
     * @param  array<string,mixed>  $payload
     * @return array{schema_version:string, status:string, artifacts:array<int,mixed>, metrics:array<string,mixed>, findings:array<int,array<string,mixed>>}
     */
    private function failed(string $op, string $error, ?int $exitCode, array $payload): array
    {
        return [
            'schema_version' => self::RESULT_SCHEMA,
            'status' => self::STATUS_FAILED,
            'artifacts' => [],
            'metrics' => [
                'op' => $op,
                'exit_code' => $exitCode,
                'runtime' => $payload['runtime'] ?? self::RUNTIME,
            ],
            'findings' => [
                [
                    'kind' => 'runtime_error',
                    'op' => $op,
                    'error' => $error,
                ],
            ],
        ];
    }

    /**
     * @param  array<string,mixed>  $limits
     */
    private function timeoutSeconds(array $limits): int
    {
        $raw = $limits['timeout_seconds'] ?? null;
        if (is_int($raw) && $raw > 0) {
            return min($raw, 300);
        }
        if (is_string($raw) && ctype_digit($raw) && (int) $raw > 0) {
            return min((int) $raw, 300);
        }

        return self::DEFAULT_TIMEOUT_SECONDS;
    }

    /**
     * Resolve a python binary, or null when none is available (Gate 2).
     *
     * Prefers the code_graph venv interpreter (it carries tree-sitter / pypdf for
     * the heavy ops); falls back to the system python3 for stdlib-only ops.
     */
    private function pythonBinary(): ?string
    {
        $venv = base_path(self::RUNTIME_ROOT.'/.venv/bin/python');
        if (is_file($venv)) {
            return $venv;
        }

        return (new ExecutableFinder)->find('python3');
    }
}
