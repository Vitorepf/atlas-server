<?php

namespace Tests\Feature\Ai;

use App\Models\AtlasSelfConstructionAgentDispatchReceipt;
use App\Models\AtlasSelfConstructionAgentWakeupItem;
use App\Services\Ai\SelfConstruction\AgentAutomaticDispatchSchedulerOneShotTickGuardedRuntimeInvoker;
use App\Services\Ai\SelfConstruction\AgentAutomaticDispatchSchedulerOneShotTickReleaseReceiptPersistenceWriter;
use App\Services\Ai\SelfConstruction\AtlasSelfConstructionReadinessService;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Schema;
use InvalidArgumentException;
use Tests\TestCase;

class AtlasAiSelfConstructionAgentAutomaticDispatchSchedulerOneShotTickGuardedRuntimeInvokerTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        $this->dropRuntimeTables();

        (require database_path('migrations/2026_05_05_020000_create_atlas_ledger_events_table.php'))->up();
        (require database_path('migrations/2026_05_12_010000_create_atlas_self_construction_agent_control_plane_tables.php'))->up();

        AtlasSelfConstructionAgentWakeupItem::query()->create([
            'wakeup_key' => 'WAKEUP-001',
            'packet_id' => 'AP-001',
            'actor' => 'codex-a',
            'provider' => 'codex',
            'reason' => 'resume_provider_session',
            'priority' => 'high',
            'status' => 'queued',
            'scheduled_for' => CarbonImmutable::now()->subMinute(),
            'payload' => ['source' => 'test'],
        ]);
    }

    protected function tearDown(): void
    {
        $this->dropRuntimeTables();

        parent::tearDown();
    }

    public function test_invoker_calls_writer_once_and_returns_no_provider_boundary(): void
    {
        $context = $this->readyReleaseContext();

        $result = app(AgentAutomaticDispatchSchedulerOneShotTickGuardedRuntimeInvoker::class)
            ->invokeSignedOneShotSchedulerTick($this->validInput($context));

        $this->assertSame('guarded_runtime_invocation_completed', $result['status']);
        $this->assertTrue($result['invoked_writer']);
        $this->assertSame(1, $result['writer_invocation_count']);
        $this->assertSame('signed_pending_dispatch_written', data_get($result, 'writer_result.status'));
        $this->assertTrue($result['created']);
        $this->assertTrue($result['wakeup_claimed']);
        $this->assertTrue($result['dispatch_receipt_written']);
        $this->assertFalse($result['dispatch_receipt_use_allowed']);
        $this->assertFalse($result['mark_dispatch_receipt_used_allowed']);
        $this->assertFalse($result['provider_start_allowed']);
        $this->assertFalse($result['adapter_invocation_allowed']);
        $this->assertFalse($result['token_spend_allowed']);
        $this->assertFalse($result['self_programming_allowed']);
        $this->assertSame('activate_signed_one_shot_scheduler_tick_dispatch_receipt_use_release_contract', $result['next_required_slice']);

        $this->assertDatabaseHas('atlas_self_construction_agent_wakeup_items', [
            'wakeup_key' => 'WAKEUP-001',
            'status' => 'claimed',
        ]);
        $this->assertDatabaseHas('atlas_self_construction_agent_dispatch_receipts', [
            'receipt_key' => 'SCHEDULER-DISPATCH-001',
            'decision' => 'approve_dispatch_once',
            'status' => 'signed_pending_dispatch',
            'receipt_hash' => str_repeat('d', 64),
        ]);
        $this->assertNull(AtlasSelfConstructionAgentDispatchReceipt::query()
            ->where('receipt_key', 'SCHEDULER-DISPATCH-001')
            ->value('used_at'));
        $this->assertDatabaseCount('atlas_self_construction_agent_dispatch_receipts', 2);
        $this->assertDatabaseCount('atlas_ledger_events', 2);
    }

    public function test_invoker_is_idempotent_on_retry_without_second_dispatch_receipt(): void
    {
        $context = $this->readyReleaseContext();
        $invoker = app(AgentAutomaticDispatchSchedulerOneShotTickGuardedRuntimeInvoker::class);

        $first = $invoker->invokeSignedOneShotSchedulerTick($this->validInput($context));
        $second = $invoker->invokeSignedOneShotSchedulerTick($this->validInput($context));

        $this->assertTrue($first['created']);
        $this->assertFalse($second['created']);
        $this->assertSame(1, $second['writer_invocation_count']);
        $this->assertSame($first['receipt_id'], $second['receipt_id']);
        $this->assertFalse($second['wakeup_claimed']);
        $this->assertFalse($second['provider_start_allowed']);
        $this->assertDatabaseCount('atlas_self_construction_agent_dispatch_receipts', 2);
        $this->assertDatabaseCount('atlas_ledger_events', 2);
    }

    public function test_invoker_rejects_missing_signature_without_calling_writer(): void
    {
        $context = $this->readyReleaseContext();

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('missing_signed_by');

        app(AgentAutomaticDispatchSchedulerOneShotTickGuardedRuntimeInvoker::class)
            ->invokeSignedOneShotSchedulerTick(array_merge($this->validInput($context), [
                'signed_by' => '',
            ]));
    }

    public function test_invoker_rejects_invalid_hash_without_calling_writer(): void
    {
        $context = $this->readyReleaseContext();

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('invalid_receipt_hash');

        app(AgentAutomaticDispatchSchedulerOneShotTickGuardedRuntimeInvoker::class)
            ->invokeSignedOneShotSchedulerTick(array_merge($this->validInput($context), [
                'receipt_hash' => 'not-a-hash',
            ]));
    }

    public function test_invoker_does_not_mark_dispatch_receipt_used_or_start_provider(): void
    {
        $context = $this->readyReleaseContext();

        $result = app(AgentAutomaticDispatchSchedulerOneShotTickGuardedRuntimeInvoker::class)
            ->invokeSignedOneShotSchedulerTick($this->validInput($context));

        $receipt = AtlasSelfConstructionAgentDispatchReceipt::query()
            ->where('receipt_hash', $result['receipt_hash'])
            ->firstOrFail();

        $this->assertNull($receipt->used_at);
        $this->assertSame('signed_pending_dispatch', $receipt->status);
        $this->assertFalse($result['provider_start_allowed']);
        $this->assertFalse($result['adapter_invocation_allowed']);
        $this->assertFalse($result['token_spend_allowed']);
        $this->assertFalse($result['self_programming_allowed']);
    }

    /**
     * @return array<string,mixed>
     */
    private function readyReleaseContext(): array
    {
        $readiness = app(AtlasSelfConstructionReadinessService::class);
        $dryRunPayload = $readiness->agentAutomaticDispatchSchedulerDryRunTick([
            'workspace' => null,
            'target' => null,
            'actor' => null,
            'session' => null,
            'packet' => null,
        ]);
        $dispatchEnvelopePreview = (array) data_get($dryRunPayload, 'agent_automatic_dispatch_scheduler_dry_run_tick.dispatch_envelope_preview', []);
        $dispatchEnvelopeHash = $this->stableHash($dispatchEnvelopePreview);

        app(AgentAutomaticDispatchSchedulerOneShotTickReleaseReceiptPersistenceWriter::class)
            ->persistSignedReleaseReceipt([
                'receipt_key' => 'SCHEDULER-RELEASE-001',
                'packet_id' => 'AP-001',
                'provider' => 'codex',
                'provider_role' => 'implementation',
                'decision' => 'approve_scheduler_claim_and_receipt_once',
                'signed_by' => 'vitor',
                'signed_at' => CarbonImmutable::now()->toIso8601String(),
                'expires_at' => CarbonImmutable::now()->addHour()->toIso8601String(),
                'selected_wakeup_key' => 'WAKEUP-001',
                'dispatch_envelope_hash' => $dispatchEnvelopeHash,
                'scope_hash' => str_repeat('2', 64),
                'source_release_template_hash' => str_repeat('3', 64),
                'source_release_receipt_draft_hash' => str_repeat('4', 64),
                'source_validation_preflight_hash' => str_repeat('5', 64),
                'source_persistence_contract_hash' => str_repeat('6', 64),
                'source_persistence_writer_preflight_hash' => str_repeat('7', 64),
                'receipt_hash' => str_repeat('a', 64),
                'payload' => [
                    'source' => 'test',
                    'provider_start_allowed_by_writer' => false,
                    'dispatch_receipt_write_allowed_by_writer' => false,
                    'self_programming_allowed_by_writer' => false,
                ],
            ]);

        $options = [
            'workspace' => null,
            'target' => null,
            'actor' => null,
            'session' => null,
            'packet' => null,
            'receipt_hash' => str_repeat('a', 64),
        ];
        $releasePreflightPayload = $readiness->agentAutomaticDispatchSchedulerOneShotTickMutatingWriterReleasePreflight($options);
        $contractPayload = $readiness->agentAutomaticDispatchSchedulerOneShotTickMutatingWriterContract($options);
        $preflightPayload = $readiness->agentAutomaticDispatchSchedulerOneShotTickMutatingWriterPreflight($options);

        $this->assertSame('one_shot_tick_mutating_writer_release_preflight_ready', data_get($releasePreflightPayload, 'status'));
        $this->assertSame('one_shot_tick_mutating_writer_contract_ready', data_get($contractPayload, 'status'));
        $this->assertSame('one_shot_tick_mutating_writer_preflight_ready', data_get($preflightPayload, 'status'));

        return [
            'release_receipt_hash' => str_repeat('a', 64),
            'dispatch_envelope_hash' => $dispatchEnvelopeHash,
            'source_release_preflight_hash' => data_get($releasePreflightPayload, 'agent_automatic_dispatch_scheduler_one_shot_tick_mutating_writer_release_preflight_hash'),
            'source_mutating_writer_contract_hash' => data_get($contractPayload, 'agent_automatic_dispatch_scheduler_one_shot_tick_mutating_writer_contract_hash'),
            'source_mutating_writer_preflight_hash' => data_get($preflightPayload, 'agent_automatic_dispatch_scheduler_one_shot_tick_mutating_writer_preflight_hash'),
        ];
    }

    /**
     * @param  array<string,mixed>  $context
     * @return array<string,mixed>
     */
    private function validInput(array $context): array
    {
        return [
            'receipt_key' => 'SCHEDULER-DISPATCH-001',
            'packet_id' => 'AP-001',
            'provider' => 'codex',
            'provider_role' => 'implementation',
            'signed_by' => 'vitor',
            'signed_at' => CarbonImmutable::now()->toIso8601String(),
            'expires_at' => CarbonImmutable::now()->addHour()->toIso8601String(),
            'release_receipt_hash' => $context['release_receipt_hash'],
            'selected_wakeup_key' => 'WAKEUP-001',
            'dispatch_envelope_hash' => $context['dispatch_envelope_hash'],
            'source_release_preflight_hash' => $context['source_release_preflight_hash'],
            'source_mutating_writer_contract_hash' => $context['source_mutating_writer_contract_hash'],
            'source_mutating_writer_preflight_hash' => $context['source_mutating_writer_preflight_hash'],
            'receipt_hash' => str_repeat('d', 64),
            'payload' => [
                'source' => 'test',
                'dispatch_receipt_use_allowed_by_invoker' => false,
                'provider_start_allowed_by_invoker' => false,
                'adapter_invocation_allowed_by_invoker' => false,
                'token_spend_allowed_by_invoker' => false,
                'self_programming_allowed_by_invoker' => false,
            ],
        ];
    }

    /**
     * @param  array<string,mixed>  $payload
     */
    private function stableHash(array $payload): string
    {
        ksort($payload);

        return hash('sha256', (string) json_encode($payload, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));
    }

    private function dropRuntimeTables(): void
    {
        Schema::dropIfExists('atlas_self_construction_agent_dispatch_receipts');
        Schema::dropIfExists('atlas_self_construction_agent_wakeup_items');
        Schema::dropIfExists('atlas_self_construction_agent_work_products');
        Schema::dropIfExists('atlas_self_construction_agent_cost_events');
        Schema::dropIfExists('atlas_self_construction_agent_heartbeats');
        Schema::dropIfExists('atlas_self_construction_agent_runs');
        Schema::dropIfExists('atlas_ledger_events');
    }
}
