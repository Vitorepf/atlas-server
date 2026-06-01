<?php

declare(strict_types=1);

namespace Tests\Unit\Ai\SoftwareCompanyStewardship\AreaFocusLoop;

use App\Services\Ai\Programming\AtlasForgeProviderTopologyService;
use App\Services\Ai\SoftwareCompanyStewardship\AreaFocusLoop\ForgeAuthority\AwisExecutionGatePort;
use App\Services\Ai\SoftwareCompanyStewardship\AreaFocusLoop\ForgeAuthority\AwisHandoffPackPort;
use App\Services\Ai\SoftwareCompanyStewardship\AreaFocusLoop\ForgeAuthority\ForgeLiveDecideReceiptPort;
use App\Services\Ai\SoftwareCompanyStewardship\AreaFocusLoop\ForgeAuthority\ForgeProviderTopologyPort;
use App\Services\Ai\SoftwareCompanyStewardship\AreaFocusLoop\AreaFocusDeepFindingEngineService;
use App\Services\Ai\SoftwareCompanyStewardship\AreaFocusLoop\ForgeLiveAuthorityBootstrapService;
use Illuminate\Support\Facades\Artisan;
use Tests\TestCase;

/**
 * AP-789. The Fake* ports below are TEST DOUBLES, confined to this test. They
 * stand in for the REAL services only here; runtime authority always comes from
 * the real bound services. partial/blocked is the correct result when real
 * authority is absent, and status=ready is only produced from a real-shaped
 * topology + live decide receipt + AWIS readiness — never synthetic operator JSON.
 */
final class ForgeLiveAuthorityBootstrapServiceTest extends TestCase
{
    private const REAL_OBRA = '11111111-2222-3333-4444-555555555555';

    public function test_blocks_without_obra(): void
    {
        $report = $this->service()->bootstrap(['actor' => 'operator']);

        $this->assertSame(ForgeLiveAuthorityBootstrapService::STATUS_BLOCKED, $report['status']);
        $this->assertContains('forge_obra_required', $report['blockers']);
        $this->assertFalse($report['ready_for_forge_owner_runtime']);
        $this->assertSame([], $report['forge_inputs']);
    }

    public function test_blocks_with_invalid_or_fake_obra(): void
    {
        $report = $this->service()->bootstrap(['forge_obra' => 'fake-obra', 'actor' => 'operator']);
        $this->assertSame(ForgeLiveAuthorityBootstrapService::STATUS_BLOCKED, $report['status']);
        $this->assertContains('forge_obra_invalid', $report['blockers']);

        $report = $this->service()->bootstrap(['forge_obra' => '00000000-0000-0000-0000-000000000000', 'actor' => 'operator']);
        $this->assertContains('forge_obra_invalid', $report['blockers']);
    }

    public function test_blocks_when_obra_not_persisted(): void
    {
        $report = $this->service(topology: ['obra_present' => false, 'status' => 'blocked'])
            ->bootstrap(['forge_obra' => self::REAL_OBRA, 'actor' => 'operator']);

        $this->assertSame(ForgeLiveAuthorityBootstrapService::STATUS_BLOCKED, $report['status']);
        $this->assertContains('forge_obra_not_found', $report['blockers']);
        $this->assertFalse($report['ready_for_forge_owner_runtime']);
    }

    public function test_requires_operator_actor(): void
    {
        $report = $this->service(topology: $this->liveTopology())
            ->bootstrap(['forge_obra' => self::REAL_OBRA]); // no actor

        $this->assertContains('forge_operator_actor_required', $report['blockers']);
        $this->assertArrayNotHasKey('forge_live_decision', $report['forge_inputs']);
    }

    public function test_blocks_when_no_live_decide_receipt(): void
    {
        // Topology runtime-allowed but NOT sourced from a live decide receipt,
        // and the decide port produces no receipt -> honest blocker, not ready.
        $report = $this->service(
            topology: ['obra_present' => true, 'runtime_dispatch_allowed' => true, 'provider_topology_id' => 'topo_x', 'decision_source' => 'static_policy'],
            decideReceipt: [], // no decision_id
        )->bootstrap(['forge_obra' => self::REAL_OBRA, 'actor' => 'operator']);

        $this->assertContains('live_decide_receipt_required', $report['blockers']);
        $this->assertNotSame(ForgeLiveAuthorityBootstrapService::STATUS_READY, $report['status']);
        $this->assertArrayNotHasKey('forge_live_decision', $report['forge_inputs']);
    }

