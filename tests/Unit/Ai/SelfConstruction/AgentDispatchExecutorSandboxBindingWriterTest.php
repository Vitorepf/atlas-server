<?php

declare(strict_types=1);

namespace Tests\Unit\Ai\SelfConstruction;

use App\Services\Ai\SelfConstruction\AgentDispatchExecutorSandboxBindingWriter;
use PHPUnit\Framework\TestCase;

final class AgentDispatchExecutorSandboxBindingWriterTest extends TestCase
{
    private function writer(): AgentDispatchExecutorSandboxBindingWriter
    {
        return new AgentDispatchExecutorSandboxBindingWriter();
    }

    private function validInput(array $overrides = []): array
    {
        return array_merge([
            'provider_id' => 'codex-1',
            'workspace_id' => 'workspace-1',
            'lease_id' => 'lease-abc-123',
            'rollback_path' => '/tmp/rollback-codex-1',
            'allowed_files' => ['app/Foo.php', 'tests/FooTest.php'],
        ], $overrides);
    }

    // ── AC: bindings without allowed_files, lease_id or rollback_path are rejected ──

    public function test_binding_without_allowed_files_is_rejected(): void
    {
        $result = $this->writer()->bind($this->validInput(['allowed_files' => []]));

        $this->assertSame(AgentDispatchExecutorSandboxBindingWriter::STATUS_REJECTED, $result['status']);
        $this->assertContains('missing_allowed_files', $result['blockers']);
        $this->assertNull($result['binding']);
    }

    public function test_binding_without_lease_id_is_rejected(): void
    {
        $result = $this->writer()->bind($this->validInput(['lease_id' => '']));

        $this->assertSame(AgentDispatchExecutorSandboxBindingWriter::STATUS_REJECTED, $result['status']);
        $this->assertContains('missing_lease_id', $result['blockers']);
    }

    public function test_binding_without_rollback_path_is_rejected(): void
    {
        $result = $this->writer()->bind($this->validInput(['rollback_path' => '']));

        $this->assertSame(AgentDispatchExecutorSandboxBindingWriter::STATUS_REJECTED, $result['status']);
        $this->assertContains('missing_rollback_path', $result['blockers']);
    }

    public function test_binding_without_provider_id_is_rejected(): void
    {
        $result = $this->writer()->bind($this->validInput(['provider_id' => '']));

        $this->assertSame(AgentDispatchExecutorSandboxBindingWriter::STATUS_REJECTED, $result['status']);
        $this->assertContains('missing_provider_id', $result['blockers']);
    }

    public function test_binding_without_workspace_id_is_rejected(): void
    {
        $result = $this->writer()->bind($this->validInput(['workspace_id' => '']));

        $this->assertSame(AgentDispatchExecutorSandboxBindingWriter::STATUS_REJECTED, $result['status']);
        $this->assertContains('missing_workspace_id', $result['blockers']);
    }

    public function test_rejection_blocker_codes_are_stable(): void
    {
        $a = $this->writer()->bind($this->validInput(['lease_id' => '', 'rollback_path' => '']));
        $b = $this->writer()->bind($this->validInput(['lease_id' => '', 'rollback_path' => '']));

        $this->assertSame($a['blockers'], $b['blockers']);
    }

    // ── AC: successful bindings persist scope_hash, provider_id and workspace_id ──

    public function test_successful_binding_persists_scope_hash(): void
    {
        $result = $this->writer()->bind($this->validInput());

        $this->assertSame(AgentDispatchExecutorSandboxBindingWriter::STATUS_BOUND, $result['status']);
        $this->assertNotEmpty($result['binding']['scope_hash']);
        $this->assertSame(64, strlen($result['binding']['scope_hash']));
    }

    public function test_successful_binding_persists_provider_id(): void
    {
        $result = $this->writer()->bind($this->validInput());

        $this->assertSame('codex-1', $result['binding']['provider_id']);
    }

