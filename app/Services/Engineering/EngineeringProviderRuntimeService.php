<?php

namespace App\Services\Engineering;

use App\Support\AtlasSecurity;
use Illuminate\Support\Facades\File;
use Symfony\Component\Process\Process;

class EngineeringProviderRuntimeService
{
    /**
     * @param  array<string,mixed>  $workspacePlan
     * @param  array<string,mixed>  $options
     * @return array<string,mixed>
     */
    public function plan(array $workspacePlan, array $options = []): array
    {
        $requested = $this->normalizeRuntime($options['provider_runtime'] ?? config('atlas.engineering.provider_runtime.default', 'host'));
        if ($requested === 'host') {
            return [
                'requested_runtime' => 'host',
                'runtime' => 'host',
                'status' => 'ready',
                'required' => false,
                'fallback_reason' => null,
            ];
        }

        $composeFile = $this->composeFile($options);
        $service = $this->nonEmptyString($options['provider_docker_service'] ?? null)
            ?: $this->nonEmptyString(config('atlas.engineering.provider_runtime.docker.service'))
            ?: 'backend';
        $appDir = $this->nonEmptyString($options['provider_docker_app_dir'] ?? null)
            ?: $this->nonEmptyString(config('atlas.engineering.provider_runtime.docker.app_dir'))
            ?: '/app';
        $workspaceDir = $this->nonEmptyString($options['provider_docker_workspace_dir'] ?? null)
            ?: $this->nonEmptyString(config('atlas.engineering.provider_runtime.docker.workspace_dir'))
            ?: '/workspace';

        $dockerAvailable = $this->process(['docker', '--version'], base_path(), 5);
        $composeAvailable = (int) $dockerAvailable['exit_code'] === 0
            ? $this->process(['docker', 'compose', 'version'], base_path(), 5)
            : ['exit_code' => 1, 'stdout' => '', 'stderr' => 'docker unavailable'];
        $services = ($composeFile !== null && (int) $composeAvailable['exit_code'] === 0)
            ? $this->process(['docker', 'compose', '-f', $composeFile, 'config', '--services'], dirname($composeFile), 10)
            : ['exit_code' => 1, 'stdout' => '', 'stderr' => 'compose file unavailable'];
        $serviceNames = collect(explode("\n", (string) ($services['stdout'] ?? '')))
            ->map(fn (string $name): string => trim($name))
            ->filter()
            ->values()
            ->all();
        $serviceFound = in_array($service, $serviceNames, true);
        $unavailableReason = $this->unavailableReason($composeFile, $service, $dockerAvailable, $composeAvailable, $services, $serviceFound);
        $required = $requested === 'docker';

        if ($unavailableReason !== null) {
            return [
                'requested_runtime' => $requested,
                'runtime' => $required ? 'docker' : 'host',
                'status' => $required ? 'unavailable' : 'fallback',
                'required' => $required,
                'fallback_reason' => $unavailableReason,
                'docker_available' => (int) $dockerAvailable['exit_code'] === 0,
                'compose_available' => (int) $composeAvailable['exit_code'] === 0,
                'compose_file' => $composeFile,
                'service' => $service,
                'service_found' => $serviceFound,
                'app_dir' => $appDir,
                'workspace_dir' => $workspaceDir,
                'execution_workspace_hash' => isset($workspacePlan['execution_workspace'])
                    ? hash('sha256', (string) $workspacePlan['execution_workspace'])
                    : null,
            ];
        }

        return [
            'requested_runtime' => $requested,
            'runtime' => 'docker',
            'status' => 'ready',
            'required' => $required,
            'fallback_reason' => null,
            'docker_available' => true,
            'compose_available' => true,
            'compose_file' => $composeFile,
            'service' => $service,
            'service_found' => true,
            'app_dir' => $appDir,
            'workspace_dir' => $workspaceDir,
            'execution_workspace_hash' => isset($workspacePlan['execution_workspace'])
                ? hash('sha256', (string) $workspacePlan['execution_workspace'])
                : null,
        ];
    }

