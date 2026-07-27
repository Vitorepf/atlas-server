<?php

namespace App\Services\Ai\Kernel\Architecture\Scanner;

use App\Support\PeeledSource;
use Illuminate\Support\Facades\File;

class VoiceAudit
{
    public function __construct(private ScanPrimitivesSupport $primitives) {}

    /**
     * @return array<string,callable(): array<int,string>>
     */
    public function checks(): array
    {
        return [
            'ap179_voice_realtime_activation_governance' => fn (): array => $this->scanVoiceRealtimeActivationGovernance(),
            'ap185_voice_realtime_runtime_certification_contract' => fn (): array => $this->scanVoiceRealtimeRuntimeCertificationContract(),
            'ap686_voice_realtime_python_runtime_boundary_contract' => fn (): array => $this->scanVoiceRealtimePythonRuntimeBoundaryContract(),
            'ap687_voice_realtime_production_promotion_gate' => fn (): array => $this->scanVoiceRealtimeProductionPromotionGate(),
        ];
    }

    /**
     * @return array<int,string>
     */
    private function scanVoiceRealtimeActivationGovernance(): array
    {
        $violations = [];
        $servicePath = app_path('Services/Ai/Voice/AtlasVoiceRealtimeService.php');
        $apiTestPath = base_path('tests/Feature/Ai/AtlasAiVoiceRealtimeApiTest.php');
        $docPath = base_path('docs/engineering-knowledge-base/atlas-ai-voice-realtime-surface.md');
        $matrixPath = base_path('docs/engineering-knowledge-base/architecture-audit/implemented-vs-scaffold-matrix.md');

        $service = PeeledSource::read($servicePath);
        $apiTest = File::exists($apiTestPath) ? File::get($apiTestPath) : '';
        $doc = File::exists($docPath) ? File::get($docPath) : '';
        $matrix = File::exists($matrixPath) ? File::get($matrixPath) : '';

        foreach ([
            "'activation_governance' => \$this->activationGovernance",
            'atlas.voice_realtime.activation_governance.v1',
            "'first_product_surface' => 'mobile'",
            "'livekit_agents_direct_provider_allowed' => false",
            "'kernel_webhook_required' => true",
            "'decision_receipt_required_per_turn' => true",
            "'runtime_daemon_start_allowed_now' => false",
            "'production_audio_streaming_allowed_now' => false",
            "'always_on_listening_allowed_now' => false",
            "'mac_edge_first_product_allowed' => false",
            "'swift_native_mac_phase' => 'future_after_mobile_voice'",
            'swift_mac_before_mobile',
            'livekit_agents_to_provider_direct',
            'voice_domain_creation',
        ] as $token) {
            if (! str_contains($service, $token)) {
                $violations[] = "app/Services/Ai/Voice/AtlasVoiceRealtimeService.php: AP-179 voice activation governance must stay mobile-first and fail-closed [{$token}]";
            }
        }

        foreach ([
            'activation_governance.schema_version',
            'atlas.voice_realtime.activation_governance.v1',
            'activation_governance.first_product_surface',
            'activation_governance.livekit_agents_direct_provider_allowed',
            'activation_governance.always_on_listening_allowed_now',
            'activation_governance.mac_edge_first_product_allowed',
            'session_lease.activation_governance.runtime_daemon_start_allowed_now',
        ] as $token) {
            if (! str_contains($apiTest, $token)) {
                $violations[] = "tests/Feature/Ai/AtlasAiVoiceRealtimeApiTest.php: AP-179 voice activation governance output must be tested [{$token}]";
            }
        }

        foreach ([
            'activation governance',
            'mobile-first',
            'no direct-provider',
            'no daemon/always-on',
            'Implementar Mac Swift antes do mobile voice',
        ] as $token) {
            if (! str_contains($doc, $token)) {
                $violations[] = "docs/engineering-knowledge-base/atlas-ai-voice-realtime-surface.md: AP-179 voice activation governance must be documented [{$token}]";
            }
        }

        foreach ([
            'Voice Realtime',
            '`activation_governance`',
            'mobile-first',
            'direct-provider',
            'always-on',
        ] as $token) {
            if (! str_contains($matrix, $token)) {
                $violations[] = "docs/engineering-knowledge-base/architecture-audit/implemented-vs-scaffold-matrix.md: AP-179 voice matrix row must expose activation governance [{$token}]";
            }
        }

        return $violations;
    }

    /**
     * @return array<int,string>
     */
    private function scanVoiceRealtimeRuntimeCertificationContract(): array
    {
        $violations = [];
        $servicePath = app_path('Services/Ai/Voice/AtlasVoiceRealtimeService.php');
        $certificationPath = app_path('Services/Ai/Voice/AtlasVoiceRuntimeCertificationService.php');
        $commandTestPath = base_path('tests/Feature/Ai/AtlasAiVoiceRealtimeCommandTest.php');
        $apiTestPath = base_path('tests/Feature/Ai/AtlasAiVoiceRealtimeApiTest.php');
        $unitTestPath = base_path('tests/Unit/Ai/Voice/AtlasVoiceRuntimeCertificationServiceTest.php');
        $apPath = base_path('docs/ap/AP-185-voice-realtime-foundation-registry.md');
        $docPath = base_path('docs/engineering-knowledge-base/atlas-ai-voice-realtime-surface.md');
        $staticScanDocPath = base_path('docs/engineering-knowledge-base/kernel/static-scans.md');

        $service = PeeledSource::read($servicePath);
        $certification = PeeledSource::read($certificationPath);
        $commandTest = File::exists($commandTestPath) ? File::get($commandTestPath) : '';
        $apiTest = File::exists($apiTestPath) ? File::get($apiTestPath) : '';
        $unitTest = File::exists($unitTestPath) ? File::get($unitTestPath) : '';
        $apDoc = File::exists($apPath) ? File::get($apPath) : '';
        $doc = File::exists($docPath) ? File::get($docPath) : '';
        $staticScanDoc = File::exists($staticScanDocPath) ? File::get($staticScanDocPath) : '';

        foreach ([
            'atlas.voice_realtime.runtime_contract.v1',
            'runtime_certification_endpoint',
            'mobile_runtime_certification_endpoint',
            'runtime_must_call_kernel_endpoint_before_provider_or_tool_execution',
            'runtime_callbacks_require_kernel_accepted_turn',
            'production_loop_smoke',
            'VOICE_TURN_DECIDED',
            "'raw_audio' => false",
            "'raw_response_text' => false",
        ] as $token) {
            if (! str_contains($service, $token)) {
                $violations[] = "app/Services/Ai/Voice/AtlasVoiceRealtimeService.php: AP-185 runtime certification contract must remain fail-closed [{$token}]";
            }
        }

        foreach ([
            'atlas.voice_realtime.runtime_certification.v1',
            'foundation_registry_ready',
            'callback_sequence_passed',
            'production_loop_smoke_passed',
            'worker_start_blocked_safely',
            'certification_artifacts_sanitized',
            'forbidden_key_count',
            'forbidden_keys',
            'rerun_runtime_certification_with_require_sdk',
            'fix_failed_runtime_certification_gates',
            'access_token',
            'provider_api_key',
            'tool_call',
            'response_text',
            'raw_audio',
            'audio_bytes',
            'runPreflight',
            'runCallbackSequenceSmoke',
            'runProductionLoopSmoke',
            'runWorkerStartCheck',
            'bridge_contract_report.status',
            'handler_registry_contract_report.status',
            'worker_return_contract.status',
        ] as $token) {
            if (! str_contains($certification, $token)) {
                $violations[] = "app/Services/Ai/Voice/AtlasVoiceRuntimeCertificationService.php: AP-185 certification gate must keep foundation, callback, production loop, worker-start and sanitization checks [{$token}]";
            }
        }

        foreach ([
            'runtime-certify',
            'gates.callback_sequence_passed.passed',
            'gates.production_loop_smoke_passed.passed',
            'gates.production_loop_smoke_passed.bridge_contract_status',
            'gates.production_loop_smoke_passed.handler_registry_contract_status',
            'gates.production_loop_smoke_passed.worker_return_contract_status',
            'gates.worker_start_blocked_safely.passed',
            'gates.certification_artifacts_sanitized.passed',
            'gates.certification_artifacts_sanitized.forbidden_key_count',
            "assertArrayNotHasKey('results', data_get(\$payload, 'artifacts.production_loop_smoke'))",
        ] as $token) {
            if (! str_contains($commandTest, $token)) {
                $violations[] = "tests/Feature/Ai/AtlasAiVoiceRealtimeCommandTest.php: AP-185 CLI certification contract must be tested [{$token}]";
            }
        }

        foreach ([
            '/ai/voice/runtime/certification',
            '/v1/mobile/ai/voice/runtime/certification',
            'gates.worker_start_blocked_safely.passed',
            'gates.production_loop_smoke_passed.bridge_contract_status',
            'gates.production_loop_smoke_passed.handler_registry_contract_status',
            'gates.production_loop_smoke_passed.worker_return_contract_status',
            'gates.certification_artifacts_sanitized.passed',
            'gates.certification_artifacts_sanitized.forbidden_key_count',
            'artifacts.production_loop_smoke.results',
        ] as $token) {
            if (! str_contains($apiTest, $token)) {
                $violations[] = "tests/Feature/Ai/AtlasAiVoiceRealtimeApiTest.php: AP-185 API/mobile certification contract must be tested [{$token}]";
            }
        }

        foreach ([
            'AtlasVoiceRuntimeCertificationServiceTest',
            'certification_artifacts_sanitized',
            'forbidden_key_count',
            'bridge_contract_status',
            'atlas.voice_realtime.bridge_contract_report.v1',
            'handler_registry_contract_status',
            'atlas.voice_realtime.handler_registry_contract_report.v1',
            'worker_return_contract_status',
            'atlas.voice_realtime.worker_return_contract_report.v1',
        ] as $token) {
            if (! str_contains($unitTest, $token)) {
                $violations[] = "tests/Unit/Ai/Voice/AtlasVoiceRuntimeCertificationServiceTest.php: AP-185 sanitization unit coverage must exist [{$token}]";
            }
        }

        foreach ([
            'Certificacao Runtime',
            'certification_artifacts_sanitized',
            'forbidden_key_count=0',
            'runtime certification prova artifact sanitization',
        ] as $token) {
            if (! str_contains($apDoc, $token)) {
                $violations[] = "docs/ap/AP-185-voice-realtime-foundation-registry.md: AP-185 runtime certification must be documented [{$token}]";
            }
        }

        foreach ([
            'Runtime Certification Gate',
            'certification_artifacts_sanitized',
            'callback_rejected_payload_contract',
            'Rivals-Voice deve consumir apenas o resumo do certificado',
        ] as $token) {
            if (! str_contains($doc, $token)) {
                $violations[] = "docs/engineering-knowledge-base/atlas-ai-voice-realtime-surface.md: AP-185 certification gate must be preserved in owner doc [{$token}]";
            }
        }

        foreach ([
            'Voice runtime certification (AP-185)',
            'certification artifacts leaking tokens, raw audio, raw text, tool calls or provider secrets',
        ] as $token) {
            if (! str_contains($staticScanDoc, $token)) {
                $violations[] = "docs/engineering-knowledge-base/kernel/static-scans.md: AP-185 static scan behavior must be documented [{$token}]";
            }
        }

        return $violations;
    }

