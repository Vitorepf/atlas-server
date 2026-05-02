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
        ]);

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
                'metadata' => ['dry_run' => (bool) ($options['dry_run'] ?? false)],
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
                'metadata' => ['dry_run' => true],
            ]) ?? throw new \RuntimeException('Tool runtime tables are not migrated.');
        }

        $started = now();
        $startedNs = hrtime(true);
        $process = new Process($command, $workspace, AtlasSecurity::processEnv(['CI' => '1'], 'tool'));
        $process->setTimeout((int) $decision['timeout_seconds']);
        $timedOut = false;

        try {
            $process->run();
        } catch (\Throwable $exception) {
            $timedOut = str_contains(strtolower($exception->getMessage()), 'timed out');
        }

        $durationMs = (int) ((hrtime(true) - $startedNs) / 1_000_000);
        $stdout = AtlasSecurity::redactString($process->getOutput());
        $stderr = AtlasSecurity::redactString($process->getErrorOutput());
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
        ]) ?? throw new \RuntimeException('Tool runtime tables are not migrated.');

        $this->evidence->attachText($run, 'stdout', 'stdout.txt', mb_substr($stdout, 0, (int) ($options['output_limit'] ?? 12000)));
        $this->evidence->attachText($run, 'stderr', 'stderr.txt', mb_substr($stderr, 0, (int) ($options['output_limit'] ?? 12000)));

        return $run->refresh();
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
