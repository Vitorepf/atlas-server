<?php

namespace App\Services\Engineering\EngineeringHarness;

use App\Models\AiTrace;
use App\Models\AtlasEngineeringControlResult;
use App\Models\AtlasEngineeringPatchArtifact;
use App\Models\AtlasEngineeringRun;
use App\Models\AtlasEngineeringRunAttempt;
use App\Models\AtlasTask;
use App\Services\Ai\Memory\AtlasMemoryRegistryService;
use App\Services\Ai\FairClaudePolicy;
use App\Services\Ai\Kernel\Evidence\AtlasEvidenceLedger;
use App\Services\Ai\Kernel\Evidence\LedgerEventType;
use App\Services\Ai\WorkspaceIntelligence\AtlasWorkspaceIntelligenceExecutionGateService;
use App\Services\Ai\WorkspaceIntelligence\AtlasWorkspacePathResolverService;
use App\Services\Ai\Support\DatabaseTableAvailability;
use App\Services\Tools\AtlasToolGateService;
use App\Support\AtlasPhpBinary;
use App\Support\AtlasSecurity;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Str;
use Symfony\Component\Process\Exception\ProcessTimedOutException;
use Symfony\Component\Process\Process;
use App\Services\Engineering\EngineeringControlRegistryService;
use App\Services\Engineering\EngineeringProviderRuntimeService;
use App\Services\Engineering\EngineeringRunArtifactService;
use App\Services\Engineering\EngineeringWorkspaceService;
use App\Services\Engineering\EngineeringReviewFindingService;
use App\Services\Engineering\EngineeringPatchArtifactService;
use App\Services\Engineering\EngineeringTaskContractService;
use App\Services\Engineering\EngineeringBlueprintService;
use App\Services\Engineering\EngineeringBlueprintSnapshotService;
use App\Services\Engineering\EngineeringHarnessabilityService;
use App\Services\Engineering\EngineeringModelPolicyService;
use App\Services\Engineering\EngineeringContextPackService;
use App\Services\Engineering\EngineeringDockerHarnessService;
use App\Services\Engineering\EngineeringTestMatrixService;
use App\Services\Engineering\EngineeringRunScoringService;
use App\Services\Engineering\EngineeringHarnessRunnerInput;

class HarnessProviderSection
{
    public function __construct(
        private readonly HarnessRunnerSupport $support,
        private readonly EngineeringProviderRuntimeService $providerRuntimes,
    ) {}

    /**
     * @param  array<string,mixed>  $providerOptions
     * @param  array<string,mixed>  $workspacePlan
     * @param  array<string,mixed>  $providerRuntimePlan
     * @return array<string,mixed>
     */
    public function runProvider(AtlasTask $task, string $workspace, array $providerOptions, array $workspacePlan, array $providerRuntimePlan): array
    {
        if (($providerRuntimePlan['runtime'] ?? null) === 'docker' && ($providerRuntimePlan['status'] ?? null) !== 'ready') {
            $reason = (string) ($providerRuntimePlan['fallback_reason'] ?? 'provider_runtime_unavailable');

            return [
                'exit_code' => 1,
                'stdout' => '',
                'stderr' => 'Provider Docker runtime unavailable: '.$reason,
                'trace_id' => null,
                'decoded' => null,
                'runtime' => 'docker',
                'provider_runtime' => $this->support->compactProviderRuntimePlan($providerRuntimePlan),
            ];
        }

        $hostCommand = [
            AtlasPhpBinary::path(),
            base_path('artisan'),
            'atlas:cli:dev',
            '--task-id='.$task->id,
            '--workspace='.$workspace,
            '--json',
            '--no-progress',
            '--no-notify',
        ];

        if (is_string($providerOptions['provider'] ?? null) && $providerOptions['provider'] !== '') {
            $hostCommand[] = '--provider='.$providerOptions['provider'];
        }

        if (is_string($providerOptions['model'] ?? null) && trim((string) $providerOptions['model']) !== '') {
            $hostCommand[] = '--model='.trim((string) $providerOptions['model']);
        }

        $hostCommand[] = '--max-iterations='.(string) ($providerOptions['max_attempts'] ?? 1);

        if ((bool) ($providerOptions['critical'] ?? false)) {
            $hostCommand[] = '--critical';
        }

        $fairMode = is_array($providerOptions['fair_mode'] ?? null) ? $providerOptions['fair_mode'] : [];
        if ((bool) ($fairMode['claude_only'] ?? false)) {
            $hostCommand[] = '--claude-only';
        }
        if ((bool) ($fairMode['single_provider'] ?? false)) {
            $hostCommand[] = '--single-provider';
        }
        if ((bool) ($fairMode['no_decide'] ?? false)) {
            $hostCommand[] = '--no-decide';
        }
        if ((bool) ($fairMode['fallback_disabled'] ?? false)) {
            $hostCommand[] = '--fallback-disabled';
        }

        $permission = (string) ($providerOptions['permission'] ?? 'auto');
        $hostCommand[] = '--permission='.$permission;
        if (in_array($permission, ['write', 'danger'], true)) {
            $hostCommand[] = '--allow-write';
        }
        if ($permission === 'danger') {
            $hostCommand[] = '--dangerously-allow-all';
            $hostCommand[] = '--allow-unsandboxed';
        }

        $timeoutSeconds = max(60, (int) ($providerOptions['timeout_seconds'] ?? 1800));
        $hostCommand[] = '--timeout='.$timeoutSeconds;

        $runtimeCommand = $this->providerRuntimes->command($hostCommand, $workspacePlan, $providerRuntimePlan);
        $stdout = '';
        $stderr = '';
        $exitCode = 1;
        $timedOut = false;
        $startedAt = microtime(true);

        try {
            $process = new Process($runtimeCommand['command'], $runtimeCommand['cwd'], AtlasSecurity::processEnv([
                'PYTHONDONTWRITEBYTECODE' => '1',
            ], 'provider_runner'));
            $process->setTimeout($timeoutSeconds);
            $process->run();

            $exitCode = $process->getExitCode() ?? 1;
            $stdout = AtlasSecurity::redactString($process->getOutput());
            $stderr = AtlasSecurity::redactString($process->getErrorOutput());
        } catch (ProcessTimedOutException $exception) {
            $timedOut = true;
            $stderr = AtlasSecurity::redactString($exception->getMessage());
        } catch (\Throwable $exception) {
            $stderr = AtlasSecurity::redactString($exception->getMessage());
        }

        $decoded = json_decode($stdout, true);
        $traceId = is_array($decoded) ? $this->providerTraceId(['decoded' => $decoded]) : null;

        return [
            'exit_code' => $exitCode,
            'stdout' => $stdout,
            'stderr' => $stderr,
            'duration_ms' => max(0, (int) round((microtime(true) - $startedAt) * 1000)),
            'timed_out' => $timedOut,
            'timeout_seconds' => $timeoutSeconds,
            'trace_id' => is_string($traceId) && $traceId !== '' ? $traceId : null,
            'decoded' => is_array($decoded) ? $decoded : null,
            'runtime' => $runtimeCommand['runtime'],
            'command' => AtlasSecurity::redactCommand($runtimeCommand['command']),
            'command_display' => $runtimeCommand['command_display'],
            'provider_runtime' => $this->support->compactProviderRuntimePlan($providerRuntimePlan),
        ];
    }

