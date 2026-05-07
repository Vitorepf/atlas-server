<?php

namespace App\Services\Ai\Voice;

use Symfony\Component\Process\Process;

final class AtlasVoiceRuntimeCertificationService
{
    public function __construct(
        private readonly AtlasVoiceRealtimeService $voice,
    ) {}

    /**
     * @param  array<string,mixed>  $payload
     * @return array<string,mixed>
     */
    public function certify(array $payload = []): array
    {
        $runtime = $this->runtime($payload);
        if (! in_array($runtime, ['livekit_agents_sdk'], true)) {
            return [
                'schema_version' => 'atlas.voice_realtime.runtime_certification.v1',
                'status' => 'invalid_runtime',
                'surface_id' => 'voice_realtime',
                'runtime_id' => $runtime,
                'allowed_runtimes' => ['livekit_agents_sdk'],
                'kernel_only' => true,
                'mobile_first' => true,
                'daemon_started' => false,
                'summary' => [
                    'gate_count' => 0,
                    'passed_gates' => 0,
                    'failed_gates' => 1,
                    'failed_keys' => ['runtime_allowlist'],
                ],
                'next_action' => 'use_allowed_voice_runtime',
            ];
        }

        $baseUrl = $this->baseUrl($payload);
        $requireSdk = (bool) ($payload['require_sdk'] ?? false);

        $preflight = $this->runPreflight($runtime, $baseUrl, $requireSdk);
        $callbackSequence = $this->runCallbackSequenceSmoke($runtime, $baseUrl);
        $productionLoop = $this->runProductionLoopSmoke($runtime, $baseUrl);
        $workerStart = $this->runWorkerStartCheck($runtime, $baseUrl);

        $workerStatus = (string) ($workerStart['status'] ?? 'unknown');
        $gates = [
            'preflight_ready' => [
                'passed' => ($preflight['status'] ?? null) === 'ready',
                'status' => $preflight['status'] ?? 'unknown',
            ],
            'callback_sequence_passed' => [
                'passed' => ($callbackSequence['status'] ?? null) === 'passed'
                    && (int) data_get($callbackSequence, 'callback_sequence.active_session_count', 1) === 0,
                'status' => $callbackSequence['status'] ?? 'unknown',
                'active_session_count' => data_get($callbackSequence, 'callback_sequence.active_session_count'),
            ],
            'production_loop_smoke_passed' => [
                'passed' => ($productionLoop['status'] ?? null) === 'production_loop_smoke_completed'
                    && (int) ($productionLoop['active_session_count'] ?? 1) === 0
                    && ($productionLoop['daemon_started'] ?? true) === false
                    && ($productionLoop['sdk_imported'] ?? true) === false,
                'status' => $productionLoop['status'] ?? 'unknown',
                'active_session_count' => $productionLoop['active_session_count'] ?? null,
                'daemon_started' => $productionLoop['daemon_started'] ?? null,
                'sdk_imported' => $productionLoop['sdk_imported'] ?? null,
            ],
            'worker_start_blocked_safely' => [
                'passed' => str_starts_with($workerStatus, 'blocked_') && ($workerStart['started'] ?? true) === false,
                'status' => $workerStatus,
                'started' => $workerStart['started'] ?? null,
                'reason' => $workerStart['reason'] ?? null,
            ],
        ];

        $failed = collect($gates)
            ->filter(fn (array $gate): bool => ! (bool) ($gate['passed'] ?? false))
            ->keys()
            ->values()
            ->all();

        return [
            'schema_version' => 'atlas.voice_realtime.runtime_certification.v1',
            'status' => $failed === [] ? 'certified_scaffold' : 'failed',
            'surface_id' => 'voice_realtime',
            'runtime_id' => $runtime,
            'kernel_only' => true,
            'mobile_first' => true,
            'daemon_started' => false,
            'sdk_required_for_certification' => $requireSdk,
            'gates' => $gates,
            'summary' => [
                'gate_count' => count($gates),
                'passed_gates' => count($gates) - count($failed),
                'failed_gates' => count($failed),
                'failed_keys' => $failed,
            ],
            'artifacts' => [
                'preflight' => $this->certificationArtifact($preflight, ['preflight']),
                'callback_sequence' => $this->certificationArtifact($callbackSequence, ['callback_sequence']),
                'production_loop_smoke' => $this->certificationArtifact($productionLoop, ['bridge_contract', 'results']),
                'worker_start_check' => $this->certificationArtifact($workerStart, ['worker_plan', 'activation_contract', 'production_loop_plan', 'sdk_wiring_contract']),
            ],
            'next_action' => $failed === []
                ? 'wire_real_livekit_agents_sdk_loop_when_optional_dependency_is_ready'
                : 'fix_failed_runtime_certification_gates',
        ];
    }

