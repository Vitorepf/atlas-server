<?php

namespace Tests\Feature\Ai;

use App\Services\Ai\SelfConstruction\ControlPlane\AgentDispatchExecutorReleaseAuthorizationPersistenceWriter;
use Carbon\CarbonImmutable;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use InvalidArgumentException;
use Tests\TestCase;

class AtlasAiSelfConstructionAgentDispatchExecutorReleaseAuthorizationPersistenceWriterTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        Schema::dropIfExists('atlas_self_construction_agent_dispatch_authorizations');
        Schema::dropIfExists('atlas_ledger_events');

        (require database_path('migrations/2026_05_05_020000_create_atlas_ledger_events_table.php'))->up();
        (require database_path('migrations/2026_05_12_020000_create_atlas_self_construction_agent_dispatch_executor_release_authorizations_table.php'))->up();
    }

    protected function tearDown(): void
    {
        Schema::dropIfExists('atlas_self_construction_agent_dispatch_authorizations');
        Schema::dropIfExists('atlas_ledger_events');

        parent::tearDown();
    }

    public function test_writer_persists_authorization_and_ledger_event_without_dispatching(): void
    {
        $result = app(AgentDispatchExecutorReleaseAuthorizationPersistenceWriter::class)
            ->persistSignedReleaseAuthorization($this->validInput());

        $this->assertTrue($result['created']);
        $this->assertFalse($result['provider_start_allowed']);
        $this->assertFalse($result['dispatch_allowed']);
        $this->assertFalse($result['receipt_use_mark_allowed']);

        $this->assertDatabaseHas('atlas_self_construction_agent_dispatch_authorizations', [
            'authorization_key' => 'AUTH-001',
            'decision' => 'approve_release_once',
            'status' => 'persisted_pending_executor_release',
            'signed_receipt_hash' => str_repeat('a', 64),
        ]);

        $this->assertDatabaseHas('atlas_ledger_events', [
            'event_type' => 'OPERATION_COMPLETED',
            'receipt_id' => 'DISPATCH-EXECUTOR-RELEASE-AUTH-001',
        ]);
    }

    public function test_writer_is_idempotent_for_same_signed_receipt_hash(): void
    {
        $writer = app(AgentDispatchExecutorReleaseAuthorizationPersistenceWriter::class);

        $first = $writer->persistSignedReleaseAuthorization($this->validInput());
        $second = $writer->persistSignedReleaseAuthorization($this->validInput());

        $this->assertTrue($first['created']);
        $this->assertFalse($second['created']);
        $this->assertSame($first['authorization_id'], $second['authorization_id']);
        $this->assertDatabaseCount('atlas_self_construction_agent_dispatch_authorizations', 1);
        $this->assertDatabaseCount('atlas_ledger_events', 1);
    }

    public function test_writer_rejects_duplicate_authorization_key_with_different_receipt_hash(): void
    {
        $writer = app(AgentDispatchExecutorReleaseAuthorizationPersistenceWriter::class);
        $writer->persistSignedReleaseAuthorization($this->validInput());

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('duplicate_authorization_key');

        $writer->persistSignedReleaseAuthorization(array_merge($this->validInput(), [
            'signed_receipt_hash' => str_repeat('b', 64),
        ]));
    }

    public function test_writer_rejects_expired_signed_receipt(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('expired_signed_receipt');

        app(AgentDispatchExecutorReleaseAuthorizationPersistenceWriter::class)
            ->persistSignedReleaseAuthorization(array_merge($this->validInput(), [
                'expires_at' => CarbonImmutable::now()->subMinute()->toIso8601String(),
            ]));
    }

    public function test_writer_rejects_missing_external_signature_validation_report_hash(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('missing_external_signature_validation_report_hash');

        $input = $this->validInput();
        unset($input['external_signature_validation_report_hash']);

        app(AgentDispatchExecutorReleaseAuthorizationPersistenceWriter::class)
            ->persistSignedReleaseAuthorization($input);
    }

    public function test_writer_requires_ledger_table_for_same_transaction_contract(): void
    {
        Schema::dropIfExists('atlas_ledger_events');

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('append_only_ledger_table_missing');

        app(AgentDispatchExecutorReleaseAuthorizationPersistenceWriter::class)
            ->persistSignedReleaseAuthorization($this->validInput());
    }

    public function test_writer_rolls_back_authorization_when_ledger_write_fails(): void
    {
        Schema::dropIfExists('atlas_ledger_events');
        Schema::create('atlas_ledger_events', function (Blueprint $table): void {
            $table->string('event_id', 8)->primary();
        });

        try {
            app(AgentDispatchExecutorReleaseAuthorizationPersistenceWriter::class)
                ->persistSignedReleaseAuthorization($this->validInput());

            $this->fail('Expected ledger write failure.');
        } catch (\Throwable) {
            $this->assertDatabaseCount('atlas_self_construction_agent_dispatch_authorizations', 0);
        }
    }

    /**
     * @return array<string,mixed>
     */
    private function validInput(): array
    {
        return [
            'authorization_key' => 'AUTH-001',
            'receipt_key' => 'RECEIPT-001',
            'authorization_id' => 'DISPATCH-EXECUTOR-RELEASE-AUTH-001',
            'packet_id' => 'AP-001',
            'provider' => 'codex',
            'provider_role' => 'implementation',
            'decision' => 'approve_release_once',
            'signed_by' => 'vitor',
            'signed_at' => CarbonImmutable::now()->toIso8601String(),
            'expires_at' => CarbonImmutable::now()->addHour()->toIso8601String(),
            'signed_receipt_template_hash' => str_repeat('1', 64),
            'signed_receipt_preflight_hash' => str_repeat('2', 64),
            'persistence_template_hash' => str_repeat('3', 64),
            'persistence_preflight_hash' => str_repeat('4', 64),
            'external_signature_validation_report_hash' => str_repeat('5', 64),
            'signed_receipt_hash' => str_repeat('a', 64),
            'payload' => [
                'source' => 'test',
                'provider_start_allowed' => false,
                'dispatch_allowed' => false,
            ],
        ];
    }
}
