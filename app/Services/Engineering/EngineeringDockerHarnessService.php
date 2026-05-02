<?php

namespace App\Services\Engineering;

use App\Models\AtlasEngineeringRun;
use App\Models\AtlasEngineeringTestRun;
use App\Support\AtlasSecurity;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Str;
use Symfony\Component\Process\Process;

class EngineeringDockerHarnessService
{
    /**
     * @param  array<string,mixed>  $profile
     * @param  array<string,mixed>  $options
     * @return array<string,mixed>
     */
    public function augmentProfile(string $workspace, array $profile, array $options = []): array
    {
        return array_merge($profile, [
            'cache' => $this->cachePlan($workspace, $options),
            'healthchecks' => $this->healthcheckPlan($options),
            'artifacts' => $this->artifactPlan($options),
            'network' => $this->networkPlan($profile, $options),
        ]);
    }

    /**
     * @param  array<string,mixed>  $workspacePlan
     * @return array{command:string,runtime:string}
     */
    public function testRuntimeCommand(string $command, string $workspace, array $workspacePlan): array
    {
        if (($workspacePlan['mode'] ?? null) !== 'docker' || ! (bool) ($workspacePlan['containerized_execution'] ?? false)) {
            return ['command' => $command, 'runtime' => 'host'];
        }

        $docker = (array) ($workspacePlan['docker'] ?? []);
        $executionWorkspace = (string) ($workspacePlan['execution_workspace'] ?? $workspace);
        $workdir = (string) ($docker['container_workdir'] ?? '/workspace');
        $runtime = (string) ($docker['runtime'] ?? 'none');
        $cacheArgs = $this->cacheShellArgs((array) data_get($docker, 'cache.mounts', []));
        $networkArgs = $this->networkShellArgs((array) ($docker['network'] ?? []), $runtime);

        if ($runtime === 'compose') {
            $composeFile = is_string($docker['selected_compose_file'] ?? null)
                ? $executionWorkspace.'/'.$docker['selected_compose_file']
                : null;
            $service = is_string($docker['service'] ?? null) ? $docker['service'] : null;
            if ($composeFile && $service) {
                return [
                    'runtime' => 'docker_compose',
                    'command' => implode(' ', array_filter([
                        'docker compose',
                        '-f '.escapeshellarg($composeFile),
                        'run --rm -T',
                        $cacheArgs,
                        '-v '.escapeshellarg($executionWorkspace.':'.$workdir),
                        '-w '.escapeshellarg($workdir),
                        escapeshellarg($service),
                        'sh -lc '.escapeshellarg($command),
                    ])),
                ];
            }
        }

        if ($runtime === 'dockerfile' && is_string($docker['dockerfile'] ?? null)) {
            $dockerfile = $executionWorkspace.'/'.$docker['dockerfile'];
            $image = is_string($docker['image'] ?? null) ? $docker['image'] : 'atlas-harness-'.substr(hash('sha256', $executionWorkspace), 0, 12);

            return [
                'runtime' => 'dockerfile',
                'command' => implode(' && ', [
                    'docker build -t '.escapeshellarg($image).' -f '.escapeshellarg($dockerfile).' '.escapeshellarg($executionWorkspace),
                    implode(' ', array_filter([
                        'docker run --rm',
                        $networkArgs,
                        $cacheArgs,
                        '-v '.escapeshellarg($executionWorkspace.':'.$workdir),
                        '-w '.escapeshellarg($workdir),
                        escapeshellarg($image),
                        'sh -lc '.escapeshellarg($command),
                    ])),
                ]),
            ];
        }

        return ['command' => $command, 'runtime' => 'host'];
    }

