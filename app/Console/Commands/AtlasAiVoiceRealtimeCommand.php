<?php

namespace App\Console\Commands;

use App\Services\Ai\Voice\AtlasVoiceLiveKitTokenIssuer;
use App\Services\Ai\Voice\AtlasVoiceLiveKitServerProbe;
use App\Services\Ai\Voice\AtlasVoiceProductionPromotionReviewBundleService;
use App\Services\Ai\Voice\AtlasVoiceRealtimeService;
use App\Services\Ai\Voice\AtlasVoiceRivalsRunner;
use App\Services\Ai\Voice\AtlasVoiceRuntimeCertificationService;
use App\Services\Ai\Voice\AtlasVoiceRuntimeEventNormalizer;
use Illuminate\Console\Command;
use Symfony\Component\Process\Exception\ProcessTimedOutException;
use Symfony\Component\Process\Process;

class AtlasAiVoiceRealtimeCommand extends Command
{
    private const PYTHON_COMMAND_TIMEOUT_SECONDS = 30;

    protected $signature = 'atlas:ai:voice
        {action=contract : Action to inspect: contract, bootstrap, dependencies, dependency-install-plan, preflight, activation-contract, scripted-example, scripted-smoke, callback-smoke, callback-sequence-smoke, callback-loop-check, sdk-check, token-issuer-plan, token-issuer-smoke, livekit-server-probe, worker-plan, production-loop-plan, product-loop-check, daemon-supervisor-check, pre-start-health-checks-smoke, production-loop-smoke, worker-start-check, normalize-event, normalize-sequence, runtime-certify, promotion-review-packet, health, readiness or rivals}
        {--runtime=livekit_agents_sdk : Runtime id for contract inspection}
        {--base-url= : Kernel base URL for bootstrap manifests}
        {--hours=24 : Readiness/evidence window in hours}
        {--event-file= : JSON file with one runtime event or an events[] sequence for normalization actions}
        {--require-sdk : Make preflight fail when LiveKit Agents SDK is not installed}
        {--callback-loop-wired : Declare the governed callback router is wired for fail-closed activation checks}
        {--production-sdk-loop-wired : Declare the real LiveKit SDK loop is wired for fail-closed production checks}
        {--production-promotion-approved : Legacy declaration only; a review file is required for actual promotion approval}
        {--production-promotion-review-file= : JSON review receipt file for the production promotion gate}
        {--promotion-review-bundle-file= : JSON Kernel promotion-review bundle that the human review receipt must match}
        {--daemon-implementation-review-file= : JSON review receipt file for the daemon implementation gate}
        {--ephemeral-test-config : Run token issuer smoke with in-memory non-production config and restore config before exit}
        {--python-bin= : Python binary for local Voice runtime probes. Defaults to ATLAS_VOICE_PYTHON_BIN/config/python3}
        {--json : Print machine-readable JSON}';

    protected $description = 'Inspect the Atlas AI Voice Realtime surface contract.';

