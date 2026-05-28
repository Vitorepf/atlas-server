<?php

declare(strict_types=1);

namespace App\Services\Ai\Programming;

use Symfony\Component\Process\Process;

/**
 * Atlas Forge Cursor CLI Invocation Driver.
 *
 * Provider: `cursor_cli`. This is intentionally separate from `cursor_sdk`:
 * it targets the locally authenticated Cursor Agent CLI and account usage
 * pools, while keeping Atlas as the authority for routing, scope and evidence.
 */
class AtlasForgeCursorCliInvocationDriver extends AtlasForgeBaseCliInvocationDriver
{
    public const PROVIDER = 'cursor_cli';

    public function provider(): string
    {
        return self::PROVIDER;
    }

    /**
     * Cursor CLI can be authenticated by local login. We therefore do not
     * require CURSOR_API_KEY unless `auth_mode=api_key` is explicitly set.
     *
     * @return array<string,mixed>
     */
    public function configured(): array
    {
        $config = $this->cursorConfig();
        $enabled = (bool) ($config['enabled'] ?? false);
        $binaryPath = $this->resolveBinaryPath();
        $authState = $this->cursorAuthState();
        $blockers = [];

        if (! $enabled) {
            $blockers[] = 'cursor_cli_disabled';
        }
        if ($binaryPath === null) {
            $blockers[] = 'cursor_cli_binary_missing';
        }
        if ($authState === 'missing') {
            $blockers[] = 'cursor_cli_auth_required';
        }

        return [
            'schema_version' => 'atlas.forge.provider_driver_config_status.v1',
            'provider' => self::PROVIDER,
            'configured' => $enabled && $binaryPath !== null && $authState !== 'missing',
            'runtime_present' => $binaryPath !== null,
            'binary_path' => $binaryPath,
            'auth_state' => $authState,
            'auth_mode' => (string) ($config['auth_mode'] ?? 'local_login'),
            'model_prefixes' => $this->modelPrefixes(),
            'allowed_binaries' => $this->candidateBinaries(),
            'blockers' => $blockers,
            'external_provider_call_possible' => true,
            'provider_tokens_may_be_spent' => true,
            'billing_mode' => (string) ($config['billing_mode'] ?? 'cursor_account_cli_pool'),
            'quota_bucket' => (string) ($config['quota_bucket'] ?? 'cursor_account_composer_pool'),
            'runtime_boundary' => [
                'owner' => 'laravel_kernel',
                'runtime_family' => 'cursor_cli',
                'authority' => 'executor_only_after_decision_receipt',
                'auth_verification' => $authState === 'local_login_unverified' ? 'deferred_to_runtime' : 'env_or_runtime',
                'forbidden_authorities' => [
                    'choose_provider_or_model',
                    'choose_domain_or_flow',
                    'write_memory_directly',
                    'mutate_policy',
                    'bypass_evidence_ledger',
                    'promote_completion_claim',
                ],
            ],
            'note' => 'Config status: no model request is sent. Local Cursor login is verified at runtime.',
        ];
    }

    /**
     * @param  array<string,mixed>  $request
     * @return array<string,mixed>
     */
    public function plan(array $request): array
    {
        $plan = parent::plan($request);
        $blockers = array_values(array_unique(array_merge(
            (array) ($plan['blockers'] ?? []),
            $this->manifestBlockers($request),
        )));

        $plan['blockers'] = $blockers;
        $plan['plan_safe'] = $blockers === [];
        $plan['billing_mode'] = (string) ($this->cursorConfig()['billing_mode'] ?? 'cursor_account_cli_pool');
        $plan['quota_bucket'] = (string) ($this->cursorConfig()['quota_bucket'] ?? 'cursor_account_composer_pool');
        $plan['cli_request_schema'] = 'atlas.provider.cursor_cli.invocation_request.v1';
        $plan['scope_required'] = true;
        $plan['note'] = 'Plan-only: Cursor CLI was not spawned.';

        return $plan;
    }

