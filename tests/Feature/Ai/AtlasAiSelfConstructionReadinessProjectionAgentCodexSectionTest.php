<?php

declare(strict_types=1);

namespace Tests\Feature\Ai;

use App\Services\Ai\SelfConstruction\Readiness\AtlasSelfConstructionReadinessService;
use App\Services\Ai\SelfConstruction\Readiness\ReadinessProjectionAgentCodexSection;
use App\Services\Ai\SelfConstruction\Support\ReadinessHash;
use Tests\TestCase;

/**
 * Locks the contract of ReadinessProjectionAgentCodexSection — the
 * agentCodex* projection concern extracted from the god-class
 * AtlasSelfConstructionReadinessService.
 *
 * The runtime service delegates 171 methods to this collaborator; this test
 * pins the wiring so a future refactor cannot silently break the delegation
 * contract.
 */
final class AtlasAiSelfConstructionReadinessProjectionAgentCodexSectionTest extends TestCase
{
    public function test_section_class_is_resolvable(): void
    {
        $this->assertTrue(class_exists(ReadinessProjectionAgentCodexSection::class));

        $runtime = app(AtlasSelfConstructionReadinessService::class);
        $this->assertTrue(method_exists($runtime, 'agentCodexProviderExecutionContractTemplate'));
    }

    public function test_hashes_use_readiness_hash_stable_convention(): void
    {
        $payload = ['key' => 'value', 'z' => 1, 'a' => 2];
        $expected = ReadinessHash::stable($payload);
        $this->assertMatchesRegularExpression('/^[a-f0-9]{64}$/', $expected);
        $this->assertSame($expected, ReadinessHash::stable($payload), 'ReadinessHash::stable must be deterministic');
    }

    public function test_all_171_agent_codex_methods_exist_on_section(): void
    {
        // Use reflection to count actual unique public methods on the section.
        $ref = new \ReflectionClass(ReadinessProjectionAgentCodexSection::class);
        $publicMethods = array_filter(
            $ref->getMethods(\ReflectionMethod::IS_PUBLIC),
            fn (\ReflectionMethod $m): bool => str_starts_with($m->getName(), 'agentCodex')
        );
        $this->assertGreaterThanOrEqual(
            171,
            count($publicMethods),
            'Section must expose at least 171 agentCodex* public methods'
        );

        // Verify at least the first few well-known methods exist on the class.
        foreach ([
            'agentCodexProviderExecutionContractTemplate',
            'agentCodexRealInvokerPostStartOperatorStartHandoffBuilderImplementationPacket',
        ] as $name) {
            $this->assertTrue(
                method_exists(ReadinessProjectionAgentCodexSection::class, $name),
                "ReadinessProjectionAgentCodexSection::{$name} must exist"
            );
        }
    }

    public function test_runtime_service_delegates_to_section(): void
    {
        $runtime = app(AtlasSelfConstructionReadinessService::class);
        // Spot-check a few key delegators exist on the runtime.
        foreach ([
            'agentCodexProviderExecutionContractTemplate',
            'agentCodexRealInvokerPostStartOperatorStartHandoffBuilderImplementationPacket',
        ] as $method) {
            $this->assertTrue(
                method_exists($runtime, $method),
                "AtlasSelfConstructionReadinessService::{$method} must exist as a delegator"
            );
        }
    }

    public function test_section_can_be_resolved_via_runtime_lazy_resolver(): void
    {
        $runtime = app(AtlasSelfConstructionReadinessService::class);
        $ref = new \ReflectionMethod($runtime, 'agentCodexSection');
        $ref->setAccessible(true);
        $section = $ref->invoke($runtime);

        $this->assertInstanceOf(ReadinessProjectionAgentCodexSection::class, $section);
    }

    public function test_liveness_monitor_preflight_reaches_read_only_storage_readiness(): void
    {
        $payload = app(AtlasSelfConstructionReadinessService::class)
            ->agentCodexRealInvokerPostStartLivenessMonitorPreflight();

        $this->assertContains($payload['status'], [
            'codex_real_invoker_post_start_liveness_monitor_ready',
            'blocked',
        ]);
        $this->assertSame(
            'read_only_agent_codex_real_invoker_post_start_liveness_monitor_preflight',
            $payload['mode']
        );
        $this->assertArrayHasKey(
            'codex_real_invoker_post_start_liveness_monitor_preflight',
            $payload
        );
        $this->assertArrayHasKey(
            'codex_real_invoker_post_start_liveness_monitor_preflight_hash',
            $payload
        );

        $preflight = $payload['codex_real_invoker_post_start_liveness_monitor_preflight'];
        $this->assertIsArray($preflight);
        $this->assertArrayHasKey('storage', $preflight);
        $this->assertArrayHasKey('agent_runs_table_ready', $preflight['storage']);
        $this->assertArrayHasKey('ledger_table_ready', $preflight['storage']);
    }

    public function test_provider_execution_contract_template_reaches_provider_adapter_preflights(): void
    {
        $runtime = app(AtlasSelfConstructionReadinessService::class);
        $registryPreflight = $runtime->agentProviderAdapterRegistryPreflight();
        $executionGuardPreflight = $runtime->agentProviderAdapterExecutionGuardPreflight();
        $payload = $runtime
            ->agentCodexProviderExecutionContractTemplate();

        $this->assertSame(
            'atlas.self_construction_agent_codex_provider_execution_contract_template.v1',
            $payload['schema_version']
        );
        $this->assertSame('read_only_agent_codex_provider_execution_contract_template', $payload['mode']);
        $this->assertSame('codex_provider_execution_contract_template_ready', $payload['status']);
        $this->assertFalse($payload['execution_allowed']);

        $template = $payload['codex_provider_execution_contract_template'];
        $this->assertIsArray($template);
        $this->assertSame(
            $registryPreflight['provider_adapter_registry_preflight_hash'],
            $template['source_provider_adapter_registry_preflight_hash']
        );
        $this->assertSame(
            $executionGuardPreflight['provider_adapter_execution_guard_preflight_hash'],
            $template['source_provider_adapter_execution_guard_preflight_hash']
        );
    }