    /**
     * @return array<int,string>
     */
    private function scanVoiceRealtimePythonRuntimeBoundaryContract(): array
    {
        $violations = [];
        $runtimeRoot = base_path('runtimes/python/voice_realtime/atlas_voice_agent');
        $dependenciesPath = base_path('runtimes/python/voice_realtime/runtime-dependencies.json');
        $agentRuntimePath = "{$runtimeRoot}/agent_runtime.py";
        $workerPath = "{$runtimeRoot}/livekit_worker.py";
        $payloadSafetyPath = "{$runtimeRoot}/payload_safety.py";
        $kernelClientPath = "{$runtimeRoot}/kernel_client.py";
        $contractPath = "{$runtimeRoot}/contract.py";
        $boundaryTestPath = base_path('runtimes/python/voice_realtime/tests/test_livekit_boundary.py');
        $workerTestPath = base_path('runtimes/python/voice_realtime/tests/test_livekit_worker.py');
        $contractTestPath = base_path('runtimes/python/voice_realtime/tests/test_contract.py');
        $mainTestPath = base_path('runtimes/python/voice_realtime/tests/test_main.py');
        $staticScanDocPath = base_path('docs/engineering-knowledge-base/kernel/static-scans.md');

        $agentRuntime = File::exists($agentRuntimePath) ? File::get($agentRuntimePath) : '';
        $worker = File::exists($workerPath) ? File::get($workerPath) : '';
        $payloadSafety = File::exists($payloadSafetyPath) ? File::get($payloadSafetyPath) : '';
        $kernelClient = File::exists($kernelClientPath) ? File::get($kernelClientPath) : '';
        $contract = File::exists($contractPath) ? File::get($contractPath) : '';
        $dependencies = File::exists($dependenciesPath) ? File::get($dependenciesPath) : '';
        $boundaryTest = File::exists($boundaryTestPath) ? File::get($boundaryTestPath) : '';
        $workerTest = File::exists($workerTestPath) ? File::get($workerTestPath) : '';
        $contractTest = File::exists($contractTestPath) ? File::get($contractTestPath) : '';
        $mainTest = File::exists($mainTestPath) ? File::get($mainTestPath) : '';
        $staticScanDoc = File::exists($staticScanDocPath) ? File::get($staticScanDocPath) : '';

        foreach ([
            'AtlasKernelClient',
            'Kernel decision receipt is required before runtime callback',
            'turn must be submitted to Kernel before callback',
        ] as $token) {
            if (! str_contains($agentRuntime, $token)) {
                $violations[] = "runtimes/python/voice_realtime/atlas_voice_agent/agent_runtime.py: AP-686 runtime facade must remain Kernel-only and receipt-gated [{$token}]";
            }
        }

        foreach ([
            '_reject_forbidden_worker_fields',
            'reject_forbidden_keys_recursive',
            '_sanitize_for_log',
            'turn must be accepted by Kernel Decision Receipt before runtime callback',
            'direct_llm_provider_call',
            'direct_provider_call',
            'direct_tool_execution',
            'memory_write',
            'tool_call',
            'tool_args',
            'provider_api_key',
            'raw_audio',
            'audio_bytes',
            'api_secret',
            'atlas.voice_realtime.worker_return.v1',
            '_worker_return_payload',
            '_reject_forbidden_worker_return_fields',
            'decision_receipt_hash',
            'evidence_refs',
            'errors',
        ] as $token) {
            if (! str_contains($worker, $token)) {
                $violations[] = "runtimes/python/voice_realtime/atlas_voice_agent/livekit_worker.py: AP-686 LiveKit worker must reject authority/raw/secret fields and emit sanitized logs [{$token}]";
            }
        }

        if (! preg_match('/if event_kind == "failed":\s+self\._require_accepted_turn\(\s*session_id,\s*event\s*\)/', $worker)) {
            $violations[] = 'runtimes/python/voice_realtime/atlas_voice_agent/livekit_worker.py: AP-686 runtime_failed callback must require an accepted Kernel turn before reporting failure.';
        }

        foreach ([
            'reject_forbidden_keys_recursive',
            '_forbidden_paths',
            'Mapping',
            'Sequence',
            'forbidden {label} keys',
        ] as $token) {
            if (! str_contains($payloadSafety, $token)) {
                $violations[] = "runtimes/python/voice_realtime/atlas_voice_agent/payload_safety.py: AP-686 must reject forbidden LiveKit fields recursively, including nested metadata [{$token}]";
            }
        }

        foreach ([
            'self.contract.session_start_url',
            'self.contract.turn_url',
            'self.contract.synthesized_url',
            'self.contract.provider_health_degraded_url',
            '"X-Atlas-Token"',
        ] as $token) {
            if (! str_contains($kernelClient, $token)) {
                $violations[] = "runtimes/python/voice_realtime/atlas_voice_agent/kernel_client.py: AP-686 Kernel client must call only Kernel contract endpoints with Atlas auth [{$token}]";
            }
        }

        foreach ([
            'REQUIRED_RUNTIME_RETURN_FIELDS',
            'runtime_invocation_contract.return_contract',
            'evidence_refs',
            'errors',
            'decision_receipt_hash',
        ] as $token) {
            if (! str_contains($contract, $token)) {
                $violations[] = "runtimes/python/voice_realtime/atlas_voice_agent/contract.py: AP-686 runtime contract must require evidence-return fields from specialized runtime [{$token}]";
            }
        }

        foreach ([
            '"third_party_dependencies": []',
            '"pip": "livekit-agents"',
            '"import": "livekit.agents"',
            'atlas ai voice sdk-check --json must report status=ready before wiring real SDK callbacks.',
            'direct_llm_provider_call',
            'direct_tool_execution',
            'raw_audio_persistence',
            'memory_write',
            'policy_override',
        ] as $token) {
            if (! str_contains($dependencies, $token)) {
                $violations[] = "runtimes/python/voice_realtime/runtime-dependencies.json: AP-686 runtime dependency manifest must keep standard-library core plus optional LiveKit only [{$token}]";
            }
        }

        foreach ([
            'test_rejects_raw_audio_and_direct_provider_authority_from_livekit_event',
            'llm_provider',
            'tool_call',
            'raw_audio',
            'test_rejects_tokens_and_api_secrets_from_livekit_event',
            'api_secret',
        ] as $token) {
            if (! str_contains($boundaryTest, $token)) {
                $violations[] = "runtimes/python/voice_realtime/tests/test_livekit_boundary.py: AP-686 boundary tests must prove runtime cannot inject provider/tool/raw/secret authority [{$token}]";
            }
        }

        foreach ([
            'test_worker_rejects_raw_response_text_from_livekit_event',
            '"metadata"',
            '"api_secret"',
            'test_worker_rejects_runtime_callbacks_before_kernel_accepts_turn',
            'assertNotIn("header.payload.signature"',
            'assertNotIn("access_token"',
            'atlas.voice_realtime.worker_return.v1',
            'test_worker_return_contract_fails_closed_for_missing_or_unsafe_fields',
            '_worker_return_payload',
            'decision_receipt_hash',
            'evidence_refs',
            'raw output must not cross worker boundary',
        ] as $token) {
            if (! str_contains($workerTest, $token)) {
                $violations[] = "runtimes/python/voice_realtime/tests/test_livekit_worker.py: AP-686 worker tests must prove token-free logs and receipt-gated callbacks [{$token}]";
            }
        }

        foreach ([
            'test_rejects_direct_provider_authority',
            'openai_direct',
            'test_rejects_raw_audio_persistence',
            'raw_audio',
            'test_rejects_runtime_invocation_contract_without_evidence_return_contract',
            'return_contract',
            'evidence_refs',
        ] as $token) {
            if (! str_contains($contractTest, $token)) {
                $violations[] = "runtimes/python/voice_realtime/tests/test_contract.py: AP-686 contract tests must reject direct provider authority and raw persistence [{$token}]";
            }
        }

        foreach ([
            'self.assertNotIn(\'"raw_audio":\', completed.stdout)',
            'self.assertNotIn(\'"api_secret"\', completed.stdout)',
        ] as $token) {
            if (! str_contains($mainTest, $token)) {
                $violations[] = "runtimes/python/voice_realtime/tests/test_main.py: AP-686 CLI smoke tests must keep runtime output sanitized [{$token}]";
            }
        }

        foreach ($this->scanVoiceRealtimePythonRuntimeForbiddenPatterns($runtimeRoot) as $violation) {
            $violations[] = $violation;
        }

        foreach ([
            'Voice Python runtime boundary (AP-686)',
            'Voice Python runtime importing provider SDKs, shelling out, logging raw audio/tokens, accepting nested SDK secret/authority metadata, omitting runtime return `evidence_refs`, or reporting `runtime_failed` before a Kernel-accepted turn must fail AP-686.',
        ] as $token) {
            if (! str_contains($staticScanDoc, $token)) {
                $violations[] = "docs/engineering-knowledge-base/kernel/static-scans.md: AP-686 static scan behavior must be documented [{$token}]";
            }
        }

        sort($violations);

        return $violations;
    }