    /**
     * @param  array<string,mixed>  $workspacePlan
     * @return array<string,mixed>
     */
    public function networkPolicyStatus(array $workspacePlan): array
    {
        if (($workspacePlan['mode'] ?? null) !== 'docker' || ! (bool) ($workspacePlan['containerized_execution'] ?? false)) {
            return ['status' => 'not_applicable', 'reason' => 'workspace_not_containerized'];
        }

        $docker = (array) ($workspacePlan['docker'] ?? []);
        $network = (array) ($docker['network'] ?? []);
        $mode = (string) ($network['mode'] ?? 'profile');
        if ($mode === 'profile') {
            return array_merge($network, [
                'status' => 'passed',
                'reason' => null,
            ]);
        }

        if ((bool) ($network['enforced'] ?? false)) {
            return array_merge($network, [
                'status' => 'passed',
                'reason' => null,
            ]);
        }

        return array_merge($network, [
            'status' => 'failed',
            'reason' => (string) ($network['unavailable_reason'] ?? 'docker_network_policy_not_enforceable'),
        ]);
    }

    /**
     * @param  array<string,mixed>  $workspacePlan
     * @return array<string,mixed>
     */
    public function runHealthchecks(array $workspacePlan): array
    {
        if (($workspacePlan['mode'] ?? null) !== 'docker' || ! (bool) ($workspacePlan['containerized_execution'] ?? false)) {
            return ['status' => 'not_applicable', 'reason' => 'workspace_not_containerized'];
        }

        $docker = (array) ($workspacePlan['docker'] ?? []);
        if (($docker['runtime'] ?? null) !== 'compose') {
            return ['status' => 'skipped', 'reason' => 'healthchecks_require_compose'];
        }

        $services = array_values(array_filter((array) data_get($docker, 'healthchecks.services', []), fn (mixed $service): bool => is_string($service) && trim($service) !== ''));
        if ($services === []) {
            return ['status' => 'skipped', 'reason' => 'no_healthcheck_services_configured'];
        }

        $executionWorkspace = (string) ($workspacePlan['execution_workspace'] ?? '');
        $composeFile = is_string($docker['selected_compose_file'] ?? null)
            ? $executionWorkspace.'/'.$docker['selected_compose_file']
            : '';
        if ($executionWorkspace === '' || $composeFile === '' || ! File::isFile($composeFile)) {
            return ['status' => 'failed', 'reason' => 'compose_file_missing'];
        }

        $timeout = max(1, (int) data_get($docker, 'healthchecks.timeout_seconds', 45));
        $start = $this->process(array_values(array_merge(
            ['docker', 'compose', '-f', $composeFile, 'up', '-d'],
            $services,
        )), $executionWorkspace, max(30, $timeout));

        if ((int) $start['exit_code'] !== 0) {
            return [
                'status' => 'failed',
                'reason' => 'docker_compose_up_failed',
                'services' => $services,
                'command' => AtlasSecurity::commandLineForDisplay(array_values(array_merge(
                    ['docker', 'compose', '-f', $composeFile, 'up', '-d'],
                    $services,
                ))),
                'stderr_excerpt' => Str::limit((string) $start['stderr'], 1200),
            ];
        }

        $deadline = microtime(true) + $timeout;
        $checks = [];
        do {
            $checks = $this->healthStatuses($composeFile, $executionWorkspace, $services);
            $unready = collect($checks)->filter(fn (array $check): bool => ! (bool) ($check['ready'] ?? false))->values();
            if ($unready->isEmpty()) {
                return [
                    'status' => 'passed',
                    'services' => $checks,
                    'timeout_seconds' => $timeout,
                    'started' => true,
                ];
            }

            usleep(500_000);
        } while (microtime(true) < $deadline);

        return [
            'status' => 'failed',
            'reason' => 'healthcheck_timeout',
            'services' => $checks,
            'timeout_seconds' => $timeout,
            'started' => true,
        ];
    }

