<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Services\Ai\Aaeos\Generated\AtlasAgenticEngineeringDocumentationInventoryService;
use Illuminate\Console\Command;
use Throwable;

/**
 * Atlas Agentic Engineering Documentation Inventory decider CLI.
 *
 *   php artisan atlas:aaeos:agentic-engineering-documentation-inventory [--json]
 *
 * Read-only and deterministic. With safe defaults it demonstrates the four
 * contracts of the inventory doc: the "Classes" governance verdict, the
 * "Familias Canonicas" routing of one file name (here a session-handoff fragment
 * that must never be a primary source), the 6-step "Fluxo" reading plan, and the
 * "Regras para IA" rejecting a read that starts from a part fragment.
 *
 * @see docs/engineering-knowledge-base/atlas-agentic-engineering-documentation-inventory.md
 */
class AtlasAgenticEngineeringDocumentationInventoryCommand extends Command
{
    protected $signature = 'atlas:aaeos:agentic-engineering-documentation-inventory {--json : machine-readable JSON output (default true)}';

    protected $description = 'Atlas AAEOS · agentic engineering documentation inventory (class governance + family routing + reading flow + AI rules).';

    public function handle(AtlasAgenticEngineeringDocumentationInventoryService $service): int
    {
        try {
            // Classes table: a handoff class is context only and never governs
            // implementation.
            $class = $service->classifyClass('handoff');

            // Familias Canonicas: a Forge continuum session-handoff fragment
            // resolves to the handoff class under the Forge Continuum owner doc.
            $file = $service->classifyFile(
                'atlas-forge-continuum-os-session-handoff-2026-05-16-part-03.md'
            );

            // Fluxo: the same file yields a reading plan that does NOT reach
            // "implement" because its class is context only.
            $plan = $service->readingPlan(
                'atlas-forge-continuum-os-session-handoff-2026-05-16-part-03.md'
            );

            // Regras para IA: starting a read from a part fragment is rejected.
            $aiRules = $service->checkAiRules([
                'starts_from_part' => true,
            ]);

            $this->line((string) json_encode(
                [
                    'ok' => true,
                    'class' => $class,
                    'file' => $file,
                    'reading_plan' => $plan,
                    'ai_rules' => $aiRules,
                ],
                JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES,
            ));

            return self::SUCCESS;
        } catch (Throwable $e) {
            $this->line((string) json_encode([
                'ok' => false,
                'error' => 'agentic_engineering_documentation_inventory_failed',
                'message' => $e->getMessage(),
            ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));

            return self::FAILURE;
        }
    }
}
