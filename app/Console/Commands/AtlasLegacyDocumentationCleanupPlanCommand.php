<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Services\Ai\Aaeos\Generated\AtlasLegacyDocumentationCleanupPlanService;
use Illuminate\Console\Command;
use Throwable;

/**
 * Legacy Documentation Cleanup Plan decider CLI.
 *
 *   php artisan atlas:aaeos:legacy-documentation-cleanup-plan [--json]
 *
 * Read-only and deterministic. With safe defaults it demonstrates the plan's
 * preserve-first posture: a delete request in a first wave with no Definition
 * Of Done satisfied is refused, and the same item falls back to quarantine
 * rather than being lost.
 *
 * @see docs/engineering-knowledge-base/legacy-documentation-cleanup-plan.md
 */
class AtlasLegacyDocumentationCleanupPlanCommand extends Command
{
    protected $signature = 'atlas:aaeos:legacy-documentation-cleanup-plan {--json : machine-readable JSON output (default true)}';

    protected $description = 'Atlas AAEOS · legacy documentation cleanup decider (promote|merge|redirect|archive|quarantine|block + delete DoD).';

    public function handle(AtlasLegacyDocumentationCleanupPlanService $service): int
    {
        try {
            // Safe default item: a first-wave delete attempt with none of the
            // Delete Definition Of Done gates satisfied — must be refused.
            $delete = $service->evaluateDelete([
                'first_wave' => true,
                'delete_dod' => [],
            ]);

            // The same legacy item, asked to promote with an incomplete DoD —
            // the decider falls back to quarantine (preserve first).
            $action = $service->decideAction([
                'intent' => 'promote',
                'kind' => 'source_material',
                'promotion_dod' => [],
            ]);

            $this->line((string) json_encode(
                ['ok' => true, 'delete' => $delete, 'action' => $action],
                JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES,
            ));

            return self::SUCCESS;
        } catch (Throwable $e) {
            $this->line((string) json_encode([
                'ok' => false,
                'error' => 'legacy_documentation_cleanup_plan_failed',
                'message' => $e->getMessage(),
            ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));

            return self::FAILURE;
        }
    }
}
