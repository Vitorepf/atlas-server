<?php

declare(strict_types=1);

namespace App\Services\Ai\Programming;

use App\Models\AiJob;
use App\Services\Ai\AiProvider;
use App\Services\Ai\AiProviderManager;
use App\Services\Ai\AiProviderResult;
use App\Services\Ai\Support\AiStringListNormalizer;
use Illuminate\Support\Str;
use Symfony\Component\Process\Process;
use Throwable;

/**
 * Atlas Forge Hermes CLI Invocation Driver (adapter).
 *
 * Provider: `hermes_cli`. Unlike the claude/codex/gemini CLI drivers (which
 * extend {@see AtlasForgeBaseCliInvocationDriver} and spawn a binary through
 * {@see AtlasForgeProviderProcessRunner}), Hermes is the Atlas executive
 * runtime and is already governed end-to-end by
 * {@see \App\Services\Ai\HermesCliProvider}. This driver is a THIN ADAPTER that
 * routes the Forge provider-invocation path through that provider via
 * {@see AiProviderManager}, then maps the returned {@see AiProviderResult} into
 * the canonical `atlas.forge.provider_driver_result.v1` shape every other Forge
 * driver returns.
 *
 * It mirrors the proven Dev-side approach in
 * {@see \App\Http\Controllers\AtlasDev\Support\PipelineRunExecutor::executeHermesProvider()}:
 * Hermes chooses its own cwd via
 * {@see \App\Services\Ai\Concerns\RunsCliProcesses::workdirForJob()} which reads
 * (in order) payload.tool_permissions.workspace, payload.workspace, then
 * config('atlas.ai.workdir'). We therefore pin BOTH workspace keys to the
 * request's `cwd` so Hermes runs in the governed workspace.
 *
 * SAFETY: this is purely additive. It does not touch the existing CLI driver
 * registrations or behaviour, and it never spends real tokens on its own — a
 * provider call only happens when the upstream Invocation Service has validated
 * every gate and an actual Hermes runtime is resolvable. Tests bind a fake
 * `hermes_cli` provider via {@see AiProviderManager::registerDriver()} so the
 * mapping is exercised with ZERO real tokens.
 *
 * Doc: docs/engineering-knowledge-base/atlas-hermes-executive-runtime.md
 */
class AtlasForgeHermesCliInvocationDriver implements AtlasForgeProviderInvocationDriver
{
    public const PROVIDER = 'hermes_cli';

    public static function focusedUnitTestPath(): string
    {
        return 'tests/Feature/Ai/Programming/AtlasForgeHermesCliProviderDriverTest.php';
    }

    public function __construct(
        private readonly AiProviderManager $providers,
    ) {}

    public function provider(): string
    {
        return self::PROVIDER;
    }

    public function supports(string $provider, ?string $model): bool
    {
        // Hermes is model-agnostic at the Forge boundary: the executive runtime
        // resolves its own model identity from config + payload. We therefore
        // only key on the provider id.
        return $provider === self::PROVIDER;
    }

    /**
     * Report runtime configuration status. NEVER calls an external provider —
     * it only asks the manager whether a `hermes_cli` driver is resolvable.
     *
     * @return array<string,mixed>  atlas.forge.provider_driver_config_status.v1
     */
    public function configured(): array
    {
        $resolvable = $this->resolveProvider() instanceof AiProvider;
        $blockers = $resolvable ? [] : ['hermes_cli_provider_unavailable'];

        return [
            'schema_version' => 'atlas.forge.provider_driver_config_status.v1',
            'provider' => self::PROVIDER,
            'configured' => $resolvable,
            'runtime_present' => $resolvable,
            'binary_path' => null,
            'auth_state' => $resolvable ? 'configured' : 'missing',
            'model_prefixes' => ['hermes'],
            'allowed_binaries' => [(string) config('atlas.ai.providers.hermes_cli.binary', 'hermes')],
            'blockers' => $blockers,
            'external_provider_call_possible' => true,
            'provider_tokens_may_be_spent' => true,
            'note' => 'Hermes executive runtime adapter: governed by HermesCliProvider; never chama provider externo no status.',
        ];
    }

