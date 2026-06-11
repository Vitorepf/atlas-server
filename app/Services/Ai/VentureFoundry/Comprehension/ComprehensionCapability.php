<?php

namespace App\Services\Ai\VentureFoundry\Comprehension;

use App\Models\AiVentureComprehensionRun;

/**
 * Contract every comprehension capability implements so the orchestrator runs
 * them uniformly. A capability reads the workspace through the {@see
 * WorkspaceReader}, records {@see FindingDraft}s via the {@see
 * ComprehensionRecorder}, and returns a structured report.
 */
interface ComprehensionCapability
{
    /** Stable capability id (see AiVentureComprehensionFinding::CAPABILITY_*). */
    public function capability(): string;

    /**
     * Scan the workspace for this capability and persist findings into the run.
     *
     * @return array<string,mixed> a structured report (counts, top findings, notes)
     */
    public function scan(AiVentureComprehensionRun $run, WorkspaceReader $reader): array;
}
