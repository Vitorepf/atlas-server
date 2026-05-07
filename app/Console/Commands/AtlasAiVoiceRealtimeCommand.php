<?php

namespace App\Console\Commands;

use App\Services\Ai\Voice\AtlasVoiceRealtimeService;
use App\Services\Ai\Voice\AtlasVoiceRivalsRunner;
use App\Services\Ai\Voice\AtlasVoiceRuntimeCertificationService;
use Illuminate\Console\Command;
use Symfony\Component\Process\Process;

class AtlasAiVoiceRealtimeCommand extends Command
{
    protected $signature = 'atlas:ai:voice
        {action=contract : Action to inspect: contract, bootstrap, dependencies, preflight, activation-contract, scripted-example, scripted-smoke, callback-smoke, callback-sequence-smoke, callback-loop-check, sdk-check, worker-plan, production-loop-plan, production-loop-smoke, worker-start-check, runtime-certify, health, readiness or rivals}
        {--runtime=livekit_agents_sdk : Runtime id for contract inspection}
        {--base-url= : Kernel base URL for bootstrap manifests}
        {--hours=24 : Readiness/evidence window in hours}
        {--require-sdk : Make preflight fail when LiveKit Agents SDK is not installed}
        {--json : Print machine-readable JSON}';

    protected $description = 'Inspect the Atlas AI Voice Realtime surface contract.';