    /**
     * @param  array<string,mixed>  $request
     * @return array<string,mixed>
     */
    public function invoke(array $request): array
    {
        $plan = $this->plan($request);
        if (($plan['blockers'] ?? []) !== []) {
            return $this->cursorBlocked($request, (array) $plan['blockers'], 'Cursor CLI stayed fail-closed before runtime dispatch.');
        }

        $workspace = $this->workspacePath($request);
        $allowed = $this->allowedFiles($request);
        $forbidden = $this->forbiddenFiles($request);
        $beforeAllowed = $this->snapshotFiles($workspace, $allowed);
        $beforeForbidden = $this->snapshotFiles($workspace, $forbidden);
        $beforeGit = $this->gitStatus($workspace);

        $result = parent::invoke($request);

        $afterAllowed = $this->snapshotFiles($workspace, $allowed);
        $afterForbidden = $this->snapshotFiles($workspace, $forbidden);
        $afterGit = $this->gitStatus($workspace);
        $allowedChanged = $this->changedFiles($beforeAllowed, $afterAllowed);
        $forbiddenChanged = $this->changedFiles($beforeForbidden, $afterForbidden);
        $gitChanged = $this->gitStatusDelta($beforeGit, $afterGit);
        $scopeViolations = array_values(array_filter(
            $gitChanged,
            fn (string $path): bool => ! $this->allowedMatch($path, $allowed),
        ));

        $scopeBlockers = [];
        if ($forbiddenChanged !== []) {
            $scopeBlockers[] = 'cursor_cli_forbidden_file_changed';
        }
        if ($scopeViolations !== []) {
            $scopeBlockers[] = 'cursor_cli_scope_violation';
        }

        $blockers = array_values(array_unique(array_merge((array) ($result['blockers'] ?? []), $scopeBlockers)));
        $changedFiles = array_values(array_unique(array_merge(
            $allowedChanged,
            array_values(array_filter($gitChanged, fn (string $path): bool => $this->allowedMatch($path, $allowed))),
        )));
        sort($changedFiles);

        $result['changed_files'] = $changedFiles;
        $result['forbidden_changed_files'] = $forbiddenChanged;
        $result['scope_violations'] = $scopeViolations;
        $result['billing_mode'] = (string) ($this->cursorConfig()['billing_mode'] ?? 'cursor_account_cli_pool');
        $result['quota_bucket'] = (string) ($this->cursorConfig()['quota_bucket'] ?? 'cursor_account_composer_pool');
        $result['blockers'] = $blockers;
        if ($scopeBlockers !== [] && ($result['failure_type'] ?? null) === null) {
            $result['failure_type'] = $scopeBlockers[0];
            $result['classification'] = [
                'schema_version' => 'atlas.forge.provider_invocation_failure_classification.v1',
                'failure_type' => $scopeBlockers[0],
                'confidence' => 'high',
                'provider' => self::PROVIDER,
            ];
            $result['process_status'] = AtlasForgeProviderProcessRunner::STATUS_FAILED;
        }
        $result['performance_signal'] = [
            'schema_version' => 'atlas.provider.cursor_cli.performance_signal.v1',
            'provider' => self::PROVIDER,
            'model_observed' => $request['model'] ?? null,
            'status' => $blockers === [] ? 'succeeded' : 'failed',
            'changed_files_count' => count($changedFiles),
            'required_gates_passed' => false,
            'completion_claim_promoted' => false,
            'routing_effect' => 'none',
            'advisory_only' => true,
            'blockers' => $blockers,
        ];
        $result['note'] = $scopeBlockers === []
            ? 'Cursor CLI driver finished under Atlas scope verification.'
            : 'Cursor CLI returned, but Atlas scope verification blocked promotion.';

        return $result;
    }

    /**
     * @param  array<string,mixed>  $request
     * @return array<int,string>
     */
    protected function buildArgv(array $request): array
    {
        $config = $this->cursorConfig();
        $binary = $this->resolveBinaryPath() ?? ($this->candidateBinaries()[0] ?? 'cursor-agent');
        $argv = [$binary, '--print'];
        $argv[] = '--trust';
        $outputFormat = trim((string) ($config['output_format'] ?? 'stream-json'));
        if ($outputFormat !== '') {
            $argv[] = '--output-format';
            $argv[] = $outputFormat;
        }
        $model = is_string($request['model'] ?? null) ? trim((string) $request['model']) : '';
        if ($model !== '') {
            $argv[] = '--model';
            $argv[] = $model;
        }
        if ((bool) ($config['force'] ?? false)) {
            $argv[] = '--force';
        }
        return $argv;
    }

    /** @return list<string> */
    protected function candidateBinaries(): array
    {
        $config = $this->cursorConfig();
        $candidates = (array) ($config['binary_candidates'] ?? []);
        array_unshift($candidates, (string) ($config['binary'] ?? 'cursor-agent'));

        return array_values(array_unique(array_filter(array_map(
            fn (mixed $candidate): string => is_string($candidate) ? trim($candidate) : '',
            $candidates,
        ), fn (string $candidate): bool => $candidate !== '')));
    }

    /** @return list<string> */
    protected function authEnvVars(): array
    {
        return array_values(array_filter((array) ($this->cursorConfig()['auth_env'] ?? ['CURSOR_API_KEY']), 'is_string'));
    }