    public function test_awis_blocked_surfaces_as_blocker_not_success(): void
    {
        $report = $this->service(
            topology: $this->liveTopology(),
            gateAllowed: false,
            gateBlockers: ['workspace_not_ready'],
            handoffReady: false,
            handoffBlockers: ['conversation_fusion_blocked'],
        )->bootstrap(['forge_obra' => self::REAL_OBRA, 'actor' => 'operator']);

        // Topology + decision are real, so forge_inputs exist (planned dispatch),
        // but AWIS is not ready -> NOT ready, surfaced as precise blockers.
        $this->assertNotSame(ForgeLiveAuthorityBootstrapService::STATUS_READY, $report['status']);
        $this->assertSame(ForgeLiveAuthorityBootstrapService::STATUS_PARTIAL, $report['status']);
        $this->assertFalse($report['ready_for_forge_owner_runtime']);
        $this->assertContains('awis_execution_gate_blocked', $report['blockers']);
        $this->assertContains('workspace_handoff_pack_blocked', $report['blockers']);
        $this->assertNotSame([], $report['next_actions']);
    }

    public function test_partial_awis_blocked_exposes_actionable_readiness_diagnostics(): void
    {
        $report = $this->service(
            topology: $this->liveTopology(),
            gateAllowed: false,
            gateBlockers: ['workspace_not_certified'],
            handoffReady: false,
            handoffBlockers: ['conversation_fusion_blocked'],
        )->bootstrap([
            'forge_obra' => self::REAL_OBRA,
            'actor' => 'operator',
            'workspace' => '/tmp/test-workspace',
        ]);

        $this->assertArrayHasKey('readiness_checks', $report);
        $this->assertArrayHasKey('readiness_summary', $report);
        $checks = $report['readiness_checks'];
        $this->assertTrue($checks['provider_topology']['ok']);
        $this->assertTrue($checks['live_decide_receipt']['ok']);
        $this->assertFalse($checks['awis_execution_gate']['ok']);
        $this->assertFalse($checks['awis_handoff_pack']['ok']);
        $this->assertSame(['workspace_not_certified'], $checks['awis_execution_gate']['gate_blockers']);
        $this->assertSame(['conversation_fusion_blocked'], $checks['awis_handoff_pack']['handoff_blockers']);
        $this->assertContains('awis_gate:workspace_not_certified', $report['blockers']);
        $this->assertContains('awis_handoff:conversation_fusion_blocked', $report['blockers']);
        $this->assertSame('awis_execution_gate_blocked', $report['primary_blocker']);
        $this->assertStringContainsString('AWIS workspace', (string) $report['primary_next_action']);
        $this->assertStringContainsString('conversation_fusion_blocked', (string) ($report['next_actions'][1] ?? ''));
        $this->assertSame(3, $report['readiness_summary']['passed']);
        $this->assertSame(5, $report['readiness_summary']['total']);
        $this->assertSame(['awis_execution_gate', 'awis_handoff_pack'], $report['readiness_summary']['blocked_pillars']);
    }

    public function test_topology_probe_failure_surfaces_probe_error_in_readiness_checks(): void
    {
        $report = new ForgeLiveAuthorityBootstrapService(
            new ThrowingForgeProviderTopologyPort('topology connection refused'),
            new FakeForgeLiveDecideReceiptPort([]),
            new FakeAwisExecutionGatePort(true),
            new FakeAwisHandoffPackPort(true),
        )->bootstrap(['forge_obra' => self::REAL_OBRA, 'actor' => 'operator']);

        $this->assertSame(ForgeLiveAuthorityBootstrapService::STATUS_BLOCKED, $report['status']);
        $this->assertSame('forge_topology_probe_failed', $report['primary_blocker']);
        $this->assertSame('topology connection refused', $report['readiness_checks']['provider_topology']['probe_error']);
        $this->assertSame('probe_failed', $report['readiness_checks']['provider_topology']['detail']);
        $this->assertStringContainsString('topology probe error', (string) $report['primary_next_action']);
        $this->assertContains('provider_topology', $report['readiness_summary']['blocked_pillars']);
    }

