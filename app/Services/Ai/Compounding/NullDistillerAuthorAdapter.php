<?php

declare(strict_types=1);

namespace App\Services\Ai\Compounding;

use App\Models\AiRunOutcome;

/**
 * ASI-09 — Null-object default binding for {@see DistillerAuthorAdapter}.
 *
 * Returning null means "no author available"; callers MUST fall back to the
 * deterministic template author. This is the safe default that keeps
 * `atlas.ai.distiller.model_author_enabled=true` non-destructive until a real
 * model-backed adapter (Hermes/GLM/…) is bound in its place.
 *
 * The judges (CaptureQualityGate + false_learning_gate + ASI-02 admission + confidence floor)
 * are NOT invoked here — that separation is the whole point of author≠judge.
 */
final class NullDistillerAuthorAdapter implements DistillerAuthorAdapter
{
    /**
     * @param  array<string,mixed>  $signals
     * @return null
     */
    public function authorClaim(AiRunOutcome $outcome, array $signals): ?array
    {
        return null;
    }
}
