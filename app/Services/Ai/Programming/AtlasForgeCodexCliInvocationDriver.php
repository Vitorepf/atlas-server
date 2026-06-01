<?php

declare(strict_types=1);

namespace App\Services\Ai\Programming;

/**
 * Atlas Forge Codex CLI Invocation Driver.
 *
 * Provider: `codex_cli`. Resolves the `codex` binary on PATH, validates auth
 * via OPENAI/CODEX env vars, runs the CLI through the safe process runner.
 *
 * This driver does NOT — and must not — use the host model that is processing
 * the Atlas session as a bypass. It exclusively runs the locally configured
 * `codex` CLI under the allowlist + safe runner.
 */
class AtlasForgeCodexCliInvocationDriver extends AtlasForgeBaseCliInvocationDriver
{
    public const PROVIDER = 'codex_cli';

    public static function focusedUnitTestPath(): string
    {
        return 'tests/Unit/Ai/Programming/AtlasForgeCodexCliInvocationDriverTest.php';
    }

    public function provider(): string
    {
        return self::PROVIDER;
    }

    /** @return list<string> */
    protected function candidateBinaries(): array
    {
        $configured = function_exists('config') ? config('atlas.ai.providers.codex_cli.binary') : null;
        $candidates = ['codex'];
        if (is_string($configured) && trim($configured) !== '') {
            array_unshift($candidates, trim($configured));
        }

        return array_values(array_unique($candidates));
    }

    /** @return list<string> */
    protected function authEnvVars(): array
    {
        return ['OPENAI_API_KEY', 'CODEX_API_KEY'];
    }

    /** @return list<string> */
    protected function modelPrefixes(): array
    {
        return ['gpt-', 'codex', 'o1', 'o3'];
    }

    /**
     * Codex CLI's governed invocation shape is `codex exec ... -`, with the
     * provider-safe prompt passed on stdin. The configured args default to
     * `exec --skip-git-repo-check`; Atlas appends sandbox/model/effort controls.
     *
     * @param  array<string,mixed>  $request
     * @return array<int,string>
     */
    protected function buildArgv(array $request): array
    {
        $binary = $this->resolveBinaryPath() ?? ($this->candidateBinaries()[0] ?? 'codex');
        $configuredArgs = function_exists('config') ? config('atlas.ai.providers.codex_cli.args') : null;
        $args = is_array($configuredArgs) && $configuredArgs !== []
            ? array_values(array_filter(array_map(
                static fn (mixed $arg): string => is_string($arg) ? trim($arg) : '',
                $configuredArgs,
            ), static fn (string $arg): bool => $arg !== ''))
            : ['exec', '--skip-git-repo-check'];
        $argv = [$binary, ...$args];
        $model = is_string($request['model'] ?? null) ? trim((string) $request['model']) : '';
        if ($model !== '') {
            $argv[] = '--model';
            $argv[] = $model;
        }
        $sandbox = is_string($request['sandbox'] ?? null)
            ? trim((string) $request['sandbox'])
            : (string) (function_exists('config') ? config('atlas.ai.providers.codex_cli.sandbox', 'read-only') : 'read-only');
        if ($sandbox !== '') {
            $argv[] = '--sandbox';
            $argv[] = $sandbox;
        }
        $effort = app(\App\Services\Ai\Kernel\Decision\ComputeEffortPolicy::class)->contract(
            requested: $request['compute_effort'] ?? data_get($request, 'compute_effort_contract.atlas_level'),
            provider: $this->provider(),
            context: [
                'flow' => 'programming.atlas_dev',
                'task' => is_string($request['prompt'] ?? null) ? (string) $request['prompt'] : null,
            ],
        );
        $mapping = is_array($effort['provider_mapping'] ?? null) ? $effort['provider_mapping'] : [];
        if (is_string($mapping['value'] ?? null) && $mapping['value'] !== '') {
            $argv[] = '-c';
            $argv[] = 'model_reasoning_effort="'.((string) $mapping['value']).'"';
        }
        if (! in_array('-', $argv, true)) {
            $argv[] = '-';
        }

        return array_values(array_filter($argv, static fn (string $value): bool => $value !== ''));
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
}
