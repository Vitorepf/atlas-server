<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Services\Ai\Aaeos\Generated\AtlasProgrammingToolFamiliesService;
use Illuminate\Console\Command;
use Throwable;

/**
 * Atlas Programming Tool Families CLI.
 *
 *   php artisan atlas:aaeos:programming-tool-families [--json]
 *
 * Read-only, deterministic. Emits the programming-tool-families map snapshot
 * plus a worked classification for the security/supply-chain primary
 * (`semgrep`), proving the family, the primary authority role, and the
 * Registry Rule (`is_registry => false`). Never executes a tool or touches
 * state.
 *
 * @see docs/engineering-knowledge-base/tool-runtime/programming-tool-families.md
 */
class AtlasProgrammingToolFamiliesCommand extends Command
{
    protected $signature = 'atlas:aaeos:programming-tool-families {--json : machine-readable JSON output (default true)}';

    protected $description = 'Atlas tool runtime · programming tool families map (family classifier + Authority Pattern completeness, advisory only).';

    public function handle(AtlasProgrammingToolFamiliesService $service): int
    {
        try {
            // Safe default: snapshot the whole map and classify a documented
            // primary tool to show the family + Registry Rule.
            $map = $service->map();
            $sample = $service->classify('semgrep');

            $this->line((string) json_encode(
                ['ok' => true, 'map' => $map, 'sample_classification' => $sample],
                JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES,
            ));

            return self::SUCCESS;
        } catch (Throwable $e) {
            $this->line((string) json_encode([
                'ok' => false,
                'error' => 'programming_tool_families_failed',
                'message' => $e->getMessage(),
            ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));

            return self::FAILURE;
        }
    }
}
