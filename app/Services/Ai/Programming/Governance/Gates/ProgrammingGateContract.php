<?php

namespace App\Services\Ai\Programming\Governance\Gates;

use App\Models\AtlasProgrammingWorkItem;

/**
 * Contract for any Atlas Programming Governance gate.
 *
 * @see docs/engineering-knowledge-base/atlas-programming-governance-system.md
 */
interface ProgrammingGateContract
{
    /** Canonical gate name (kebab-case, matches doc). */
    public function name(): string;

    public function evaluate(AtlasProgrammingWorkItem $workItem): ProgrammingGateOutcome;
}
