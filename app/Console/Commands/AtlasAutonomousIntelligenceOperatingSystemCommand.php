<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Services\Ai\Aaeos\Generated\AtlasAutonomousIntelligenceOperatingSystemService;
use Illuminate\Console\Command;
use Throwable;

/**
 * Atlas Autonomous Intelligence OS decider CLI.
 *
 *   php artisan atlas:aaeos:autonomous-intelligence-operating-system [--json]
 *
 * Read-only, deterministic. Demonstrates the four governed verdicts for a
 * representative complex prompt: mission classification ("Fluxo" step 2), the
 * Tool Policy decision, the Safety / Riscos gate, and the Definition-of-Done
 * certification.
 *
 * @see docs/engineering-knowledge-base/atlas-autonomous-intelligence-operating-system.md
 */
class AtlasAutonomousIntelligenceOperatingSystemCommand extends Command
{
    protected $signature = 'atlas:aaeos:autonomous-intelligence-operating-system
        {--json : machine-readable JSON output (default true)}';

    protected $description = 'Atlas Autonomous Intelligence OS decider (mission tier, tool policy, safety gate, certification).';

    public function handle(AtlasAutonomousIntelligenceOperatingSystemService $service): int
    {
        try {
            // A multi-step research mission that needs tools -> governed mission.
            $classification = $service->classifyMission([
                'requires_research' => true,
                'multi_step' => true,
                'needs_tools' => true,
            ]);

            // A needed tool with no trusted ready option and an unvetted repo
            // (no license proof, no reputation) -> fall back to building own.
            $tool = $service->decideTool([
                'tool_is_necessary' => true,
                'trusted_ready_tool_exists' => false,
                'how_to_validate_it_worked' => true,
                'how_to_record_learning_for_evolution' => true,
            ]);

            // A login-gated automation with external cost -> requires confirmation.
            $safety = $service->evaluateSafetyGate([
                'login_or_credential' => true,
                'external_cost' => true,
            ]);

            // A validated result with an evidence pack and no open blockers ->
            // certified.
            $certification = $service->certifyMission([
                'domain_selected' => true,
                'result_validated' => true,
                'evidence_pack_present' => true,
                'open_blockers' => [],
            ]);

            $this->line((string) json_encode([
                'ok' => true,
                'mission_classification' => $classification,
                'tool_decision' => $tool,
                'safety_gate' => $safety,
                'certification' => $certification,
            ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));

            return self::SUCCESS;
        } catch (Throwable $e) {
            $this->line((string) json_encode([
                'ok' => false,
                'error' => 'autonomous_intelligence_operating_system_failed',
                'message' => $e->getMessage(),
            ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));

            return self::FAILURE;
        }
    }
}
