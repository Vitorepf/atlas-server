<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Services\Ai\Aaeos\Generated\AtlasAiSkillSystemService;
use Illuminate\Console\Command;
use Throwable;

/**
 * Atlas AI Skill System decider CLI.
 *
 *   php artisan atlas:aaeos:atlas-ai-skill-system [--json]
 *
 * Read-only and deterministic. Demonstrates the doc's lifecycle gate with a safe
 * default: a candidate skill that holds comparable traces AND evidence of gain
 * vs baseline is allowed to advance to `ready`. Also shows the fixed
 * conflict-resolution authority order. No provider call, no DB.
 *
 * @see docs/engineering-knowledge-base/atlas-ai-skill-system.md
 */
class AtlasAiSkillSystemCommand extends Command
{
    protected $signature = 'atlas:aaeos:atlas-ai-skill-system {--json : machine-readable JSON output (default true)}';

    protected $description = 'Atlas AI Skill System: lifecycle transition gates, Fronteira classification and conflict-resolution order.';

    public function handle(AtlasAiSkillSystemService $service): int
    {
        try {
            // Safe default: a candidate with evidence-of-gain advancing to ready.
            $transition = $service->decideTransition([
                'current_state' => AtlasAiSkillSystemService::STATE_CANDIDATE,
                'requested_state' => AtlasAiSkillSystemService::STATE_READY,
                'has_comparable_traces' => true,
                'evidence_of_gain_vs_baseline' => true,
            ]);

            $classification = $service->classifyConcept([
                'is_versioned' => true,
                'has_output_contract' => true,
                'has_eval' => true,
            ]);

            $conflict = $service->resolveConflict([
                'output_governor' => null,
                'kernel_policy' => 'atlas.skill.core.formatter',
                'flow_owner' => 'atlas.skill.user.formatter',
            ]);

            $decision = [
                'transition' => $transition,
                'classification' => $classification,
                'conflict' => $conflict,
            ];

            $this->line((string) json_encode(
                ['ok' => true, 'decision' => $decision],
                JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES,
            ));

            return self::SUCCESS;
        } catch (Throwable $e) {
            $this->line((string) json_encode([
                'ok' => false,
                'error' => 'atlas_ai_skill_system_failed',
                'message' => $e->getMessage(),
            ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));

            return self::FAILURE;
        }
    }
}
