<?php

namespace Tests\Feature\Ai;

use App\Models\AtlasSelfConstructionAgentSandboxBinding;
use App\Services\Ai\SelfConstruction\AgentDispatchExecutorSandboxBindingWriter;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use InvalidArgumentException;
use Tests\TestCase;

class AtlasAiSelfConstructionAgentDispatchExecutorSandboxBindingWriterTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        $this->dropTables();

        (require database_path('migrations/2026_05_05_020000_create_atlas_ledger_events_table.php'))->up();
        (require database_path('migrations/2026_05_12_030000_create_atlas_self_construction_agent_sandbox_bindings_table.php'))->up();
    }

    protected function tearDown(): void
    {
        $this->dropTables();

        parent::tearDown();
    }

    public function test_writer_creates_binding_without_starting_provider(): void
    {
        $result = app(AgentDispatchExecutorSandboxBindingWriter::class)
            ->bindProviderToWorkspace($this->validInput());

        $this->assertSame('sandbox_binding_active', $result['status']);
        $this->assertTrue($result['created']);
        $this->assertFalse($result['provider_start_allowed_after_binding']);
        $this->assertFalse($result['receipt_use_mark_allowed']);
        $this->assertFalse($result['dispatch_allowed']);

        $this->assertDatabaseHas('atlas_self_construction_agent_sandbox_bindings', [
            'binding_key' => 'BINDING-001',
            'receipt_hash' => str_repeat('a', 64),
            'packet_id' => 'AP-001',
            'provider' => 'codex',
            'status' => 'active_pending_provider_start',
        ]);

        $this->assertDatabaseHas('atlas_ledger_events', [
            'event_type' => 'OPERATION_COMPLETED',
            'receipt_id' => 'BINDING-001',
        ]);

        $binding = AtlasSelfConstructionAgentSandboxBinding::query()->firstOrFail();

        $this->assertFalse(data_get($binding->payload, 'provider_start_side_effect_performed'));
    }

    public function test_writer_is_idempotent_for_same_receipt_and_workspace(): void
    {
        $writer = app(AgentDispatchExecutorSandboxBindingWriter::class);

        $first = $writer->bindProviderToWorkspace($this->validInput());
        $second = $writer->bindProviderToWorkspace($this->validInput());

        $this->assertTrue($first['created']);
        $this->assertFalse($second['created']);
        $this->assertSame($first['binding_id'], $second['binding_id']);
        $this->assertDatabaseCount('atlas_self_construction_agent_sandbox_bindings', 1);
        $this->assertDatabaseCount('atlas_ledger_events', 1);
    }

    public function test_writer_rejects_workspace_outside_allowed_root(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('worktree_path_outside_workspace_root');

        app(AgentDispatchExecutorSandboxBindingWriter::class)
            ->bindProviderToWorkspace(array_merge($this->validInput(), [
                'worktree_path' => '/tmp/outside-atlas/worktrees/codex-a',
            ]));
    }

    public function test_writer_rejects_provider_mismatch_for_existing_receipt_binding(): void
    {
        $writer = app(AgentDispatchExecutorSandboxBindingWriter::class);
        $writer->bindProviderToWorkspace($this->validInput());

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('active_sandbox_binding_exists_for_receipt');

        $writer->bindProviderToWorkspace(array_merge($this->validInput(), [
            'provider' => 'claude',
        ]));
    }

    public function test_writer_rejects_hot_scope_overlap(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('hot_scope_overlap_detected');

        app(AgentDispatchExecutorSandboxBindingWriter::class)
            ->bindProviderToWorkspace(array_merge($this->validInput(), [
                'hot_scope_overlap' => true,
            ]));
    }

    public function test_writer_requires_ledger_table_for_same_transaction_contract(): void
    {
        Schema::dropIfExists('atlas_ledger_events');

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('append_only_ledger_table_missing');

        app(AgentDispatchExecutorSandboxBindingWriter::class)
            ->bindProviderToWorkspace($this->validInput());
    }

    public function test_writer_rolls_back_binding_when_ledger_write_fails(): void
    {
        Schema::dropIfExists('atlas_ledger_events');
        Schema::create('atlas_ledger_events', function (Blueprint $table): void {
            $table->string('event_id', 8)->primary();
        });

        try {
            app(AgentDispatchExecutorSandboxBindingWriter::class)
                ->bindProviderToWorkspace($this->validInput());

            $this->fail('Expected ledger write failure.');
        } catch (\Throwable) {
            $this->assertDatabaseCount('atlas_self_construction_agent_sandbox_bindings', 0);
        }
    }

    /**
     * @return array<string,mixed>
     */
    private function validInput(): array
    {
        return [
            'binding_key' => 'BINDING-001',
            'receipt_hash' => str_repeat('a', 64),
            'receipt_key' => 'DISPATCH-RECEIPT-001',
            'executor_contract_hash' => str_repeat('b', 64),
            'executor_release_authorization_hash' => str_repeat('c', 64),
            'packet_id' => 'AP-001',
            'provider' => 'codex',
            'provider_role' => 'implementation',
            'workspace_root' => '/Users/vitorepf/develop/Atlas',
            'worktree_path' => '/Users/vitorepf/develop/Atlas/worktrees/codex-a',
            'branch' => 'self-construction/codex-a',
            'allowed_files_hash' => str_repeat('d', 64),
            'forbidden_scope_hash' => str_repeat('e', 64),
            'scope_validator_hash' => str_repeat('f', 64),
            'actor' => 'codex-a',
            'session' => 'session-a',
            'reason' => 'future_provider_start_guard',
        ];
    }

    private function dropTables(): void
    {
        Schema::dropIfExists('atlas_self_construction_agent_sandbox_bindings');
        Schema::dropIfExists('atlas_ledger_events');
    }
}