    /**
     * @param  array<int,string>  $hostCommand
     * @param  array<string,mixed>  $workspacePlan
     * @param  array<string,mixed>  $runtimePlan
     * @return array{command:array<int,string>,cwd:string,runtime:string,command_display:?string}
     */
    public function command(array $hostCommand, array $workspacePlan, array $runtimePlan): array
    {
        if (($runtimePlan['runtime'] ?? 'host') !== 'docker' || ($runtimePlan['status'] ?? null) !== 'ready') {
            return [
                'command' => $hostCommand,
                'cwd' => base_path(),
                'runtime' => 'host',
                'command_display' => AtlasSecurity::commandLineForDisplay($hostCommand),
            ];
        }

        $executionWorkspace = (string) ($workspacePlan['execution_workspace'] ?? '');
        $composeFile = (string) ($runtimePlan['compose_file'] ?? '');
        $service = (string) ($runtimePlan['service'] ?? '');
        $appDir = (string) ($runtimePlan['app_dir'] ?? '/app');
        $workspaceDir = (string) ($runtimePlan['workspace_dir'] ?? '/workspace');

        $containerCommand = [
            'docker',
            'compose',
            '-f',
            $composeFile,
            'run',
            '--rm',
            '-T',
            '-v',
            $executionWorkspace.':'.$workspaceDir,
            '-w',
            $appDir,
            $service,
            'php',
            'artisan',
            ...$this->containerArtisanArguments($hostCommand, $executionWorkspace, $workspaceDir),
        ];

        return [
            'command' => $containerCommand,
            'cwd' => dirname($composeFile),
            'runtime' => 'docker',
            'command_display' => AtlasSecurity::commandLineForDisplay($containerCommand),
        ];
    }

    /**
     * @param  array<int,string>  $hostCommand
     * @return array<int,string>
     */
    private function containerArtisanArguments(array $hostCommand, string $executionWorkspace, string $workspaceDir): array
    {
        $args = array_slice($hostCommand, 2);

        return array_values(array_map(
            fn (string $arg): string => $arg === '--workspace='.$executionWorkspace ? '--workspace='.$workspaceDir : $arg,
            $args,
        ));
    }

    /**
     * @param  array<string,mixed>  $options
     */
    private function composeFile(array $options): ?string
    {
        $configured = $this->nonEmptyString($options['provider_docker_compose_file'] ?? null)
            ?: $this->nonEmptyString(config('atlas.engineering.provider_runtime.docker.compose_file'));
        if ($configured === null) {
            return null;
        }

        $path = str_starts_with($configured, DIRECTORY_SEPARATOR)
            ? $configured
            : base_path($configured);
        $resolved = realpath($path);

        return $resolved && File::isFile($resolved) ? $resolved : null;
    }

    /**
     * @param  array{exit_code:int,stdout:string,stderr:string}  $dockerAvailable
     * @param  array{exit_code:int,stdout:string,stderr:string}  $composeAvailable
     * @param  array{exit_code:int,stdout:string,stderr:string}  $services
     */
    private function unavailableReason(
        ?string $composeFile,
        ?string $service,
        array $dockerAvailable,
        array $composeAvailable,
        array $services,
        bool $serviceFound,
    ): ?string {
        if ($composeFile === null) {
            return 'provider_docker_compose_file_missing';
        }
        if ((int) $dockerAvailable['exit_code'] !== 0) {
            return 'docker_binary_unavailable';
        }
        if ((int) $composeAvailable['exit_code'] !== 0) {
            return 'docker_compose_unavailable';
        }
        if ($service === null || $service === '') {
            return 'provider_docker_service_missing';
        }
        if ((int) $services['exit_code'] !== 0) {
            return 'provider_docker_compose_config_failed';
        }
        if (! $serviceFound) {
            return 'provider_docker_service_not_found';
        }

        return null;
    }

    /**
     * @param  array<int,string>  $command
     * @return array{exit_code:int,stdout:string,stderr:string}
     */
    private function process(array $command, string $cwd, int $timeout): array
    {
        try {
            $process = new Process($command, $cwd, AtlasSecurity::processEnv(profile: 'tool'));
            $process->setTimeout($timeout);
            $process->run();

            return [
                'exit_code' => $process->getExitCode() ?? 1,
                'stdout' => AtlasSecurity::redactString($process->getOutput()),
                'stderr' => AtlasSecurity::redactString($process->getErrorOutput()),
            ];
        } catch (\Throwable $exception) {
            return [
                'exit_code' => 1,
                'stdout' => '',
                'stderr' => AtlasSecurity::redactString($exception->getMessage()),
            ];
        }
    }

    private function normalizeRuntime(mixed $value): string
    {
        $runtime = $this->nonEmptyString($value) ?: 'host';

        return in_array($runtime, ['host', 'docker', 'auto'], true) ? $runtime : 'host';
    }

    private function nonEmptyString(mixed $value): ?string
    {
        if (! is_scalar($value)) {
            return null;
        }

        $value = trim((string) $value);

        return $value === '' ? null : $value;
    }
}