    /**
     * @param  array<string,mixed>  $workspacePlan
     * @return array<string,mixed>
     */
    public function captureArtifacts(AtlasEngineeringRun $run, AtlasEngineeringTestRun $testRun, string $workspace, array $workspacePlan): array
    {
        if (($workspacePlan['mode'] ?? null) !== 'docker' || ! (bool) ($workspacePlan['containerized_execution'] ?? false)) {
            return ['status' => 'not_applicable', 'reason' => 'workspace_not_containerized'];
        }

        $docker = (array) ($workspacePlan['docker'] ?? []);
        $artifactPlan = (array) ($docker['artifacts'] ?? []);
        $paths = array_values(array_filter((array) ($artifactPlan['paths'] ?? []), fn (mixed $path): bool => is_string($path) && trim($path) !== ''));
        if ($paths === []) {
            return ['status' => 'skipped', 'reason' => 'no_artifact_paths_configured'];
        }

        $workspace = realpath($workspace) ?: $workspace;
        $targetRoot = storage_path('app/engineering-runs/'.$run->id.'/test-artifacts/'.$testRun->id);
        $maxFiles = max(1, (int) ($artifactPlan['max_files'] ?? 100));
        $maxBytes = max(1, (int) ($artifactPlan['max_bytes'] ?? 10_485_760));
        $copied = [];
        $totalBytes = 0;

        foreach ($paths as $relativePath) {
            $relativePath = trim((string) $relativePath, "/ \t\n\r\0\x0B");
            if ($relativePath === '' || str_contains($relativePath, '..')) {
                continue;
            }

            $source = AtlasSecurity::canonicalPath($relativePath, $workspace, allowMissing: true);
            if (! AtlasSecurity::pathIsInside($source, $workspace) || ! File::exists($source)) {
                continue;
            }

            if (File::isFile($source)) {
                $bytes = File::size($source);
                if (count($copied) >= $maxFiles || $totalBytes + $bytes > $maxBytes) {
                    break;
                }
                $destination = $targetRoot.'/'.$relativePath;
                File::ensureDirectoryExists(dirname($destination));
                File::copy($source, $destination);
                $copied[] = ['path' => $relativePath, 'bytes' => $bytes];
                $totalBytes += $bytes;

                continue;
            }

            foreach (File::allFiles($source) as $file) {
                if (count($copied) >= $maxFiles) {
                    break 2;
                }

                $filePath = $file->getPathname();
                $bytes = $file->getSize();
                if ($totalBytes + $bytes > $maxBytes) {
                    break 2;
                }

                $artifactRelative = trim($relativePath.'/'.ltrim(Str::after($filePath, rtrim($source, DIRECTORY_SEPARATOR)), DIRECTORY_SEPARATOR), DIRECTORY_SEPARATOR);
                $destination = $targetRoot.'/'.$artifactRelative;
                File::ensureDirectoryExists(dirname($destination));
                File::copy($filePath, $destination);
                $copied[] = ['path' => $artifactRelative, 'bytes' => $bytes];
                $totalBytes += $bytes;
            }
        }

        if ($copied === []) {
            return [
                'status' => 'empty',
                'paths' => $paths,
                'max_files' => $maxFiles,
                'max_bytes' => $maxBytes,
            ];
        }

        $result = [
            'status' => 'captured',
            'artifact_path' => $targetRoot,
            'file_count' => count($copied),
            'total_bytes' => $totalBytes,
            'files' => array_slice($copied, 0, 50),
            'truncated' => count($copied) >= $maxFiles || $totalBytes >= $maxBytes,
        ];

        $testRun->forceFill([
            'artifact_path' => $targetRoot,
            'metadata' => array_merge($testRun->metadata ?? [], [
                'artifact_export' => $this->compactArtifactResult($result),
            ]),
        ])->save();

        return $result;
    }

    /**
     * @param  array<string,mixed>  $result
     * @return array<string,mixed>
     */
    private function compactArtifactResult(array $result): array
    {
        return [
            'status' => $result['status'] ?? null,
            'artifact_path_hash' => isset($result['artifact_path']) ? hash('sha256', (string) $result['artifact_path']) : null,
            'file_count' => $result['file_count'] ?? 0,
            'total_bytes' => $result['total_bytes'] ?? 0,
            'truncated' => (bool) ($result['truncated'] ?? false),
        ];
    }