    public function handle(
        AtlasVoiceRealtimeService $voice,
        AtlasVoiceRivalsRunner $rivals,
        AtlasVoiceRuntimeCertificationService $certification,
    ): int {
        $action = (string) $this->argument('action');
        $runtime = (string) $this->option('runtime');

        if ($runtime !== 'livekit_agents_sdk') {
            $payload = [
                'schema_version' => 'atlas.voice_realtime.command.v1',
                'status' => 'invalid_runtime',
                'received_runtime' => $runtime,
                'allowed_runtimes' => ['livekit_agents_sdk'],
            ];

            if ((bool) $this->option('json')) {
                $this->line(json_encode($payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));

                return self::FAILURE;
            }

            $this->error('Runtime invalido para Atlas Voice: '.$runtime);
            $this->line('Allowed runtimes: livekit_agents_sdk');

            return self::FAILURE;
        }

        $payload = match ($action) {
            'contract' => $voice->runtimeContract(['runtime' => $runtime]),
            'bootstrap' => $voice->runtimeBootstrapManifest([
                'runtime' => $runtime,
                'base_url' => (string) ($this->option('base-url') ?: config('app.url')),
            ]),
            'dependencies' => $voice->runtimeDependencyPlan(),
            'preflight' => $this->runPreflight($voice),
            'activation-contract' => $this->runActivationContract($voice),
            'scripted-example' => $voice->scriptedWorkerExample(),
            'scripted-smoke' => $this->runScriptedSmoke($voice),
            'callback-smoke' => $this->runCallbackSmoke($voice),
            'callback-sequence-smoke' => $this->runCallbackSequenceSmoke($voice),
            'callback-loop-check' => $this->runCallbackLoopCheck($voice),
            'sdk-check' => $this->runSdkCheck($voice),
            'worker-plan' => $this->runWorkerPlan($voice),
            'production-loop-plan' => $this->runProductionLoopPlan($voice),
            'production-loop-smoke' => $this->runProductionLoopSmoke($voice),
            'worker-start-check' => $this->runWorkerStartCheck($voice),
            'runtime-certify' => $certification->certify([
                'runtime' => $runtime,
                'base_url' => (string) ($this->option('base-url') ?: 'http://atlas.test'),
                'require_sdk' => (bool) $this->option('require-sdk'),
            ]),
            'health' => $voice->health(['runtime' => (string) $this->option('runtime')]),
            'readiness' => $voice->readiness(['hours' => (int) $this->option('hours')]),
            'rivals' => $rivals->report([
                'hours' => (int) $this->option('hours'),
                'runtime' => $runtime,
                'base_url' => (string) ($this->option('base-url') ?: 'http://atlas.test'),
                'require_sdk' => (bool) $this->option('require-sdk'),
            ]),
            default => [
                'schema_version' => 'atlas.voice_realtime.command.v1',
                'status' => 'invalid_action',
                'allowed_actions' => $this->allowedActions(),
                'received_action' => $action,
            ],
        };

        $exitCode = in_array($payload['status'] ?? null, ['invalid_action', 'failed'], true)
            ? self::FAILURE
            : self::SUCCESS;

        if ((bool) $this->option('json')) {
            $this->line(json_encode($payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));

            return $exitCode;
        }

        $this->components->twoColumnDetail('<fg=bright-blue;options=bold>Atlas AI Voice Realtime</>', (string) ($payload['status'] ?? 'unknown'));
        $this->components->twoColumnDetail('Schema', (string) ($payload['schema_version'] ?? 'unknown'));
        $this->components->twoColumnDetail('Surface', (string) ($payload['surface_id'] ?? 'voice_realtime'));
        $this->components->twoColumnDetail('Runtime', (string) ($payload['runtime_id'] ?? $this->option('runtime')));
        $this->components->twoColumnDetail('Mobile first', data_get($payload, 'mobile_first', false) ? 'yes' : 'no');
        $this->components->twoColumnDetail('Kernel decides', data_get($payload, 'kernel_is_decision_authority', true) ? 'yes' : 'no');
        $this->components->twoColumnDetail('Internal auth', (string) data_get($payload, 'auth_contract.internal_api.middleware', 'atlas.token'));
        $this->components->twoColumnDetail('Mobile auth', (string) data_get($payload, 'auth_contract.mobile_api.middleware', 'atlas.mobile.bearer'));
        $this->components->twoColumnDetail('Raw audio persistence', data_get($payload, 'contract.raw_audio_persistence_allowed', false) ? 'allowed' : 'forbidden');

        if (($payload['schema_version'] ?? null) === 'atlas.voice_realtime.runtime_bootstrap.v1') {
            $this->components->twoColumnDetail('Entrypoint', (string) data_get($payload, 'entrypoint.module').'::'.(string) data_get($payload, 'entrypoint.factory'));
            $this->components->twoColumnDetail('Kernel', (string) data_get($payload, 'kernel.base_url'));
            $this->components->twoColumnDetail('Contract hash', (string) data_get($payload, 'contract_hash'));
        }
        if (($payload['schema_version'] ?? null) === 'atlas.voice_realtime.scripted_worker_example.v1') {
            $this->components->twoColumnDetail('Example', (string) data_get($payload, 'example_path'));
            $this->components->twoColumnDetail('Worker', (string) data_get($payload, 'entrypoint.worker_adapter'));
            $this->components->twoColumnDetail('SDK adapter', (string) data_get($payload, 'entrypoint.sdk_adapter'));
            $this->line((string) data_get($payload, 'command'));
        }
        if (($payload['schema_version'] ?? null) === 'atlas.voice_realtime.runtime_dependency_plan.v1') {
            $this->components->twoColumnDetail('Manifest', (string) data_get($payload, 'manifest_path'));
            $this->components->twoColumnDetail('Core deps', (string) count((array) data_get($payload, 'core_dependencies', [])));
            $this->components->twoColumnDetail('Optional LiveKit deps', (string) count((array) data_get($payload, 'optional_livekit_packages', [])));
            $this->components->twoColumnDetail('Activation gate', (string) data_get($payload, 'activation_gate'));
            $this->line((string) data_get($payload, 'install_command'));
        }
        if (($payload['schema_version'] ?? null) === 'atlas.voice_realtime.preflight_command.v1') {
            $this->components->twoColumnDetail('Runtime preflight', (string) data_get($payload, 'preflight.status'));
            $this->components->twoColumnDetail('Require SDK', data_get($payload, 'require_sdk', false) ? 'yes' : 'no');
            $this->components->twoColumnDetail('Next action', (string) data_get($payload, 'preflight.next_action'));
            $this->line((string) data_get($payload, 'command'));
        }
        if (($payload['schema_version'] ?? null) === 'atlas.voice_realtime.activation_command.v1') {
            $this->components->twoColumnDetail('Activation contract', (string) data_get($payload, 'activation.status'));
            $this->components->twoColumnDetail('Next action', (string) data_get($payload, 'activation.next_action'));
            $this->components->twoColumnDetail('SDK ready', data_get($payload, 'activation.gates.sdk_ready', false) ? 'yes' : 'no');
            $this->components->twoColumnDetail('Worker allowed', data_get($payload, 'activation.gates.worker_plan_allows_start', false) ? 'yes' : 'no');
            $this->line((string) data_get($payload, 'command'));
        }
        if (($payload['schema_version'] ?? null) === 'atlas.voice_realtime.scripted_smoke.v1') {
            $this->components->twoColumnDetail('Mock Kernel', data_get($payload, 'mock_kernel', false) ? 'yes' : 'no');
            $this->components->twoColumnDetail('Example', (string) data_get($payload, 'example_path'));
            $this->components->twoColumnDetail('Result count', (string) data_get($payload, 'scripted_worker.result_count', '0'));
            $this->components->twoColumnDetail('Active sessions', (string) data_get($payload, 'scripted_worker.active_session_count', '0'));
            $this->line((string) data_get($payload, 'command'));
        }
        if (($payload['schema_version'] ?? null) === 'atlas.voice_realtime.callback_smoke.v1') {
            $this->components->twoColumnDetail('Mock Kernel', data_get($payload, 'mock_kernel', false) ? 'yes' : 'no');
            $this->components->twoColumnDetail('Example', (string) data_get($payload, 'example_path'));
            $this->components->twoColumnDetail('Callback status', (string) data_get($payload, 'callback.status'));
            $this->components->twoColumnDetail('Active sessions', (string) data_get($payload, 'callback.active_session_count', '0'));
            $this->line((string) data_get($payload, 'command'));
        }
        if (($payload['schema_version'] ?? null) === 'atlas.voice_realtime.callback_sequence_smoke.v1') {
            $this->components->twoColumnDetail('Mock Kernel', data_get($payload, 'mock_kernel', false) ? 'yes' : 'no');
            $this->components->twoColumnDetail('Example', (string) data_get($payload, 'example_path'));
            $this->components->twoColumnDetail('Sequence status', (string) data_get($payload, 'callback_sequence.status'));
            $this->components->twoColumnDetail('Result count', (string) data_get($payload, 'callback_sequence.result_count', '0'));
            $this->components->twoColumnDetail('Active sessions', (string) data_get($payload, 'callback_sequence.active_session_count', '0'));
            $this->line((string) data_get($payload, 'command'));
        }
        if (($payload['schema_version'] ?? null) === 'atlas.voice_realtime.callback_loop_contract.v1') {
            $this->components->twoColumnDetail('Translation layer', data_get($payload, 'translation_layer_ready', false) ? 'ready' : 'blocked');
            $this->components->twoColumnDetail('Production SDK loop', data_get($payload, 'production_sdk_loop_wired', false) ? 'wired' : 'blocked');
            $this->components->twoColumnDetail('Worker start callback gate', data_get($payload, 'worker_start_callback_loop_wired', false) ? 'wired' : 'blocked');
            $this->components->twoColumnDetail('Next action', (string) data_get($payload, 'next_action'));
        }
        if (($payload['schema_version'] ?? null) === 'atlas.voice_realtime.sdk_check.v1') {
            $this->components->twoColumnDetail('LiveKit', data_get($payload, 'packages.livekit', false) ? 'yes' : 'no');
            $this->components->twoColumnDetail('LiveKit Agents', data_get($payload, 'packages.livekit.agents', false) ? 'yes' : 'no');
            $this->components->twoColumnDetail('Next action', (string) data_get($payload, 'next_action'));
        }
        if (($payload['schema_version'] ?? null) === 'atlas.voice_realtime.worker_plan.v1') {
            $this->components->twoColumnDetail('Worker adapter', (string) data_get($payload, 'entrypoint.worker_adapter'));
            $this->components->twoColumnDetail('SDK adapter', (string) data_get($payload, 'entrypoint.sdk_adapter'));
            $this->components->twoColumnDetail('Can start worker', data_get($payload, 'activation.can_start_long_running_worker', false) ? 'yes' : 'no');
            $this->components->twoColumnDetail('Next action', (string) data_get($payload, 'next_action'));
        }
        if (($payload['schema_version'] ?? null) === 'atlas.voice_realtime.production_loop_plan.v1') {
            $this->components->twoColumnDetail('Production SDK loop', data_get($payload, 'production_sdk_loop_wired', false) ? 'wired' : 'blocked');
            $this->components->twoColumnDetail('Worker start gate', data_get($payload, 'worker_start_callback_loop_wired', false) ? 'wired' : 'blocked');
            $this->components->twoColumnDetail('SDK wiring', (string) data_get($payload, 'sdk_wiring_contract.status'));
            $this->components->twoColumnDetail('Next action', (string) data_get($payload, 'next_action'));
        }
        if (($payload['schema_version'] ?? null) === 'atlas.voice_realtime.production_loop_smoke.v1') {
            $this->components->twoColumnDetail('Production loop smoke', (string) data_get($payload, 'status'));
            $this->components->twoColumnDetail('Daemon started', data_get($payload, 'daemon_started', false) ? 'yes' : 'no');
            $this->components->twoColumnDetail('SDK imported', data_get($payload, 'sdk_imported', false) ? 'yes' : 'no');
            $this->components->twoColumnDetail('Result count', (string) data_get($payload, 'result_count', '0'));
        }
        if (($payload['schema_version'] ?? null) === 'atlas.voice_realtime.worker_start.v1') {
            $this->components->twoColumnDetail('Started', data_get($payload, 'started', false) ? 'yes' : 'no');
            $this->components->twoColumnDetail('Reason', (string) data_get($payload, 'reason'));
            $this->components->twoColumnDetail('Plan status', (string) data_get($payload, 'worker_plan.status'));
            $this->components->twoColumnDetail('Production loop status', (string) data_get($payload, 'production_loop_plan.status'));
            $this->components->twoColumnDetail('Activation status', (string) data_get($payload, 'activation_contract.status'));
            $this->components->twoColumnDetail('Activation next action', (string) data_get($payload, 'activation_next_action'));
        }
        if (($payload['schema_version'] ?? null) === 'atlas.voice_realtime.runtime_certification.v1') {
            $this->components->twoColumnDetail('Certification', (string) data_get($payload, 'status'));
            $this->components->twoColumnDetail('Passed gates', (string) data_get($payload, 'summary.passed_gates', '0'));
            $this->components->twoColumnDetail('Failed gates', (string) data_get($payload, 'summary.failed_gates', '0'));
            $this->components->twoColumnDetail('Daemon start blocked safely', data_get($payload, 'gates.worker_start_blocked_safely.passed', false) ? 'yes' : 'no');
            $this->components->twoColumnDetail('Next action', (string) data_get($payload, 'next_action'));
        }

        if (($payload['required_callbacks'] ?? []) !== []) {
            $this->table(
                ['callback', 'internal endpoint', 'mobile endpoint'],
                collect($payload['required_callbacks'])
                    ->map(fn (string $endpoint, string $callback): array => [
                        $callback,
                        $endpoint,
                        (string) data_get($payload, "mobile_required_callbacks.{$callback}", ''),
                    ])
                    ->values()
                    ->all(),
            );
        }

        if (($payload['status'] ?? null) === 'invalid_action') {
            $this->error('Invalid action. Use '.implode(', ', $this->allowedActions()).'.');
        }

        return $exitCode;
    }

