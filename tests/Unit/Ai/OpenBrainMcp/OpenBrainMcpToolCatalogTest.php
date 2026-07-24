<?php

declare(strict_types=1);

namespace Tests\Unit\Ai\OpenBrainMcp;

use App\Services\Ai\OpenBrainMcp\OpenBrainMcpToolCatalog;
use PHPUnit\Framework\TestCase;

final class OpenBrainMcpToolCatalogTest extends TestCase
{
    public function test_definitions_include_primary_open_brain_tools(): void
    {
        $defs = OpenBrainMcpToolCatalog::definitions();
        $this->assertIsArray($defs);
        $this->assertGreaterThan(20, count($defs));
        $names = array_map(static fn (array $tool): string => (string) ($tool['name'] ?? ''), $defs);
        $this->assertContains('atlas_memory_recall', $names);
        $this->assertContains('atlas_open_brain_context_pack', $names);
        $this->assertContains('atlas_context_expand', $names);
    }
}