    /**
     * @param  array<int,mixed>  $services
     * @return array<int,array<string,mixed>>
     */
    private function healthStatuses(string $composeFile, string $cwd, array $services): array
    {
        return collect($services)
            ->map(function (mixed $service) use ($composeFile, $cwd): array {
                $service = trim((string) $service);
                $process = $this->process(['docker', 'compose', '-f', $composeFile, 'ps', '--format', 'json', $service], $cwd, 10);
                $decoded = json_decode((string) $process['stdout'], true);
                if (is_array($decoded) && array_is_list($decoded) && isset($decoded[0]) && is_array($decoded[0])) {
                    $decoded = $decoded[0];
                }
                if (! is_array($decoded)) {
                    $decoded = [];
                }

                $state = strtolower((string) ($decoded['State'] ?? $decoded['state'] ?? 'unknown'));
                $health = strtolower((string) ($decoded['Health'] ?? $decoded['health'] ?? ''));
                $ready = $health !== ''
                    ? $health === 'healthy'
                    : in_array($state, ['running', 'created'], true);

                return [
                    'service' => $service,
                    'ready' => $ready,
                    'state' => $state,
                    'health' => $health !== '' ? $health : null,
                    'exit_code' => $process['exit_code'],
                    'stderr_excerpt' => $process['stderr'] !== '' ? Str::limit((string) $process['stderr'], 400) : null,
                ];
            })
            ->values()
            ->all();
    }

    /**
     * @param  array<int,mixed>  $mounts
     */
    private function cacheShellArgs(array $mounts): string
    {
        return collect($mounts)
            ->filter(fn (mixed $mount): bool => is_array($mount) && (bool) ($mount['enabled'] ?? true))
            ->flatMap(function (array $mount): array {
                $args = [];
                $hostPath = is_string($mount['host_path'] ?? null) ? $mount['host_path'] : null;
                $containerPath = is_string($mount['container_path'] ?? null) ? $mount['container_path'] : null;
                if ($hostPath && $containerPath) {
                    $args[] = '-v '.escapeshellarg($hostPath.':'.$containerPath);
                }

                foreach ((array) ($mount['env'] ?? []) as $key => $value) {
                    if (is_string($key) && $key !== '' && is_scalar($value)) {
                        $args[] = '-e '.escapeshellarg($key.'='.(string) $value);
                    }
                }

                return $args;
            })
            ->implode(' ');
    }

    /**
     * @param  array<string,mixed>  $network
     */
    private function networkShellArgs(array $network, string $runtime): string
    {
        $mode = (string) ($network['mode'] ?? 'profile');
        if ($runtime !== 'dockerfile' || $mode === 'profile') {
            return '';
        }

        return '--network '.escapeshellarg($mode);
    }

    /**
     * @param  array<string,mixed>  $options
     * @return array<string,mixed>
     */
    private function cachePlan(string $workspace, array $options): array
    {
        $mode = $this->nonEmptyString($options['docker_cache'] ?? null)
            ?: $this->nonEmptyString(config('atlas.engineering.docker.cache.mode'))
            ?: 'auto';
        if (! in_array($mode, ['auto', 'off'], true)) {
            $mode = 'auto';
        }

        $mounts = $mode === 'off' ? [] : $this->detectedCacheMounts($workspace);

        return [
            'mode' => $mode,
            'enabled' => $mode !== 'off' && $mounts !== [],
            'root' => storage_path('app/engineering-cache/docker/'.substr(hash('sha256', $workspace), 0, 16)),
            'mounts' => $mounts,
        ];
    }

