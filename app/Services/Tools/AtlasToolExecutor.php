<?php

namespace App\Services\Tools;

use App\Models\AtlasToolRun;
use App\Support\AtlasSecurity;
use Illuminate\Support\Facades\File;
use Symfony\Component\Process\Process;

class AtlasToolExecutor
{
    public function __construct(
        private readonly AtlasToolRegistryService $registry,
        private readonly AtlasToolPolicyEngine $policy,
        private readonly AtlasToolEvidenceStore $evidence,
    ) {}

    /**
     * @param  array<int,string>  $command
     * @param  array<string,mixed>  $options
     */
    public function execute(string $toolSlug, string $workspace, array $command, array $options = []): AtlasToolRun
    {
        $workspace = $this->safeWorkspace($workspace);
        $definition = $this->registry->definition($toolSlug);

        if (! $definition) {
            throw new \InvalidArgumentException("Tool [{$toolSlug}] is not registered.");
        }

        $decision = $this->policy->decide($definition, [
            'workspace' => $workspace,
            'required' => (bool) ($options['required'] ?? false),
            'dry_run' => (bool) ($options['dry_run'] ?? false),
            'network_allowed' => (bool) ($options['network_allowed'] ?? false),
            'approved' => (bool) ($options['approved'] ?? false),
            'max_execution_tier' => $options['max_execution_tier'] ?? null,
            'sandbox_mode' => $options['sandbox_mode'] ?? null,
            'privacy_level' => $options['privacy_level'] ?? null,
            'task_type' => $options['task_type'] ?? null,
            'requires_provider_safe' => $options['requires_provider_safe'] ?? null,
        ]);
        $safeEnv = $this->safeEnv((array) ($options['env'] ?? []));
        $outputLimit = $this->outputLimit($options['output_limit'] ?? null);

        if (! $decision['allowed']) {
            return $this->evidence->recordExternalToolResult($toolSlug, $workspace, [
                'status' => (string) $decision['decision'],
                'required' => (bool) ($options['required'] ?? false),
                'failure_policy' => $decision['failure_policy'],
                'policy_decision' => $decision['decision'],
                'policy_decision_json' => $decision,
                'command' => $command,
                'duration_ms' => 0,
                'findings' => [],
            ], [
                'surface' => $options['surface'] ?? 'cli',
                'source' => 'atlas_tool_executor_policy',
                'metadata' => [
                    'dry_run' => (bool) ($options['dry_run'] ?? false),
                    'recipe' => $options['recipe'] ?? null,
                    'env_keys' => array_keys($safeEnv),
                    'output_limit' => $outputLimit,
                ],
            ]) ?? throw new \RuntimeException('Tool runtime tables are not migrated.');
        }

        $this->assertSafeCommand($command, $workspace);

        if ((bool) ($options['dry_run'] ?? false)) {
            return $this->evidence->recordExternalToolResult($toolSlug, $workspace, [
                'status' => 'skipped',
                'required' => (bool) ($options['required'] ?? false),
                'failure_policy' => $decision['failure_policy'],
                'policy_decision' => 'skipped',
                'policy_decision_json' => [...$decision, 'decision' => 'skipped', 'reason' => 'dry_run'],
                'command' => $command,
                'duration_ms' => 0,
                'findings' => [],
            ], [
                'surface' => $options['surface'] ?? 'cli',
                'source' => 'atlas_tool_executor_dry_run',
                'metadata' => [
                    'dry_run' => true,
                    'recipe' => $options['recipe'] ?? null,
                    'env_keys' => array_keys($safeEnv),
                    'output_limit' => $outputLimit,
                ],
            ]) ?? throw new \RuntimeException('Tool runtime tables are not migrated.');
        }

        $started = now();
        $startedNs = hrtime(true);
        $process = new Process($command, $workspace, AtlasSecurity::processEnv([
            'CI' => '1',
            ...$safeEnv,
        ], 'tool'));
        $process->setTimeout((int) $decision['timeout_seconds']);
        $timedOut = false;

        try {
            $process->run();
        } catch (\Throwable $exception) {
            $timedOut = str_contains(strtolower($exception->getMessage()), 'timed out');
        }

        $durationMs = (int) ((hrtime(true) - $startedNs) / 1_000_000);
        $rawStdout = AtlasSecurity::redactString($process->getOutput());
        $rawStderr = AtlasSecurity::redactString($process->getErrorOutput());
        $stdout = mb_substr($rawStdout, 0, $outputLimit);
        $stderr = mb_substr($rawStderr, 0, $outputLimit);
        $stdoutTruncated = mb_strlen($rawStdout) > $outputLimit;
        $stderrTruncated = mb_strlen($rawStderr) > $outputLimit;
        $exitCode = $timedOut ? null : ($process->getExitCode() ?? 1);
        $status = $timedOut ? 'timeout' : ($exitCode === 0 ? 'passed' : 'failed');

        $run = $this->evidence->recordExternalToolResult($toolSlug, $workspace, [
            'status' => $status,
            'required' => (bool) ($options['required'] ?? false),
            'failure_policy' => $decision['failure_policy'],
            'policy_decision' => 'allowed',
            'policy_decision_json' => $decision,
            'command' => $command,
            'exit_code' => $exitCode,
            'duration_ms' => $durationMs,
            'stdout' => $stdout,
            'stderr' => $stderr,
        ], [
            'surface' => $options['surface'] ?? 'cli',
            'source' => 'atlas_tool_executor',
            'started_at' => $started,
            'finished_at' => now(),
            'metadata' => [
                'env_keys' => array_keys($safeEnv),
                'output_limit' => $outputLimit,
                'recipe' => $options['recipe'] ?? null,
                'stdout_truncated' => $stdoutTruncated,
                'stderr_truncated' => $stderrTruncated,
            ],
        ]) ?? throw new \RuntimeException('Tool runtime tables are not migrated.');

        $this->evidence->attachText($run, 'stdout', 'stdout.txt', $stdout);
        $this->evidence->attachText($run, 'stderr', 'stderr.txt', $stderr);

        return $run->refresh();
    }

