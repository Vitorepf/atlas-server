<?php

declare(strict_types=1);

namespace Tests\Unit\Ai\SoftwareCompanyStewardship\AreaFocusLoop;

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
     */
    private function service(
        array $topology = [],
        ?array $decideReceipt = null,
        bool $gateAllowed = false,
        array $gateBlockers = [],
        bool $handoffReady = false,
    ): ForgeLiveAuthorityBootstrapService {
        return new ForgeLiveAuthorityBootstrapService(
            new FakeForgeProviderTopologyPort($topology),
            new FakeForgeLiveDecideReceiptPort($decideReceipt ?? ['decision_id' => 'rcpt_fallback']),
            new FakeAwisExecutionGatePort($gateAllowed, $gateBlockers),
            new FakeAwisHandoffPackPort($handoffReady),
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

final class FakeForgeLiveDecideReceiptPort implements ForgeLiveDecideReceiptPort
{
    /** @param array<string,mixed> $receipt */
    public function __construct(private array $receipt) {}

    public function receiptForTrace(array $options, string $selectedProvider, ?string $model = null): array
    {
        return $this->receipt;
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
    public function __construct(private bool $ready) {}

    public function build(?string $workspace = null, string $task = '', string $consumer = 'atlas_dev', array $threadIds = []): array
    {
        return ['status' => $this->ready ? 'ready' : 'blocked', 'ready' => $this->ready];
    }
}