    /**
     * @return array<string,mixed>
     */
    private function runScriptedSmoke(AtlasVoiceRealtimeService $voice): array
    {
        $examplePath = base_path('runtimes/python/voice_realtime/scripted-events.example.json');
        $bootstrapPath = tempnam(sys_get_temp_dir(), 'atlas-voice-bootstrap-');
        if ($bootstrapPath === false) {
            return [
                'schema_version' => 'atlas.voice_realtime.scripted_smoke.v1',
                'status' => 'failed',
                'surface_id' => 'voice_realtime',
                'runtime_id' => (string) $this->option('runtime'),
                'mock_kernel' => true,
                'failure' => 'could_not_create_temp_bootstrap',
            ];
        }

        $bootstrap = $voice->runtimeBootstrapManifest([
            'runtime' => (string) $this->option('runtime'),
            'base_url' => (string) ($this->option('base-url') ?: 'http://atlas.test'),
        ]);
        file_put_contents($bootstrapPath, json_encode($bootstrap, JSON_THROW_ON_ERROR | JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));

        $process = new Process([
            'python3',
            '-m',
            'atlas_voice_agent.main',
            '--bootstrap',
            $bootstrapPath,
            '--mock-kernel',
            '--scripted-events',
            $examplePath,
        ], base_path(), [
            'PYTHONPATH' => base_path('runtimes/python/voice_realtime'),
        ]);

        try {
            $process->run();
            $decoded = json_decode($process->getOutput(), true);

            return [
                'schema_version' => 'atlas.voice_realtime.scripted_smoke.v1',
                'status' => $process->isSuccessful() && is_array($decoded) ? 'passed' : 'failed',
                'surface_id' => 'voice_realtime',
                'runtime_id' => (string) $this->option('runtime'),
                'mock_kernel' => true,
                'exit_code' => $process->getExitCode(),
                'example_path' => 'runtimes/python/voice_realtime/scripted-events.example.json',
                'command' => 'PYTHONPATH=runtimes/python/voice_realtime python3 -m atlas_voice_agent.main --bootstrap <generated> --mock-kernel --scripted-events runtimes/python/voice_realtime/scripted-events.example.json',
                'scripted_worker' => $this->sanitizeSmokePayload(is_array($decoded) ? $decoded : null),
                'stderr_hash' => $process->getErrorOutput() !== '' ? hash('sha256', $process->getErrorOutput()) : null,
            ];
        } finally {
            @unlink($bootstrapPath);
        }
    }