    /**
     * @return array<int,string>
     */
    private function scanVoiceRealtimeProductionPromotionGate(): array
    {
        $violations = [];
        $certificationPath = app_path('Services/Ai/Voice/AtlasVoiceRuntimeCertificationService.php');
        $voiceServicePath = app_path('Services/Ai/Voice/AtlasVoiceRealtimeService.php');
        $tokenIssuerPath = app_path('Services/Ai/Voice/AtlasVoiceLiveKitTokenIssuer.php');
        $selfImprovementPath = app_path('Services/Ai/SelfImprovement/AtlasSelfImprovementRuntime.php');
        $commandPath = app_path('Console/Commands/AtlasAiVoiceRealtimeCommand.php');
        $unitTestPath = base_path('tests/Unit/Ai/Voice/AtlasVoiceRuntimeCertificationServiceTest.php');
        $tokenIssuerTestPath = base_path('tests/Unit/Ai/Voice/AtlasVoiceLiveKitTokenIssuerTest.php');
        $commandTestPath = base_path('tests/Feature/Ai/AtlasAiVoiceRealtimeCommandTest.php');
        $apiTestPath = base_path('tests/Feature/Ai/AtlasAiVoiceRealtimeApiTest.php');
        $pythonContractPath = base_path('runtimes/python/voice_realtime/atlas_voice_agent/contract.py');
        $pythonContractTestPath = base_path('runtimes/python/voice_realtime/tests/test_contract.py');
        $pythonSessionLeasePath = base_path('runtimes/python/voice_realtime/atlas_voice_agent/session_lease.py');
        $pythonSessionLeaseTestPath = base_path('runtimes/python/voice_realtime/tests/test_session_lease.py');
        $pythonMockKernelPath = base_path('runtimes/python/voice_realtime/atlas_voice_agent/mock_kernel.py');
        $pythonMockKernelTestPath = base_path('runtimes/python/voice_realtime/tests/test_mock_kernel.py');
        $pythonRuntimeEntrypointPath = base_path('runtimes/python/voice_realtime/atlas_voice_agent/livekit_runtime_entrypoint.py');
        $pythonRuntimeEntrypointTestPath = base_path('runtimes/python/voice_realtime/tests/test_livekit_runtime_entrypoint.py');
        $pythonSupervisedStartPlanPath = base_path('runtimes/python/voice_realtime/atlas_voice_agent/supervised_start_plan.py');
        $pythonSupervisedStartPlanTestPath = base_path('runtimes/python/voice_realtime/tests/test_supervised_start_plan.py');
        $pythonDaemonImplementationReviewPath = base_path('runtimes/python/voice_realtime/atlas_voice_agent/daemon_implementation_review.py');
        $pythonDaemonImplementationReviewTestPath = base_path('runtimes/python/voice_realtime/tests/test_daemon_implementation_review.py');
        $pythonDaemonSupervisorPath = base_path('runtimes/python/voice_realtime/atlas_voice_agent/daemon_supervisor.py');
        $pythonDaemonSupervisorTestPath = base_path('runtimes/python/voice_realtime/tests/test_daemon_supervisor.py');
        $pythonSupervisedProcessAdapterPath = base_path('runtimes/python/voice_realtime/atlas_voice_agent/supervised_process_adapter.py');
        $pythonSupervisedProcessAdapterTestPath = base_path('runtimes/python/voice_realtime/tests/test_supervised_process_adapter.py');
        $pythonManagedEnvWriterPath = base_path('runtimes/python/voice_realtime/atlas_voice_agent/managed_env_writer.py');
        $pythonManagedEnvWriterTestPath = base_path('runtimes/python/voice_realtime/tests/test_managed_env_writer.py');
        $pythonSupervisedLaunchExecutionPath = base_path('runtimes/python/voice_realtime/atlas_voice_agent/supervised_launch_execution.py');
        $pythonSupervisedLaunchExecutionTestPath = base_path('runtimes/python/voice_realtime/tests/test_supervised_launch_execution.py');
        $pythonActivationContractPath = base_path('runtimes/python/voice_realtime/atlas_voice_agent/activation_contract.py');
        $pythonActivationContractTestPath = base_path('runtimes/python/voice_realtime/tests/test_activation_contract.py');
        $pythonSdkHandlersPath = base_path('runtimes/python/voice_realtime/atlas_voice_agent/livekit_sdk_handlers.py');
        $pythonSdkHandlersTestPath = base_path('runtimes/python/voice_realtime/tests/test_livekit_sdk_handlers.py');
        $pythonSdkWiringContractPath = base_path('runtimes/python/voice_realtime/atlas_voice_agent/livekit_sdk_wiring_contract.py');
        $pythonSdkWiringContractTestPath = base_path('runtimes/python/voice_realtime/tests/test_livekit_sdk_wiring_contract.py');
        $pythonProductionLoopRunnerPath = base_path('runtimes/python/voice_realtime/atlas_voice_agent/livekit_production_loop_runner.py');
        $pythonProductionLoopRunnerTestPath = base_path('runtimes/python/voice_realtime/tests/test_livekit_production_loop_runner.py');
        $pythonProductLoopCheckPath = base_path('runtimes/python/voice_realtime/atlas_voice_agent/product_loop_check.py');
        $pythonProductLoopCheckTestPath = base_path('runtimes/python/voice_realtime/tests/test_product_loop_check.py');
        $pythonSdkStatusPath = base_path('runtimes/python/voice_realtime/atlas_voice_agent/sdk_status.py');
        $pythonSdkStatusTestPath = base_path('runtimes/python/voice_realtime/tests/test_sdk_status.py');
        $pythonSettingsPath = base_path('runtimes/python/voice_realtime/atlas_voice_agent/settings.py');
        $pythonSettingsTestPath = base_path('runtimes/python/voice_realtime/tests/test_settings.py');
        $apPath = base_path('docs/ap/AP-687-voice-realtime-production-promotion-gate.md');
        $docPath = base_path('docs/engineering-knowledge-base/atlas-ai-voice-realtime-surface.md');
        $staticScanDocPath = base_path('docs/engineering-knowledge-base/kernel/static-scans.md');

        $certification = PeeledSource::read($certificationPath);
        $voiceService = PeeledSource::read($voiceServicePath);
        $tokenIssuer = PeeledSource::read($tokenIssuerPath);
        $selfImprovement = PeeledSource::read($selfImprovementPath);
        $command = PeeledSource::read($commandPath);
        $unitTest = $this->primitives->fileContents($unitTestPath);
        $tokenIssuerTest = $this->primitives->fileContents($tokenIssuerTestPath);
        $commandTest = $this->primitives->fileContents($commandTestPath);
        $apiTest = $this->primitives->fileContents($apiTestPath);
        $pythonContract = $this->primitives->fileContents($pythonContractPath);
        $pythonContractTest = $this->primitives->fileContents($pythonContractTestPath);
        $pythonSessionLease = $this->primitives->fileContents($pythonSessionLeasePath);
        $pythonSessionLeaseTest = $this->primitives->fileContents($pythonSessionLeaseTestPath);
        $pythonMockKernel = $this->primitives->fileContents($pythonMockKernelPath);
        $pythonMockKernelTest = $this->primitives->fileContents($pythonMockKernelTestPath);
        $pythonRuntimeEntrypoint = $this->primitives->fileContents($pythonRuntimeEntrypointPath);
        $pythonRuntimeEntrypointTest = $this->primitives->fileContents($pythonRuntimeEntrypointTestPath);
        $pythonSupervisedStartPlan = $this->primitives->fileContents($pythonSupervisedStartPlanPath);
        $pythonSupervisedStartPlanTest = $this->primitives->fileContents($pythonSupervisedStartPlanTestPath);
        $pythonDaemonImplementationReview = $this->primitives->fileContents($pythonDaemonImplementationReviewPath);
        $pythonDaemonImplementationReviewTest = $this->primitives->fileContents($pythonDaemonImplementationReviewTestPath);
        $pythonDaemonSupervisor = $this->primitives->fileContents($pythonDaemonSupervisorPath);
        $pythonDaemonSupervisorTest = $this->primitives->fileContents($pythonDaemonSupervisorTestPath);
        $pythonSupervisedProcessAdapter = $this->primitives->fileContents($pythonSupervisedProcessAdapterPath);
        $pythonSupervisedProcessAdapterTest = $this->primitives->fileContents($pythonSupervisedProcessAdapterTestPath);
        $pythonManagedEnvWriter = $this->primitives->fileContents($pythonManagedEnvWriterPath);
        $pythonManagedEnvWriterTest = $this->primitives->fileContents($pythonManagedEnvWriterTestPath);
        $pythonSupervisedLaunchExecution = $this->primitives->fileContents($pythonSupervisedLaunchExecutionPath);
        $pythonSupervisedLaunchExecutionTest = $this->primitives->fileContents($pythonSupervisedLaunchExecutionTestPath);
        $pythonActivationContract = $this->primitives->fileContents($pythonActivationContractPath);
        $pythonActivationContractTest = $this->primitives->fileContents($pythonActivationContractTestPath);
        $pythonSdkHandlers = $this->primitives->fileContents($pythonSdkHandlersPath);
        $pythonSdkHandlersTest = $this->primitives->fileContents($pythonSdkHandlersTestPath);
        $pythonSdkWiringContract = $this->primitives->fileContents($pythonSdkWiringContractPath);
        $pythonSdkWiringContractTest = $this->primitives->fileContents($pythonSdkWiringContractTestPath);
        $pythonProductionLoopRunner = $this->primitives->fileContents($pythonProductionLoopRunnerPath);
        $pythonProductionLoopRunnerTest = $this->primitives->fileContents($pythonProductionLoopRunnerTestPath);
        $pythonProductLoopCheck = $this->primitives->fileContents($pythonProductLoopCheckPath);
        $pythonProductLoopCheckTest = $this->primitives->fileContents($pythonProductLoopCheckTestPath);
        $pythonSdkStatus = $this->primitives->fileContents($pythonSdkStatusPath);
        $pythonSdkStatusTest = $this->primitives->fileContents($pythonSdkStatusTestPath);
        $pythonSettings = $this->primitives->fileContents($pythonSettingsPath);
        $pythonSettingsTest = $this->primitives->fileContents($pythonSettingsTestPath);
        $apDoc = $this->primitives->fileContents($apPath);
        $doc = $this->primitives->fileContents($docPath);
        $staticScanDoc = $this->primitives->fileContents($staticScanDocPath);

        $violations = array_merge($violations, $this->primitives->missingTokenViolations($certification, [
            'production_promotion_gate',
            'atlas.voice_realtime.production_promotion_gate.v1',
            'human_review_required',
            'promotion_allowed',
            'auto_promotion_allowed',
            'decision_receipt_required',
            'rollback_plan_required',
            'sdk_certification_required',
            'livekit_agents_sdk_ready',
            'livekit_token_issuer_ready',
            'production_promotion_must_run_with_require_sdk',
            'submit_voice_production_promotion_for_human_review',
            "preg_match('/[\\x00-\\x1F\\x7F]/'",
            'parse_url($baseUrl)',
            'PYTHON_COMMAND_TIMEOUT_SECONDS',
            'voice_runtime_command_timeout',
            'runProductLoopCheck',
            'product_loop_check_available',
            'sdk_handler_blueprint_available',
            'sdk_kernel_normalizer_required',
            'artifacts',
            'product_loop_check',
            '--product-loop-check',
        ], 'app/Services/Ai/Voice/AtlasVoiceRuntimeCertificationService.php: AP-687 production promotion gate must remain fail-closed and human-review governed'));

        $violations = array_merge($violations, $this->primitives->missingTokenViolations($voiceService, [
            'voiceRoomName',
            'voiceParticipantIdentity',
            'phase0HardeningGate',
            'productLoopCheckReference',
            'atlas.voice_realtime.phase0_hardening_gate.v1',
            'atlas.voice_realtime.product_loop_check_reference.v1',
            'php artisan atlas:ai:voice product-loop-check --json',
            "'safe_next_block' => 'Voice Realtime phase 0 hardening'",
            'runtime_boundary_green',
            "'atlas-voice-'",
            'required_room_prefix',
            'participant_namespace_source',
            'session_lease',
            'kernelBaseUrl',
            'parse_url($baseUrl)',
        ], 'app/Services/Ai/Voice/AtlasVoiceRealtimeService.php: AP-687 session lease must scope LiveKit rooms to Atlas Voice namespace'));

        $violations = array_merge($violations, $this->primitives->missingTokenViolations($tokenIssuer, [
            'readiness(): array',
            'atlas.voice_realtime.livekit_token_issuer_readiness.v1',
            "'secrets_exposed' => false",
            'livekit_url_missing',
            'livekit_room_outside_atlas_voice_namespace',
            'livekit_participant_outside_client_surface_namespace',
            'configure_livekit_token_issuer',
        ], 'app/Services/Ai/Voice/AtlasVoiceLiveKitTokenIssuer.php: AP-687 LiveKit token issuer readiness must be explicit and secret-safe'));

        $violations = array_merge($violations, $this->primitives->missingTokenViolations($pythonContract, [
            'urlparse',
            'session_lease.room_prefix',
            'atlas-voice- namespace',
            'not isinstance(room_prefix, str)',
            '_absolute_http_url',
            'control characters',
        ], 'runtimes/python/voice_realtime/atlas_voice_agent/contract.py: AP-687 Python runtime contract must reject unsafe manifest URLs and room prefixes outside Atlas Voice namespace'));

        $violations = array_merge($violations, $this->primitives->missingTokenViolations($pythonContractTest, [
            'rejects_room_prefix_outside_atlas_voice_namespace',
            'rejects_kernel_url_with_control_characters',
            'rejects_kernel_url_without_host',
            'rogue-voice-',
            'LIVEKIT_API_SECRET=injected',
        ], 'runtimes/python/voice_realtime/tests/test_contract.py: AP-687 Python runtime contract URL and room namespace safety must be tested'));

        $violations = array_merge($violations, $this->primitives->missingTokenViolations($pythonSettings, [
            'urlparse',
            'control characters',
            'atlas-voice-',
            '_room_prefix',
        ], 'runtimes/python/voice_realtime/atlas_voice_agent/settings.py: AP-687 Python runtime settings must mirror Kernel URL and room namespace safety'));

        $violations = array_merge($violations, $this->primitives->missingTokenViolations($pythonSessionLease, [
            'urlparse',
            'ALLOWED_PARTICIPANT_NAMESPACES',
            '_atlas_voice_room',
            '_participant_identity',
            '_optional_url',
            'atlas-voice- namespace',
            'allowed client surface',
        ], 'runtimes/python/voice_realtime/atlas_voice_agent/session_lease.py: AP-687 Python runtime session leases must reject unsafe LiveKit URL, room and participant namespaces'));

        $violations = array_merge($violations, $this->primitives->missingTokenViolations($pythonSessionLeaseTest, [
            'rejects_room_name_outside_atlas_voice_namespace',
            'rejects_participant_identity_outside_client_surface_namespace',
            'rejects_livekit_url_with_control_characters',
            'rejects_livekit_url_without_host',
            'LIVEKIT_API_SECRET=injected',
        ], 'runtimes/python/voice_realtime/tests/test_session_lease.py: AP-687 Python runtime session lease namespace safety must be tested'));

        $violations = array_merge($violations, $this->primitives->missingTokenViolations($pythonMockKernel, [
            '_atlas_voice_room',
            '_participant_identity',
            'atlas-voice-',
            'mobile:',
            'mac_edge:',
        ], 'runtimes/python/voice_realtime/atlas_voice_agent/mock_kernel.py: AP-687 mock Kernel must normalize session leases like the real Kernel'));

        $violations = array_merge($violations, $this->primitives->missingTokenViolations($pythonMockKernelTest, [
            'normalizes_unsafe_session_lease_namespaces',
            'atlas-voice-prod-room',
            'mobile:adminroot',
        ], 'runtimes/python/voice_realtime/tests/test_mock_kernel.py: AP-687 mock Kernel namespace normalization must be tested'));

        $violations = array_merge($violations, $this->primitives->missingTokenViolations($pythonRuntimeEntrypoint, [
            'production_promotion',
            'human_review_required',
            'decision_receipt_required',
            'rollback_plan_required',
            'review_receipt_valid',
            'boolean_approval_is_sufficient',
            'validate_production_promotion_review',
            'validate_daemon_implementation_review',
            'build_supervised_start_plan',
            'supervised_start_plan',
            'daemon_implementation',
            'blocked_pending_daemon_implementation_review',
            'ready_for_supervised_start_implementation',
            'start_allowed_by_review',
            'auto_promotion_allowed',
            'worker_start_without_production_promotion_allowed',
            'callback_loop_wired',
            'production_sdk_loop_wired',
            'supervised_start_plan',
        ], 'runtimes/python/voice_realtime/atlas_voice_agent/livekit_runtime_entrypoint.py: AP-687 worker start must expose production promotion and human review guardrails'));

        $violations = array_merge($violations, $this->primitives->missingTokenViolations($pythonRuntimeEntrypointTest, [
            'worker_start_without_production_promotion_allowed',
            'production_promotion',
            'human_review_required',
            'rollback_plan_required',
            'human_review_approved',
            'review_receipt_valid',
            'boolean_approval_is_sufficient',
            'auto_promotion_allowed',
            'test_start_worker_blocks_fully_wired_product_loop_until_human_review',
            'test_start_worker_requires_review_receipt_not_boolean_flag',
            'test_start_worker_accepts_valid_review_receipt_but_still_blocks_until_daemon_review',
            'test_start_worker_accepts_daemon_review_receipt_but_still_does_not_start',
            'daemon_implementation_review_valid',
            'supervised_start_plan',
            'ready_for_supervised_start_implementation',
            'start_allowed_by_review',
            'blocked_pending_daemon_implementation_review',
            'blocked_pending_human_review',
            'production_sdk_loop_wired',
        ], 'runtimes/python/voice_realtime/tests/test_livekit_runtime_entrypoint.py: AP-687 worker start production promotion guardrails must be tested'));

        $violations = array_merge($violations, $this->primitives->missingTokenViolations($pythonSupervisedStartPlan, [
            'atlas.voice_realtime.supervised_start_plan.v1',
            'atlas.voice_realtime.daemon_supervisor_contract.v1',
            'atlas.voice_realtime.daemon_supervisor_health_snapshot.v1',
            'atlas.voice_realtime.daemon_supervisor_preflight.v1',
            'build_supervised_start_plan',
            'ready_for_supervised_start_implementation',
            'start_allowed',
            'execution_implemented',
            'supervisor_required',
            'supervisor_contract',
            'supervisor_health_snapshot',
            'supervisor_preflight',
            'lifecycle_states',
            'callback_router_roundtrip',
            'kernel_event_normalizer_roundtrip',
            'restart_without_decision_receipt',
            'revoke_livekit_session_leases',
            'VOICE_DAEMON_SUPERVISOR_PLANNED',
            'VOICE_DAEMON_HEALTH_CHECK_PLANNED',
            'VOICE_DAEMON_PREFLIGHT_CHECKED',
            'worker_process_launch_disabled',
            'kernel_event_normalizer_required',
            'direct_provider_call_allowed',
            'raw_audio_persistence_allowed',
            'start_without_supervisor_allowed',
            'unbounded_restart_loop_allowed',
            'implement_supervised_daemon_start',
        ], 'runtimes/python/voice_realtime/atlas_voice_agent/supervised_start_plan.py: AP-687 supervised daemon start plan must remain fail-closed before real process launch'));

        $violations = array_merge($violations, $this->primitives->missingTokenViolations($pythonSupervisedStartPlanTest, [
            'test_blocks_until_human_review',
            'test_blocks_until_daemon_implementation_review',
            'test_ready_for_implementation_still_does_not_start',
            'test_kernel_normalizer_is_required',
            'ready_for_supervised_start_implementation',
            'daemon_supervisor_contract.v1',
            'daemon_supervisor_health_snapshot.v1',
            'daemon_supervisor_preflight.v1',
            'callback_router_roundtrip',
            'restart_without_decision_receipt',
            'ready_for_supervisor_implementation',
            'ready_for_supervisor_execution_implementation',
            'worker_process_launch_disabled',
            'blocked_kernel_normalizer_contract',
        ], 'runtimes/python/voice_realtime/tests/test_supervised_start_plan.py: AP-687 supervised start plan must be tested fail-closed'));

        $violations = array_merge($violations, $this->primitives->missingTokenViolations($pythonDaemonImplementationReview, [
            'atlas.voice_realtime.daemon_implementation_review.v1',
            'atlas.voice_realtime.daemon_implementation_review_check.v1',
            'validate_daemon_implementation_review',
            'implementation_ref',
            'disable_livekit_worker_launch',
            'direct_provider_call_from_daemon',
            'start_without_supervisor',
            'supervised_start_required_must_be_true',
            'direct_provider_call_allowed_must_be_false',
            'raw_audio_persistence_allowed_must_be_false',
        ], 'runtimes/python/voice_realtime/atlas_voice_agent/daemon_implementation_review.py: AP-687 daemon implementation review must be a separate fail-closed receipt'));

        $violations = array_merge($violations, $this->primitives->missingTokenViolations($pythonDaemonImplementationReviewTest, [
            'test_valid_daemon_implementation_review_is_approved_and_sanitized',
            'test_missing_daemon_implementation_review_is_not_approved',
            'test_rejects_review_without_required_rollback_actions',
            'test_rejects_direct_provider_or_raw_audio_shortcut',
            'test_rejects_nested_secret_keys_in_daemon_review',
            'commit:voice-daemon-reviewed',
            'direct_provider_call_from_daemon',
            'start_without_supervisor',
        ], 'runtimes/python/voice_realtime/tests/test_daemon_implementation_review.py: AP-687 daemon implementation review receipt must be tested'));

        $violations = array_merge($violations, $this->primitives->missingTokenViolations($pythonDaemonSupervisor, [
            'atlas.voice_realtime.daemon_supervisor_execution.v1',
            'atlas.voice_realtime.daemon_process_adapter_blueprint.v1',
            'AtlasVoiceDaemonSupervisor',
            'evaluate_daemon_supervisor',
            'ready_for_process_adapter_implementation',
            'supervised_contract_only',
            'process_adapter_blueprint',
            'argv_template',
            'required_env_keys',
            'secret_safe_output_policy',
            'launch_allowed',
            'VOICE_DAEMON_PROCESS_ADAPTER_BLUEPRINTED',
            'supervised_process_adapter',
            'inspect_supervised_process_adapter',
            'atlas.voice_realtime.supervised_process_adapter.v1',
            'process_launch_attempted',
            'daemon_started',
            'process_adapter_implemented',
            'start_allowed',
            'VOICE_DAEMON_SUPERVISOR_EVALUATED',
            'VOICE_DAEMON_START_BLOCKED',
            'process_launch_allowed_by_this_contract',
            'implement_reviewed_process_adapter',
        ], 'runtimes/python/voice_realtime/atlas_voice_agent/daemon_supervisor.py: AP-687 daemon supervisor execution boundary must exist and remain fail-closed'));

        $violations = array_merge($violations, $this->primitives->missingTokenViolations($pythonDaemonSupervisorTest, [
            'test_ready_worker_reaches_process_adapter_boundary_without_launch',
            'test_blocks_when_receipts_are_missing',
            'test_blocks_when_preflight_is_not_ready',
            'atlas.voice_realtime.daemon_supervisor_execution.v1',
            'atlas.voice_realtime.daemon_process_adapter_blueprint.v1',
            'ready_for_process_adapter_implementation',
            'process_adapter_blueprint',
            'launch_allowed',
            'supervised_process_adapter',
            'atlas.voice_realtime.supervised_process_adapter.v1',
            'process_launch_attempted',
            'daemon_started',
            'process_adapter_implemented',
            'process_launch_allowed_by_this_contract',
        ], 'runtimes/python/voice_realtime/tests/test_daemon_supervisor.py: AP-687 daemon supervisor execution boundary must be tested fail-closed'));

        $violations = array_merge($violations, $this->primitives->missingTokenViolations($pythonSupervisedProcessAdapter, [
            'atlas.voice_realtime.supervised_process_adapter.v1',
            'AtlasVoiceSupervisedProcessAdapter',
            'inspect_supervised_process_adapter',
            'reviewed_shell_no_launch',
            'blocked_launch_not_implemented',
            'subprocess_module_imported',
            'livekit_sdk_imported',
            'start_worker_process',
            'stop_worker_process',
            'rollback',
            'atlas.voice_realtime.managed_env_contract.v1',
            'managed_environment_contract',
            'env_file_write_attempted',
            'secret_values_present_in_output',
            'write_env_file_in_contract_shell',
            'atlas.voice_realtime.launch_authorization_contract.v1',
            'launch_authorization_contract',
            'authorize_launch',
            'start_process_from_authorization_contract',
            'atlas.voice_realtime.managed_env_writer.v1',
            'managed_env_writer',
            'managed_env_writer_contract_available',
            'execute_managed_env_write',
            'supervised_launch_execution',
            'inspect_supervised_launch_execution',
            'supervised_launch_execution_contract_available',
            'atlas.voice_realtime.supervised_launch_execution.v1',
            'subprocess_start_contract',
            'inspect_subprocess_start_contract',
            'subprocess_start_contract_available',
            'subprocess_start_contract_implemented',
            'atlas.voice_realtime.subprocess_start_contract.v1',
            'VOICE_DAEMON_PROCESS_ADAPTER_INSPECTED',
            'VOICE_DAEMON_MANAGED_ENV_CONTRACT_DECLARED',
            'VOICE_DAEMON_LAUNCH_AUTHORIZATION_DECLARED',
            'VOICE_DAEMON_MANAGED_ENV_WRITER_EVALUATED',
            'VOICE_DAEMON_SUPERVISED_LAUNCH_EVALUATED',
            'VOICE_DAEMON_SUBPROCESS_START_CONTRACT_EVALUATED',
            'process_launch_attempted',
            'daemon_started',
            'launch_allowed',
        ], 'runtimes/python/voice_realtime/atlas_voice_agent/supervised_process_adapter.py: AP-687 supervised process adapter shell must exist and remain fail-closed'));

        $violations = array_merge($violations, $this->primitives->missingTokenViolations($pythonSupervisedProcessAdapterTest, [
            'test_inspects_ready_supervisor_without_launching_process',
            'test_blocks_when_supervisor_execution_is_not_ready',
            'atlas.voice_realtime.supervised_process_adapter.v1',
            'blocked_launch_not_implemented',
            'subprocess_module_imported',
            'process_launch_attempted',
            'daemon_started',
            'VOICE_DAEMON_PROCESS_ADAPTER_INSPECTED',
            'atlas.voice_realtime.managed_env_contract.v1',
            'managed_environment_contract',
            'env_file_write_attempted',
            'secret_values_present_in_output',
            'VOICE_DAEMON_MANAGED_ENV_CONTRACT_DECLARED',
            'atlas.voice_realtime.launch_authorization_contract.v1',
            'launch_authorization_contract',
            'VOICE_DAEMON_LAUNCH_AUTHORIZATION_DECLARED',
            'atlas.voice_realtime.managed_env_writer.v1',
            'managed_env_writer',
            'managed_env_writer_contract_available',
            'VOICE_DAEMON_MANAGED_ENV_WRITER_EVALUATED',
            'supervised_launch_execution',
            'supervised_launch_execution_contract_available',
            'atlas.voice_realtime.supervised_launch_execution.v1',
            'subprocess_start_contract',
            'subprocess_start_contract_available',
            'atlas.voice_realtime.subprocess_start_contract.v1',
            'VOICE_DAEMON_SUBPROCESS_START_CONTRACT_EVALUATED',
            'VOICE_DAEMON_SUPERVISED_LAUNCH_EVALUATED',
        ], 'runtimes/python/voice_realtime/tests/test_supervised_process_adapter.py: AP-687 supervised process adapter shell must be tested fail-closed'));

        $violations = array_merge($violations, $this->primitives->missingTokenViolations($pythonManagedEnvWriter, [
            'atlas.voice_realtime.managed_env_writer.v1',
            'inspect_managed_env_writer',
            'contract_only_no_file_write',
            'execute_managed_env_write',
            'atlas.voice_realtime.managed_env_write_authorization.v1',
            'atlas.voice_realtime.managed_env_write_execution.v1',
            'writer_contract_implemented',
            'write_execution_available',
            'write_execution_implemented',
            'env_file_write_attempted',
            'written_placeholder_env',
            'content_sha256',
            'os.chmod(target_path, 0o600)',
            'secret_values_present_in_output',
            'redacted_env_manifest',
            'write_env_file_from_writer_contract',
            'VOICE_DAEMON_MANAGED_ENV_WRITER_EVALUATED',
            'VOICE_DAEMON_MANAGED_ENV_WRITE_BLOCKED',
            'start_process_after_env_render',
        ], 'runtimes/python/voice_realtime/atlas_voice_agent/managed_env_writer.py: AP-687 managed env writer contract must exist without writing env files'));

        $violations = array_merge($violations, $this->primitives->missingTokenViolations($pythonManagedEnvWriterTest, [
            'test_writer_contract_is_ready_without_writing_env_file_or_leaking_secret',
            'test_writer_blocks_unsafe_manifest_values',
            'test_execute_managed_env_write_requires_explicit_authorization',
            'test_execute_managed_env_write_writes_placeholder_only_file_without_starting_process',
            'test_execute_managed_env_write_blocks_unsafe_target_path',
            'atlas.voice_realtime.managed_env_writer.v1',
            'atlas.voice_realtime.managed_env_write_execution.v1',
            'write_execution_implemented',
            'env_file_write_attempted',
            'secret_values_present_in_output',
            'write_env_file_from_writer_contract',
            'literal-secret-value',
            '0o600',
        ], 'runtimes/python/voice_realtime/tests/test_managed_env_writer.py: AP-687 managed env writer contract must be tested fail-closed'));

        $violations = array_merge($violations, $this->primitives->missingTokenViolations($pythonSupervisedLaunchExecution, [
            'atlas.voice_realtime.supervised_launch_execution.v1',
            'inspect_supervised_launch_execution',
            'atlas.voice_realtime.launch_execution_authorization.v1',
            'atlas.voice_realtime.managed_env_write_execution.v1',
            'atlas.voice_realtime.pre_start_health_checks_authorization.v1',
            'atlas.voice_realtime.pre_start_health_checks_execution.v1',
            'atlas.voice_realtime.subprocess_start_authorization.v1',
            'atlas.voice_realtime.subprocess_start_contract.v1',
            'execute_pre_start_health_checks',
            'inspect_subprocess_start_contract',
            'ready_for_subprocess_implementation',
            'ready_for_reviewed_subprocess_start_implementation',
            'execution_contract_no_subprocess_start',
            'start_contract_no_subprocess_import',
            'pre_start_health_checks_execution_available',
            'pre_start_health_checks_executed',
            'subprocess_start_contract_implemented',
            'subprocess_launch_implemented',
            'process_launch_attempted',
            'daemon_started',
            'subprocess_module_imported',
            'livekit_sdk_imported',
            'managed_env_content_sha256',
            'managed_env_target_path',
            'argv_redacted',
            'provider_calls_made',
            'raw_audio_touched',
            'VOICE_DAEMON_SUPERVISED_LAUNCH_EVALUATED',
            'VOICE_DAEMON_PRE_START_HEALTH_CHECKS_EVALUATED',
            'VOICE_DAEMON_SUBPROCESS_START_CONTRACT_EVALUATED',
            'VOICE_DAEMON_SUBPROCESS_START_BLOCKED',
            'implement_real_subprocess_start_after_final_review',
            'start_without_launch_execution_decision_receipt',
            'call_provider_from_launch_execution',
        ], 'runtimes/python/voice_realtime/atlas_voice_agent/supervised_launch_execution.py: AP-687 supervised launch execution contract must exist without starting subprocess'));

        $violations = array_merge($violations, $this->primitives->missingTokenViolations($pythonSupervisedLaunchExecutionTest, [
            'test_launch_execution_contract_is_available_but_blocked_without_written_env',
            'test_launch_execution_becomes_ready_after_written_env_and_authorization_without_starting',
            'test_launch_execution_blocks_if_authorization_would_allow_process_launch',
            'test_pre_start_health_checks_pass_without_starting_process',
            'test_pre_start_health_checks_block_missing_or_unsafe_check',
            'test_subprocess_start_contract_blocks_without_pre_start_health_checks',
            'test_subprocess_start_contract_becomes_ready_without_importing_or_starting',
            'test_subprocess_start_contract_blocks_if_authorization_would_allow_process_launch',
            'atlas.voice_realtime.supervised_launch_execution.v1',
            'atlas.voice_realtime.launch_execution_authorization.v1',
            'atlas.voice_realtime.managed_env_write_execution.v1',
            'atlas.voice_realtime.pre_start_health_checks_authorization.v1',
            'atlas.voice_realtime.pre_start_health_checks_execution.v1',
            'atlas.voice_realtime.subprocess_start_authorization.v1',
            'atlas.voice_realtime.subprocess_start_contract.v1',
            'ready_for_subprocess_implementation',
            'ready_for_reviewed_subprocess_start_implementation',
            'pre_start_health_checks_execution_available',
            'pre_start_health_checks_executed',
            'subprocess_start_contract_implemented',
            'subprocess_launch_implemented',
            'process_launch_attempted',
            'daemon_started',
            'managed_env_content_sha256',
            'VOICE_DAEMON_SUPERVISED_LAUNCH_EVALUATED',
            'VOICE_DAEMON_PRE_START_HEALTH_CHECKS_EVALUATED',
            'VOICE_DAEMON_SUBPROCESS_START_CONTRACT_EVALUATED',
            'import_subprocess_from_launch_execution_contract',
        ], 'runtimes/python/voice_realtime/tests/test_supervised_launch_execution.py: AP-687 supervised launch execution contract must be tested fail-closed'));

        $violations = array_merge($violations, $this->primitives->missingTokenViolations($pythonActivationContract, [
            'production_sdk_loop_wired',
            'start_worker_before_production_sdk_loop_wired',
            'wire_production_sdk_loop',
        ], 'runtimes/python/voice_realtime/atlas_voice_agent/activation_contract.py: AP-687 activation contract must require production SDK loop wiring before worker start'));

        $violations = array_merge($violations, $this->primitives->missingTokenViolations($pythonActivationContractTest, [
            'test_activation_contract_blocks_until_production_sdk_loop_is_wired',
            'production_sdk_loop_wired',
            'wire_production_sdk_loop',
        ], 'runtimes/python/voice_realtime/tests/test_activation_contract.py: AP-687 activation contract production SDK loop gate must be tested'));

        $violations = array_merge($violations, $this->primitives->missingTokenViolations($pythonSettingsTest, [
            'rejects_urls_with_control_characters',
            'rejects_room_prefix_outside_atlas_voice_namespace',
            'LIVEKIT_API_SECRET=injected',
            'atlas-voice-from-file-',
        ], 'runtimes/python/voice_realtime/tests/test_settings.py: AP-687 Python runtime settings safety must be tested'));

        $violations = array_merge($violations, $this->primitives->missingTokenViolations($command, [
            'Production promotion',
            'Human review required',
            'PYTHON_COMMAND_TIMEOUT_SECONDS',
            'voice_runtime_command_timeout',
            'callback-loop-wired',
            'production-sdk-loop-wired',
            'production-promotion-review-file',
            'daemon-implementation-review-file',
            'Daemon review valid',
            'Supervised start plan',
            'Supervised start allowed',
            'Supervisor health snapshot',
            'Supervisor daemon started',
            'Daemon supervisor execution',
            'Daemon supervisor launch attempted',
            'product-loop-check',
            'daemon-supervisor-check',
        ], 'app/Console/Commands/AtlasAiVoiceRealtimeCommand.php: AP-687 CLI must surface production promotion status and human review requirement'));

        $violations = array_merge($violations, $this->primitives->missingTokenViolations($tokenIssuerTest, [
            'rejects_rooms_outside_atlas_voice_namespace',
            'rejects_participants_outside_client_surface_namespace',
            'not_issued_invalid_lease',
        ], 'tests/Unit/Ai/Voice/AtlasVoiceLiveKitTokenIssuerTest.php: AP-687 token issuer must reject arbitrary room and participant leases'));

        $violations = array_merge($violations, $this->primitives->missingTokenViolations($unitTest, [
            'production_promotion_gate.status',
            'production_promotion_gate.promotion_allowed',
            'auto_promotion_allowed',
            'decision_receipt_required',
            'rollback_plan_required',
            'sdk_certification_required',
            'livekit_token_issuer_ready',
            'production_promotion_must_run_with_require_sdk',
            'sanitizes_base_url_before_generating_runtime_env_files',
            'LIVEKIT_API_SECRET=injected',
            'timeout_seconds',
            'product_loop_check_available',
            'sdk_handler_blueprint_available',
            'sdk_kernel_normalizer_required',
            'daemon_supervisor_contract_available',
            'daemon_supervisor_health_snapshot_available',
            'daemon_supervisor_preflight_available',
            'daemon_supervisor_execution_available',
            'daemon_supervisor_process_launch_disabled',
            'daemon_process_adapter_blueprint_available',
            'supervised_process_adapter_available',
            'managed_env_contract_available',
            'launch_authorization_contract_available',
            'launch_authorization_contract_ready',
            'managed_env_writer_contract_available',
            'supervised_launch_execution_contract_available',
            'subprocess_start_contract_available',
            'daemon_supervisor_execution_schema_version',
            'daemon_supervisor_execution_process_launch_attempted',
            'daemon_supervisor_execution_start_allowed',
            'daemon_process_adapter_blueprint_schema_version',
            'daemon_process_adapter_blueprint_launch_allowed',
            'supervised_process_adapter_schema_version',
            'supervised_process_adapter_process_launch_attempted',
            'managed_env_contract_schema_version',
            'managed_env_contract_write_attempted',
            'managed_env_contract_secret_values_present',
            'launch_authorization_contract_schema_version',
            'launch_authorization_contract_launch_allowed',
            'launch_authorization_contract_process_launch_attempted',
            'managed_env_writer_schema_version',
            'managed_env_writer_write_execution_available',
            'managed_env_writer_write_execution_implemented',
            'managed_env_writer_write_attempted',
            'supervised_launch_execution_schema_version',
            'supervised_launch_execution_process_launch_attempted',
            'supervised_launch_execution_daemon_started',
            'supervised_launch_execution_pre_start_health_checks_available',
            'supervised_launch_execution_pre_start_health_checks_executed',
            'subprocess_start_contract_schema_version',
            'subprocess_start_contract_process_launch_attempted',
            'subprocess_start_contract_daemon_started',
            'subprocess_start_contract_subprocess_launch_implemented',
            'supervisor_health_snapshot_schema_version',
            'supervisor_health_snapshot_daemon_started',
            'supervisor_preflight_schema_version',
            'supervisor_preflight_process_launch_attempted',
            'daemon_implementation_review_valid',
            'artifacts.product_loop_check',
            'product-loop-secret',
        ], 'tests/Unit/Ai/Voice/AtlasVoiceRuntimeCertificationServiceTest.php: AP-687 production promotion gate must be covered by unit tests'));

        $violations = array_merge($violations, $this->primitives->missingTokenViolations($commandTest, [
            'Production promotion',
            'Human review required',
            'production_promotion_gate.status',
            'production_promotion_gate.promotion_allowed',
            'phase0_hardening.schema_version',
            'phase0_hardening.safe_next_block',
            'sanitizes_bootstrap_base_url_before_runtime_env_use',
            'test_command_exposes_worker_start_check_with_product_loop_wired_but_still_blocked',
            'test_command_exposes_worker_start_check_with_review_flag_without_starting_daemon',
            'test_command_exposes_worker_start_check_with_review_receipt_without_starting_daemon',
            'test_command_exposes_worker_start_check_with_daemon_review_receipt_without_starting_daemon',
            'test_command_exposes_product_loop_check_with_review_receipt_without_starting_daemon',
            'test_command_exposes_product_loop_check_with_daemon_review_receipt_without_starting_daemon',
            'test_command_exposes_daemon_supervisor_check_without_process_launch',
            'test_command_human_output_lists_daemon_supervisor_check',
            'test_command_exposes_product_loop_check_as_json',
            'test_command_human_output_lists_product_loop_check',
            'test_command_human_output_lists_readiness_product_loop_reference',
            'atlas.voice_realtime.product_loop_check.v1',
            'atlas.voice_realtime.product_loop_check_reference.v1',
            '--product-loop-check',
            '--daemon-supervisor-check',
            '--callback-loop-wired',
            '--production-sdk-loop-wired',
            '--production-promotion-review-file',
            '--daemon-implementation-review-file',
            'production_review_receipt_valid',
            'daemon_implementation_review_valid',
            'supervised_start_plan.schema_version',
            'supervised_start_plan.supervisor_contract.schema_version',
            'supervised_start_plan.supervisor_health_snapshot.schema_version',
            'supervised_start_plan.supervisor_preflight.schema_version',
            'supervised_start_plan.start_allowed',
            'supervised_start_plan.supervisor_contract.process_launch_implemented',
            'supervised_start_plan.supervisor_health_snapshot.daemon_started',
            'supervised_start_plan.supervisor_preflight.process_launch_attempted',
            'daemon_supervisor_execution_available',
            'daemon_supervisor_process_launch_disabled',
            'daemon_process_adapter_blueprint_available',
            'supervised_process_adapter_available',
            'managed_env_contract_available',
            'launch_authorization_contract_available',
            'launch_authorization_contract_ready',
            'managed_env_writer_contract_available',
            'supervised_launch_execution_contract_available',
            'subprocess_start_contract_available',
            'daemon_supervisor_execution_schema_version',
            'daemon_supervisor_execution_process_launch_attempted',
            'daemon_supervisor_execution_start_allowed',
            'daemon_process_adapter_blueprint_schema_version',
            'daemon_process_adapter_blueprint_launch_allowed',
            'supervised_process_adapter_schema_version',
            'supervised_process_adapter_process_launch_attempted',
            'managed_env_contract_schema_version',
            'managed_env_contract_write_attempted',
            'managed_env_contract_secret_values_present',
            'launch_authorization_contract_schema_version',
            'launch_authorization_contract_launch_allowed',
            'launch_authorization_contract_process_launch_attempted',
            'managed_env_writer_schema_version',
            'managed_env_writer_write_execution_available',
            'managed_env_writer_write_execution_implemented',
            'managed_env_writer_write_attempted',
            'supervised_launch_execution_schema_version',
            'supervised_launch_execution_process_launch_attempted',
            'supervised_launch_execution_daemon_started',
            'supervised_launch_execution_pre_start_health_checks_available',
            'supervised_launch_execution_pre_start_health_checks_executed',
            'subprocess_start_contract_schema_version',
            'subprocess_start_contract_process_launch_attempted',
            'subprocess_start_contract_daemon_started',
            'subprocess_start_contract_subprocess_launch_implemented',
            'Supervised start plan',
            'Supervised start allowed',
            'Supervisor health snapshot',
            'Supervisor daemon started',
            'review_receipt_valid',
            'boolean_approval_is_sufficient',
            'sdk_imported',
            'import_probe_only',
            'sdk_probe_import_safe',
            'sdk_handler_blueprint_available',
            'sdk_kernel_normalizer_required',
            'product_loop_check_available',
            'artifacts.product_loop_check',
            'timeout_seconds',
        ], 'tests/Feature/Ai/AtlasAiVoiceRealtimeCommandTest.php: AP-687 CLI promotion gate output must be tested'));

        $violations = array_merge($violations, $this->primitives->missingTokenViolations($apiTest, [
            'production_promotion_gate.schema_version',
            'production_promotion_gate.promotion_allowed',
            'livekit_token_issuer.schema_version',
            'does_not_issue_livekit_token_without_livekit_url',
            'scopes_requested_livekit_room_to_atlas_voice_prefix',
            'scopes_requested_livekit_participant_to_client_surface',
            'sanitizes_base_url_before_manifest_publication',
            'product_loop_check_available',
            'sdk_handler_blueprint_available',
            'sdk_kernel_normalizer_required',
            'artifacts.product_loop_check',
        ], 'tests/Feature/Ai/AtlasAiVoiceRealtimeApiTest.php: AP-687 API/mobile promotion gate and token issuer artifacts must be tested'));

        $violations = array_merge($violations, $this->primitives->missingTokenViolations($apDoc, [
            'status: implemented_ready',
            'production_promotion_gate.status=blocked',
            'promotion_allowed=false',
            'auto_promotion_allowed=false',
            'human_review_required=true',
            'review_packet',
            'atlas-voice-',
            'base_url',
            'caracteres de controle',
            'voice_runtime_command_timeout',
            'Rivals-Voice e Curator consomem o gate antes de maturidade',
            'product_loop_wiring_flags_visible',
            'product_loop_check_available',
            'worker_return_contract.status=valid',
            'bridge_contract_report.status=valid',
            'sdk_probe_import_safe',
            'sdk_handler_blueprint_available',
            'sdk_kernel_normalizer_required',
            'managed_env_contract_available',
            'atlas.voice_realtime.managed_env_contract.v1',
            'launch_authorization_contract_available',
            'launch_authorization_contract_ready',
            'atlas.voice_realtime.launch_authorization_contract.v1',
            'managed_env_writer_contract_available',
            'atlas.voice_realtime.managed_env_writer.v1',
            'subprocess_start_contract_available',
            'atlas.voice_realtime.subprocess_start_contract.v1',
            'env_file_write_attempted=false',
            'secret_values_present_in_output=false',
            'daemon_implementation_review_valid',
            'ready_for_supervised_start_implementation',
            'blocked_pending_daemon_implementation_review',
            'production_sdk_loop_wired',
            'atlas.voice_realtime.product_loop_check.v1',
            'sdk_imported=false',
            'import_probe_only=true',
            '--callback-loop-wired',
            '--production-sdk-loop-wired',
            'runtime-certify',
            'status=blocked',
        ], 'docs/ap/AP-687-voice-realtime-production-promotion-gate.md: AP-687 implementation contract must remain documented'));

        $violations = array_merge($violations, $this->primitives->missingTokenViolations($doc, [
            'AP-687',
            'production_promotion_gate',
            'phase0_hardening',
            'product_loop_wiring_flags',
            'product-loop-check',
            'atlas.voice_realtime.product_loop_check.v1',
            'worker_return_contract',
            'bridge_contract_report',
            'sdk-check',
            'sdk_imported=false',
            'import_probe_only=true',
            'auto-promotion',
            'artifacts.product_loop_check',
            'product_loop_check_available',
            'sdk_handler_blueprint_available',
            'sdk_kernel_normalizer_required',
            'atlas.voice_realtime.managed_env_contract.v1',
            'atlas.voice_realtime.launch_authorization_contract.v1',
            'atlas.voice_realtime.subprocess_start_contract.v1',
            'subprocess_start_contract_available',
            'env_file_write_attempted=false',
            'daemon_implementation_review_valid',
            'ready_for_supervised_start_implementation',
            'blocked_pending_daemon_implementation_review',
            'production_sdk_loop_wired',
        ], 'docs/engineering-knowledge-base/atlas-ai-voice-realtime-surface.md: AP-687 owner doc must keep production promotion governance'));

        $violations = array_merge($violations, $this->primitives->missingTokenViolations($pythonProductionLoopRunnerTest, [
            'test_bridge_contract_report_fails_closed_for_missing_callback_or_guardrail',
            'test_handler_registry_contract_report_fails_closed_for_missing_handler_or_guardrail',
            'handler_registry_contract_report',
            'atlas.voice_realtime.handler_registry_contract_report.v1',
            'missing_handlers',
            'direct_provider_call_allowed',
            'missing_callbacks',
            'invalid_guardrails',
        ], 'runtimes/python/voice_realtime/tests/test_livekit_production_loop_runner.py: AP-687 production loop smoke must fail closed when bridge callbacks or guardrails regress'));

        $violations = array_merge($violations, $this->primitives->missingTokenViolations($pythonProductionLoopRunner, [
            'LiveKitSdkHandlerRegistry',
            'handler_registry_contract_report',
            'atlas.voice_realtime.handler_registry_contract_report.v1',
            '_handler_registry_contract_report',
            'missing_handlers',
            'sdk_import_safe',
        ], 'runtimes/python/voice_realtime/atlas_voice_agent/livekit_production_loop_runner.py: AP-687 production smoke must pass through SDK handler registry'));

        $violations = array_merge($violations, $this->primitives->missingTokenViolations($pythonSdkWiringContract, [
            'handler_blueprint',
            'handler_registry_contract',
            'complete_handler_registry',
            'LiveKitSdkHandlerRegistry',
            'KernelRuntimeEventNormalizerGuard',
            'wiring_invariants',
            'never_forward_sdk_objects_or_tokens',
            'validate_every_sdk_event_through_kernel_normalizer',
            'call_LiveKitSdkEventBridge_to_callback_event',
            'route_through_LiveKitCallbackRouter',
            'route_all_livekit_sdk_handlers_through_registry',
            'kernel_event_normalizer_required_for_real_loop',
            'return_LiveKitWorkerResult_log_payload_only',
            'never_call_provider_tool_memory_or_policy_from_sdk_handler',
        ], 'runtimes/python/voice_realtime/atlas_voice_agent/livekit_sdk_wiring_contract.py: AP-687 SDK wiring contract must document exact handler blueprint and Kernel-only invariants'));

        $violations = array_merge($violations, $this->primitives->missingTokenViolations($pythonSdkWiringContractTest, [
            'test_wiring_contract_uses_bridge_and_router_as_sources_of_truth',
            'handler_blueprint',
            'handler_registry_contract',
            'complete_handler_registry',
            'LiveKitSdkHandlerRegistry',
            'KernelRuntimeEventNormalizerGuard',
            'call_LiveKitSdkEventBridge_to_callback_event',
            'validate_every_sdk_event_through_kernel_normalizer',
            'route_all_livekit_sdk_handlers_through_registry',
            'never_call_provider_tool_memory_or_policy_from_sdk_handler',
            'kernel_event_normalizer_required_for_real_loop',
            'provider SDK call',
        ], 'runtimes/python/voice_realtime/tests/test_livekit_sdk_wiring_contract.py: AP-687 SDK wiring blueprint must be tested'));

        $violations = array_merge($violations, $this->primitives->missingTokenViolations($pythonSdkHandlers, [
            'atlas.voice_realtime.sdk_handler_registry.v1',
            'LiveKitSdkHandlerRegistry',
            'KernelRuntimeEventNormalizerGuard',
            'assert_event_valid',
            'LiveKitSdkEventBridge.to_callback_event',
            'LiveKitCallbackRouter.route',
            'direct_provider_call_allowed',
            'direct_tool_execution_allowed',
            'memory_write_allowed',
            'policy_mutation_allowed',
            'sdk_import_required_for_contract',
        ], 'runtimes/python/voice_realtime/atlas_voice_agent/livekit_sdk_handlers.py: AP-687 SDK handlers must route real SDK callbacks through Kernel-only registry'));

        $violations = array_merge($violations, $this->primitives->missingTokenViolations($pythonSdkHandlersTest, [
            'test_handler_registry_contract_is_complete_without_importing_sdk',
            'test_handlers_route_sdk_events_through_kernel_only_router',
            'test_handlers_validate_sdk_events_with_kernel_normalizer_before_routing',
            'test_handlers_fail_closed_when_kernel_normalizer_rejects_event',
            'test_handlers_reject_forbidden_authority_and_raw_audio_fields',
            'KernelRuntimeEventNormalizerGuard',
            'direct_provider_call',
            'audio_bytes',
        ], 'runtimes/python/voice_realtime/tests/test_livekit_sdk_handlers.py: AP-687 SDK handler registry must be tested fail-closed'));

        $violations = array_merge($violations, $this->primitives->missingTokenViolations($pythonProductLoopCheck, [
            'atlas.voice_realtime.product_loop_check.v1',
            'build_product_loop_check',
            'daemon_started',
            'production_promotion_blocked',
            'sdk_probe_import_safe',
            'sdk_handler_blueprint_available',
            'sdk_kernel_normalizer_required',
            'complete_handler_registry',
            'handler_registry_contract',
            'handler_blueprint',
            'KernelRuntimeEventNormalizerGuard',
            'kernel_event_normalizer_required_for_real_loop',
            'validate_every_sdk_event_through_kernel_normalizer',
            'fix_sdk_kernel_normalizer_contract',
            'fix_sdk_handler_blueprint_contract',
            'auto_promotion_allowed',
            'fix_sdk_probe_contract',
            'production_review_receipt_valid',
            'daemon_implementation_review_valid',
            'supervised_start_plan',
            'supervised_start_plan_available',
            'daemon_supervisor_contract_available',
            'daemon_supervisor_health_snapshot_available',
            'daemon_supervisor_preflight_available',
            'daemon_supervisor_execution_available',
            'daemon_supervisor_process_launch_disabled',
            'daemon_process_adapter_blueprint_available',
            'supervised_process_adapter_available',
            'managed_env_contract_available',
            'launch_authorization_contract_available',
            'launch_authorization_contract_ready',
            'managed_env_writer_contract_available',
            'supervised_launch_execution_contract_available',
            'subprocess_start_contract_available',
            'evaluate_daemon_supervisor',
            'boolean_approval_is_sufficient',
            'ready_for_human_review',
            'ready_for_daemon_implementation_review',
            'ready_for_supervised_start_implementation',
            'submit_daemon_implementation_review',
            'implement_supervised_daemon_start',
        ], 'runtimes/python/voice_realtime/atlas_voice_agent/product_loop_check.py: AP-687 product loop check must aggregate readiness without starting daemon'));

        $violations = array_merge($violations, $this->primitives->missingTokenViolations($pythonProductLoopCheckTest, [
            'test_product_loop_check_aggregates_wired_gates_without_starting_daemon',
            'test_product_loop_check_never_treats_mock_kernel_as_product_ready',
            'test_product_loop_check_blocks_if_sdk_probe_contract_was_bypassed',
            'daemon_started',
            'production_promotion_blocked',
            'sdk_probe_import_safe',
            'sdk_handler_blueprint_available',
            'sdk_kernel_normalizer_required',
            'test_product_loop_check_blocks_if_sdk_handler_blueprint_is_missing',
            'test_product_loop_check_blocks_if_kernel_normalizer_is_not_required',
            'test_product_loop_check_reaches_daemon_review_only_with_valid_review_receipt',
            'test_product_loop_check_reaches_supervised_start_only_with_daemon_review_receipt',
            'production_review_receipt_valid',
            'daemon_implementation_review_valid',
            'supervised_start_plan_available',
            'daemon_supervisor_contract_available',
            'daemon_supervisor_health_snapshot_available',
            'daemon_supervisor_preflight_available',
            'daemon_supervisor_execution_available',
            'daemon_supervisor_process_launch_disabled',
            'daemon_process_adapter_blueprint_available',
            'supervised_process_adapter_available',
            'managed_env_contract_available',
            'launch_authorization_contract_available',
            'launch_authorization_contract_ready',
            'managed_env_writer_contract_available',
            'supervised_launch_execution_contract_available',
            'subprocess_start_contract_available',
            'atlas.voice_realtime.subprocess_start_contract.v1',
            'atlas.voice_realtime.daemon_supervisor_execution.v1',
            'atlas.voice_realtime.supervised_process_adapter.v1',
            'atlas.voice_realtime.managed_env_contract.v1',
            'atlas.voice_realtime.launch_authorization_contract.v1',
            'atlas.voice_realtime.managed_env_writer.v1',
            'atlas.voice_realtime.supervised_launch_execution.v1',
            'atlas.voice_realtime.supervised_start_plan.v1',
            'atlas.voice_realtime.daemon_supervisor_preflight.v1',
            'boolean_approval_is_sufficient',
            'ready_for_human_review',
            'ready_for_daemon_implementation_review',
            'ready_for_supervised_start_implementation',
            'fix_sdk_handler_blueprint_contract',
            'fix_sdk_kernel_normalizer_contract',
            'fix_sdk_probe_contract',
        ], 'runtimes/python/voice_realtime/tests/test_product_loop_check.py: AP-687 product loop check must be tested fail-closed'));

        $violations = array_merge($violations, $this->primitives->missingTokenViolations($pythonSdkStatus, [
            'metadata.version',
            'package_checks',
            'missing_imports',
            'sdk_imported',
            'import_probe_only',
            'probe_policy',
        ], 'runtimes/python/voice_realtime/atlas_voice_agent/sdk_status.py: AP-687 SDK status must be import-safe and expose package compatibility checks'));

        $violations = array_merge($violations, $this->primitives->missingTokenViolations($pythonSdkStatusTest, [
            'test_sdk_check_reports_package_checks_without_importing_sdk',
            'sdk_imported',
            'import_probe_only',
            'package_checks',
            'missing_imports',
            'probe_policy',
        ], 'runtimes/python/voice_realtime/tests/test_sdk_status.py: AP-687 SDK status probe contract must be tested'));

        $violations = array_merge($violations, $this->primitives->missingTokenViolations($staticScanDoc, [
            'Voice production promotion (AP-687)',
            'voice runtime being promoted to production',
        ], 'docs/engineering-knowledge-base/kernel/static-scans.md: AP-687 static scan behavior must be documented'));

        return $violations;
    }

