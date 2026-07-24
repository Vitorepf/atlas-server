<?php

declare(strict_types=1);

namespace App\Services\Ai\OpenBrainMcp;

/**
 * MCP tool definitions catalog (full-pass density extract).
 *
 * Behavior-preserving façade over OpenBrainMcpToolDefinitions data table.
 */
final class OpenBrainMcpToolCatalog
{
    /**
     * @return list<array<string, mixed>>
     */
    public static function definitions(): array
    {
        /** @var list<array<string, mixed>> $tools */
        $tools = require __DIR__.'/OpenBrainMcpToolDefinitions.php';

        return $tools;
    }
}