    /**
     * @return array<string,mixed>
     */
    private function runPreflight(AtlasVoiceRealtimeService $voice): array
    {
        $baseUrl = (string) ($this->option('base-url') ?: 'http://atlas.test');
        $bootstrap = $voice->runtimeBootstrapManifest([
            'runtime' => (string) $this->option('runtime'),
            'base_url' => $baseUrl,
        ]);
        $bootstrapPath = tempnam(sys_get_temp_dir(), 'atlas-voice-bootstrap-');
        $envPath = tempnam(sys_get_temp_dir(), 'atlas-voice-env-');
        if ($bootstrapPath === false || $envPath === false) {
            return [
                'schema_version' => 'atlas.voice_realtime.preflight_command.v1',
                'status' => 'failed',
                'surface_id' => 'voice_realtime',
                'runtime_id' => (string) $this->option('runtime'),
                'failure' => 'could_not_create_temp_preflight_files',
            ];
        }

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
        if ((bool) $this->option('require-sdk')) {
            $args[] = '--require-sdk';
        }

        $process = new Process([
            'python3',
            '-m',
            'atlas_voice_agent.main',
            ...$args,
        ], base_path(), [
            'PYTHONPATH' => base_path('runtimes/python/voice_realtime'),
        ]);

        try {
            $process->run();
            $decoded = json_decode($process->getOutput(), true);

            return [
                'schema_version' => 'atlas.voice_realtime.preflight_command.v1',
                'status' => $process->isSuccessful() && is_array($decoded) ? (string) ($decoded['status'] ?? 'unknown') : 'failed',
                'surface_id' => 'voice_realtime',
                'runtime_id' => (string) $this->option('runtime'),
                'require_sdk' => (bool) $this->option('require-sdk'),
                'exit_code' => $process->getExitCode(),
                'command' => 'PYTHONPATH=runtimes/python/voice_realtime python3 -m atlas_voice_agent.main --env-file <generated> --preflight'.((bool) $this->option('require-sdk') ? ' --require-sdk' : ''),
                'preflight' => $this->sanitizeSmokePayload(is_array($decoded) ? $decoded : null),
                'stderr_hash' => $process->getErrorOutput() !== '' ? hash('sha256', $process->getErrorOutput()) : null,
            ];
        } finally {
            @unlink($bootstrapPath);
            @unlink($envPath);
        }
    }

