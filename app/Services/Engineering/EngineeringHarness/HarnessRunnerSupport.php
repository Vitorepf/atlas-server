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

class HarnessRunnerSupport
{
    public function sourceTestCommand(AtlasEngineeringRun $sourceRun): ?string
    {
        $sourceRun->loadMissing('testRuns');
        $command = $sourceRun->testRuns
            ->map(fn ($testRun): ?string => is_string($testRun->command) ? trim($testRun->command) : null)
            ->first(fn (?string $command): bool => is_string($command) && $command !== '');

        return is_string($command) && $command !== '' ? $command : null;
    }

    public function statusForDecision(string $decision): string
    {
        return match ($decision) {
            'resolved' => 'passed',
            'blocked' => 'blocked',
            'unsafe', 'unresolved' => 'failed',
            default => 'reviewing',
        };
    }

    /**
     * @param  array<string,mixed>  $plan
     * @return array<string,mixed>
     */
    public function compactWorkspacePlan(array $plan): array
    {
        return [
            'mode' => $plan['mode'] ?? null,
            'requested_mode' => $plan['requested_mode'] ?? null,
            'status' => $plan['status'] ?? null,
            'original_workspace_hash' => isset($plan['original_workspace']) ? hash('sha256', (string) $plan['original_workspace']) : null,
            'execution_workspace_hash' => isset($plan['execution_workspace']) ? hash('sha256', (string) $plan['execution_workspace']) : null,
            'repo_root_hash' => isset($plan['repo_root']) ? hash('sha256', (string) $plan['repo_root']) : null,
            'branch' => $plan['branch'] ?? null,
            'head' => $plan['head'] ?? null,
            'dirty_count' => count((array) ($plan['dirty_files'] ?? [])),
            'isolated' => (bool) ($plan['isolated'] ?? false),
            'isolation_type' => $plan['isolation_type'] ?? null,
            'containerized_execution' => (bool) ($plan['containerized_execution'] ?? false),
            'docker' => $this->compactDockerPlan((array) ($plan['docker'] ?? [])),
            'dirty_files_included' => $plan['dirty_files_included'] ?? null,
            'fallback_reason' => $plan['fallback_reason'] ?? null,
        ];
    }

    public function qualityScanMode(mixed $value): string
    {
        $mode = is_scalar($value) ? trim((string) $value) : 'off';

        return in_array($mode, ['auto', 'off', 'required'], true) ? $mode : 'off';
    }

    public function qualityScanProfile(mixed $value): string
    {
        $profile = is_scalar($value) ? trim((string) $value) : 'auto';

        return in_array($profile, ['auto', 'fast', 'standard', 'release', 'deep'], true) ? $profile : 'auto';
    }

    /**
     * @param  array<string,mixed>  $docker
     * @return array<string,mixed>|null
     */
    public function compactDockerPlan(array $docker): ?array
    {
        if ($docker === []) {
            return null;
        }

        return [
            'profile_found' => (bool) ($docker['profile_found'] ?? false),
            'usable' => (bool) ($docker['usable'] ?? false),
            'runtime' => $docker['runtime'] ?? null,
            'docker_available' => (bool) ($docker['docker_available'] ?? false),
            'compose_available' => (bool) ($docker['compose_available'] ?? false),
            'compose_files' => array_values((array) ($docker['compose_files'] ?? [])),
            'selected_compose_file' => $docker['selected_compose_file'] ?? null,
            'dockerfile' => $docker['dockerfile'] ?? null,
            'devcontainer' => $docker['devcontainer'] ?? null,
            'service' => $docker['service'] ?? null,
            'image' => $docker['image'] ?? null,
            'container_workdir' => $docker['container_workdir'] ?? null,
            'cache' => $this->compactDockerCachePlan((array) ($docker['cache'] ?? [])),
            'healthchecks' => $this->compactDockerHealthcheckPlan((array) ($docker['healthchecks'] ?? [])),
            'artifacts' => $this->compactDockerArtifactPlan((array) ($docker['artifacts'] ?? [])),
            'network' => $this->compactDockerNetworkPlan((array) ($docker['network'] ?? [])),
            'unusable_reason' => $docker['unusable_reason'] ?? null,
        ];
    }