    /** @return list<string> */
    protected function modelPrefixes(): array
    {
        return ['auto', 'composer', 'cursor', 'gpt-', 'claude-', 'gemini-', 'opus', 'sonnet'];
    }

    protected function resolveBinaryPath(): ?string
    {
        foreach ($this->candidateBinaries() as $candidate) {
            $candidate = trim($candidate);
            if ($candidate !== '' && str_contains($candidate, '/') && is_file($candidate) && is_executable($candidate)) {
                return $candidate;
            }
            $path = $this->which($candidate);
            if ($path !== null) {
                return $path;
            }
        }

        return null;
    }

    protected function encodePrompt(mixed $prompt): ?string
    {
        $payload = [
            'schema_version' => 'atlas.provider.cursor_cli.prompt.v1',
            'provider' => self::PROVIDER,
            'atlas_contract' => [
                'provider_authority' => 'atlas_decide',
                'completion_claim_allowed' => false,
                'decision_receipt_required' => true,
            ],
            'instructions' => [
                'You are executing inside Atlas as Cursor CLI, not deciding routing or completion.',
                'Only edit allowed_files from scope_contract.',
                'Do not edit forbidden_files or change Atlas policy/memory.',
                'Return concise evidence: changed files, tests, blockers.',
            ],
            'prompt' => $prompt,
        ];

        return (string) json_encode($payload, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    }

    /**
     * @return array<string,mixed>
     */
    private function cursorConfig(): array
    {
        return (array) config('atlas.ai.providers.cursor_cli', []);
    }

    private function cursorAuthState(): string
    {
        $authMode = (string) ($this->cursorConfig()['auth_mode'] ?? 'local_login');
        if ($authMode !== 'api_key') {
            return 'local_login_unverified';
        }

        foreach ($this->authEnvVars() as $var) {
            $value = getenv($var);
            if (is_string($value) && trim($value) !== '') {
                return 'api_key_configured';
            }
        }

        return 'missing';
    }

    /**
     * @param  array<string,mixed>  $request
     * @return array<string,string|false>|null
     */
    protected function processEnv(array $request): ?array
    {
        if ((string) ($this->cursorConfig()['auth_mode'] ?? 'local_login') === 'api_key') {
            return null;
        }

        $env = [];
        foreach ($this->authEnvVars() as $var) {
            $env[$var] = false;
        }

        return $env === [] ? null : $env;
    }

    /**
     * @param  array<string,mixed>  $request
     * @return list<string>
     */
    private function manifestBlockers(array $request): array
    {
        $blockers = [];
        if ($this->workspacePath($request) === null) {
            $blockers[] = 'cursor_cli_workspace_required';
        }
        if (! is_string($request['model'] ?? null) || trim((string) $request['model']) === '') {
            $blockers[] = 'cursor_cli_model_required';
        }
        if (! is_string($request['decision_receipt_id'] ?? data_get($request, 'prompt.decision_receipt_id'))) {
            $blockers[] = 'decision_receipt_required';
        }
        if (! is_string($request['decision_receipt_hash'] ?? data_get($request, 'prompt.decision_receipt_hash'))) {
            $blockers[] = 'decision_receipt_required';
        }
        if ($this->allowedFiles($request) === []) {
            $blockers[] = 'cursor_cli_allowed_files_required';
        }
        if (strtolower(trim((string) ($this->cursorConfig()['output_format'] ?? 'stream-json'))) !== 'stream-json') {
            $blockers[] = 'cursor_cli_output_format_must_be_stream_json';
        }
        if ((bool) ($this->cursorConfig()['force'] ?? false) === true) {
            $blockers[] = 'cursor_cli_force_mode_forbidden';
        }

        return array_values(array_unique($blockers));
    }

    /**
     * @param  array<string,mixed>  $request
     */
    private function workspacePath(array $request): ?string
    {
        $value = $request['cwd'] ?? data_get($request, 'workspace.path');
        if (! is_string($value) || trim($value) === '') {
            return null;
        }
        $path = realpath($value);

        return is_string($path) && is_dir($path) ? $path : null;
    }

    /** @param array<string,mixed> $request @return list<string> */
    private function allowedFiles(array $request): array
    {
        return $this->stringList(data_get($request, 'prompt.scope_contract.allowed_files', []));
    }

    /** @param array<string,mixed> $request @return list<string> */
    private function forbiddenFiles(array $request): array
    {
        return $this->stringList(data_get($request, 'prompt.scope_contract.forbidden_files', []));
    }

    /** @return list<string> */
    private function stringList(mixed $value): array
    {
        if (! is_array($value)) {
            return [];
        }

        return array_values(array_filter(array_map(
            fn (mixed $item): string => is_string($item) ? $this->normalizeRel($item) : '',
            $value,
        ), fn (string $item): bool => $item !== ''));
    }

    /**
     * @param  list<string>  $files
     * @return array<string,string|null>
     */
    private function snapshotFiles(?string $workspace, array $files): array
    {
        if ($workspace === null) {
            return [];
        }
        $snapshot = [];
        foreach ($files as $file) {
            $path = $this->resolveInWorkspace($workspace, $file);
            $hash = is_file($path) ? hash_file('sha256', $path) : null;
            $snapshot[$file] = is_string($hash) ? $hash : null;
        }

        return $snapshot;
    }

    /** @param array<string,string|null> $before @param array<string,string|null> $after @return list<string> */
    private function changedFiles(array $before, array $after): array
    {
        $changed = [];
        foreach ($after as $file => $hash) {
            if (($before[$file] ?? null) !== $hash) {
                $changed[] = $file;
            }
        }

        return $changed;
    }

    /** @return array<string,string> */
    private function gitStatus(?string $workspace): array
    {
        if ($workspace === null || ! is_dir($workspace.'/.git')) {
            return [];
        }
        $process = new Process(['git', 'status', '--porcelain=v1', '--untracked-files=all'], $workspace, null, null, 10.0);
        $process->run();
        if (! $process->isSuccessful()) {
            return [];
        }
        $status = [];
        foreach (explode("\n", rtrim((string) $process->getOutput(), "\r\n")) as $line) {
            if ($line === '') {
                continue;
            }
            $path = trim(substr($line, 3));
            if (str_contains($path, ' -> ')) {
                $parts = explode(' -> ', $path);
                $path = (string) end($parts);
            }
            $status[$this->normalizeRel($path)] = substr($line, 0, 2);
        }

        return $status;
    }

    /** @param array<string,string> $before @param array<string,string> $after @return list<string> */
    private function gitStatusDelta(array $before, array $after): array
    {
        $changed = [];
        foreach ($after as $path => $status) {
            if (($before[$path] ?? null) !== $status) {
                $changed[] = $path;
            }
        }

        return $changed;
    }

    /** @param list<string> $allowed */
    private function allowedMatch(string $path, array $allowed): bool
    {
        $path = $this->normalizeRel($path);
        foreach ($allowed as $entry) {
            $entry = $this->normalizeRel($entry);
            if ($entry === $path || (str_ends_with($entry, '/') && str_starts_with($path, $entry))) {
                return true;
            }
        }

        return false;
    }

    private function resolveInWorkspace(string $workspace, string $rel): string
    {
        $root = rtrim(realpath($workspace) ?: $workspace, DIRECTORY_SEPARATOR);
        $path = $root.DIRECTORY_SEPARATOR.$this->normalizeRel($rel);
        $dir = realpath(dirname($path)) ?: dirname($path);
        if ($dir !== $root && ! str_starts_with($dir, $root.DIRECTORY_SEPARATOR)) {
            return $root.DIRECTORY_SEPARATOR.'__atlas_path_escape__';
        }

        return $path;
    }

    private function normalizeRel(string $value): string
    {
        return ltrim(str_replace('\\', '/', trim($value)), './');
    }

    /**
     * @param  array<string,mixed>  $request
     * @param  list<string>  $blockers
     * @return array<string,mixed>
     */
    private function cursorBlocked(array $request, array $blockers, string $note): array
    {
        return [
            'schema_version' => 'atlas.forge.provider_driver_result.v1',
            'provider' => self::PROVIDER,
            'model' => $request['model'] ?? null,
            'argv' => [],
            'cwd' => $request['cwd'] ?? null,
            'configured' => (bool) ($this->configured()['configured'] ?? false),
            'provider_called' => false,
            'external_provider_call' => false,
            'provider_tokens_spent' => false,
            'exit_code' => null,
            'duration_ms' => 0,
            'timeout_seconds' => (int) ($request['timeout_seconds'] ?? 120),
            'timed_out' => false,
            'stdout_hash' => hash('sha256', ''),
            'stderr_hash' => hash('sha256', ''),
            'stdout_excerpt' => '',
            'stderr_excerpt' => '',
            'process_status' => AtlasForgeProviderProcessRunner::STATUS_BLOCKED,
            'changed_files' => [],
            'forbidden_changed_files' => [],
            'scope_violations' => [],
            'classification' => null,
            'failure_type' => null,
            'blockers' => array_values(array_unique($blockers)),
            'note' => $note,
        ];
    }
}