    public function test_decide_probe_failure_surfaces_probe_error_in_readiness_checks(): void
    {
        $report = new ForgeLiveAuthorityBootstrapService(
            new FakeForgeProviderTopologyPort([
                'obra_present' => true,
                'runtime_dispatch_allowed' => true,
                'provider_topology_id' => 'topo_x',
                'decision_source' => 'static_policy',
            ]),
            new ThrowingForgeLiveDecideReceiptPort('decide ledger unavailable'),
            new FakeAwisExecutionGatePort(true),
            new FakeAwisHandoffPackPort(true),
        )->bootstrap(['forge_obra' => self::REAL_OBRA, 'actor' => 'operator']);

        $this->assertSame(ForgeLiveAuthorityBootstrapService::STATUS_BLOCKED, $report['status']);
        $this->assertSame('live_decide_receipt_required', $report['primary_blocker']);
        $this->assertSame('decide_probe_failed', $report['readiness_checks']['live_decide_receipt']['decision_source']);
        $this->assertSame('decide ledger unavailable', $report['readiness_checks']['live_decide_receipt']['probe_error']);
        $this->assertStringContainsString('Atlas Decide receipt probe failed', (string) $report['primary_next_action']);
        $this->assertStringContainsString('decide ledger unavailable', (string) $report['primary_next_action']);
        $this->assertContains('live_decide_receipt', $report['readiness_summary']['blocked_pillars']);
    }

    public function test_handoff_probe_uses_atlas_forge_consumer(): void
    {
        $handoffPort = new FakeAwisHandoffPackPort(true);
        $report = $this->service(
            topology: $this->liveTopology(),
            gateAllowed: true,
            handoffPort: $handoffPort,
        )->bootstrap(['forge_obra' => self::REAL_OBRA, 'actor' => 'operator']);

        $this->assertSame('atlas_forge', $handoffPort->lastConsumer());
        $this->assertSame('atlas_forge', $report['readiness_checks']['awis_handoff_pack']['handoff_consumer']);
        $this->assertSame(ForgeLiveAuthorityBootstrapService::STATUS_READY, $report['status']);
    }

    public function test_handoff_blocked_surfaces_missing_artifacts_when_blockers_absent(): void
    {
        $handoffPort = FakeAwisHandoffPackPort::blockedWithMissingArtifacts([
            'execution_plan',
            'workspace_runbook',
        ]);
        $report = $this->service(
            topology: $this->liveTopology(),
            gateAllowed: true,
            handoffPort: $handoffPort,
        )->bootstrap([
            'forge_obra' => self::REAL_OBRA,
            'actor' => 'operator',
            'workspace' => '/tmp/test-workspace',
        ]);

        $this->assertSame(ForgeLiveAuthorityBootstrapService::STATUS_PARTIAL, $report['status']);
        $this->assertFalse($report['ready_for_forge_owner_runtime']);
        $this->assertSame('workspace_handoff_pack_blocked', $report['primary_blocker']);
        $this->assertContains('awis_handoff:missing_execution_plan', $report['blockers']);
        $this->assertContains('awis_handoff:missing_workspace_runbook', $report['blockers']);
        $this->assertSame(['execution_plan', 'workspace_runbook'], $report['readiness_checks']['awis_handoff_pack']['missing_artifacts']);
        $this->assertStringContainsString('execution_plan', (string) $report['primary_next_action']);
        $this->assertStringContainsString('atlas_forge consumer', (string) $report['primary_next_action']);
    }