    /**
     * @return array<int,string>
     */
    private function scanVoiceRealtimePythonRuntimeForbiddenPatterns(string $runtimeRoot): array
    {
        if (! File::isDirectory($runtimeRoot)) {
            return ["missing directory [{$runtimeRoot}]"];
        }

        $patterns = [
            'direct_provider_sdk_import' => '/^\s*(?:import|from)\s+(?:openai|anthropic|google\.generativeai|google_genai|cohere|mistralai|ollama|langchain|llama_index|crewai|autogen)\b/m',
            'direct_provider_endpoint' => '/(?:api\.openai\.com|api\.anthropic\.com|generativelanguage\.googleapis\.com|api\.mistral\.ai)/i',
            'non_kernel_http_client' => '/\b(?:requests\.|httpx\.|aiohttp\.ClientSession)\b/',
            'shell_escape' => '/\b(?:subprocess\.|os\.system|eval\s*\(|exec\s*\()\b/',
            'raw_audio_file_write' => '/\bopen\s*\([^)]*(?:raw_audio|audio_bytes|pcm|wav)[^)]*,\s*[\'"]w/',
        ];

        $violations = [];
        foreach (File::allFiles($runtimeRoot) as $file) {
            if ($file->getExtension() !== 'py') {
                continue;
            }

            $path = $file->getRealPath() ?: $file->getPathname();
            $relativePath = str_replace(base_path().DIRECTORY_SEPARATOR, '', $path);
            $contents = File::get($path);

            foreach ($patterns as $patternId => $pattern) {
                if (preg_match($pattern, $contents) === 1) {
                    $violations[] = "{$relativePath}: forbidden AP-686 voice runtime boundary violation [{$patternId}]. LiveKit/Python runtime may call only Atlas Kernel contract endpoints and cannot call providers/tools/shell or persist raw audio.";
                }
            }
        }

        sort($violations);

        return array_values(array_unique($violations));
    }
}
