<?php

declare(strict_types=1);

namespace App\Services\Ai\Cognition;


/**
 * MAXI-04 — Port for the semantic arm of the immune input classifier.
 *
 * Contract:
 * - similarity(candidate, exemplar) MUST return a float in [0.0, 1.0] when the
 *   backend is available and honest, and null when the backend is unavailable
 *   (never fabricate a similarity of 0.0 or 0.5).
 * - The candidate text and exemplar text NEVER leave the machine.
 * - Implementations MUST be deterministic for the same inputs.
 *
 * Default implementation: {@see BigramJaccardImmuneSemanticSimilarityPort}
 * (pure PHP, char-bigram Jaccard \u2014 the honest baseline when the daemon of
 * MAXA-01 is absent). A daemon-backed port (real cosine over embeddings) can
 * replace the default without changing this interface \u2014 the classifier
 * remains agnostic.
 */
interface ImmuneSemanticSimilarityPort
{
    /**
     * @return float|null null iff the backend is unavailable
     */
    public function similarity(string $candidate, string $exemplar): ?float;
}
