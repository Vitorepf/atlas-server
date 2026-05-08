<?php

namespace Tests\Unit\Ai\Voice;

use App\Services\Ai\Voice\AtlasVoiceRealtimeFoundationRegistry;
use Tests\TestCase;

final class AtlasVoiceRealtimeFoundationRegistryTest extends TestCase
{
    public function test_summary_aggregates_voice_foundation_components_without_execution_authority(): void
    {
        $payload = app(AtlasVoiceRealtimeFoundationRegistry::class)->summary();

        $this->assertSame('atlas.voice_realtime.foundation_registry.v1', $payload['schema_version']);
        $this->assertSame('foundation_ready_for_adapter_integration', $payload['status']);
        $this->assertSame('voice_realtime', $payload['surface_id']);
        $this->assertSame('read_only_registry', $payload['mode']);
        $this->assertSame('foundation_status_only_no_runtime_execution', $payload['authority']);
        $this->assertSame(4, $payload['component_count']);
        $this->assertSame('AP-181', data_get($payload, 'components.0.ap'));
        $this->assertSame('atlas.voice_realtime.callback_payload_contract.v1', data_get($payload, 'components.0.schema_version'));
        $this->assertSame('AP-184', data_get($payload, 'components.3.ap'));
        $this->assertContains('ap183_runtime_event_normalizer', $payload['pipeline']);
        $this->assertContains('ap184_kernel_handoff_contract', $payload['pipeline']);
        $this->assertFalse(data_get($payload, 'guardrails.runtime_execution_enabled'));
        $this->assertFalse(data_get($payload, 'guardrails.kernel_execution_enabled'));
        $this->assertFalse(data_get($payload, 'guardrails.ledger_write_enabled'));
        $this->assertFalse(data_get($payload, 'guardrails.decision_receipt_issuance_enabled'));
    }

    public function test_readiness_passes_all_foundation_gates_for_adapter_integration_review(): void
    {
        $payload = app(AtlasVoiceRealtimeFoundationRegistry::class)->readiness();

        $this->assertSame('atlas.voice_realtime.foundation_registry.v1', $payload['schema_version']);
        $this->assertSame('ready', $payload['status']);
        $this->assertSame('adapter_integration_review', $payload['ready_for']);
        $this->assertSame(0, $payload['failed_gate_count']);
        $this->assertSame([], $payload['failed_gates']);
        $this->assertSame('connect_through_authorized_adapter_with_existing_kernel_methods', $payload['next_allowed_step']);

        foreach ($payload['gates'] as $gate) {
            $this->assertTrue($gate['passed'], 'Expected readiness gate '.$gate['id'].' to pass');
            $this->assertSame('integration_blocker', $gate['severity_if_failed']);
        }
    }

    public function test_registry_declares_integration_boundaries_for_main_codex(): void
    {
        $payload = app(AtlasVoiceRealtimeFoundationRegistry::class)->summary();

        $this->assertContains('authorized_voice_adapter', data_get($payload, 'integration_boundaries.may_connect_next'));
        $this->assertContains('existing_atlas_voice_realtime_service_methods', data_get($payload, 'integration_boundaries.may_connect_next'));
        $this->assertContains('provider_direct_execution', data_get($payload, 'integration_boundaries.must_not_connect_here'));
        $this->assertContains('evidence_ledger_write', data_get($payload, 'integration_boundaries.must_not_connect_here'));
        $this->assertContains('policy_override', data_get($payload, 'integration_boundaries.must_not_connect_here'));
    }
}