    public function test_empty_input_atlas_decide_provider_lane_routing_readiness_signal_returns_bounded_default(): void
    {
        $signal = $this->service()->atlasDecideProviderLaneRoutingReadinessSignal([]);

        $this->assertSame(
            ForgeLiveAuthorityBootstrapService::ATLAS_DECIDE_PROVIDER_LANE_ROUTING_READINESS_SIGNAL_SCHEMA,
            $signal['schema_version'],
        );
        $this->assertSame(
            ForgeLiveAuthorityBootstrapService::ATLAS_DECIDE_PROVIDER_LANE_ROUTING_READINESS_SIGNAL_ID,
            $signal['signal_id'],
        );
        $this->assertSame(
            ForgeLiveAuthorityBootstrapService::ATLAS_DECIDE_PROVIDER_LANE_ROUTING_READINESS_SIGNAL_ID,
            $signal['outputs']['signal_id'],
        );
        $this->assertSame('AP-789', $signal['ap_contract']);
        $this->assertSame('aaeos_atlas_decide_provider_lane_routing_readiness', $signal['finding_id']);
        $this->assertSame(
            'docs/engineering-knowledge-base/atlas-agentic-engineering-os-runbook.md',
            $signal['runbook_canonical'],
        );
        $this->assertSame('docs/ap/AP-804-lane-provider-routing-contract.md', $signal['ap804_canonical']);
        $this->assertSame('agentic_engineering_os', $signal['area_id']);
        $this->assertSame('dev_forge', $signal['focus']);
        $this->assertSame('', $signal['inputs']['forge_obra']);
        $this->assertSame(AtlasForgeProviderTopologyService::ROLE_PRIMARY_BUILDER, $signal['inputs']['forge_role']);
        $this->assertFalse($signal['outputs']['ready']);
        $this->assertSame('deferred', $signal['outputs']['provider_lane_plan_state']);
        $this->assertSame('unwired', $signal['outputs']['routing_source']);
        $this->assertSame(
            ForgeLiveAuthorityBootstrapService::ATLAS_DECIDE_PROVIDER_LANE_ROUTING_UNWIRED_BLOCKER,
            $signal['outputs']['blocker'],
        );
        $this->assertFalse($signal['outputs']['provider_router_invoked']);
        $this->assertFalse($signal['claim_policy']['provider_router_invoked']);
        $this->assertTrue($signal['claim_policy']['never_invokes_provider_driver']);
        $this->assertTrue($signal['claim_policy']['informational_signal_only']);
    }

    public function test_atlas_decide_provider_lane_routing_readiness_signal_callable_without_explicit_input(): void
    {
        $signal = $this->service()->atlasDecideProviderLaneRoutingReadinessSignal();

        $this->assertSame(
            ForgeLiveAuthorityBootstrapService::ATLAS_DECIDE_PROVIDER_LANE_ROUTING_READINESS_SIGNAL_ID,
            $signal['signal_id'],
        );
        $this->assertSame(
            ForgeLiveAuthorityBootstrapService::ATLAS_DECIDE_PROVIDER_LANE_ROUTING_UNWIRED_BLOCKER,
            $signal['outputs']['blocker'],
        );
        $this->assertFalse($signal['outputs']['ready']);
        $this->assertSame('unwired', $signal['outputs']['routing_source']);
    }

    public function test_atlas_decide_provider_lane_routing_readiness_signal_never_invokes_forge_ports(): void
    {
        $service = new ForgeLiveAuthorityBootstrapService(
            new ThrowingForgeProviderTopologyPort('must not probe topology for routing readiness'),
            new ThrowingForgeLiveDecideReceiptPort('must not probe decide for routing readiness'),
            new ThrowingAwisExecutionGatePort('must not probe awis gate for routing readiness'),
            new ThrowingAwisHandoffPackPort('must not probe handoff for routing readiness'),
        );

        $signal = $service->atlasDecideProviderLaneRoutingReadinessSignal([
            'forge_obra' => self::REAL_OBRA,
            'forge_operator_actor' => 'operator',
        ]);

        $this->assertSame(
            ForgeLiveAuthorityBootstrapService::ATLAS_DECIDE_PROVIDER_LANE_ROUTING_READINESS_SIGNAL_ID,
            $signal['signal_id'],
        );
        $this->assertSame('unwired', $signal['outputs']['routing_source']);
        $this->assertFalse($signal['outputs']['provider_router_invoked']);
    }