    /**
     * @param  array<string,mixed>  $options
     */
    public function executeRecipe(string $toolSlug, string $recipeName, string $workspace, array $options = []): AtlasToolRun
    {
        $workspace = $this->safeWorkspace($workspace);
        $catalog = $this->registry->commandCatalog($toolSlug, $workspace);
        if (! $catalog) {
            throw new \InvalidArgumentException("Tool [{$toolSlug}] is not registered.");
        }

        $recipe = collect($catalog['commands'] ?? [])->firstWhere('name', $recipeName);
        if (! is_array($recipe)) {
            throw new \InvalidArgumentException("Tool recipe [{$recipeName}] is not registered for [{$toolSlug}].");
        }

        return $this->execute($toolSlug, $workspace, (array) ($recipe['command'] ?? []), [
            'dry_run' => array_key_exists('dry_run', $options) ? (bool) $options['dry_run'] : (bool) ($recipe['dry_run_default'] ?? true),
            'approved' => (bool) ($options['approved'] ?? false),
            'required' => (bool) ($options['required'] ?? false),
            'network_allowed' => (bool) ($recipe['network_allowed'] ?? false),
            'max_execution_tier' => $recipe['max_execution_tier'] ?? null,
            'sandbox_mode' => $recipe['sandbox_mode'] ?? null,
            'privacy_level' => $recipe['privacy_level'] ?? null,
            'task_type' => $recipe['task_type'] ?? null,
            'requires_provider_safe' => (bool) ($recipe['requires_provider_safe'] ?? false),
            'env' => $options['env'] ?? [],
            'output_limit' => $options['output_limit'] ?? null,
            'surface' => $options['surface'] ?? 'cli',
            'recipe' => $recipeName,
        ]);
    }

    private function safeWorkspace(string $workspace): string
    {
        $real = realpath($workspace);
        if (! $real || ! is_dir($real)) {
            throw new \InvalidArgumentException('Workspace does not exist.');
        }

        return $real;
    }

    /**
     * @param  array<mixed,mixed>  $env
     * @return array<string,string>
     */
    private function safeEnv(array $env): array
    {
        $safe = [];

        foreach ($env as $key => $value) {
            if (is_int($key) && is_string($value)) {
                [$key, $value] = $this->envPair($value);
            }

            if (! is_string($key) || is_array($value) || is_object($value)) {
                throw new \InvalidArgumentException('Tool env must be KEY=VALUE strings or a string map.');
            }

            $key = trim($key);
            if (! preg_match('/^[A-Z_][A-Z0-9_]{0,63}$/', $key)) {
                throw new \InvalidArgumentException('Unsafe env key rejected.');
            }

            if ($this->isSensitiveEnvKey($key)) {
                throw new \InvalidArgumentException('Sensitive env key rejected.');
            }

            $stringValue = (string) $value;
            if (str_contains($stringValue, "\0") || mb_strlen($stringValue) > 2000) {
                throw new \InvalidArgumentException('Unsafe env value rejected.');
            }

            $safe[$key] = $stringValue;
        }

        if (count($safe) > 20) {
            throw new \InvalidArgumentException('Too many env variables for one tool run.');
        }

        return $safe;
    }

    /**
     * @return array{0:string,1:string}
     */
    private function envPair(string $pair): array
    {
        if (! str_contains($pair, '=')) {
            throw new \InvalidArgumentException('Tool env entries must use KEY=VALUE.');
        }

        [$key, $value] = explode('=', $pair, 2);

        return [$key, $value];
    }

    private function isSensitiveEnvKey(string $key): bool
    {
        return preg_match('/(TOKEN|SECRET|PASSWORD|PASSWD|PWD|COOKIE|CREDENTIAL|PRIVATE_KEY|API_KEY|AUTH|BEARER)/i', $key) === 1;
    }

    private function outputLimit(mixed $value): int
    {
        if ($value === null || $value === '') {
            return 12000;
        }

        if (! is_numeric($value)) {
            throw new \InvalidArgumentException('Output limit must be numeric.');
        }

        return max(1000, min(200000, (int) $value));
    }

    /**
     * @param  array<int,string>  $command
     */
    private function assertSafeCommand(array $command, string $workspace): void
    {
        if ($command === [] || collect($command)->contains(fn (mixed $part): bool => ! is_string($part) || trim($part) === '')) {
            throw new \InvalidArgumentException('Tool command must be a non-empty argv array.');
        }

        foreach ($command as $part) {
            if (str_contains($part, "\0") || str_contains($part, '..'.DIRECTORY_SEPARATOR)) {
                throw new \InvalidArgumentException('Unsafe command argument rejected.');
            }
        }

        $binary = $command[0];
        if (str_contains($binary, '/')) {
            $real = realpath($workspace.'/'.ltrim($binary, './'));
            if (! $real || ! str_starts_with($real, $workspace.DIRECTORY_SEPARATOR) || ! File::isFile($real)) {
                throw new \InvalidArgumentException('Command binary must resolve inside the workspace or use PATH lookup.');
            }
        }
    }
}
