<?php

declare(strict_types=1);

namespace App\Services\Ai\Organism;

/**
 * AOBG N4.F1 — the BRAIN-ANCHOR seam.
 *
 * Before a domain proposes, the organism asks the brain for the curated, provider-safe
 * context that should anchor the proposal (the same Open-Brain context pack N3 used to
 * anchor each obra node). Keeping this behind a thin interface lets the organism service
 * be unit-tested cost-free (a fake returns a deterministic context), while production
 * binds it to {@see OpenBrainContextPackAnchor} over
 * {@see \App\Services\Ai\AtlasOpenBrainContextPackService} — the real fused brain.
 *
 * The returned context is ALWAYS provider-safe (curated top-K labels/refs); the anchor
 * additionally folds in `prior_proposals` (cross-domain compounding) supplied by the
 * organism from the {@see OrganismProposalRecorder}.
 */
interface OrganismBrainAnchor
{
    /**
     * @param  array<string,mixed>  $opts  {domain?, workspace?, cwd?, ...}
     * @return array<string,mixed> a provider-safe context pack {code_graph?,
     *         reality_graph_paths?, memory?, brain_refs?, ...}
     */
    public function anchor(string $intent, array $opts = []): array;
}
