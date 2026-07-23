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

class HarnessOptionsSection
{
    /**
     * @param  array<string,mixed>  $options
     * @return array<string,mixed>
     */
    public function dockerOptions(array $options): array
    {
        return array_filter([
            'docker_service' => is_string($options['docker_service'] ?? null) ? $options['docker_service'] : null,
            'docker_image' => is_string($options['docker_image'] ?? null) ? $options['docker_image'] : null,
            'docker_workdir' => is_string($options['docker_workdir'] ?? null) ? $options['docker_workdir'] : null,
            'docker_cache' => is_string($options['docker_cache'] ?? null) ? $options['docker_cache'] : null,
            'docker_network' => is_string($options['docker_network'] ?? null) ? $options['docker_network'] : null,
            'docker_healthcheck_services' => is_array($options['docker_healthcheck_services'] ?? null) ? $options['docker_healthcheck_services'] : null,
            'docker_healthcheck_timeout' => is_numeric($options['docker_healthcheck_timeout'] ?? null) ? (int) $options['docker_healthcheck_timeout'] : null,
            'docker_artifact_paths' => is_array($options['docker_artifact_paths'] ?? null) ? $options['docker_artifact_paths'] : null,
            'docker_artifact_max_files' => is_numeric($options['docker_artifact_max_files'] ?? null) ? (int) $options['docker_artifact_max_files'] : null,
            'docker_artifact_max_bytes' => is_numeric($options['docker_artifact_max_bytes'] ?? null) ? (int) $options['docker_artifact_max_bytes'] : null,
        ], fn (mixed $value): bool => $value !== null && $value !== '' && (! is_array($value) || $value !== []));
    }

    public function visualE2eMode(mixed $value): string
    {
        $mode = is_scalar($value) ? trim((string) $value) : 'auto';

        return in_array($mode, ['auto', 'off', 'required'], true) ? $mode : 'auto';
    }

    public function harnessPolicyMode(mixed $value): string
    {
        $mode = is_scalar($value) ? trim((string) $value) : 'auto';

        return in_array($mode, ['auto', 'off', 'strict'], true) ? $mode : 'auto';
    }

    public function permissionMode(mixed $value): string
    {
        $mode = is_scalar($value) ? trim((string) $value) : 'auto';

        return in_array($mode, ['auto', 'read', 'write', 'danger'], true) ? $mode : 'auto';
    }

    public function sandboxMode(mixed $value): string
    {
        $mode = is_scalar($value) ? trim((string) $value) : 'workspace';

        return in_array($mode, ['workspace', 'worktree', 'docker'], true) ? $mode : 'workspace';
    }

    /**
     * @param  array<string,mixed>  $harnessability
     * @return array<string,int|string>
     */
    public function harnessabilityThresholds(array $harnessability): array
    {
        $defaults = [
            'medium_min_score' => 55,
            'high_min_score' => 80,
            'require_worktree_below_score' => 80,
            'danger_permission_min_score' => 80,
            'write_permission_min_score' => 55,
            'cap_attempts_to_one_below_score' => 55,
            'cap_attempts_to_two_below_score' => 80,
            'require_auto_test_below_score' => 80,
            'policy_source' => 'static_default',
        ];
        $recommended = (array) data_get($harnessability, 'calibration.recommended_thresholds', []);
        $thresholds = array_merge($defaults, array_intersect_key($recommended, $defaults));

        foreach ($thresholds as $key => $value) {
            if ($key === 'policy_source') {
                $thresholds[$key] = is_string($value) && $value !== '' ? $value : 'static_default';

                continue;
            }

            $thresholds[$key] = max(0, min(100, (int) $value));
        }

        return $thresholds;
    }

    /**
     * @param  array<string,mixed>  $options
     * @return array<string,mixed>
     */
    public function providerRuntimeOptions(array $options): array
    {
        return array_filter([
            'provider_runtime' => is_string($options['provider_runtime'] ?? null) ? $options['provider_runtime'] : null,
            'provider_docker_compose_file' => is_string($options['provider_docker_compose_file'] ?? null) ? $options['provider_docker_compose_file'] : null,
            'provider_docker_service' => is_string($options['provider_docker_service'] ?? null) ? $options['provider_docker_service'] : null,
            'provider_docker_app_dir' => is_string($options['provider_docker_app_dir'] ?? null) ? $options['provider_docker_app_dir'] : null,
            'provider_docker_workspace_dir' => is_string($options['provider_docker_workspace_dir'] ?? null) ? $options['provider_docker_workspace_dir'] : null,
        ], fn (mixed $value): bool => $value !== null && $value !== '');
    }

    /**
     * @param  array<string,mixed>  $providerRuntimeOptions
     * @return array<string,mixed>
     */
    public function skippedProviderRuntimePlan(array $providerRuntimeOptions): array
    {
        return [
            'requested_runtime' => (string) ($providerRuntimeOptions['provider_runtime'] ?? config('atlas.engineering.provider_runtime.default', 'host')),
            'runtime' => 'host',
            'status' => 'skipped',
            'required' => false,
            'fallback_reason' => 'provider_not_executed',
        ];
    }
}