    /**
     * @param  array<string,mixed>  $cache
     * @return array<string,mixed>|null
     */
    public function compactDockerCachePlan(array $cache): ?array
    {
        if ($cache === []) {
            return null;
        }

        return [
            'mode' => $cache['mode'] ?? null,
            'enabled' => (bool) ($cache['enabled'] ?? false),
            'root_hash' => isset($cache['root']) ? hash('sha256', (string) $cache['root']) : null,
            'mounts' => collect((array) ($cache['mounts'] ?? []))
                ->filter(fn (mixed $mount): bool => is_array($mount))
                ->map(fn (array $mount): array => [
                    'name' => $mount['name'] ?? null,
                    'host_path_hash' => isset($mount['host_path']) ? hash('sha256', (string) $mount['host_path']) : null,
                    'container_path' => $mount['container_path'] ?? null,
                    'env_keys' => array_keys((array) ($mount['env'] ?? [])),
                    'enabled' => (bool) ($mount['enabled'] ?? false),
                ])
                ->values()
                ->all(),
        ];
    }

    /**
     * @param  array<string,mixed>  $healthchecks
     * @return array<string,mixed>|null
     */
    public function compactDockerHealthcheckPlan(array $healthchecks): ?array
    {
        if ($healthchecks === []) {
            return null;
        }

        return [
            'services' => array_values((array) ($healthchecks['services'] ?? [])),
            'timeout_seconds' => $healthchecks['timeout_seconds'] ?? null,
        ];
    }

    /**
     * @param  array<string,mixed>  $artifacts
     * @return array<string,mixed>|null
     */
    public function compactDockerArtifactPlan(array $artifacts): ?array
    {
        if ($artifacts === []) {
            return null;
        }

        return [
            'paths' => array_values((array) ($artifacts['paths'] ?? [])),
            'max_files' => $artifacts['max_files'] ?? null,
            'max_bytes' => $artifacts['max_bytes'] ?? null,
        ];
    }

    /**
     * @param  array<string,mixed>  $network
     * @return array<string,mixed>|null
     */
    public function compactDockerNetworkPlan(array $network): ?array
    {
        if ($network === []) {
            return null;
        }

        return [
            'mode' => $network['mode'] ?? null,
            'runtime' => $network['runtime'] ?? null,
            'enforced' => (bool) ($network['enforced'] ?? false),
            'required' => (bool) ($network['required'] ?? false),
            'unavailable_reason' => $network['unavailable_reason'] ?? null,
        ];
    }

    /**
     * @param  array<string,mixed>  $healthchecks
     * @return array<string,mixed>
     */
    public function compactDockerHealthchecks(array $healthchecks): array
    {
        return [
            'status' => $healthchecks['status'] ?? null,
            'reason' => $healthchecks['reason'] ?? null,
            'service_count' => count((array) ($healthchecks['services'] ?? [])),
            'services' => collect((array) ($healthchecks['services'] ?? []))
                ->filter(fn (mixed $service): bool => is_array($service) || is_scalar($service))
                ->map(fn (mixed $service): mixed => is_array($service) ? [
                    'service' => $service['service'] ?? null,
                    'ready' => (bool) ($service['ready'] ?? false),
                    'state' => $service['state'] ?? null,
                    'health' => $service['health'] ?? null,
                    'exit_code' => $service['exit_code'] ?? null,
                ] : (string) $service)
                ->values()
                ->all(),
            'timeout_seconds' => $healthchecks['timeout_seconds'] ?? null,
            'started' => (bool) ($healthchecks['started'] ?? false),
        ];
    }

    /**
     * @param  array<string,mixed>  $plan
     * @return array<string,mixed>
     */
    public function compactProviderRuntimePlan(array $plan): array
    {
        return [
            'requested_runtime' => $plan['requested_runtime'] ?? null,
            'runtime' => $plan['runtime'] ?? null,
            'status' => $plan['status'] ?? null,
            'required' => (bool) ($plan['required'] ?? false),
            'fallback_reason' => $plan['fallback_reason'] ?? null,
            'docker_available' => array_key_exists('docker_available', $plan) ? (bool) $plan['docker_available'] : null,
            'compose_available' => array_key_exists('compose_available', $plan) ? (bool) $plan['compose_available'] : null,
            'compose_file_hash' => isset($plan['compose_file']) ? hash('sha256', (string) $plan['compose_file']) : null,
            'service' => $plan['service'] ?? null,
            'service_found' => array_key_exists('service_found', $plan) ? (bool) $plan['service_found'] : null,
            'app_dir' => $plan['app_dir'] ?? null,
            'workspace_dir' => $plan['workspace_dir'] ?? null,
            'execution_workspace_hash' => $plan['execution_workspace_hash'] ?? null,
        ];
    }

