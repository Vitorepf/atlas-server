<?php

declare(strict_types=1);

namespace App\Services\Ai\Compounding;

use App\Models\AiRunOutcome;

/**
 * ASI-09 — Distiller AUTHOR adapter (author≠judge).
 *
 * The AUTHOR proposes the distilled claim text; the JUDGES (CaptureQualityGate
 * + false_learning_gate + ASI-02 admission + confidence floor) remain 100%
 * deterministic and untouched. A claim from the author still passes through
 * the same gates the template claim does — never self-promoted.
 */
interface DistillerAuthorAdapter
{
    /**
     * @param  array<string,mixed>  $signals
     * @return array{claim:string, evidence_refs:list<string>}|null
     *                                                              Null when the adapter cannot author for this outcome (e.g. missing
     *                                                              inputs, provider unreachable, privacy class local-only mismatch).
     *                                                              Callers MUST fall back to the template author (degrade honest).
     */
    public function authorClaim(AiRunOutcome $outcome, array $signals): ?array;
}