    /**
     * Plan-only. NEVER calls an external provider.
     *
     * @param  array<string,mixed>  $request
     * @return array<string,mixed>  atlas.forge.provider_driver_plan.v1
     */
    public function plan(array $request): array
    {
        $config = $this->configured();
        $blockers = array_values((array) $config['blockers']);

        return [
            'schema_version' => 'atlas.forge.provider_driver_plan.v1',
            'provider' => self::PROVIDER,
            'model' => $request['model'] ?? null,
            // Forge provider invocations default to the non-interactive one-shot
            // CLI form (`hermes -z PROMPT`); the interactive `chat` subcommand
            // blocks without a TTY. {@see \App\Services\Ai\HermesCliProvider::useCliOneShot}
            'argv_preview' => (bool) config('atlas.ai.providers.hermes_cli.cli_oneshot_for_forge', true)
                ? [(string) config('atlas.ai.providers.hermes_cli.binary', 'hermes'), '-z']
                : [(string) config('atlas.ai.providers.hermes_cli.binary', 'hermes'), 'chat', '--quiet'],
            'cwd' => is_string($request['cwd'] ?? null) ? $request['cwd'] : null,
            'configured' => (bool) $config['configured'],
            'allowlist_passed' => true,
            'allowlist_blockers' => [],
            'config_blockers' => $blockers,
            'blockers' => $blockers,
            'plan_safe' => $blockers === [],
            'provider_called' => false,
            'external_provider_call' => false,
            'provider_tokens_spent' => false,
            'note' => 'Plan-only: Hermes executive runtime nao foi invocado.',
        ];
    }

    /**
     * Execute through {@see \App\Services\Ai\HermesCliProvider} and map the
     * result into the canonical Forge driver-result schema. Only this method
     * may reach a real Hermes runtime, and only when the caller confirmed all
     * gates upstream.
     *
     * @param  array<string,mixed>  $request
     * @return array<string,mixed>  atlas.forge.provider_driver_result.v1
     */
    public function invoke(array $request): array
    {
        $model = is_string($request['model'] ?? null) ? (string) $request['model'] : null;
        $cwd = is_string($request['cwd'] ?? null) ? (string) $request['cwd'] : null;
        $timeout = max(1, (int) ($request['timeout_seconds'] ?? config('atlas.ai.providers.hermes_cli.timeout_seconds', 600)));
        $maxOutputChars = max(200, (int) ($request['max_output_chars'] ?? 12000));

        $provider = $this->resolveProvider();
        if (! $provider instanceof AiProvider) {
            return $this->blocked(
                $request,
                blockers: ['provider_driver_not_configured', 'hermes_cli_provider_unavailable'],
                note: 'Hermes CLI provider nao pode ser resolvido do AiProviderManager. Mantido fail-closed.',
            );
        }

        $promptText = $this->encodePrompt($request['prompt'] ?? null);

        // workdirForJob() reads tool_permissions.workspace || workspace ||
        // config('atlas.ai.workdir'). Pin both so Hermes runs IN the governed
        // workspace ($cwd) and edits files there — same contract as the Dev side.
        $payload = [
            'forge_provider_invocation' => array_values(array_filter([
                'obra_id' => $request['obra_id'] ?? null,
                'role' => $request['role'] ?? null,
                'dispatch_id' => $request['dispatch_id'] ?? null,
                'decision_receipt_id' => $request['decision_receipt_id'] ?? null,
                'decision_receipt_hash' => $request['decision_receipt_hash'] ?? null,
            ], static fn (mixed $value): bool => $value !== null)),
        ];
        $env = $this->processEnv($request);
        if ($env !== []) {
            $payload['forge_provider_invocation_env'] = $env;
        }
        if ($cwd !== null) {
            $payload['workspace'] = $cwd;
            // mode 'danger' → HermesCliProvider passes --yolo (autonomous edit);
            // single-sourced with the Dev side. {@see HermesWorkspaceDefaults}
            $payload['tool_permissions'] = HermesWorkspaceDefaults::toolPermissions($cwd);
        }

        $job = new AiJob([
            'trace_id' => 'atlas-forge:'.((string) ($request['dispatch_id'] ?? $request['obra_id'] ?? Str::uuid())),
            'kind' => 'atlas_forge_provider_invocation',
            'provider' => self::PROVIDER,
            'model' => HermesWorkspaceDefaults::model(),
            'prompt' => $promptText,
            'input_text' => $promptText,
            'timeout_seconds' => $timeout,
            'payload' => $payload,
        ]);

        $started = microtime(true);
        try {
            $result = $provider->run($job, $promptText ?? '');
        } catch (Throwable $e) {
            $durationMs = (int) round((microtime(true) - $started) * 1000);
            $stderr = Str::limit($e->getMessage(), $maxOutputChars, '…');

            return [
                'schema_version' => 'atlas.forge.provider_driver_result.v1',
                'provider' => self::PROVIDER,
                'model' => $model,
                'argv' => [],
                'cwd' => $cwd,
                'configured' => true,
                'changed_files' => [],
                'provider_called' => true,
                'external_provider_call' => true,
                'provider_tokens_spent' => 'unknown',
                'exit_code' => 1,
                'duration_ms' => $durationMs,
                'timeout_seconds' => $timeout,
                'timed_out' => false,
                'stdout_hash' => hash('sha256', ''),
                'stderr_hash' => hash('sha256', $e->getMessage()),
                'stdout_excerpt' => '',
                'stderr_excerpt' => $stderr,
                'process_status' => AtlasForgeProviderProcessRunner::STATUS_FAILED,
                'classification' => null,
                'failure_type' => 'hermes_cli_invocation_threw',
                'blockers' => ['hermes_cli_invocation_threw'],
                'note' => 'Hermes executive runtime lancou excecao durante a invocacao.',
            ];
        }

        return $this->mapResult($result, $request, $model, $cwd, $timeout, $maxOutputChars);
    }

