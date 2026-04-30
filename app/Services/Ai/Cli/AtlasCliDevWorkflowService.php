<?php

namespace App\Services\Ai\Cli;

use App\Models\AiTrace;
use Illuminate\Support\Str;

class AtlasCliDevWorkflowService
{
    public function __construct(
        private readonly AtlasCliProviderStrategyService $providers,
        private readonly AtlasCliQualityService $quality,
    ) {}

    /**
     * @return array<string,mixed>
     */
    public function preflight(string $workspace, string $task, ?string $provider = null, bool $critical = false): array
    {
        $workspace = realpath($workspace) ?: $workspace;
        $strategy = $this->providers->recommend('dev', $critical);
        $selectedProvider = $provider ?: (string) $strategy['recommended_provider'];
        $quality = $this->quality->evaluate($workspace);

        return [
            'workspace' => $workspace,
            'task' => $task,
            'selected_provider' => $selectedProvider,
            'provider_strategy' => $strategy,
            'preflight_quality' => $this->quality->compact($quality),
            'can_execute_provider' => (bool) ($strategy['has_online_provider'] ?? false) || $provider !== null,
            'requires_override' => ! (bool) ($strategy['has_online_provider'] ?? false) && $provider === null,
        ];
    }

    /**
     * @return array<string,mixed>
     */
    public function executionPlan(
        string $workspace,
        string $task,
        ?string $provider,
        int $maxIterations = 3,
        string $mode = 'multi_step',
    ): array {
        $workspace = realpath($workspace) ?: $workspace;

        return [
            'plan_id' => (string) Str::orderedUuid(),
            'objective' => $task,
            'workspace' => $workspace,
            'selected_provider' => $provider,
            'mode' => $mode,
            'phases' => ['inspect', 'plan', 'edit', 'test', 'repair', 'review', 'finish'],
            'current_phase' => 'inspect',
            'steps' => [],
            'iterations' => [
                'current' => 0,
                'max' => max(1, min(10, $maxIterations)),
                'reason_if_stopped' => null,
            ],
            'checkpoints' => [],
            'created_at' => now()->toJSON(),
            'updated_at' => now()->toJSON(),
        ];
    }

    /**
     * @param  array<int,string>  $requestedSkills
     * @return array<int,string>
     */
    public function qualityGateSkills(array $requestedSkills, bool $complete, int $maxIterations): array
    {
        $skills = collect($requestedSkills)
            ->filter(fn (mixed $skill): bool => is_scalar($skill) && trim((string) $skill) !== '')
            ->map(fn (mixed $skill): string => Str::of((string) $skill)->lower()->trim()->value())
            ->values();

        if ($complete || $maxIterations > 1) {
            $skills->push('dev-quality-gate');
        }

        return $skills->unique()->values()->all();
    }

    /**
     * @param  array<int,string>  $skills
     * @return array<string,mixed>
     */
    public function qualityGatePolicy(bool $complete, int $maxIterations, array $skills): array
    {
        return [
            'complete_mode' => $complete,
            'max_iterations' => max(1, min(10, $maxIterations)),
            'required_final_status' => $complete ? 'passed' : 'not_failed',
            'auto_skills' => array_values(array_intersect($skills, ['dev-quality-gate'])),
            'procedure' => 'plan_validate_execute',
        ];
    }

    /**
     * @param  array<string,mixed>  $plan
     * @param  array<string,mixed>  $metadata
     * @return array<string,mixed>
     */
    public function markStep(array $plan, string $phase, string $status, array $metadata = []): array
    {
        $steps = array_values((array) ($plan['steps'] ?? []));
        $steps[] = array_filter([
            'id' => 's'.(count($steps) + 1),
            'phase' => $phase,
            'status' => $status,
            'tool' => $metadata['tool'] ?? null,
            'output' => $metadata['output'] ?? null,
            'duration_ms' => $metadata['duration_ms'] ?? null,
            'files' => $metadata['files'] ?? null,
            'trace_id' => $metadata['trace_id'] ?? null,
            'quality_status' => $metadata['quality_status'] ?? null,
            'error' => $metadata['error'] ?? null,
            'created_at' => now()->toJSON(),
        ], fn (mixed $value): bool => $value !== null && $value !== []);

        $plan['steps'] = $steps;
        $plan['current_phase'] = $phase;
        $plan['updated_at'] = now()->toJSON();

        if (isset($metadata['iteration'])) {
            $iterations = is_array($plan['iterations'] ?? null) ? $plan['iterations'] : [];
            $iterations['current'] = (int) $metadata['iteration'];
            $plan['iterations'] = $iterations;
        }

        if (isset($metadata['reason_if_stopped'])) {
            $iterations = is_array($plan['iterations'] ?? null) ? $plan['iterations'] : [];
            $iterations['reason_if_stopped'] = $metadata['reason_if_stopped'];
            $plan['iterations'] = $iterations;
        }

        return $plan;
    }

    /**
     * @param  array<string,mixed>  $plan
     */
    public function persistPlan(?string $traceId, array $plan): void
    {
        if (! $traceId) {
            return;
        }

        $trace = AiTrace::query()->find($traceId);
        if (! $trace) {
            return;
        }

        $metadata = $trace->metadata ?? [];
        $metadata['dev_execution_plan'] = $plan;

        $trace->update(['metadata' => $metadata]);
    }

    /**
     * @return array<int,string>
     */
    public function chatCommand(
        string $task,
        string $workspace,
        ?string $provider,
        string $permission,
        bool $allowWrite,
        bool $autoTest,
        int $timeout,
        bool $stream,
        bool $noRun,
        ?array $devExecutionPlan = null,
        array $skills = [],
        bool $json = false,
    ): array {
        $command = [
            PHP_BINARY,
            'artisan',
            'atlas:ai:chat',
            $task,
            '--dev',
            '--workspace='.$workspace,
            '--permission='.$permission,
            '--timeout='.(string) $timeout,
            '--no-quality-gate',
        ];

        if ($provider) {
            $command[] = '--provider='.$provider;
        }

        if ($allowWrite) {
            $command[] = '--allow-write';
        }

        if ($autoTest) {
            $command[] = '--auto-test';
        }

        if ($stream) {
            $command[] = '--stream';
        }

        if ($noRun) {
            $command[] = '--no-run';
        }

        if ($json) {
            $command[] = '--json';
        }

        if ($devExecutionPlan !== null) {
            $encoded = json_encode($devExecutionPlan, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
            if (is_string($encoded) && $encoded !== '') {
                $command[] = '--dev-plan='.$encoded;
            }
        }

        foreach ($skills as $skill) {
            if (is_scalar($skill) && trim((string) $skill) !== '') {
                $command[] = '--skill='.trim((string) $skill);
            }
        }

        return $command;
    }
}