    public function test_ready_only_from_real_topology_decision_and_awis(): void
    {
        $report = $this->service(
            topology: $this->liveTopology(),
            gateAllowed: true,
            handoffReady: true,
        )->bootstrap(['forge_obra' => self::REAL_OBRA, 'actor' => 'operator']);

        $this->assertSame(ForgeLiveAuthorityBootstrapService::STATUS_READY, $report['status']);
        $this->assertTrue($report['ready_for_forge_owner_runtime']);
        $this->assertArrayHasKey('forge_live_topology', $report['forge_inputs']);
        $this->assertArrayHasKey('forge_live_decision', $report['forge_inputs']);
        $this->assertSame('live', $report['forge_inputs']['forge_live_topology']['status']);
        $this->assertSame('rcpt_live_x', $report['forge_inputs']['forge_live_decision']['decision_receipt_id']);
        $this->assertSame('operator', $report['forge_inputs']['forge_live_decision']['operator_actor']);
        $this->assertContains('atlas_decide_receipt:rcpt_live_x', $report['evidence_refs']);

        // Anti-fake guarantees.
        $this->assertFalse($report['provider_router_used']);
        $this->assertFalse($report['claim_policy']['ready_from_synthetic_shape']);
        $this->assertFalse($report['claim_policy']['mocks_or_test_doubles_in_runtime']);
        $this->assertFalse($report['claim_policy']['simulates_decision_receipt']);

        $this->assertNull($report['primary_blocker']);
        $this->assertTrue($report['readiness_checks']['provider_topology']['ok']);
        $this->assertTrue($report['readiness_checks']['live_decide_receipt']['ok']);
        $this->assertTrue($report['readiness_checks']['awis_execution_gate']['ok']);
        $this->assertTrue($report['readiness_checks']['awis_handoff_pack']['ok']);
        $this->assertSame(5, $report['readiness_summary']['passed']);
        $this->assertSame(5, $report['readiness_summary']['total']);
        $this->assertSame([], $report['readiness_summary']['blocked_pillars']);
    }

    public function test_cli_bootstrap_forge_authority_injects_real_live_fields(): void
    {
        // Confined test doubles for the AP-789 ports; runtime uses the real bound
        // services. They return REAL-shaped live authority so the CLI injection wiring
        // is proven end-to-end.
        $this->app->instance(ForgeProviderTopologyPort::class, new FakeForgeProviderTopologyPort($this->liveTopology()));
        $this->app->instance(ForgeLiveDecideReceiptPort::class, new FakeForgeLiveDecideReceiptPort(['decision_id' => 'rcpt_live_x']));
        $this->app->instance(AwisExecutionGatePort::class, new FakeAwisExecutionGatePort(true));
        $this->app->instance(AwisHandoffPackPort::class, new FakeAwisHandoffPackPort(true));
        $this->mock(AreaFocusDeepFindingEngineService::class, function ($mock): void {
            $mock->shouldReceive('scan')->andReturn(['findings' => [], 'status' => 'ready']);
        });

        Artisan::call('atlas:software-company-stewardship:autonomous-evolution-session', [
            '--area' => 'agentic_engineering_os',
            '--cycles' => 1,
            '--bootstrap-forge-authority' => true,
            '--forge-obra' => self::REAL_OBRA,
            '--forge-operator-actor' => 'vitor',
            '--json' => true,
        ]);

        $payload = json_decode(Artisan::output(), true);
        $this->assertIsArray($payload);
        $boot = $payload['forge_authority_bootstrap'] ?? [];
        $this->assertSame('ready', $boot['status'] ?? null);
        $this->assertTrue($boot['forge_live_topology_injected'] ?? false);
        $this->assertTrue($boot['forge_live_decision_injected'] ?? false);
        $this->assertTrue($boot['authority_is_real_or_blocked'] ?? false);
        $this->assertFalse($boot['provider_router_used'] ?? true);
        // The injected REAL authority reaches the AP-787/AP-788 forge authority surface.
        $this->assertTrue($payload['forge_authority']['live_topology_live'] ?? false);
        $this->assertTrue($payload['forge_authority']['live_decision_supplied'] ?? false);
    }

    /**
     * @param  array<string,mixed>  $topology
     * @param  array<string,mixed>  $decideReceipt
     * @param  list<string>  $gateBlockers
     * @param  list<string>  $handoffBlockers
     */
    private function service(
        array $topology = [],
        ?array $decideReceipt = null,
        bool $gateAllowed = false,
        array $gateBlockers = [],
        bool $handoffReady = false,
        array $handoffBlockers = [],
        ?FakeAwisHandoffPackPort $handoffPort = null,
    ): ForgeLiveAuthorityBootstrapService {
        return new ForgeLiveAuthorityBootstrapService(
            new FakeForgeProviderTopologyPort($topology),
            new FakeForgeLiveDecideReceiptPort($decideReceipt ?? ['decision_id' => 'rcpt_fallback']),
            new FakeAwisExecutionGatePort($gateAllowed, $gateBlockers),
            $handoffPort ?? new FakeAwisHandoffPackPort($handoffReady, $handoffBlockers),
        );
    }