    /**
     * @param  array<string,mixed>  $request
     * @return array<string,string|int|float|false>
     */
    private function processEnv(array $request): array
    {
        $env = $request['env'] ?? null;
        if (! is_array($env)) {
            return [];
        }

        $out = [];
        foreach ($env as $key => $value) {
            if (! is_string($key) || $key === '') {
                continue;
            }
            if (is_string($value) || is_numeric($value) || $value === false) {
                $out[$key] = $value;
            }
        }

        return $out;
    }

    /**
     * Map a Hermes {@see AiProviderResult} into atlas.forge.provider_driver_result.v1,
     * the exact shape the other Forge drivers return.
     *
     * @param  array<string,mixed>  $request
     * @return array<string,mixed>
     */
    private function mapResult(
        AiProviderResult $result,
        array $request,
        ?string $model,
        ?string $cwd,
        int $timeout,
        int $maxOutputChars,
    ): array {
        $stdout = $this->excerpt($result->stdout !== '' ? $result->stdout : $result->output, $maxOutputChars);
        $stderr = $this->excerpt($result->stderr, $maxOutputChars);

        $blockers = $result->ok
            ? []
            : array_values(array_filter([
                is_string($result->errorCode) && $result->errorCode !== '' ? $result->errorCode : 'hermes_cli_invocation_failed',
            ]));

        // Hermes mutates the governed workspace directly but its generic CLI
        // result carries no structured changed_files. Derive them from the
        // post-run git diff in the workspace (tracked + untracked) — the same way
        // the Dev pipeline's workspaceDiff captures a mutating provider's edits —
        // so scope/verification gates actually see them. Fall back to anything the
        // result packet happened to record.
        $changedFiles = $this->changedFilesInWorkspace($cwd);
        if ($changedFiles === []) {
            $changedFiles = $this->changedFilesFromMetadata($result->metadata);
        }

        return [
            'schema_version' => 'atlas.forge.provider_driver_result.v1',
            'provider' => self::PROVIDER,
            'model' => $model,
            'argv' => AiStringListNormalizer::trimmedStrings($result->command),
            'cwd' => $cwd,
            'configured' => true,
            'changed_files' => $changedFiles,
            'provider_called' => true,
            'external_provider_call' => true,
            'provider_tokens_spent' => 'unknown',
            'exit_code' => is_int($result->exitCode) ? $result->exitCode : ($result->ok ? 0 : 1),
            'duration_ms' => $result->durationMs,
            'timeout_seconds' => $timeout,
            'timed_out' => false,
            'stdout_hash' => hash('sha256', $result->stdout !== '' ? $result->stdout : $result->output),
            'stderr_hash' => hash('sha256', $result->stderr),
            'stdout_excerpt' => $stdout,
            'stderr_excerpt' => $stderr,
            'process_status' => $result->ok
                ? AtlasForgeProviderProcessRunner::STATUS_COMPLETED
                : AtlasForgeProviderProcessRunner::STATUS_FAILED,
            'classification' => null,
            'failure_type' => $result->ok ? null : (is_string($result->errorCode) ? $result->errorCode : null),
            'blockers' => $blockers,
            'note' => $result->ok
                ? 'Hermes executive runtime finished (governed by HermesCliProvider).'
                : 'Hermes executive runtime returned a non-ok result; see blockers.',
        ];
    }

