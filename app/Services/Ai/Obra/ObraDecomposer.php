<?php

declare(strict_types=1);

namespace App\Services\Ai\Obra;

/**
 * AOBG N3.F1 — the DECOMPOSER seam.
 *
 * The single responsibility: turn one natural-language INTENT into the raw list of
 * steps ({@see ObraNodeDraft}) that form the obra's plan-DAG. It does NOT validate
 * the DAG, anchor to the brain, sequence, or persist — that is the
 * {@see AtlasObraPlanService}'s job. Keeping the decomposer behind an interface is
 * what makes the spine COST-FREE to test: the real path
 * ({@see ProviderObraDecomposer}) calls a provider; tests inject a deterministic /
 * fake decomposer ({@see DeterministicObraDecomposer} or a closure-backed fake) so
 * no tokens are ever burned in a test or an agent.
 */
interface ObraDecomposer
{
    /**
     * Decompose an intent into raw step drafts (un-validated, un-sequenced).
     *
     * @param  string  $intent  the operator's natural-language ask
     * @param  array<string,mixed>  $opts  optional hints (workspace, max_nodes, ...)
     * @return list<ObraNodeDraft>  the raw steps; depends_on entries name OTHER
     *                              drafts by their `key`
     */
    public function decompose(string $intent, array $opts = []): array;

    /**
     * A short, stable label for this decomposer, recorded into the plan's meta so a
     * reader knows HOW the plan was produced (deterministic vs provider, which
     * provider) without re-running it.
     */
    public function label(): string;
}
