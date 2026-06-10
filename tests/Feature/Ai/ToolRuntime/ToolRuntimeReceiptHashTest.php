<?php

namespace Tests\Feature\Ai\ToolRuntime;

use App\Models\AiToolReceipt;
use App\Services\Ai\ToolRuntime\ToolDefinitionRegistryService;
use App\Services\Ai\ToolRuntime\ToolInvocationService;
use Tests\Concerns\CreatesToolRuntimeTables;
use Tests\TestCase;

class ToolRuntimeReceiptHashTest extends TestCase
{
    use CreatesToolRuntimeTables;

    protected function setUp(): void
    {
        parent::setUp();
        config()->set('atlas_ai.tool_runtime.strict_mode', false);
        $this->createToolRuntimeTables();
    }

    protected function tearDown(): void
    {
        $this->dropToolRuntimeTables();
        parent::tearDown();
    }

    public function test_invocation_emits_receipt_with_hash(): void
    {
        $tool = app(ToolDefinitionRegistryService::class)->register(
            ToolRuntimeRegistryUniquenessTest::validToolAttributes('receipt_demo'),
        );
        $invocation = app(ToolInvocationService::class)->invoke($tool, ['payload' => 'demo']);

        $receipt = AiToolReceipt::query()->where('tool_invocation_id', $invocation->id)->first();

        $this->assertNotNull($receipt);
        $this->assertSame('tool_call', $receipt->receipt_type);
        $this->assertSame('succeeded', $receipt->status);
        $this->assertSame(64, strlen((string) $receipt->receipt_hash));
        $this->assertIsArray($receipt->evidence_refs);
        $this->assertNotEmpty($receipt->evidence_refs);
    }
}