    /**
     * @return array<string,mixed>
     */
    private function liveTopology(): array
    {
        return [
            'obra_present' => true,
            'runtime_dispatch_allowed' => true,
            'provider_topology_id' => 'topo_live_x',
            'decision_source' => 'live_atlas_decide',
            'decision_receipt_id' => 'rcpt_live_x',
            'decision_receipt_hash' => 'sha256:live_x',
            'status' => 'selected',
        ];
    }
}

final class FakeForgeProviderTopologyPort implements ForgeProviderTopologyPort
{
    /** @param array<string,mixed> $topology */
    public function __construct(private array $topology) {}

    public function topology(array $options = []): array
    {
        return $this->topology;
    }
}

final class ThrowingForgeProviderTopologyPort implements ForgeProviderTopologyPort
{
    public function __construct(private string $message) {}

    public function topology(array $options = []): array
    {
        throw new \RuntimeException($this->message);
    }
}

final class FakeForgeLiveDecideReceiptPort implements ForgeLiveDecideReceiptPort
{
    /** @param array<string,mixed> $receipt */
    public function __construct(private array $receipt) {}

    public function receiptForTrace(array $options, string $selectedProvider, ?string $model = null): array
    {
        return $this->receipt;
    }
}

final class ThrowingForgeLiveDecideReceiptPort implements ForgeLiveDecideReceiptPort
{
    public function __construct(private string $message) {}

    public function receiptForTrace(array $options, string $selectedProvider, ?string $model = null): array
    {
        throw new \RuntimeException($this->message);
    }
}

final class ThrowingAwisExecutionGatePort implements AwisExecutionGatePort
{
    public function __construct(private string $message) {}

    public function gate(?string $workspace = null, string $mode = 'conversation', string $task = '', array $conversationTexts = []): array
    {
        throw new \RuntimeException($this->message);
    }
}

final class ThrowingAwisHandoffPackPort implements AwisHandoffPackPort
{
    public function __construct(private string $message) {}

    public function build(?string $workspace = null, string $task = '', string $consumer = 'atlas_dev', array $threadIds = []): array
    {
        throw new \RuntimeException($this->message);
    }
}

final class FakeAwisExecutionGatePort implements AwisExecutionGatePort
{
    /** @param list<string> $blockers */
    public function __construct(private bool $allowed, private array $blockers = []) {}

    public function gate(?string $workspace = null, string $mode = 'conversation', string $task = '', array $conversationTexts = []): array
    {
        return ['allowed' => $this->allowed, 'blockers' => $this->blockers];
    }
}

final class FakeAwisHandoffPackPort implements AwisHandoffPackPort
{
    /** @param list<string> $blockers */
    public function __construct(
        private bool $ready,
        private array $blockers = [],
        private ?array $handoffPayload = null,
    ) {}

    /**
     * @param  list<string>  $missingArtifacts
     */
    public static function blockedWithMissingArtifacts(array $missingArtifacts): self
    {
        return new self(
            ready: false,
            blockers: [],
            handoffPayload: [
                'schema_version' => 'atlas.workspace_handoff_pack.v1',
                'status' => 'blocked',
                'consumer' => 'atlas_forge',
                'missing_artifacts' => $missingArtifacts,
                'blockers' => [],
            ],
        );
    }

    private ?string $consumer = null;

    public function lastConsumer(): ?string
    {
        return $this->consumer;
    }

    public function build(?string $workspace = null, string $task = '', string $consumer = 'atlas_dev', array $threadIds = []): array
    {
        $this->consumer = $consumer;

        if ($this->handoffPayload !== null) {
            return $this->handoffPayload;
        }

        return [
            'status' => $this->ready ? 'ready' : 'blocked',
            'ready' => $this->ready,
            'consumer' => $consumer,
            'blockers' => $this->ready ? [] : $this->blockers,
        ];
    }
}
