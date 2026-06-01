<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Services\Ai\Aaeos\Generated\AtlasAiCartographyRootService;
use Illuminate\Console\Command;
use Throwable;

/**
 * Atlas AI cartography-root parent-resolution / orphan validator CLI.
 *
 *   php artisan atlas:aaeos:atlas-ai-cartography-root [--json]
 *
 * Read-only, deterministic. Runs the `cartography-orphan-count-zero` gate over
 * a canonical sample node set (the doc's own example: mission-mode and the
 * autonomous-intelligence-os parented to atlas-ai under the world root) and
 * emits the orphan audit + gate decision.
 *
 * @see docs/engineering-knowledge-base/atlas-ai.md
 */
class AtlasAiCartographyRootCommand extends Command
{
    protected $signature = 'atlas:aaeos:atlas-ai-cartography-root
        {--json : machine-readable JSON output (default true)}';

    protected $description = 'Atlas AI · cartography root orphan validator (cartography-orphan-count-zero gate).';

    public function handle(AtlasAiCartographyRootService $service): int
    {
        try {
            // Safe default: the doc's own canonical example — atlas-ai under the
            // world root, with mission-mode and the autonomous-intelligence-os
            // correctly parented to it. A healthy graph => gate passes.
            $nodes = [
                ['id' => 'atlas', 'parent' => null, 'status' => 'active'],
                ['id' => 'atlas-ai', 'parent' => 'atlas', 'status' => 'active'],
                ['id' => 'atlas-mission-mode', 'parent' => 'atlas-ai', 'status' => 'active'],
                ['id' => 'atlas-autonomous-intelligence-operating-system', 'parent' => 'atlas-ai', 'status' => 'active'],
                ['id' => 'atlas-ai-canonical-architecture-index', 'parent' => 'atlas-ai', 'status' => 'active'],
            ];

            $audit = $service->audit($nodes);

            $this->line((string) json_encode(
                ['ok' => true, 'audit' => $audit],
                JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES,
            ));

            return self::SUCCESS;
        } catch (Throwable $e) {
            $this->line((string) json_encode([
                'ok' => false,
                'error' => 'atlas_ai_cartography_root_failed',
                'message' => $e->getMessage(),
            ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));

            return self::FAILURE;
        }
    }
}