    public function handle(
        AtlasVoiceRealtimeService $voice,
        AtlasVoiceLiveKitTokenIssuer $liveKitTokens,
        AtlasVoiceLiveKitServerProbe $liveKitServerProbe,
        AtlasVoiceRivalsRunner $rivals,
        AtlasVoiceRuntimeCertificationService $certification,
        AtlasVoiceRuntimeEventNormalizer $runtimeEvents,
        AtlasVoiceProductionPromotionReviewBundleService $promotionReviews,
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

        config(['atlas_ai.voice_realtime.python_binary' => $this->pythonBinary()]);

        $payload = match ($action) {
            'contract' => $voice->runtimeContract(['runtime' => $runtime]),
            'bootstrap' => $voice->runtimeBootstrapManifest([
                'runtime' => $runtime,
                'base_url' => (string) ($this->option('base-url') ?: config('app.url')),
            ]),
            'dependencies' => $voice->runtimeDependencyPlan(),
            'dependency-install-plan' => $this->runDependencyInstallPlan($voice),
            'preflight' => $this->runPreflight($voice),
            'activation-contract' => $this->runActivationContract($voice),
            'scripted-example' => $voice->scriptedWorkerExample(),
            'scripted-smoke' => $this->runScriptedSmoke($voice),
            'callback-smoke' => $this->runCallbackSmoke($voice),
            'callback-sequence-smoke' => $this->runCallbackSequenceSmoke($voice),
            'callback-loop-check' => $this->runCallbackLoopCheck($voice),
            'sdk-check' => $this->runSdkCheck($voice),
            'token-issuer-plan' => $liveKitTokens->configurationPlan(),
            'token-issuer-smoke' => $liveKitTokens->smoke((bool) $this->option('ephemeral-test-config')),
            'livekit-server-probe' => $liveKitServerProbe->probe(),
            'worker-plan' => $this->runWorkerPlan($voice),
            'production-loop-plan' => $this->runProductionLoopPlan($voice),
            'product-loop-check' => $this->runProductLoopCheck($voice),
            'daemon-supervisor-check' => $this->runDaemonSupervisorCheck($voice),
            'pre-start-health-checks-smoke' => $this->runPreStartHealthChecksSmoke($voice),
            'production-loop-smoke' => $this->runProductionLoopSmoke($voice),
            'worker-start-check' => $this->runWorkerStartCheck($voice),
            'normalize-event' => $this->runNormalizeEvent($runtimeEvents),
            'normalize-sequence' => $this->runNormalizeSequence($runtimeEvents),
            'runtime-certify' => $certification->certify([
                'runtime' => $runtime,
                'base_url' => (string) ($this->option('base-url') ?: 'http://atlas.test'),
                'require_sdk' => (bool) $this->option('require-sdk'),
                'callback_loop_wired' => (bool) $this->option('callback-loop-wired'),
                'production_sdk_loop_wired' => (bool) $this->option('production-sdk-loop-wired'),
            ]),
            'promotion-review-packet' => $promotionReviews->bundle([
                'runtime' => $runtime,
                'base_url' => (string) ($this->option('base-url') ?: 'http://atlas.test'),
                'hours' => (int) $this->option('hours'),
                'callback_loop_wired' => (bool) $this->option('callback-loop-wired'),
                'production_sdk_loop_wired' => (bool) $this->option('production-sdk-loop-wired'),
            ]),
            'health' => $voice->health(['runtime' => (string) $this->option('runtime')]),
            'readiness' => $voice->readiness(['hours' => (int) $this->option('hours')]),
            'rivals' => $rivals->report([
                'hours' => (int) $this->option('hours'),
                'runtime' => $runtime,
                'base_url' => (string) ($this->option('base-url') ?: 'http://atlas.test'),
                'require_sdk' => (bool) $this->option('require-sdk'),
                'callback_loop_wired' => (bool) $this->option('callback-loop-wired'),
                'production_sdk_loop_wired' => (bool) $this->option('production-sdk-loop-wired'),
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
        if (($payload['schema_version'] ?? null) === 'atlas.voice_realtime.dependency_install_plan.v1') {
            $this->components->twoColumnDetail('Dependency install plan', (string) data_get($payload, 'status'));
            $this->components->twoColumnDetail('Requirements', (string) data_get($payload, 'requirements_file'));
            $this->components->twoColumnDetail('Pip executed', data_get($payload, 'pip_execution_attempted', true) ? 'yes' : 'no');
            $this->components->twoColumnDetail('SDK imported', data_get($payload, 'sdk_imported', true) ? 'yes' : 'no');
            $this->components->twoColumnDetail('Next action', (string) data_get($payload, 'next_action'));
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
        if (($payload['schema_version'] ?? null) === 'atlas.voice_realtime.livekit_token_issuer_config_plan.v1') {
            $this->components->twoColumnDetail('Token issuer plan', (string) data_get($payload, 'status'));
            $this->components->twoColumnDetail('Secrets exposed', data_get($payload, 'security_contract.secrets_exposed', true) ? 'yes' : 'no');
            $this->components->twoColumnDetail('Writes env', data_get($payload, 'security_contract.writes_env_file', true) ? 'yes' : 'no');
            $this->components->twoColumnDetail('Starts daemon', data_get($payload, 'security_contract.starts_daemon', true) ? 'yes' : 'no');
            $this->components->twoColumnDetail('Missing env', implode(', ', (array) data_get($payload, 'missing_env', [])) ?: 'none');
            $this->components->twoColumnDetail('Next action', (string) data_get($payload, 'next_action'));
        }
        if (($payload['schema_version'] ?? null) === 'atlas.voice_realtime.livekit_token_issuer_smoke.v1') {
            $this->components->twoColumnDetail('Token issuer smoke', (string) data_get($payload, 'status'));
            $this->components->twoColumnDetail('Ephemeral test config', data_get($payload, 'ephemeral_test_config', false) ? 'yes' : 'no');
            $this->components->twoColumnDetail('Production readiness', (string) data_get($payload, 'production_readiness'));
            $this->components->twoColumnDetail('Token issued', data_get($payload, 'token_issued', false) ? 'yes' : 'no');
            $this->components->twoColumnDetail('Access token exposed', data_get($payload, 'access_token_exposed', true) ? 'yes' : 'no');
            $this->components->twoColumnDetail('Starts daemon', data_get($payload, 'security_contract.starts_daemon', true) ? 'yes' : 'no');
            $this->components->twoColumnDetail('Next action', (string) data_get($payload, 'next_action'));
        }
        if (($payload['schema_version'] ?? null) === 'atlas.voice_realtime.livekit_server_probe.v1') {
            $this->components->twoColumnDetail('LiveKit server probe', (string) data_get($payload, 'status'));
            $this->components->twoColumnDetail('Configured', data_get($payload, 'configured', false) ? 'yes' : 'no');
            $this->components->twoColumnDetail('URL', (string) data_get($payload, 'livekit_url_redacted', 'missing'));
            $this->components->twoColumnDetail('Reachable', data_get($payload, 'probe.reachable', false) ? 'yes' : 'no');
            $this->components->twoColumnDetail('Secrets exposed', data_get($payload, 'security_contract.secrets_exposed', true) ? 'yes' : 'no');
            $this->components->twoColumnDetail('Daemon started', data_get($payload, 'security_contract.daemon_started', true) ? 'yes' : 'no');
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
        if (($payload['schema_version'] ?? null) === 'atlas.voice_realtime.product_loop_check.v1') {
            $this->components->twoColumnDetail('Product loop check', (string) data_get($payload, 'status'));
            $this->components->twoColumnDetail('Daemon started', data_get($payload, 'daemon_started', false) ? 'yes' : 'no');
            $this->components->twoColumnDetail('SDK probe safe', data_get($payload, 'gates.sdk_probe_import_safe', false) ? 'yes' : 'no');
            $this->components->twoColumnDetail('Kernel normalizer required', data_get($payload, 'gates.sdk_kernel_normalizer_required', false) ? 'yes' : 'no');
            $this->components->twoColumnDetail('Promotion blocked', data_get($payload, 'gates.production_promotion_blocked', false) ? 'yes' : 'no');
            $this->components->twoColumnDetail('Supervised start plan', (string) data_get($payload, 'supervised_start_plan.status', 'unknown'));
            $this->components->twoColumnDetail('Supervisor health snapshot', (string) data_get($payload, 'supervised_start_plan.supervisor_health_snapshot.schema_version', 'missing'));
            $this->components->twoColumnDetail('Supervisor daemon started', data_get($payload, 'supervised_start_plan.supervisor_health_snapshot.daemon_started', true) ? 'yes' : 'no');
            $this->components->twoColumnDetail('Daemon supervisor execution', (string) data_get($payload, 'daemon_supervisor_execution.schema_version', 'missing'));
            $this->components->twoColumnDetail('Daemon supervisor launch attempted', data_get($payload, 'daemon_supervisor_execution.process_launch_attempted', true) ? 'yes' : 'no');
            $this->components->twoColumnDetail('Process adapter blueprint', (string) data_get($payload, 'daemon_supervisor_execution.process_adapter_blueprint.schema_version', 'missing'));
            $this->components->twoColumnDetail('Process adapter launch allowed', data_get($payload, 'daemon_supervisor_execution.process_adapter_blueprint.launch_allowed', true) ? 'yes' : 'no');
            $this->components->twoColumnDetail('Supervised process adapter', (string) data_get($payload, 'daemon_supervisor_execution.supervised_process_adapter.schema_version', 'missing'));
            $this->components->twoColumnDetail('Supervised adapter launch attempted', data_get($payload, 'daemon_supervisor_execution.supervised_process_adapter.process_launch_attempted', true) ? 'yes' : 'no');
            $this->components->twoColumnDetail('Managed env contract', (string) data_get($payload, 'daemon_supervisor_execution.supervised_process_adapter.managed_environment_contract.schema_version', 'missing'));
            $this->components->twoColumnDetail('Managed env write attempted', data_get($payload, 'daemon_supervisor_execution.supervised_process_adapter.managed_environment_contract.env_file_write_attempted', true) ? 'yes' : 'no');
            $this->components->twoColumnDetail('Launch authorization contract', (string) data_get($payload, 'daemon_supervisor_execution.supervised_process_adapter.launch_authorization_contract.schema_version', 'missing'));
            $this->components->twoColumnDetail('Launch allowed', data_get($payload, 'daemon_supervisor_execution.supervised_process_adapter.launch_authorization_contract.launch_allowed', true) ? 'yes' : 'no');
            $this->components->twoColumnDetail('Managed env writer', (string) data_get($payload, 'daemon_supervisor_execution.supervised_process_adapter.managed_env_writer.schema_version', 'missing'));
            $this->components->twoColumnDetail('Managed env writer wrote file', data_get($payload, 'daemon_supervisor_execution.supervised_process_adapter.managed_env_writer.env_file_write_attempted', true) ? 'yes' : 'no');
            $this->components->twoColumnDetail('Supervised launch execution', (string) data_get($payload, 'daemon_supervisor_execution.supervised_process_adapter.supervised_launch_execution.schema_version', 'missing'));
            $this->components->twoColumnDetail('Supervised launch status', (string) data_get($payload, 'daemon_supervisor_execution.supervised_process_adapter.supervised_launch_execution.status', 'unknown'));
            $this->components->twoColumnDetail('Supervised launch attempted', data_get($payload, 'daemon_supervisor_execution.supervised_process_adapter.supervised_launch_execution.process_launch_attempted', true) ? 'yes' : 'no');
            $this->components->twoColumnDetail('Pre-start health checks available', data_get($payload, 'daemon_supervisor_execution.supervised_process_adapter.supervised_launch_execution.pre_start_health_checks_execution_available', false) ? 'yes' : 'no');
            $this->components->twoColumnDetail('Pre-start health checks executed', data_get($payload, 'daemon_supervisor_execution.supervised_process_adapter.supervised_launch_execution.pre_start_health_checks_executed', true) ? 'yes' : 'no');
            $this->components->twoColumnDetail('Subprocess start contract', (string) data_get($payload, 'daemon_supervisor_execution.supervised_process_adapter.subprocess_start_contract.schema_version', 'missing'));
            $this->components->twoColumnDetail('Subprocess start status', (string) data_get($payload, 'daemon_supervisor_execution.supervised_process_adapter.subprocess_start_contract.status', 'unknown'));
            $this->components->twoColumnDetail('Subprocess start attempted', data_get($payload, 'daemon_supervisor_execution.supervised_process_adapter.subprocess_start_contract.process_launch_attempted', true) ? 'yes' : 'no');
            $this->components->twoColumnDetail('Next action', (string) data_get($payload, 'next_action'));
        }
        if (($payload['schema_version'] ?? null) === 'atlas.voice_realtime.pre_start_health_checks_smoke.v1') {
            $this->components->twoColumnDetail('Pre-start smoke', (string) data_get($payload, 'status'));
            $this->components->twoColumnDetail('Smoke only', data_get($payload, 'smoke_only', false) ? 'yes' : 'no');
            $this->components->twoColumnDetail('Production readiness', (string) data_get($payload, 'production_readiness'));
            $this->components->twoColumnDetail('Temporary env removed', data_get($payload, 'temporary_env_file_removed_after_smoke', false) ? 'yes' : 'no');
            $this->components->twoColumnDetail('Process launch attempted', data_get($payload, 'process_launch_attempted', true) ? 'yes' : 'no');
            $this->components->twoColumnDetail('Pre-start checks', (string) data_get($payload, 'pre_start_health_checks.status'));
            $this->components->twoColumnDetail('Subprocess start contract', (string) data_get($payload, 'subprocess_start_contract.status'));
            $this->components->twoColumnDetail('Next action', (string) data_get($payload, 'next_action'));
        }
        if (($payload['schema_version'] ?? null) === 'atlas.voice_realtime.daemon_supervisor_execution.v1') {
            $this->components->twoColumnDetail('Daemon supervisor execution', (string) data_get($payload, 'status'));
            $this->components->twoColumnDetail('Execution mode', (string) data_get($payload, 'execution_mode'));
            $this->components->twoColumnDetail('Process launch attempted', data_get($payload, 'process_launch_attempted', true) ? 'yes' : 'no');
            $this->components->twoColumnDetail('Daemon started', data_get($payload, 'daemon_started', true) ? 'yes' : 'no');
            $this->components->twoColumnDetail('Start allowed', data_get($payload, 'start_allowed', true) ? 'yes' : 'no');
            $this->components->twoColumnDetail('Process adapter blueprint', (string) data_get($payload, 'process_adapter_blueprint.schema_version', 'missing'));
            $this->components->twoColumnDetail('Process adapter launch allowed', data_get($payload, 'process_adapter_blueprint.launch_allowed', true) ? 'yes' : 'no');
            $this->components->twoColumnDetail('Supervised process adapter', (string) data_get($payload, 'supervised_process_adapter.schema_version', 'missing'));
            $this->components->twoColumnDetail('Supervised adapter launch attempted', data_get($payload, 'supervised_process_adapter.process_launch_attempted', true) ? 'yes' : 'no');
            $this->components->twoColumnDetail('Managed env contract', (string) data_get($payload, 'supervised_process_adapter.managed_environment_contract.schema_version', 'missing'));
            $this->components->twoColumnDetail('Managed env write attempted', data_get($payload, 'supervised_process_adapter.managed_environment_contract.env_file_write_attempted', true) ? 'yes' : 'no');
            $this->components->twoColumnDetail('Launch authorization contract', (string) data_get($payload, 'supervised_process_adapter.launch_authorization_contract.schema_version', 'missing'));
            $this->components->twoColumnDetail('Launch allowed', data_get($payload, 'supervised_process_adapter.launch_authorization_contract.launch_allowed', true) ? 'yes' : 'no');
            $this->components->twoColumnDetail('Managed env writer', (string) data_get($payload, 'supervised_process_adapter.managed_env_writer.schema_version', 'missing'));
            $this->components->twoColumnDetail('Managed env writer wrote file', data_get($payload, 'supervised_process_adapter.managed_env_writer.env_file_write_attempted', true) ? 'yes' : 'no');
            $this->components->twoColumnDetail('Supervised launch execution', (string) data_get($payload, 'supervised_process_adapter.supervised_launch_execution.schema_version', 'missing'));
            $this->components->twoColumnDetail('Supervised launch status', (string) data_get($payload, 'supervised_process_adapter.supervised_launch_execution.status', 'unknown'));
            $this->components->twoColumnDetail('Supervised launch attempted', data_get($payload, 'supervised_process_adapter.supervised_launch_execution.process_launch_attempted', true) ? 'yes' : 'no');
            $this->components->twoColumnDetail('Pre-start health checks available', data_get($payload, 'supervised_process_adapter.supervised_launch_execution.pre_start_health_checks_execution_available', false) ? 'yes' : 'no');
            $this->components->twoColumnDetail('Pre-start health checks executed', data_get($payload, 'supervised_process_adapter.supervised_launch_execution.pre_start_health_checks_executed', true) ? 'yes' : 'no');
            $this->components->twoColumnDetail('Subprocess start contract', (string) data_get($payload, 'supervised_process_adapter.subprocess_start_contract.schema_version', 'missing'));
            $this->components->twoColumnDetail('Subprocess start status', (string) data_get($payload, 'supervised_process_adapter.subprocess_start_contract.status', 'unknown'));
            $this->components->twoColumnDetail('Subprocess start attempted', data_get($payload, 'supervised_process_adapter.subprocess_start_contract.process_launch_attempted', true) ? 'yes' : 'no');
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
            $this->components->twoColumnDetail('Human review approved', data_get($payload, 'production_promotion.human_review_approved', false) ? 'yes' : 'no');
            $this->components->twoColumnDetail('Review receipt valid', data_get($payload, 'production_promotion.review_receipt_valid', false) ? 'yes' : 'no');
            $this->components->twoColumnDetail('Daemon review valid', data_get($payload, 'daemon_implementation.review_receipt_valid', false) ? 'yes' : 'no');
            $this->components->twoColumnDetail('Supervised start plan', (string) data_get($payload, 'supervised_start_plan.status', 'unknown'));
            $this->components->twoColumnDetail('Supervised start allowed', data_get($payload, 'supervised_start_plan.start_allowed', false) ? 'yes' : 'no');
            $this->components->twoColumnDetail('Activation status', (string) data_get($payload, 'activation_contract.status'));
            $this->components->twoColumnDetail('Activation next action', (string) data_get($payload, 'activation_next_action'));
        }
        if (($payload['schema_version'] ?? null) === 'atlas.voice_realtime.runtime_event_normalizer.v1') {
            $this->components->twoColumnDetail('Normalizer', (string) data_get($payload, 'status'));
            $this->components->twoColumnDetail('Valid', data_get($payload, 'valid', false) ? 'yes' : 'no');
            $this->components->twoColumnDetail('Execution enabled', data_get($payload, 'contract.guardrails.runtime_execution_enabled', false) ? 'yes' : 'no');
            $this->components->twoColumnDetail('Provider enabled', data_get($payload, 'contract.guardrails.provider_execution_enabled', false) ? 'yes' : 'no');
        }
        if (($payload['schema_version'] ?? null) === 'atlas.voice_realtime.runtime_certification.v1') {
            $this->components->twoColumnDetail('Certification', (string) data_get($payload, 'status'));
            $this->components->twoColumnDetail('Passed gates', (string) data_get($payload, 'summary.passed_gates', '0'));
            $this->components->twoColumnDetail('Failed gates', (string) data_get($payload, 'summary.failed_gates', '0'));
            $this->components->twoColumnDetail('Daemon start blocked safely', data_get($payload, 'gates.worker_start_blocked_safely.passed', false) ? 'yes' : 'no');
            $this->components->twoColumnDetail('Product loop check', (string) data_get($payload, 'artifacts.product_loop_check.status', 'unknown'));
            $this->components->twoColumnDetail('Product loop next action', (string) data_get($payload, 'artifacts.product_loop_check.next_action', 'unknown'));
            $this->components->twoColumnDetail('Supervisor health snapshot', (string) data_get($payload, 'artifacts.product_loop_check.supervised_start_plan.supervisor_health_snapshot.schema_version', 'missing'));
            $this->components->twoColumnDetail('Supervisor daemon started', data_get($payload, 'artifacts.product_loop_check.supervised_start_plan.supervisor_health_snapshot.daemon_started', true) ? 'yes' : 'no');
            $this->components->twoColumnDetail('Daemon supervisor execution', (string) data_get($payload, 'artifacts.product_loop_check.daemon_supervisor_execution.schema_version', 'missing'));
            $this->components->twoColumnDetail('Daemon supervisor launch attempted', data_get($payload, 'artifacts.product_loop_check.daemon_supervisor_execution.process_launch_attempted', true) ? 'yes' : 'no');
            $this->components->twoColumnDetail('Process adapter blueprint', (string) data_get($payload, 'artifacts.product_loop_check.daemon_supervisor_execution.process_adapter_blueprint.schema_version', 'missing'));
            $this->components->twoColumnDetail('Process adapter launch allowed', data_get($payload, 'artifacts.product_loop_check.daemon_supervisor_execution.process_adapter_blueprint.launch_allowed', true) ? 'yes' : 'no');
            $this->components->twoColumnDetail('Supervised process adapter', (string) data_get($payload, 'artifacts.product_loop_check.daemon_supervisor_execution.supervised_process_adapter.schema_version', 'missing'));
            $this->components->twoColumnDetail('Supervised adapter launch attempted', data_get($payload, 'artifacts.product_loop_check.daemon_supervisor_execution.supervised_process_adapter.process_launch_attempted', true) ? 'yes' : 'no');
            $this->components->twoColumnDetail('Managed env contract', (string) data_get($payload, 'artifacts.product_loop_check.daemon_supervisor_execution.supervised_process_adapter.managed_environment_contract.schema_version', 'missing'));
            $this->components->twoColumnDetail('Managed env write attempted', data_get($payload, 'artifacts.product_loop_check.daemon_supervisor_execution.supervised_process_adapter.managed_environment_contract.env_file_write_attempted', true) ? 'yes' : 'no');
            $this->components->twoColumnDetail('Launch authorization contract', (string) data_get($payload, 'artifacts.product_loop_check.daemon_supervisor_execution.supervised_process_adapter.launch_authorization_contract.schema_version', 'missing'));
            $this->components->twoColumnDetail('Launch allowed', data_get($payload, 'artifacts.product_loop_check.daemon_supervisor_execution.supervised_process_adapter.launch_authorization_contract.launch_allowed', true) ? 'yes' : 'no');
            $this->components->twoColumnDetail('Managed env writer', (string) data_get($payload, 'artifacts.product_loop_check.daemon_supervisor_execution.supervised_process_adapter.managed_env_writer.schema_version', 'missing'));
            $this->components->twoColumnDetail('Managed env writer wrote file', data_get($payload, 'artifacts.product_loop_check.daemon_supervisor_execution.supervised_process_adapter.managed_env_writer.env_file_write_attempted', true) ? 'yes' : 'no');
            $this->components->twoColumnDetail('Supervised launch execution', (string) data_get($payload, 'artifacts.product_loop_check.daemon_supervisor_execution.supervised_process_adapter.supervised_launch_execution.schema_version', 'missing'));
            $this->components->twoColumnDetail('Supervised launch status', (string) data_get($payload, 'artifacts.product_loop_check.daemon_supervisor_execution.supervised_process_adapter.supervised_launch_execution.status', 'unknown'));
            $this->components->twoColumnDetail('Supervised launch attempted', data_get($payload, 'artifacts.product_loop_check.daemon_supervisor_execution.supervised_process_adapter.supervised_launch_execution.process_launch_attempted', true) ? 'yes' : 'no');
            $this->components->twoColumnDetail('Pre-start health checks available', data_get($payload, 'artifacts.product_loop_check.daemon_supervisor_execution.supervised_process_adapter.supervised_launch_execution.pre_start_health_checks_execution_available', false) ? 'yes' : 'no');
            $this->components->twoColumnDetail('Pre-start health checks executed', data_get($payload, 'artifacts.product_loop_check.daemon_supervisor_execution.supervised_process_adapter.supervised_launch_execution.pre_start_health_checks_executed', true) ? 'yes' : 'no');
            $this->components->twoColumnDetail('Subprocess start contract', (string) data_get($payload, 'artifacts.product_loop_check.daemon_supervisor_execution.supervised_process_adapter.subprocess_start_contract.schema_version', 'missing'));
            $this->components->twoColumnDetail('Subprocess start status', (string) data_get($payload, 'artifacts.product_loop_check.daemon_supervisor_execution.supervised_process_adapter.subprocess_start_contract.status', 'unknown'));
            $this->components->twoColumnDetail('Subprocess start attempted', data_get($payload, 'artifacts.product_loop_check.daemon_supervisor_execution.supervised_process_adapter.subprocess_start_contract.process_launch_attempted', true) ? 'yes' : 'no');
            $this->components->twoColumnDetail('Production promotion', (string) data_get($payload, 'production_promotion_gate.status', 'unknown'));
            $this->components->twoColumnDetail('Human review required', data_get($payload, 'production_promotion_gate.human_review_required', false) ? 'yes' : 'no');
            $this->components->twoColumnDetail('Review packet', (string) data_get($payload, 'production_promotion_gate.review_packet.status', 'unknown'));
            $this->components->twoColumnDetail('Next action', (string) data_get($payload, 'next_action'));
        }
        if (($payload['schema_version'] ?? null) === 'atlas.voice_realtime.production_promotion_review_bundle.v1') {
            $this->components->twoColumnDetail('Promotion review bundle', (string) data_get($payload, 'status'));
            $this->components->twoColumnDetail('Production gate', (string) data_get($payload, 'production_promotion_gate.status', 'unknown'));
            $this->components->twoColumnDetail('Review packet', (string) data_get($payload, 'review_packet.status', 'unknown'));
            $this->components->twoColumnDetail('Evidence count', (string) data_get($payload, 'summary.evidence_count', '0'));
            $this->components->twoColumnDetail('Bundle hash', (string) data_get($payload, 'bundle_hash'));
            $this->components->twoColumnDetail('Promotion allowed', data_get($payload, 'promotion_allowed', true) ? 'yes' : 'no');
            $this->components->twoColumnDetail('Auto promotion', data_get($payload, 'auto_promotion_allowed', true) ? 'yes' : 'no');
            $this->components->twoColumnDetail('Next action', (string) data_get($payload, 'next_action'));
        }
        if (($payload['schema_version'] ?? null) === 'atlas.voice.readiness.v1') {
            $this->components->twoColumnDetail('Readiness', (string) data_get($payload, 'status'));
            $this->components->twoColumnDetail('Phase 0', (string) data_get($payload, 'phase0_hardening.status', 'unknown'));
            $this->components->twoColumnDetail('Product loop contract', (string) data_get($payload, 'product_loop_check.status', 'unknown'));
            $this->components->twoColumnDetail('Product loop command', (string) data_get($payload, 'product_loop_check.command', ''));
            $this->components->twoColumnDetail('Review action', (string) data_get($payload, 'review_signal.recommended_action', 'unknown'));
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
            $this->pythonBinary(),
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
        $process->setTimeout(self::PYTHON_COMMAND_TIMEOUT_SECONDS);

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
                'command' => 'PYTHONPATH=runtimes/python/voice_realtime '.$this->pythonBinary().' -m atlas_voice_agent.main --bootstrap <generated> --mock-kernel --scripted-events runtimes/python/voice_realtime/scripted-events.example.json',
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
        $baseUrl = (string) data_get($bootstrap, 'kernel.base_url', 'http://atlas.test');
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
            $this->pythonBinary(),
            '-m',
            'atlas_voice_agent.main',
            ...$args,
        ], base_path(), [
            'PYTHONPATH' => base_path('runtimes/python/voice_realtime'),
        ]);
        $process->setTimeout(self::PYTHON_COMMAND_TIMEOUT_SECONDS);

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
                'command' => 'PYTHONPATH=runtimes/python/voice_realtime '.$this->pythonBinary().' -m atlas_voice_agent.main --env-file <generated> --preflight'.((bool) $this->option('require-sdk') ? ' --require-sdk' : ''),
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
        $extraArgs = [
            '--activation-contract',
        ];
        if ((bool) $this->option('callback-loop-wired')) {
            $extraArgs[] = '--callback-loop-wired';
        }

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
            $this->pythonBinary(),
            '-m',
            'atlas_voice_agent.main',
            '--env-file',
            $envPath,
            ...$extraArgs,
        ], base_path(), [
            'PYTHONPATH' => base_path('runtimes/python/voice_realtime'),
        ]);
        $process->setTimeout(self::PYTHON_COMMAND_TIMEOUT_SECONDS);

        try {
            $process->run();
            $decoded = json_decode($process->getOutput(), true);

            return [
                'schema_version' => 'atlas.voice_realtime.activation_command.v1',
                'status' => $process->isSuccessful() && is_array($decoded) ? (string) ($decoded['status'] ?? 'unknown') : 'failed',
                'surface_id' => 'voice_realtime',
                'runtime_id' => (string) $this->option('runtime'),
                'exit_code' => $process->getExitCode(),
                'command' => 'PYTHONPATH=runtimes/python/voice_realtime '.$this->pythonBinary().' -m atlas_voice_agent.main --env-file <generated> '.implode(' ', $extraArgs),
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
            'command' => 'PYTHONPATH=runtimes/python/voice_realtime '.$this->pythonBinary().' -m atlas_voice_agent.main --bootstrap <generated> --mock-kernel --callback-event runtimes/python/voice_realtime/callback-event.example.json',
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
            'command' => 'PYTHONPATH=runtimes/python/voice_realtime '.$this->pythonBinary().' -m atlas_voice_agent.main --bootstrap <generated> --mock-kernel --callback-events runtimes/python/voice_realtime/callback-events.example.json',
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
    private function runDependencyInstallPlan(AtlasVoiceRealtimeService $voice): array
    {
        return $voice->runtimeDependencyInstallPlan();
    }

    /**
     * @return array<string,mixed>
     */
    private function runCallbackLoopCheck(AtlasVoiceRealtimeService $voice): array
    {
        $extraArgs = [
            '--callback-loop-check',
        ];
        if ((bool) $this->option('production-sdk-loop-wired')) {
            $extraArgs[] = '--production-sdk-loop-wired';
        }

        return $this->runPythonBootstrapCommand($voice, $extraArgs, 'atlas.voice_realtime.callback_loop_contract.v1');
    }

    /**
     * @return array<string,mixed>
     */
    private function runWorkerPlan(AtlasVoiceRealtimeService $voice): array
    {
        $extraArgs = [
            '--worker-plan',
        ];
        if ((bool) $this->option('callback-loop-wired')) {
            $extraArgs[] = '--callback-loop-wired';
        }

        return $this->runPythonBootstrapCommand($voice, $extraArgs, 'atlas.voice_realtime.worker_plan.v1');
    }

    /**
     * @return array<string,mixed>
     */
    private function runProductionLoopPlan(AtlasVoiceRealtimeService $voice): array
    {
        $extraArgs = [
            '--production-loop-plan',
        ];
        if ((bool) $this->option('production-sdk-loop-wired')) {
            $extraArgs[] = '--production-sdk-loop-wired';
        }

        return $this->runPythonBootstrapCommand($voice, $extraArgs, 'atlas.voice_realtime.production_loop_plan.v1');
    }

    /**
     * @return array<string,mixed>
     */
    private function runProductLoopCheck(AtlasVoiceRealtimeService $voice): array
    {
        $extraArgs = [
            '--product-loop-check',
            '--callback-loop-wired',
            '--production-sdk-loop-wired',
        ];
        $reviewFile = trim((string) ($this->option('production-promotion-review-file') ?? ''));
        if ($reviewFile !== '') {
            $extraArgs[] = '--production-promotion-review-file';
            $extraArgs[] = str_starts_with($reviewFile, DIRECTORY_SEPARATOR)
                ? $reviewFile
                : base_path($reviewFile);
        }
        $bundleFile = trim((string) ($this->option('promotion-review-bundle-file') ?? ''));
        if ($bundleFile !== '') {
            $extraArgs[] = '--promotion-review-bundle-file';
            $extraArgs[] = str_starts_with($bundleFile, DIRECTORY_SEPARATOR)
                ? $bundleFile
                : base_path($bundleFile);
        }
        $daemonReviewFile = trim((string) ($this->option('daemon-implementation-review-file') ?? ''));
        if ($daemonReviewFile !== '') {
            $extraArgs[] = '--daemon-implementation-review-file';
            $extraArgs[] = str_starts_with($daemonReviewFile, DIRECTORY_SEPARATOR)
                ? $daemonReviewFile
                : base_path($daemonReviewFile);
        }

        return $this->runPythonEnvCommand($voice, $extraArgs, 'atlas.voice_realtime.product_loop_check.v1', 'product-loop-token', 'product-loop-key', 'product-loop-secret');
    }

    /**
     * @return array<string,mixed>
     */
    private function runDaemonSupervisorCheck(AtlasVoiceRealtimeService $voice): array
    {
        $extraArgs = [
            '--daemon-supervisor-check',
        ];
        if ((bool) $this->option('callback-loop-wired')) {
            $extraArgs[] = '--callback-loop-wired';
        }
        if ((bool) $this->option('production-sdk-loop-wired')) {
            $extraArgs[] = '--production-sdk-loop-wired';
        }
        $reviewFile = trim((string) ($this->option('production-promotion-review-file') ?? ''));
        if ($reviewFile !== '') {
            $extraArgs[] = '--production-promotion-review-file';
            $extraArgs[] = str_starts_with($reviewFile, DIRECTORY_SEPARATOR)
                ? $reviewFile
                : base_path($reviewFile);
        }
        $bundleFile = trim((string) ($this->option('promotion-review-bundle-file') ?? ''));
        if ($bundleFile !== '') {
            $extraArgs[] = '--promotion-review-bundle-file';
            $extraArgs[] = str_starts_with($bundleFile, DIRECTORY_SEPARATOR)
                ? $bundleFile
                : base_path($bundleFile);
        }
        $daemonReviewFile = trim((string) ($this->option('daemon-implementation-review-file') ?? ''));
        if ($daemonReviewFile !== '') {
            $extraArgs[] = '--daemon-implementation-review-file';
            $extraArgs[] = str_starts_with($daemonReviewFile, DIRECTORY_SEPARATOR)
                ? $daemonReviewFile
                : base_path($daemonReviewFile);
        }

        return $this->runPythonEnvCommand(
            $voice,
            $extraArgs,
            'atlas.voice_realtime.daemon_supervisor_execution.v1',
            'daemon-supervisor-token',
            'daemon-supervisor-key',
            'daemon-supervisor-secret',
        );
    }

    /**
     * @return array<string,mixed>
     */
    private function runPreStartHealthChecksSmoke(AtlasVoiceRealtimeService $voice): array
    {
        $payload = $this->runPythonBootstrapCommand(
            $voice,
            [
                '--pre-start-health-checks-smoke',
            ],
            'atlas.voice_realtime.pre_start_health_checks_smoke.v1',
        );
        $payload['command'] = 'PYTHONPATH=runtimes/python/voice_realtime '.$this->pythonBinary().' -m atlas_voice_agent.main --bootstrap <generated> --pre-start-health-checks-smoke';

        return $payload;
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
        $extraArgs = [
            '--start-worker',
        ];
        if ((bool) $this->option('callback-loop-wired')) {
            $extraArgs[] = '--callback-loop-wired';
        }
        if ((bool) $this->option('production-sdk-loop-wired')) {
            $extraArgs[] = '--production-sdk-loop-wired';
        }
        if ((bool) $this->option('production-promotion-approved')) {
            $extraArgs[] = '--production-promotion-approved';
        }
        $reviewFile = trim((string) ($this->option('production-promotion-review-file') ?? ''));
        if ($reviewFile !== '') {
            $extraArgs[] = '--production-promotion-review-file';
            $extraArgs[] = str_starts_with($reviewFile, DIRECTORY_SEPARATOR)
                ? $reviewFile
                : base_path($reviewFile);
        }
        $bundleFile = trim((string) ($this->option('promotion-review-bundle-file') ?? ''));
        if ($bundleFile !== '') {
            $extraArgs[] = '--promotion-review-bundle-file';
            $extraArgs[] = str_starts_with($bundleFile, DIRECTORY_SEPARATOR)
                ? $bundleFile
                : base_path($bundleFile);
        }
        $daemonReviewFile = trim((string) ($this->option('daemon-implementation-review-file') ?? ''));
        if ($daemonReviewFile !== '') {
            $extraArgs[] = '--daemon-implementation-review-file';
            $extraArgs[] = str_starts_with($daemonReviewFile, DIRECTORY_SEPARATOR)
                ? $daemonReviewFile
                : base_path($daemonReviewFile);
        }

        return $this->runPythonEnvCommand(
            $voice,
            $extraArgs,
            'atlas.voice_realtime.worker_start.v1',
            'worker-start-token',
            'worker-start-key',
            'worker-start-secret',
        );
    }

    /**
     * @return array<string,mixed>
     */
    private function runNormalizeEvent(AtlasVoiceRuntimeEventNormalizer $runtimeEvents): array
    {
        $payload = $this->loadEventFilePayload('atlas.voice_realtime.runtime_event_normalizer.v1');
        if (($payload['status'] ?? null) === 'failed') {
            return $payload;
        }

        $event = isset($payload['event']) && is_array($payload['event'])
            ? $payload['event']
            : $payload;

        return $runtimeEvents->normalize($event);
    }

    /**
     * @return array<string,mixed>
     */
    private function runNormalizeSequence(AtlasVoiceRuntimeEventNormalizer $runtimeEvents): array
    {
        $payload = $this->loadEventFilePayload('atlas.voice_realtime.runtime_event_normalizer.v1');
        if (($payload['status'] ?? null) === 'failed') {
            return $payload;
        }

        $events = $payload['events'] ?? $payload;
        if (! is_array($events) || ! array_is_list($events)) {
            return [
                'schema_version' => 'atlas.voice_realtime.runtime_event_normalizer.v1',
                'status' => 'failed',
                'surface_id' => 'voice_realtime',
                'runtime_id' => (string) $this->option('runtime'),
                'failure' => 'event_file_must_contain_events_array_or_json_array',
                'event_file' => (string) $this->option('event-file'),
            ];
        }

        return $runtimeEvents->normalizeSequence($events);
    }

    /**
     * @return array<string,mixed>
     */
    private function loadEventFilePayload(string $schemaVersion): array
    {
        $eventFile = trim((string) ($this->option('event-file') ?? ''));
        if ($eventFile === '') {
            return [
                'schema_version' => $schemaVersion,
                'status' => 'failed',
                'surface_id' => 'voice_realtime',
                'runtime_id' => (string) $this->option('runtime'),
                'failure' => 'event_file_required',
                'usage' => 'php artisan atlas:ai:voice normalize-event --event-file=<path> --json',
            ];
        }

        $path = str_starts_with($eventFile, DIRECTORY_SEPARATOR)
            ? $eventFile
            : base_path($eventFile);
        if (! is_file($path)) {
            return [
                'schema_version' => $schemaVersion,
                'status' => 'failed',
                'surface_id' => 'voice_realtime',
                'runtime_id' => (string) $this->option('runtime'),
                'failure' => 'event_file_not_found',
                'event_file' => $eventFile,
            ];
        }

        try {
            $decoded = json_decode((string) file_get_contents($path), true, flags: JSON_THROW_ON_ERROR);
        } catch (\JsonException) {
            return [
                'schema_version' => $schemaVersion,
                'status' => 'failed',
                'surface_id' => 'voice_realtime',
                'runtime_id' => (string) $this->option('runtime'),
                'failure' => 'event_file_invalid_json',
                'event_file' => $eventFile,
            ];
        }

        if (! is_array($decoded)) {
            return [
                'schema_version' => $schemaVersion,
                'status' => 'failed',
                'surface_id' => 'voice_realtime',
                'runtime_id' => (string) $this->option('runtime'),
                'failure' => 'event_file_json_must_be_object_or_array',
                'event_file' => $eventFile,
            ];
        }

        return $decoded;
    }

    /**
     * @param  array<int,string>  $extraArgs
     * @return array<string,mixed>
     */
    private function runPythonEnvCommand(
        AtlasVoiceRealtimeService $voice,
        array $extraArgs,
        string $schemaVersion,
        string $token,
        string $livekitKey,
        string $livekitSecret,
    ): array {
        $baseUrl = (string) ($this->option('base-url') ?: 'http://atlas.test');
        $bootstrap = $voice->runtimeBootstrapManifest([
            'runtime' => (string) $this->option('runtime'),
            'base_url' => $baseUrl,
        ]);
        $bootstrapPath = tempnam(sys_get_temp_dir(), 'atlas-voice-bootstrap-');
        $envPath = tempnam(sys_get_temp_dir(), 'atlas-voice-env-');
        if ($bootstrapPath === false || $envPath === false) {
            return [
                'schema_version' => $schemaVersion,
                'status' => 'failed',
                'surface_id' => 'voice_realtime',
                'runtime_id' => (string) $this->option('runtime'),
                'failure' => 'could_not_create_temp_runtime_files',
            ];
        }

        file_put_contents($bootstrapPath, json_encode($bootstrap, JSON_THROW_ON_ERROR | JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
        file_put_contents($envPath, implode("\n", [
            'ATLAS_BASE_URL='.$baseUrl,
            'ATLAS_TOKEN='.$token,
            'ATLAS_VOICE_BOOTSTRAP='.$bootstrapPath,
            'LIVEKIT_URL=http://livekit.test',
            'LIVEKIT_API_KEY='.$livekitKey,
            'LIVEKIT_API_SECRET='.$livekitSecret,
            'ATLAS_VOICE_STT_PROVIDER=configurable',
            'ATLAS_VOICE_TTS_PROVIDER=configurable',
        ]));

        $process = new Process([
            $this->pythonBinary(),
            '-m',
            'atlas_voice_agent.main',
            '--env-file',
            $envPath,
            ...$extraArgs,
        ], base_path(), [
            'PYTHONPATH' => base_path('runtimes/python/voice_realtime'),
        ]);
        $process->setTimeout(self::PYTHON_COMMAND_TIMEOUT_SECONDS);

        try {
            $process->run();
            $decoded = json_decode($process->getOutput(), true);
            if (! is_array($decoded)) {
                return [
                    'schema_version' => $schemaVersion,
                    'status' => 'failed',
                    'surface_id' => 'voice_realtime',
                    'runtime_id' => (string) $this->option('runtime'),
                    'failure' => 'voice_runtime_command_invalid_json',
                    'timeout_seconds' => self::PYTHON_COMMAND_TIMEOUT_SECONDS,
                    'exit_code' => $process->getExitCode(),
                    'stderr_hash' => $process->getErrorOutput() !== '' ? hash('sha256', $process->getErrorOutput()) : null,
                ];
            }

            $payload = $this->sanitizeSmokePayload($decoded);
            $payload['command'] = 'PYTHONPATH=runtimes/python/voice_realtime '.$this->pythonBinary().' -m atlas_voice_agent.main --env-file <generated> '.implode(' ', $extraArgs);
            $payload['timeout_seconds'] = self::PYTHON_COMMAND_TIMEOUT_SECONDS;
            $payload['exit_code'] = $process->getExitCode();
            $payload['stderr_hash'] = $process->getErrorOutput() !== '' ? hash('sha256', $process->getErrorOutput()) : null;

            return $payload;
        } catch (ProcessTimedOutException) {
            return [
                'schema_version' => $schemaVersion,
                'status' => 'failed',
                'surface_id' => 'voice_realtime',
                'runtime_id' => (string) $this->option('runtime'),
                'failure' => 'voice_runtime_command_timeout',
                'timeout_seconds' => self::PYTHON_COMMAND_TIMEOUT_SECONDS,
                'exit_code' => null,
                'stderr_hash' => null,
            ];
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
            $this->pythonBinary(),
            '-m',
            'atlas_voice_agent.main',
            '--bootstrap',
            $bootstrapPath,
            ...$extraArgs,
        ], base_path(), [
            'PYTHONPATH' => base_path('runtimes/python/voice_realtime'),
        ]);
        $process->setTimeout(self::PYTHON_COMMAND_TIMEOUT_SECONDS);

        try {
            $process->run();
            $decoded = json_decode($process->getOutput(), true);
            if (! is_array($decoded)) {
                return [
                    'schema_version' => $schemaVersion,
                    'status' => 'failed',
                    'surface_id' => 'voice_realtime',
                    'runtime_id' => (string) $this->option('runtime'),
                    'failure' => 'voice_runtime_command_invalid_json',
                    'timeout_seconds' => self::PYTHON_COMMAND_TIMEOUT_SECONDS,
                    'exit_code' => $process->getExitCode(),
                    'stderr_hash' => $process->getErrorOutput() !== '' ? hash('sha256', $process->getErrorOutput()) : null,
                ];
            }

            $payload = $this->sanitizeSmokePayload($decoded);
            $payload['timeout_seconds'] = self::PYTHON_COMMAND_TIMEOUT_SECONDS;

            return $payload;
        } catch (ProcessTimedOutException) {
            return [
                'schema_version' => $schemaVersion,
                'status' => 'failed',
                'surface_id' => 'voice_realtime',
                'runtime_id' => (string) $this->option('runtime'),
                'failure' => 'voice_runtime_command_timeout',
                'timeout_seconds' => self::PYTHON_COMMAND_TIMEOUT_SECONDS,
                'exit_code' => null,
                'stderr_hash' => null,
            ];
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

        $forbidden = [
            'access_token',
            'token',
            'livekit_token',
            'api_key',
            'api_secret',
            'raw_audio',
            'audio_bytes',
            'pcm',
            'wav',
            'response_text',
            'raw_response_text',
            'tts_text',
            'tool_call',
            'tool_args',
            'provider_api_key',
        ];

        return collect($payload)
            ->reject(fn (mixed $_, string|int $key): bool => in_array((string) $key, $forbidden, true))
            ->map(fn (mixed $value): mixed => is_array($value) ? $this->sanitizeSmokePayload($value) : $value)
            ->all();
    }

    private function pythonBinary(): string
    {
        $option = trim((string) ($this->option('python-bin') ?? ''));
        $configured = $option !== ''
            ? $option
            : trim((string) config('atlas_ai.voice_realtime.python_binary', 'python3'));

        if ($configured === '' || str_contains($configured, "\0") || str_contains($configured, "\n") || str_contains($configured, "\r")) {
            return 'python3';
        }

        return $configured;
    }

    /**
     * @return array<int,string>
     */
    private function allowedActions(): array
    {
        return ['contract', 'bootstrap', 'dependencies', 'dependency-install-plan', 'preflight', 'activation-contract', 'scripted-example', 'scripted-smoke', 'callback-smoke', 'callback-sequence-smoke', 'callback-loop-check', 'sdk-check', 'token-issuer-plan', 'token-issuer-smoke', 'livekit-server-probe', 'worker-plan', 'production-loop-plan', 'product-loop-check', 'daemon-supervisor-check', 'pre-start-health-checks-smoke', 'production-loop-smoke', 'worker-start-check', 'normalize-event', 'normalize-sequence', 'runtime-certify', 'promotion-review-packet', 'health', 'readiness', 'rivals'];
    }
}