    /**
     * Hermes (like Cursor/MiniMax) edits the worktree directly. Its result
     * packet may record the changed file paths; surface them when present.
     *
     * @param  array<string,mixed>  $metadata
     * @return list<string>
     */
    private function changedFilesFromMetadata(array $metadata): array
    {
        foreach ([
            'hermes_result_packet.changed_files',
            'changed_files',
        ] as $path) {
            $candidate = data_get($metadata, $path);
            if (is_array($candidate)) {
                $list = AiStringListNormalizer::trimmedStrings($candidate);
                if ($list !== []) {
                    return $list;
                }
            }
        }

        return [];
    }

    /**
     * Changed files derived from the post-run git diff in the governed workspace
     * (tracked modifications + untracked additions), the way the Dev pipeline
     * captures a mutating provider's edits. Returns [] when $cwd is unset or not a
     * git repository.
     *
     * @return list<string>
     */
    private function changedFilesInWorkspace(?string $cwd): array
    {
        if ($cwd === null || ! is_dir($cwd)) {
            return [];
        }

        $files = [];
        foreach ([
            ['git', 'diff', '--name-only', '--no-ext-diff'],
            ['git', 'diff', '--cached', '--name-only', '--no-ext-diff'], // staged edits (git add removes them from the unstaged diff + untracked set)
            ['git', 'ls-files', '--others', '--exclude-standard'],
        ] as $argv) {
            $process = new Process($argv, $cwd, null, null, 15.0);
            $process->run();
            if (! $process->isSuccessful() && $process->getExitCode() !== 1) {
                continue;
            }
            foreach (preg_split('/\R/', trim((string) $process->getOutput())) ?: [] as $line) {
                $line = trim($line);
                if ($line !== '') {
                    $files[$line] = true;
                }
            }
        }

        return array_values(array_keys($files));
    }

    private function resolveProvider(): ?AiProvider
    {
        try {
            $provider = $this->providers->get(self::PROVIDER);
        } catch (Throwable) {
            return null;
        }

        return $provider instanceof AiProvider ? $provider : null;
    }

    private function encodePrompt(mixed $prompt): ?string
    {
        if (is_string($prompt) && $prompt !== '') {
            return $prompt;
        }
        if (is_array($prompt)) {
            return (string) json_encode($prompt, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        }

        return null;
    }

    private function excerpt(string $value, int $maxLength): string
    {
        if ($value === '' || strlen($value) <= $maxLength) {
            return $value;
        }

        return substr($value, 0, $maxLength).'…';
    }

    /**
     * @param  array<string,mixed>  $request
     * @param  array<int,string>  $blockers
     * @return array<string,mixed>
     */
    private function blocked(array $request, array $blockers, string $note): array
    {
        return [
            'schema_version' => 'atlas.forge.provider_driver_result.v1',
            'provider' => self::PROVIDER,
            'model' => $request['model'] ?? null,
            'argv' => [],
            'cwd' => $request['cwd'] ?? null,
            'configured' => false,
            'changed_files' => [],
            'provider_called' => false,
            'external_provider_call' => false,
            'provider_tokens_spent' => false,
            'exit_code' => null,
            'duration_ms' => 0,
            'timeout_seconds' => (int) ($request['timeout_seconds'] ?? 600),
            'timed_out' => false,
            'stdout_hash' => hash('sha256', ''),
            'stderr_hash' => hash('sha256', ''),
            'stdout_excerpt' => '',
            'stderr_excerpt' => '',
            'process_status' => AtlasForgeProviderProcessRunner::STATUS_BLOCKED,
            'classification' => null,
            'failure_type' => null,
            'blockers' => array_values(array_unique($blockers)),
            'note' => $note,
        ];
    }
}