    /**
     * @return array<int,array<string,mixed>>
     */
    private function detectedCacheMounts(string $workspace): array
    {
        $root = storage_path('app/engineering-cache/docker/'.substr(hash('sha256', $workspace), 0, 16));
        $definitions = [
            [
                'name' => 'npm',
                'detectors' => ['package-lock.json', 'package.json'],
                'container_path' => '/cache/npm',
                'env' => ['NPM_CONFIG_CACHE' => '/cache/npm'],
            ],
            [
                'name' => 'pnpm',
                'detectors' => ['pnpm-lock.yaml'],
                'container_path' => '/cache/pnpm',
                'env' => ['PNPM_STORE_DIR' => '/cache/pnpm'],
            ],
            [
                'name' => 'yarn',
                'detectors' => ['yarn.lock'],
                'container_path' => '/cache/yarn',
                'env' => ['YARN_CACHE_FOLDER' => '/cache/yarn'],
            ],
            [
                'name' => 'composer',
                'detectors' => ['composer.json', 'composer.lock'],
                'container_path' => '/cache/composer',
                'env' => ['COMPOSER_CACHE_DIR' => '/cache/composer'],
            ],
            [
                'name' => 'pip',
                'detectors' => ['requirements.txt', 'pyproject.toml', 'poetry.lock'],
                'container_path' => '/cache/pip',
                'env' => ['PIP_CACHE_DIR' => '/cache/pip'],
            ],
        ];

        return collect($definitions)
            ->filter(fn (array $definition): bool => collect($definition['detectors'])->contains(fn (string $file): bool => File::exists($workspace.'/'.$file)))
            ->map(function (array $definition) use ($root): array {
                $hostPath = $root.'/'.$definition['name'];
                File::ensureDirectoryExists($hostPath);

                return [
                    'name' => $definition['name'],
                    'host_path' => $hostPath,
                    'container_path' => $definition['container_path'],
                    'env' => $definition['env'],
                    'detected_by' => $definition['detectors'],
                    'enabled' => true,
                ];
            })
            ->values()
            ->all();
    }

    /**
     * @param  array<string,mixed>  $options
     * @return array<string,mixed>
     */
    private function healthcheckPlan(array $options): array
    {
        $services = $this->stringList($options['docker_healthcheck_services'] ?? $options['docker_healthcheck_service'] ?? config('atlas.engineering.docker.healthcheck_services', []));

        return [
            'services' => $services,
            'timeout_seconds' => max(1, (int) ($options['docker_healthcheck_timeout'] ?? config('atlas.engineering.docker.healthcheck_timeout_seconds', 45))),
        ];
    }

    /**
     * @param  array<string,mixed>  $options
     * @return array<string,mixed>
     */
    private function artifactPlan(array $options): array
    {
        $paths = $this->stringList($options['docker_artifact_paths'] ?? $options['docker_artifact_path'] ?? config('atlas.engineering.docker.artifact_paths', []));

        return [
            'paths' => $paths,
            'max_files' => max(1, (int) ($options['docker_artifact_max_files'] ?? config('atlas.engineering.docker.artifact_max_files', 100))),
            'max_bytes' => max(1, (int) ($options['docker_artifact_max_bytes'] ?? config('atlas.engineering.docker.artifact_max_bytes', 10_485_760))),
        ];
    }

    /**
     * @param  array<string,mixed>  $profile
     * @param  array<string,mixed>  $options
     * @return array<string,mixed>
     */
    private function networkPlan(array $profile, array $options): array
    {
        $mode = $this->nonEmptyString($options['docker_network'] ?? null)
            ?: $this->nonEmptyString(config('atlas.engineering.docker.network'))
            ?: 'profile';
        if (! in_array($mode, ['profile', 'none', 'bridge'], true)) {
            $mode = 'profile';
        }

        $runtime = (string) ($profile['runtime'] ?? 'none');
        $enforced = match (true) {
            $mode === 'profile' => true,
            $runtime === 'dockerfile' => true,
            default => false,
        };

        return [
            'mode' => $mode,
            'runtime' => $runtime,
            'enforced' => $enforced,
            'required' => $mode !== 'profile',
            'unavailable_reason' => $enforced ? null : 'docker_compose_network_policy_requires_compose_profile',
        ];
    }