    public function test_post_start_release_preflight_contract_id_binds_real_upstream_hash(): void
    {
        $runtime = app(AtlasSelfConstructionReadinessService::class);
        $releasePreflightPayload = $runtime->agentCodexRealInvokerReleasePreflightPreflight();
        $dryRunPayload = $runtime->agentCodexRealInvokerPostStartExternalProcessInvokerDryRunGatePreflight();
        $contractPayload = $runtime->agentCodexRealInvokerPostStartRealInvokerReleasePreflightGateContractTemplate();

        $releasePreflightHash = $releasePreflightPayload['codex_real_invoker_release_preflight_hash'];
        $dryRunHash = $dryRunPayload['codex_real_invoker_post_start_external_process_invoker_dry_run_gate_preflight_hash'];
        $expectedContractId = 'CODEX-REAL-INVOKER-POST-START-REAL-INVOKER-RELEASE-PREFLIGHT-GATE-'.strtoupper(substr(
            ReadinessHash::stable([
                'post_start_external_process_invoker_dry_run_gate_preflight_hash' => $dryRunHash,
                'codex_real_invoker_release_preflight_hash' => $releasePreflightHash,
                'provider' => 'codex',
                'adapter' => 'codex',
            ]),
            0,
            24
        ));
        $changedReleasePreflightHash = $releasePreflightHash === str_repeat('0', 64)
            ? str_repeat('1', 64)
            : str_repeat('0', 64);
        $changedContractId = 'CODEX-REAL-INVOKER-POST-START-REAL-INVOKER-RELEASE-PREFLIGHT-GATE-'.strtoupper(substr(
            ReadinessHash::stable([
                'post_start_external_process_invoker_dry_run_gate_preflight_hash' => $dryRunHash,
                'codex_real_invoker_release_preflight_hash' => $changedReleasePreflightHash,
                'provider' => 'codex',
                'adapter' => 'codex',
            ]),
            0,
            24
        ));

        $this->assertArrayNotHasKey(
            'codex_real_invoker_release_preflight_preflight_hash',
            $releasePreflightPayload
        );
        $this->assertSame(
            $expectedContractId,
            $contractPayload['codex_real_invoker_post_start_real_invoker_release_preflight_gate_contract_template']['contract_id']
        );
        $this->assertNotSame($expectedContractId, $changedContractId);
    }

    public function test_post_start_evidence_producers_do_not_require_their_downstream_bridge_id(): void
    {
        $runtime = app(AtlasSelfConstructionReadinessService::class);
        $receiptEnvelope = $runtime->agentCodexRealInvokerPostStartReceiptContractBuilderContractTemplate();
        $writerEnvelope = $runtime->agentCodexRealInvokerPostStartEvidenceReceiptWriterContractTemplate();
        $bridgeEnvelope = $runtime->agentCodexRealInvokerPostStartEvidenceAcceptanceBridgeContractTemplate();
        $bridgePreflight = $runtime->agentCodexRealInvokerPostStartEvidenceAcceptanceBridgePreflight();
        $bridgeId = 'post_start_evidence_acceptance_bridge_id';
        $receiptInput = (array) data_get($receiptEnvelope, 'codex_real_invoker_post_start_receipt_contract_builder_contract_template.contract.input_contract', []);
        $writerInput = (array) data_get($writerEnvelope, 'codex_real_invoker_post_start_evidence_receipt_writer_contract_template.contract.input_contract', []);
        $writerRequirements = (array) data_get($writerEnvelope, 'codex_real_invoker_post_start_evidence_receipt_writer_contract_template.real_invoker_post_start_evidence_receipt_must', []);
        $bridgeInput = (array) data_get($bridgeEnvelope, 'codex_real_invoker_post_start_evidence_acceptance_bridge_contract_template.contract.input_contract', []);
        $bridgeResult = (array) data_get($bridgeEnvelope, 'codex_real_invoker_post_start_evidence_acceptance_bridge_contract_template.contract.result_contract', []);

        $this->assertSame([
            'receipt_input_bridge_id_count' => 0,
            'writer_input_bridge_id_count' => 0,
            'writer_requires_downstream_bridge' => false,
        ], [
            'receipt_input_bridge_id_count' => count(array_keys($receiptInput, $bridgeId, true)),
            'writer_input_bridge_id_count' => count(array_keys($writerInput, $bridgeId, true)),
            'writer_requires_downstream_bridge' => in_array('require_post_start_evidence_acceptance_bridge_from_receipt_contract', $writerRequirements, true),
        ]);
        $this->assertSame(1, count(array_keys($bridgeInput, $bridgeId, true)));
        $this->assertSame(1, count(array_keys($bridgeResult, $bridgeId, true)));
        $this->assertNotSame('', (string) data_get($bridgePreflight, 'codex_real_invoker_post_start_evidence_acceptance_bridge_preflight.source_codex_real_invoker_post_start_receipt_contract_builder_status'));
        $this->assertNotSame('', (string) data_get($bridgePreflight, 'codex_real_invoker_post_start_evidence_acceptance_bridge_preflight.source_codex_real_invoker_post_start_evidence_receipt_writer_status'));

        foreach ([$receiptEnvelope, $writerEnvelope, $bridgeEnvelope, $bridgePreflight] as $envelope) {
            $this->assertFalse($envelope['execution_allowed']);
            $this->assertFalse($envelope['dispatch_allowed']);
        }
    }
}
