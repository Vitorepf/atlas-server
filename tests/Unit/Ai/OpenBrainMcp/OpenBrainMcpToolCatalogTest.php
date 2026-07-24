<?php

declare(strict_types=1);

namespace Tests\Unit\Ai\OpenBrainMcp;

use App\Services\Ai\OpenBrainMcp\OpenBrainMcpToolCatalog;
use PHPUnit\Framework\TestCase;

final class OpenBrainMcpToolCatalogTest extends TestCase
{
    public function test_definitions_are_named_tool_rows(): void
    {
        $tools = OpenBrainMcpToolCatalog::definitions();

        $this->assertCount(65, $tools);
        $names = array_map(static fn (array $t): string => (string) ($t['name'] ?? ''), $tools);
        $this->assertContains('atlas_memory_recall', $names);
        $this->assertContains('atlas_context_pack', $names);
        $this->assertContains('atlas_capabilities', $names);
        foreach ($tools as $tool) {
            $this->assertArrayHasKey('name', $tool);
            $this->assertArrayHasKey('inputSchema', $tool);
        }
    }
}