    /**
     * @param  array<string,mixed>  $networkPolicy
     * @return array<string,mixed>
     */
    public function compactDockerNetworkPolicy(array $networkPolicy): array
    {
        return [
            'status' => $networkPolicy['status'] ?? null,
            'reason' => $networkPolicy['reason'] ?? null,
            'mode' => $networkPolicy['mode'] ?? null,
            'runtime' => $networkPolicy['runtime'] ?? null,
            'enforced' => (bool) ($networkPolicy['enforced'] ?? false),
            'required' => (bool) ($networkPolicy['required'] ?? false),
        ];
    }

    /**
     * @param  array<string,mixed>  $providerRun
     * @return array<string,mixed>
     */
    public function compactProviderPayload(array $providerRun): array
    {
        return [
            'exit_code' => $providerRun['exit_code'] ?? null,
            'trace_id' => $providerRun['trace_id'] ?? null,
            'runtime' => $providerRun['runtime'] ?? null,
            'command_display' => $providerRun['command_display'] ?? null,
            'provider_runtime' => $providerRun['provider_runtime'] ?? null,
            'phase' => data_get($providerRun, 'decoded.phase'),
            'ok' => data_get($providerRun, 'decoded.ok'),
            'completion_status' => data_get($providerRun, 'decoded.completion.status'),
            'fair_mode_result' => data_get($providerRun, 'decoded.fair_mode_result'),
            'fair_mode' => data_get($providerRun, 'decoded.dev_execution_plan.fair_mode'),
            'quality_gate_policy' => data_get($providerRun, 'decoded.dev_execution_plan.quality_gate_policy'),
            'dev_plan_id' => data_get($providerRun, 'decoded.dev_execution_plan.plan_id'),
            'provider_runs' => collect((array) data_get($providerRun, 'decoded.provider_runs', []))
                ->filter(fn (mixed $entry): bool => is_array($entry))
                ->map(fn (array $entry): array => $this->compactSingleProviderRun($entry))
                ->values()
                ->all(),
            'stdout_excerpt' => isset($providerRun['stdout']) ? Str::limit((string) $providerRun['stdout'], 1200) : null,
            'stderr_excerpt' => isset($providerRun['stderr']) ? Str::limit((string) $providerRun['stderr'], 1200) : null,
        ];
    }

    /**
     * @param  array<string,mixed>  $providerRun
     * @return array<string,mixed>
     */
    public function compactSingleProviderRun(array $providerRun): array
    {
        return [
            'iteration' => $providerRun['iteration'] ?? null,
            'trace_id' => $providerRun['trace_id'] ?? null,
            'exit_code' => $providerRun['exit_code'] ?? null,
            'stdout_excerpt' => isset($providerRun['stdout']) ? Str::limit((string) $providerRun['stdout'], 1200) : null,
            'stderr_excerpt' => isset($providerRun['stderr']) ? Str::limit((string) $providerRun['stderr'], 1200) : null,
        ];
    }

    /**
     * @param  array<string,mixed>  $payload
     */
    public function failureSummary(array $payload): string
    {
        return Str::limit((string) ($payload['stderr'] ?? $payload['stdout'] ?? 'Provider execution failed.'), 2000);
    }

    public function uuidOrNull(mixed $value): ?string
    {
        return is_string($value) && Str::isUuid($value) ? $value : null;
    }

    public function workspace(mixed $workspace): string
    {
        $workspace = is_string($workspace) && $workspace !== '' ? $workspace : (getcwd() ?: base_path());
        $resolved = realpath($workspace);

        return $resolved && is_dir($resolved) ? $resolved : $workspace;
    }
}
