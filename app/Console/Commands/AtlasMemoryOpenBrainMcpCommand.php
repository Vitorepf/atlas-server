<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Services\Ai\Aaeos\Generated\AtlasMemoryOpenBrainMcpService;
use Illuminate\Console\Command;
use Throwable;

/**
 * Atlas Memory · Open Brain MCP & API contract CLI.
 *
 *   php artisan atlas:aaeos:memory-open-brain-mcp
 *     [--intent=context]   // route() target: context|decide|execute|repair|learn|persist
 *     [--capability=...]   // probe mayPerform() / requiresAp() for one capability
 *     [--json]
 *
 * Read-only, deterministic. Emits the routing verdict for the intent, the
 * boundary/manifest, and (when --capability is given) whether Open Brain may
 * perform it and whether it needs a dedicated AP.
 *
 * @see docs/engineering-knowledge-base/memory/open-brain-mcp.md
 */
class AtlasMemoryOpenBrainMcpCommand extends Command
{
    protected $signature = 'atlas:aaeos:memory-open-brain-mcp
        {--intent= : an intent to route (context|decide|execute|repair|learn|persist)}
        {--capability= : a capability to probe against the boundary / future-scope}
        {--json : machine-readable JSON output (default true)}';

    protected $description = 'Atlas Memory · Open Brain MCP contract — route intents to Open Brain vs Kernel and expose the access boundary.';

    public function handle(AtlasMemoryOpenBrainMcpService $service): int
    {
        try {
            $intent = $this->option('intent');
            $capability = $this->option('capability');

            $payload = [
                'ok' => true,
                'route' => $service->route(
                    is_string($intent) && trim($intent) !== '' ? $intent : 'context',
                ),
                'manifest' => $service->manifest(),
            ];

            if (is_string($capability) && trim($capability) !== '') {
                $payload['capability'] = [
                    'name' => $capability,
                    'may_perform' => $service->mayPerform($capability),
                    'requires_ap' => $service->requiresAp($capability),
                ];
            }

            $this->line((string) json_encode(
                $payload,
                JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES,
            ));

            return self::SUCCESS;
        } catch (Throwable $e) {
            $this->line((string) json_encode([
                'ok' => false,
                'error' => 'memory_open_brain_mcp_failed',
                'message' => $e->getMessage(),
            ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));

            return self::FAILURE;
        }
    }
}