    public function test_successful_binding_persists_workspace_id(): void
    {
        $result = $this->writer()->bind($this->validInput());

        $this->assertSame('workspace-1', $result['binding']['workspace_id']);
    }

    public function test_successful_binding_does_not_include_raw_secrets(): void
    {
        $result = $this->writer()->bind($this->validInput([
            'api_key' => 'secret-key-12345',
            'provider_token' => 'secret-token',
        ]));

        $binding = $result['binding'];
        $this->assertArrayNotHasKey('api_key', $binding);
        $this->assertArrayNotHasKey('provider_token', $binding);
        $this->assertArrayNotHasKey('secret', $binding);
        $this->assertArrayNotHasKey('token', $binding);
    }

    public function test_scope_hash_is_deterministic_for_same_allowed_files(): void
    {
        $a = $this->writer()->bind($this->validInput());
        $b = $this->writer()->bind($this->validInput());

        $this->assertSame($a['binding']['scope_hash'], $b['binding']['scope_hash']);
    }

    public function test_scope_hash_changes_when_allowed_files_change(): void
    {
        $a = $this->writer()->bind($this->validInput());
        $b = $this->writer()->bind($this->validInput(['allowed_files' => ['app/Different.php']]));

        $this->assertNotSame($a['binding']['scope_hash'], $b['binding']['scope_hash']);
    }

    public function test_scope_hash_invariant_to_file_order(): void
    {
        $a = $this->writer()->bind($this->validInput(['allowed_files' => ['app/A.php', 'app/B.php']]));
        $b = $this->writer()->bind($this->validInput(['allowed_files' => ['app/B.php', 'app/A.php']]));

        $this->assertSame($a['binding']['scope_hash'], $b['binding']['scope_hash']);
    }

    // ── AC: rebinding the same provider/workspace/scope is idempotent ──

    public function test_rebinding_same_provider_workspace_scope_is_idempotent(): void
    {
        $first = $this->writer()->bind($this->validInput());
        $second = $this->writer()->bind($this->validInput());

        $this->assertSame($first['binding']['idempotency_key'], $second['binding']['idempotency_key']);
        $this->assertSame($first['binding']['binding_id'], $second['binding']['binding_id']);
    }

    public function test_is_idempotent_returns_true_for_same_binding(): void
    {
        $first = $this->writer()->bind($this->validInput());
        $existingBinding = $first['binding'];

        $this->assertTrue($this->writer()->isIdempotent($existingBinding, $this->validInput()));
    }

    public function test_is_idempotent_returns_false_for_different_scope(): void
    {
        $first = $this->writer()->bind($this->validInput());
        $existingBinding = $first['binding'];

        $differentInput = $this->validInput(['allowed_files' => ['app/Different.php']]);
        $this->assertFalse($this->writer()->isIdempotent($existingBinding, $differentInput));
    }

    public function test_is_idempotent_returns_false_for_different_provider(): void
    {
        $first = $this->writer()->bind($this->validInput());
        $existingBinding = $first['binding'];

        $differentInput = $this->validInput(['provider_id' => 'codex-2']);
        $this->assertFalse($this->writer()->isIdempotent($existingBinding, $differentInput));
    }

    public function test_is_idempotent_returns_false_for_different_workspace(): void
    {
        $first = $this->writer()->bind($this->validInput());
        $existingBinding = $first['binding'];

        $differentInput = $this->validInput(['workspace_id' => 'workspace-2']);
        $this->assertFalse($this->writer()->isIdempotent($existingBinding, $differentInput));
    }

    public function test_binding_is_deterministic(): void
    {
        $input = $this->validInput();
        $a = $this->writer()->bind($input);
        $b = $this->writer()->bind($input);

        $this->assertSame(json_encode($a), json_encode($b));
    }

    public function test_schema_present(): void
    {
        $result = $this->writer()->bind($this->validInput());

        $this->assertSame(AgentDispatchExecutorSandboxBindingWriter::SCHEMA, $result['schema']);
    }
}