    /**
     * @param  array<string,mixed>  $providerRun
     */
    public function providerTraceId(array $providerRun): ?string
    {
        foreach ([
            data_get($providerRun, 'decoded.trace_id'),
            data_get($providerRun, 'decoded.provider_runs.0.trace_id'),
            data_get($providerRun, 'trace_id'),
            data_get($providerRun, 'decoded.programming_result.trace_id'),
        ] as $candidate) {
            if (is_string($candidate) && trim($candidate) !== '') {
                return trim($candidate);
            }
        }

        return null;
    }

    /**
     * @param  array<string,mixed>  $providerRun
     * @return Collection<int,array<string,mixed>>
     */
    public function providerRunsFromTrace(array $providerRun): Collection
    {
        $traceId = $this->support->uuidOrNull($this->providerTraceId($providerRun));
        if ($traceId === null || ! DatabaseTableAvailability::all(['ai_traces', 'ai_jobs'])) {
            return collect();
        }

        $trace = AiTrace::query()
            ->with(['jobs' => fn ($query) => $query->orderBy('created_at')->orderBy('id')])
            ->find($traceId);

        if (! $trace) {
            return collect();
        }

        $jobs = $trace->jobs;
        if ($jobs->isEmpty()) {
            return collect([[
                'iteration' => 1,
                'trace_id' => $trace->id,
                'provider' => $trace->provider,
                'model' => $trace->model,
                'exit_code' => $trace->status === 'succeeded' ? 0 : 1,
                'stdout' => $trace->response_text,
                'stderr' => null,
                'programming_repair' => data_get($trace->metadata, 'programming_repair'),
            ]]);
        }

        $totalJobs = $jobs->count();

        return $jobs->values()->map(function ($job, int $index) use ($trace, $totalJobs): array {
            $iteration = (int) data_get($job->metadata, 'programming_repair_iteration', $index + 1);
            $status = (string) $job->status;

            return [
                'iteration' => max(1, $iteration),
                'trace_id' => $trace->id,
                'provider' => $job->provider ?: $trace->provider,
                'model' => $job->model ?: $trace->model,
                'exit_code' => in_array($status, ['succeeded'], true) ? 0 : 1,
                'stdout' => $job->result_text ?: ($index === $totalJobs - 1 ? $trace->response_text : null),
                'stderr' => $job->error_message,
                'programming_repair' => data_get($job->metadata, 'programming_repair') ?: data_get($trace->metadata, 'programming_repair'),
            ];
        });
    }
}