    /**
     * @param  array<string,mixed>  $options
     * @return array<string,mixed>
     */
    public function cleanup(bool $dryRun = true, array $options = []): array
    {
        $cacheDays = max(1, (int) ($options['cache_retention_days'] ?? config('atlas.engineering.docker.cleanup.cache_retention_days', 14)));
        $artifactDays = max(1, (int) ($options['artifact_retention_days'] ?? config('atlas.engineering.docker.cleanup.artifact_retention_days', 30)));
        $targets = [
            [
                'kind' => 'cache',
                'root' => storage_path('app/engineering-cache/docker'),
                'retention_days' => $cacheDays,
            ],
            [
                'kind' => 'test_artifacts',
                'root' => storage_path('app/engineering-runs'),
                'retention_days' => $artifactDays,
                'leaf' => 'test-artifacts',
            ],
        ];

        $deleted = [];
        $candidates = [];
        foreach ($targets as $target) {
            foreach ($this->cleanupCandidates($target) as $candidate) {
                $candidates[] = $candidate;
                if (! $dryRun) {
                    File::deleteDirectory($candidate['path']);
                    $deleted[] = $candidate;
                }
            }
        }

        return [
            'status' => 'completed',
            'dry_run' => $dryRun,
            'candidate_count' => count($candidates),
            'deleted_count' => count($deleted),
            'candidates' => array_map(fn (array $candidate): array => $this->compactCleanupCandidate($candidate), array_slice($candidates, 0, 100)),
            'deleted' => array_map(fn (array $candidate): array => $this->compactCleanupCandidate($candidate), array_slice($deleted, 0, 100)),
            'cache_retention_days' => $cacheDays,
            'artifact_retention_days' => $artifactDays,
        ];
    }

    /**
     * @param  array<string,mixed>  $target
     * @return array<int,array<string,mixed>>
     */
    private function cleanupCandidates(array $target): array
    {
        $root = (string) ($target['root'] ?? '');
        if ($root === '' || ! File::isDirectory($root)) {
            return [];
        }

        $cutoff = now()->subDays((int) ($target['retention_days'] ?? 1))->getTimestamp();
        $paths = [];
        if (is_string($target['leaf'] ?? null)) {
            foreach (File::directories($root) as $runDir) {
                $leaf = $runDir.DIRECTORY_SEPARATOR.$target['leaf'];
                if (File::isDirectory($leaf)) {
                    $paths[] = $leaf;
                }
            }
        } else {
            $paths = File::directories($root);
        }

        return collect($paths)
            ->filter(fn (string $path): bool => @filemtime($path) !== false && (int) @filemtime($path) <= $cutoff)
            ->map(fn (string $path): array => [
                'kind' => $target['kind'] ?? 'unknown',
                'path' => $path,
                'path_hash' => hash('sha256', $path),
                'bytes' => $this->directoryBytes($path),
                'mtime' => @filemtime($path) ?: null,
            ])
            ->values()
            ->all();
    }

    /**
     * @param  array<string,mixed>  $candidate
     * @return array<string,mixed>
     */
    private function compactCleanupCandidate(array $candidate): array
    {
        return [
            'kind' => $candidate['kind'] ?? null,
            'path_hash' => $candidate['path_hash'] ?? null,
            'bytes' => $candidate['bytes'] ?? null,
            'mtime' => $candidate['mtime'] ?? null,
        ];
    }

    private function directoryBytes(string $path): int
    {
        if (! File::isDirectory($path)) {
            return 0;
        }

        $bytes = 0;
        foreach (File::allFiles($path) as $file) {
            $bytes += $file->getSize();
        }

        return $bytes;
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

    /**
     * @return array<int,string>
     */
    private function stringList(mixed $value): array
    {
        if (is_string($value)) {
            $value = explode(',', $value);
        }

        if (! is_array($value)) {
            return [];
        }

        return collect($value)
            ->filter(fn (mixed $item): bool => is_scalar($item) && trim((string) $item) !== '')
            ->map(fn (mixed $item): string => trim((string) $item))
            ->unique()
            ->values()
            ->all();
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
