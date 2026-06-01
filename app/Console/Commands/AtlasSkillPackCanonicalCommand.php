<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Services\Ai\Aaeos\Generated\AtlasSkillPackCanonicalService;
use Illuminate\Console\Command;
use Throwable;

/**
 * Atlas Skill Pack Canonical decider CLI.
 *
 *   php artisan atlas:aaeos:skill-pack-canonical [--json]
 *
 * Read-only and deterministic. Demonstrates the atlas.skill_pack.v1 contract with
 * a safe default: the doc's worked example — atlas.skill.user.expense_categorizer
 * with 45 uses, 0 failures, ok_to_share, Architect approved — which the gate
 * promotes to atlas.skill.core.expense_categorizer. No upload, no DB.
 *
 * @see docs/engineering-knowledge-base/atlas-skill-pack-canonical.md
 */
class AtlasSkillPackCanonicalCommand extends Command
{
    protected $signature = 'atlas:aaeos:skill-pack-canonical {--json : machine-readable JSON output (default true)}';

    protected $description = 'Atlas Skill Pack Canonical: validate the atlas.skill_pack.v1 schema and the user -> core promotion gate.';

    public function handle(AtlasSkillPackCanonicalService $service): int
    {
        try {
            // Safe default: the doc's worked example. A user skill with 45 uses,
            // 0 failures, ok_to_share sovereignty and Architect approval is the
            // one case the doc says promotes to core.
            $sample = [
                'schema' => AtlasSkillPackCanonicalService::SKILL_PACK_SCHEMA_ID,
                'skill_id' => 'atlas.skill.user.expense_categorizer',
                'version' => 'v1',
                'namespace' => 'user',
                'human_name' => 'Expense Categorizer',
                'purpose' => 'Categorize expenses for the operator finance flow.',
                'code_path' => 'app/Services/Ai/AiSkillStore.php',
                'sovereignty_class' => 'ok_to_share',
                'uses_count' => 45,
                'failure_count' => 0,
                'incident_count' => 0,
                'architect_approved' => true,
            ];

            $decision = [
                'schema_check' => $service->validateSchema($sample),
                'promotion' => $service->evaluatePromotion($sample),
            ];

            $this->line((string) json_encode(
                ['ok' => true, 'decision' => $decision],
                JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES,
            ));

            return self::SUCCESS;
        } catch (Throwable $e) {
            $this->line((string) json_encode([
                'ok' => false,
                'error' => 'skill_pack_canonical_failed',
                'message' => $e->getMessage(),
            ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));

            return self::FAILURE;
        }
    }
}
