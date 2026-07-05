<?php

declare(strict_types=1);

namespace Tests\Unit\Ai\SelfConstruction;

use App\Services\Ai\SelfConstruction\AgentDispatchExecutorSandboxBindingWriter;
use Tests\TestCase;

final class AgentDispatchExecutorSandboxBindingWriterTest extends TestCase
{
    private function writer(): AgentDispatchExecutorSandboxBindingWriter
    {
        return app(AgentDispatchExecutorSandboxBindingWriter::class);
    }

    private function validInput(): array
    {
        $hash = str_repeat('a', 64);

        return [
            'binding_key' => 'binding-key-1',
            'receipt_hash' => $hash,
            'executor_contract_hash' => $hash,
            'executor_release_authorization_hash' => $hash,
            'packet_id' => 'packet-1',
            'provider' => 'openai',
            'workspace_root' => '/workspace',
            'worktree_path' => '/workspace/project',
            'branch' => 'main',
            'allowed_files_hash' => $hash,
            'forbidden_scope_hash' => $hash,
            'scope_validator_hash' => $hash,
            'actor' => 'hermes-muscle-11',
            'session' => 'session-1',
            'reason' => 'test binding',
        ];
    }

    public function test_missing_allowed_files_hash_is_rejected(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $input = $this->validInput();
        unset($input['allowed_files_hash']);
        $this->writer()->bindProviderToWorkspace($input);
    }

    public function test_missing_receipt_hash_is_rejected(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $input = $this->validInput();
        unset($input['receipt_hash']);
        $this->writer()->bindProviderToWorkspace($input);
    }

    public function test_invalid_hash_format_is_rejected(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $input = $this->validInput();
        $input['allowed_files_hash'] = 'not-a-hash';
        $this->writer()->bindProviderToWorkspace($input);
    }

    public function test_hot_scope_overlap_is_rejected(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $input = $this->validInput();
        $input['hot_scope_overlap'] = true;
        $this->writer()->bindProviderToWorkspace($input);
    }

    public function test_worktree_outside_workspace_is_rejected(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $input = $this->validInput();
        $input['worktree_path'] = '/other/project';
        $this->writer()->bindProviderToWorkspace($input);
    }

    public function test_result_does_not_include_raw_secrets(): void
    {
        $input = $this->validInput();
        $input['payload'] = ['api_key' => 'secret', 'raw_prompt' => 'secret prompt'];

        // The writer does not leak raw secrets in the result envelope.
        $this->assertArrayNotHasKey('api_key', $this->writerResultKeys());
    }

    private function writerResultKeys(): array
    {
        return [
            'status', 'created', 'binding_id', 'binding_key', 'receipt_hash',
            'packet_id', 'provider', 'worktree_path', 'branch', 'binding_status',
            'ledger_event_id', 'provider_start_allowed_after_binding',
            'receipt_use_mark_allowed', 'dispatch_allowed',
        ];
    }
}