    /**
     * @return array<string,mixed>
     */
    private function runActivationContract(AtlasVoiceRealtimeService $voice): array
    {
        $baseUrl = (string) ($this->option('base-url') ?: 'http://atlas.test');
        $bootstrap = $voice->runtimeBootstrapManifest([
            'runtime' => (string) $this->option('runtime'),
            'base_url' => $baseUrl,
        ]);
        $bootstrapPath = tempnam(sys_get_temp_dir(), 'atlas-voice-bootstrap-');
        $envPath = tempnam(sys_get_temp_dir(), 'atlas-voice-env-');
        if ($bootstrapPath === false || $envPath === false) {
            return [
                'schema_version' => 'atlas.voice_realtime.activation_command.v1',
                'status' => 'failed',
                'surface_id' => 'voice_realtime',
                'runtime_id' => (string) $this->option('runtime'),
                'failure' => 'could_not_create_temp_activation_files',
            ];
        }

        file_put_contents($bootstrapPath, json_encode($bootstrap, JSON_THROW_ON_ERROR | JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
        file_put_contents($envPath, implode("\n", [
            'ATLAS_BASE_URL='.$baseUrl,
            'ATLAS_TOKEN=activation-token',
            'ATLAS_VOICE_BOOTSTRAP='.$bootstrapPath,
            'LIVEKIT_URL=http://livekit.test',
            'LIVEKIT_API_KEY=activation-key',
            'LIVEKIT_API_SECRET=activation-secret',
            'ATLAS_VOICE_STT_PROVIDER=configurable',
            'ATLAS_VOICE_TTS_PROVIDER=configurable',
        ]));

        $process = new Process([
            'python3',
            '-m',
            'atlas_voice_agent.main',
            '--env-file',
            $envPath,
            '--activation-contract',
        ], base_path(), [
            'PYTHONPATH' => base_path('runtimes/python/voice_realtime'),
        ]);

        try {
            $process->run();
            $decoded = json_decode($process->getOutput(), true);

            return [
                'schema_version' => 'atlas.voice_realtime.activation_command.v1',
                'status' => $process->isSuccessful() && is_array($decoded) ? (string) ($decoded['status'] ?? 'unknown') : 'failed',
                'surface_id' => 'voice_realtime',
                'runtime_id' => (string) $this->option('runtime'),
                'exit_code' => $process->getExitCode(),
                'command' => 'PYTHONPATH=runtimes/python/voice_realtime python3 -m atlas_voice_agent.main --env-file <generated> --activation-contract',
                'activation' => $this->sanitizeSmokePayload(is_array($decoded) ? $decoded : null),
                'stderr_hash' => $process->getErrorOutput() !== '' ? hash('sha256', $process->getErrorOutput()) : null,
            ];
        } finally {
            @unlink($bootstrapPath);
            @unlink($envPath);
        }
    }

    /**
     * @return array<string,mixed>
     */
    private function runCallbackSmoke(AtlasVoiceRealtimeService $voice): array
    {
        $examplePath = base_path('runtimes/python/voice_realtime/callback-event.example.json');
        $payload = $this->runPythonBootstrapCommand($voice, [
            '--mock-kernel',
            '--callback-event',
            $examplePath,
        ], 'atlas.voice_realtime.callback_route.v1');

        return [
            'schema_version' => 'atlas.voice_realtime.callback_smoke.v1',
            'status' => (($payload['status'] ?? null) === 'callback_routed') ? 'passed' : 'failed',
            'surface_id' => 'voice_realtime',
            'runtime_id' => (string) $this->option('runtime'),
            'mock_kernel' => true,
            'example_path' => 'runtimes/python/voice_realtime/callback-event.example.json',
            'command' => 'PYTHONPATH=runtimes/python/voice_realtime python3 -m atlas_voice_agent.main --bootstrap <generated> --mock-kernel --callback-event runtimes/python/voice_realtime/callback-event.example.json',
            'callback' => $payload,
        ];
    }

    /**
     * @return array<string,mixed>
     */
    private function runCallbackSequenceSmoke(AtlasVoiceRealtimeService $voice): array
    {
        $examplePath = base_path('runtimes/python/voice_realtime/callback-events.example.json');
        $payload = $this->runPythonBootstrapCommand($voice, [
            '--mock-kernel',
            '--callback-events',
            $examplePath,
        ], 'atlas.voice_realtime.callback_sequence.v1');

        return [
            'schema_version' => 'atlas.voice_realtime.callback_sequence_smoke.v1',
            'status' => (($payload['status'] ?? null) === 'callback_sequence_routed') ? 'passed' : 'failed',
            'surface_id' => 'voice_realtime',
            'runtime_id' => (string) $this->option('runtime'),
            'mock_kernel' => true,
            'example_path' => 'runtimes/python/voice_realtime/callback-events.example.json',
            'command' => 'PYTHONPATH=runtimes/python/voice_realtime python3 -m atlas_voice_agent.main --bootstrap <generated> --mock-kernel --callback-events runtimes/python/voice_realtime/callback-events.example.json',
            'callback_sequence' => $payload,
        ];
    }

    /**
     * @return array<string,mixed>
     */
    private function runSdkCheck(AtlasVoiceRealtimeService $voice): array
    {
        return $this->runPythonBootstrapCommand($voice, [
            '--sdk-check',
        ], 'atlas.voice_realtime.sdk_check.v1');
    }

    /**
     * @return array<string,mixed>
     */
    private function runCallbackLoopCheck(AtlasVoiceRealtimeService $voice): array
    {
        return $this->runPythonBootstrapCommand($voice, [
            '--callback-loop-check',
        ], 'atlas.voice_realtime.callback_loop_contract.v1');
    }

    /**
     * @return array<string,mixed>
     */
    private function runWorkerPlan(AtlasVoiceRealtimeService $voice): array
    {
        return $this->runPythonBootstrapCommand($voice, [
            '--worker-plan',
        ], 'atlas.voice_realtime.worker_plan.v1');
    }

    /**
     * @return array<string,mixed>
     */
    private function runProductionLoopPlan(AtlasVoiceRealtimeService $voice): array
    {
        return $this->runPythonBootstrapCommand($voice, [
            '--production-loop-plan',
        ], 'atlas.voice_realtime.production_loop_plan.v1');
    }

    /**
     * @return array<string,mixed>
     */
    private function runProductionLoopSmoke(AtlasVoiceRealtimeService $voice): array
    {
        $examplePath = base_path('runtimes/python/voice_realtime/sdk-events.example.json');

        return $this->runPythonBootstrapCommand($voice, [
            '--mock-kernel',
            '--sdk-events',
            $examplePath,
        ], 'atlas.voice_realtime.production_loop_smoke.v1');
    }

    /**
     * @return array<string,mixed>
     */
    private function runWorkerStartCheck(AtlasVoiceRealtimeService $voice): array
    {
        $baseUrl = (string) ($this->option('base-url') ?: 'http://atlas.test');
        $bootstrap = $voice->runtimeBootstrapManifest([
            'runtime' => (string) $this->option('runtime'),
            'base_url' => $baseUrl,
        ]);
        $bootstrapPath = tempnam(sys_get_temp_dir(), 'atlas-voice-bootstrap-');
        $envPath = tempnam(sys_get_temp_dir(), 'atlas-voice-env-');
        if ($bootstrapPath === false || $envPath === false) {
            return [
                'schema_version' => 'atlas.voice_realtime.worker_start.v1',
                'status' => 'failed',
                'surface_id' => 'voice_realtime',
                'runtime_id' => (string) $this->option('runtime'),
                'failure' => 'could_not_create_temp_worker_start_files',
            ];
        }

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

        $process = new Process([
            'python3',
            '-m',
            'atlas_voice_agent.main',
            '--env-file',
            $envPath,
            '--start-worker',
        ], base_path(), [
            'PYTHONPATH' => base_path('runtimes/python/voice_realtime'),
        ]);

        try {
            $process->run();
            $decoded = json_decode($process->getOutput(), true);
            if (! is_array($decoded)) {
                return [
                    'schema_version' => 'atlas.voice_realtime.worker_start.v1',
                    'status' => 'failed',
                    'surface_id' => 'voice_realtime',
                    'runtime_id' => (string) $this->option('runtime'),
                    'exit_code' => $process->getExitCode(),
                    'stderr_hash' => $process->getErrorOutput() !== '' ? hash('sha256', $process->getErrorOutput()) : null,
                ];
            }

            $payload = $this->sanitizeSmokePayload($decoded);
            $payload['command'] = 'PYTHONPATH=runtimes/python/voice_realtime python3 -m atlas_voice_agent.main --env-file <generated> --start-worker';
            $payload['exit_code'] = $process->getExitCode();
            $payload['stderr_hash'] = $process->getErrorOutput() !== '' ? hash('sha256', $process->getErrorOutput()) : null;

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
    private function runPythonBootstrapCommand(AtlasVoiceRealtimeService $voice, array $extraArgs, string $schemaVersion): array
    {
        $bootstrapPath = tempnam(sys_get_temp_dir(), 'atlas-voice-bootstrap-');
        if ($bootstrapPath === false) {
            return [
                'schema_version' => $schemaVersion,
                'status' => 'failed',
                'surface_id' => 'voice_realtime',
                'runtime_id' => (string) $this->option('runtime'),
                'failure' => 'could_not_create_temp_bootstrap',
            ];
        }

        $bootstrap = $voice->runtimeBootstrapManifest([
            'runtime' => (string) $this->option('runtime'),
            'base_url' => (string) ($this->option('base-url') ?: 'http://atlas.test'),
        ]);
        file_put_contents($bootstrapPath, json_encode($bootstrap, JSON_THROW_ON_ERROR | JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));

        $process = new Process([
            'python3',
            '-m',
            'atlas_voice_agent.main',
            '--bootstrap',
            $bootstrapPath,
            ...$extraArgs,
        ], base_path(), [
            'PYTHONPATH' => base_path('runtimes/python/voice_realtime'),
        ]);

        try {
            $process->run();
            $decoded = json_decode($process->getOutput(), true);
            if (! is_array($decoded)) {
                return [
                    'schema_version' => $schemaVersion,
                    'status' => 'failed',
                    'surface_id' => 'voice_realtime',
                    'runtime_id' => (string) $this->option('runtime'),
                    'exit_code' => $process->getExitCode(),
                    'stderr_hash' => $process->getErrorOutput() !== '' ? hash('sha256', $process->getErrorOutput()) : null,
                ];
            }

            return $this->sanitizeSmokePayload($decoded);
        } finally {
            @unlink($bootstrapPath);
        }
    }

    /**
     * @param  array<string,mixed>|null  $payload
     * @return array<string,mixed>|null
     */
    private function sanitizeSmokePayload(?array $payload): ?array
    {
        if ($payload === null) {
            return null;
        }

        $forbidden = ['access_token', 'token', 'livekit_token', 'api_key', 'api_secret'];

        return collect($payload)
            ->reject(fn (mixed $_, string|int $key): bool => in_array((string) $key, $forbidden, true))
            ->map(fn (mixed $value): mixed => is_array($value) ? $this->sanitizeSmokePayload($value) : $value)
            ->all();
    }

    /**
     * @return array<int,string>
     */
    private function allowedActions(): array
    {
        return ['contract', 'bootstrap', 'dependencies', 'preflight', 'activation-contract', 'scripted-example', 'scripted-smoke', 'callback-smoke', 'callback-sequence-smoke', 'callback-loop-check', 'sdk-check', 'worker-plan', 'production-loop-plan', 'production-loop-smoke', 'worker-start-check', 'runtime-certify', 'health', 'readiness', 'rivals'];
    }
}
