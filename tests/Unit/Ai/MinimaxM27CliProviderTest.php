<?php

namespace Tests\Unit\Ai;

use App\Models\AiJob;
use App\Services\Ai\MinimaxM27CliProvider;
use App\Services\Ai\Programming\AtlasMinimaxM27CliRuntimeExecutor;
use Tests\TestCase;

final class CapturingMinimaxM27CliRuntime extends AtlasMinimaxM27CliRuntimeExecutor
{
    /** @var array<string,mixed> */
    public array $lastManifest = [];

    /**
     * @param  array<string,mixed>  $manifest
     * @return array{status:string,text:string,input_tokens:int,output_tokens:int,provider_called:bool,duration_ms:int,error:string}
     */
    public function execute(array $manifest): array
    {
        $this->lastManifest = $manifest;

        return [
            'status' => self::STATUS_COMPLETED,
            'text' => 'provider response',
            'input_tokens' => 11,
            'output_tokens' => 7,
            'provider_called' => true,
            'duration_ms' => 123,
            'error' => '',
        ];
    }
}

class MinimaxM27CliProviderTest extends TestCase
{
    public function test_run_builds_scope_contract_with_normalized_file_lists(): void
    {
        $runtime = new CapturingMinimaxM27CliRuntime();
        $provider = new MinimaxM27CliProvider($runtime);
        $job = new AiJob();
        $job->forceFill([
            'trace_id' => 'trace-1',
            'input_text' => 'Implement the bounded change.',
            'timeout_seconds' => 45,
            'payload' => [
                'decision_receipt' => [
                    'decision_id' => 'decision-1',
                    'receipt_hash' => 'receipt-hash-1',
                ],
                'tool_permissions' => [
                    'workspace' => base_path(),
                    'allowed_files' => [' app/Foo.php ', '', 42, 'app/Foo.php'],
                    'forbidden_files' => [' secrets/.env ', null, '', '0'],
                ],
                'expected_files' => [' tests/FooTest.php ', 'app/Foo.php'],
                'context_refs' => [' docs/foo.md ', ['nested'], ''],
            ],
        ]);

        $result = $provider->runStreaming($job, 'provider prompt');

        $this->assertTrue($result->ok);
        $this->assertSame('provider response', $result->output);
        $this->assertSame(['app/Foo.php', 'tests/FooTest.php', 'docs/foo.md'], data_get($runtime->lastManifest, 'scope_contract.allowed_files'));
        $this->assertSame(['secrets/.env', '0'], data_get($runtime->lastManifest, 'scope_contract.forbidden_files'));
        $this->assertSame('decision-1', $runtime->lastManifest['decision_receipt_id'] ?? null);
        $this->assertSame('receipt-hash-1', $runtime->lastManifest['decision_receipt_hash'] ?? null);
    }

    public function test_run_defaults_allowed_files_to_workspace_root_when_scope_is_empty(): void
    {
        $runtime = new CapturingMinimaxM27CliRuntime();
        $provider = new MinimaxM27CliProvider($runtime);
        $job = new AiJob();
        $job->forceFill([
            'trace_id' => 'trace-2',
            'input_text' => 'Read-only task.',
            'timeout_seconds' => 45,
            'payload' => [
                'decision_receipt' => [
                    'decision_id' => 'decision-2',
                    'receipt_hash' => 'receipt-hash-2',
                ],
                'tool_permissions' => [
                    'workspace' => base_path(),
                    'allowed_files' => ['', null, 42],
                    'forbidden_files' => 'not-an-array',
                ],
                'expected_files' => 'also-not-an-array',
                'context_refs' => [],
            ],
        ]);

        $provider->runStreaming($job, 'provider prompt');

        $this->assertSame(['.'], data_get($runtime->lastManifest, 'scope_contract.allowed_files'));
        $this->assertSame([], data_get($runtime->lastManifest, 'scope_contract.forbidden_files'));
    }
}