    /**
     * @return array<string,mixed>
     */
    private function runPreflight(string $runtime, string $baseUrl, bool $requireSdk): array
    {
        $bootstrapPath = tempnam(sys_get_temp_dir(), 'atlas-voice-bootstrap-');
        $envPath = tempnam(sys_get_temp_dir(), 'atlas-voice-env-');
        if ($bootstrapPath === false || $envPath === false) {
            return $this->failed('atlas.voice_realtime.preflight_command.v1', $runtime, 'could_not_create_temp_preflight_files');
        }

        $bootstrap = $this->voice->runtimeBootstrapManifest([
            'runtime' => $runtime,
            'base_url' => $baseUrl,
        ]);
        file_put_contents($bootstrapPath, json_encode($bootstrap, JSON_THROW_ON_ERROR | JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
        file_put_contents($envPath, implode("\n", [
            'ATLAS_BASE_URL='.$baseUrl,
            'ATLAS_TOKEN=preflight-token',
            'ATLAS_VOICE_BOOTSTRAP='.$bootstrapPath,
            'LIVEKIT_URL=http://livekit.test',
            'LIVEKIT_API_KEY=preflight-key',
            'LIVEKIT_API_SECRET=preflight-secret',
            'ATLAS_VOICE_STT_PROVIDER=configurable',
            'ATLAS_VOICE_TTS_PROVIDER=configurable',
        ]));

        $args = ['--env-file', $envPath, '--preflight'];
        if ($requireSdk) {
            $args[] = '--require-sdk';
        }

        try {
            $decoded = $this->runPython($args);

            return [
                'schema_version' => 'atlas.voice_realtime.preflight_command.v1',
                'status' => (string) ($decoded['status'] ?? 'failed'),
                'surface_id' => 'voice_realtime',
                'runtime_id' => $runtime,
                'require_sdk' => $requireSdk,
                'command' => 'PYTHONPATH=runtimes/python/voice_realtime python3 -m atlas_voice_agent.main --env-file <generated> --preflight'.($requireSdk ? ' --require-sdk' : ''),
                'preflight' => $this->sanitize($decoded),
            ];
        } finally {
            @unlink($bootstrapPath);
            @unlink($envPath);
        }
    }

    /**
     * @return array<string,mixed>
     */
    private function runCallbackSequenceSmoke(string $runtime, string $baseUrl): array
    {
        $examplePath = base_path('runtimes/python/voice_realtime/callback-events.example.json');
        $payload = $this->runPythonBootstrapCommand($runtime, $baseUrl, [
            '--mock-kernel',
            '--callback-events',
            $examplePath,
        ], 'atlas.voice_realtime.callback_sequence.v1');

        return [
            'schema_version' => 'atlas.voice_realtime.callback_sequence_smoke.v1',
            'status' => (($payload['status'] ?? null) === 'callback_sequence_routed') ? 'passed' : 'failed',
            'surface_id' => 'voice_realtime',
            'runtime_id' => $runtime,
            'mock_kernel' => true,
            'example_path' => 'runtimes/python/voice_realtime/callback-events.example.json',
            'command' => 'PYTHONPATH=runtimes/python/voice_realtime python3 -m atlas_voice_agent.main --bootstrap <generated> --mock-kernel --callback-events runtimes/python/voice_realtime/callback-events.example.json',
            'callback_sequence' => $payload,
        ];
    }

    /**
     * @return array<string,mixed>
     */
    private function runProductionLoopSmoke(string $runtime, string $baseUrl): array
    {
        return $this->runPythonBootstrapCommand($runtime, $baseUrl, [
            '--mock-kernel',
            '--sdk-events',
            base_path('runtimes/python/voice_realtime/sdk-events.example.json'),
        ], 'atlas.voice_realtime.production_loop_smoke.v1');
    }

    /**
     * @return array<string,mixed>
     */
    private function runWorkerStartCheck(string $runtime, string $baseUrl): array
    {
        $bootstrapPath = tempnam(sys_get_temp_dir(), 'atlas-voice-bootstrap-');
        $envPath = tempnam(sys_get_temp_dir(), 'atlas-voice-env-');
        if ($bootstrapPath === false || $envPath === false) {
            return $this->failed('atlas.voice_realtime.worker_start.v1', $runtime, 'could_not_create_temp_worker_start_files');
        }

        $bootstrap = $this->voice->runtimeBootstrapManifest([
            'runtime' => $runtime,
            'base_url' => $baseUrl,
        ]);
        file_put_contents($bootstrapPath, json_encode($bootstrap, JSON_THROW_ON_ERROR | JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
        file_put_contents($envPath, implode("\n", [
            'ATLAS_BASE_URL='.$baseUrl,
            'ATLAS_TOKEN=worker-start-token',
            'ATLAS_VOICE_BOOTSTRAP='.$bootstrapPath,
            'LIVEKIT_URL=http://livekit.test',
            'LIVEKIT_API_KEY=worker-start-key',
            'LIVEKIT_API_SECRET=worker-start-secret',
            'ATLAS_VOICE_STT_PROVIDER=configurable',
            'ATLAS_VOICE_TTS_PROVIDER=configurable',
        ]));

        try {
            $decoded = $this->runPython(['--env-file', $envPath, '--start-worker']);
            $payload = $this->sanitize($decoded);
            $payload['command'] = 'PYTHONPATH=runtimes/python/voice_realtime python3 -m atlas_voice_agent.main --env-file <generated> --start-worker';

            return $payload;
        } finally {
            @unlink($bootstrapPath);
            @unlink($envPath);
        }
    }

    /**
     * @param  array<int,string>  $extraArgs
     * @return array<string,mixed>
     */
    private function runPythonBootstrapCommand(string $runtime, string $baseUrl, array $extraArgs, string $schemaVersion): array
    {
        $bootstrapPath = tempnam(sys_get_temp_dir(), 'atlas-voice-bootstrap-');
        if ($bootstrapPath === false) {
            return $this->failed($schemaVersion, $runtime, 'could_not_create_temp_bootstrap');
        }

        $bootstrap = $this->voice->runtimeBootstrapManifest([
            'runtime' => $runtime,
            'base_url' => $baseUrl,
        ]);
        file_put_contents($bootstrapPath, json_encode($bootstrap, JSON_THROW_ON_ERROR | JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));

        try {
            return $this->sanitize($this->runPython([
                '--bootstrap',
                $bootstrapPath,
                ...$extraArgs,
            ]));
        } finally {
            @unlink($bootstrapPath);
        }
    }

    /**
     * @param  array<int,string>  $args
     * @return array<string,mixed>
     */
    private function runPython(array $args): array
    {
        $process = new Process([
            'python3',
            '-m',
            'atlas_voice_agent.main',
            ...$args,
        ], base_path(), [
            'PYTHONPATH' => base_path('runtimes/python/voice_realtime'),
        ]);
        $process->run();
        $decoded = json_decode($process->getOutput(), true);
        if (is_array($decoded)) {
            $payload = $this->sanitize($decoded);
            $payload['exit_code'] = $process->getExitCode();
            $payload['stderr_hash'] = $process->getErrorOutput() !== '' ? hash('sha256', $process->getErrorOutput()) : null;

            return $payload;
        }

        return [
            'schema_version' => 'atlas.voice_realtime.python_command.v1',
            'status' => 'failed',
            'surface_id' => 'voice_realtime',
            'exit_code' => $process->getExitCode(),
            'stderr_hash' => $process->getErrorOutput() !== '' ? hash('sha256', $process->getErrorOutput()) : null,
        ];
    }

    /**
     * @return array<string,mixed>
     */
    private function failed(string $schemaVersion, string $runtime, string $failure): array
    {
        return [
            'schema_version' => $schemaVersion,
            'status' => 'failed',
            'surface_id' => 'voice_realtime',
            'runtime_id' => $runtime,
            'failure' => $failure,
        ];
    }

    /**
     * @param  array<string,mixed>  $payload
     * @return array<string,mixed>
     */
    private function certificationArtifact(array $payload, array $omitKeys = []): array
    {
        return collect($this->sanitize($payload))
            ->reject(fn (mixed $_, string|int $key): bool => in_array((string) $key, $omitKeys, true))
            ->all();
    }

    /**
     * @param  array<string,mixed>  $payload
     * @return array<string,mixed>
     */
    private function sanitize(array $payload): array
    {
        $forbidden = ['access_token', 'token', 'livekit_token', 'api_key', 'api_secret'];

        return collect($payload)
            ->reject(fn (mixed $_, string|int $key): bool => in_array((string) $key, $forbidden, true))
            ->map(fn (mixed $value): mixed => is_array($value) ? $this->sanitize($value) : $value)
            ->all();
    }

    /**
     * @param  array<string,mixed>  $payload
     */
    private function runtime(array $payload): string
    {
        return (string) ($payload['runtime'] ?? 'livekit_agents_sdk');
    }

    /**
     * @param  array<string,mixed>  $payload
     */
    private function baseUrl(array $payload): string
    {
        return rtrim((string) ($payload['base_url'] ?? config('app.url', 'http://atlas.test')), '/') ?: 'http://atlas.test';
    }
}
