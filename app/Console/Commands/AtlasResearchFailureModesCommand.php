<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Services\Ai\Aaeos\Generated\AtlasResearchFailureModesService;
use Illuminate\Console\Command;
use Throwable;

/**
 * Atlas AI Research Self-Improvement Failure Modes decider CLI.
 *
 *   php artisan atlas:aaeos:atlas-research-failure-modes [--json]
 *
 * Evaluates a safe reference packet against the documented Failure Table and the
 * Stop-The-Line conditions, emitting the fused fail-closed verdict, the posture,
 * whether promotion must be withheld, and (when failing closed) the ordered
 * Recovery sequence. Read-only and deterministic; it never runs a crawler,
 * writes memory, promotes a packet or relaxes a gate.
 *
 * @see docs/engineering-knowledge-base/research-self-improvement/failure-modes.md
 */
class AtlasResearchFailureModesCommand extends Command
{
    protected $signature = 'atlas:aaeos:atlas-research-failure-modes {--json : machine-readable JSON output (default true)}';

    protected $description = 'Atlas Research Self-Improvement Failure Modes · evaluates a packet against the failure taxonomy + stop-the-line conditions and emits the fail-closed verdict + recovery sequence.';

    public function handle(AtlasResearchFailureModesService $service): int
    {
        try {
            // Safe reference packet: a hype-driven release (route-level) combined
            // with an invented citation stop-the-line trigger. The stop-the-line
            // gate must dominate, so the fused verdict fails closed, promotion is
            // withheld and the ordered recovery sequence is attached.
            $verdict = $service->decide(
                [AtlasResearchFailureModesService::MODE_HYPE_DRIVEN_RELEASE],
                [AtlasResearchFailureModesService::STL_INVENTED_CITATION],
            );

            $payload = [
                'ok' => true,
                'schema' => AtlasResearchFailureModesService::SCHEMA,
                'verdict' => $verdict,
                'severity_ladder' => AtlasResearchFailureModesService::SEVERITY_POSTURE,
                'failure_table_size' => count(AtlasResearchFailureModesService::FAILURE_TABLE),
                'stop_the_line_size' => count(AtlasResearchFailureModesService::STOP_THE_LINE_CONDITIONS),
            ];

            $this->line((string) json_encode($payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));

            // The reference run is "healthy" (the decider worked) when the packet
            // correctly failed closed, promotion was withheld and the six-step
            // recovery sequence was attached.
            $healthy = $verdict['fail_closed'] === true
                && $verdict['can_promote'] === false
                && count($verdict['recovery']) === 6;

            return $healthy ? self::SUCCESS : self::FAILURE;
        } catch (Throwable $e) {
            $this->line((string) json_encode([
                'ok' => false,
                'error' => 'atlas_research_failure_modes_failed',
                'message' => $e->getMessage(),
            ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));

            return self::FAILURE;
        }
    }
}
