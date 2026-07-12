<?php

declare(strict_types=1);

namespace App\Services\Ai\RuntimeBoundary;

/**
 * Optional semantic_rag boundary for MAXA-09 late-interaction rerank.
 *
 * The PHP kernel only chooses the candidate window and verifies the receipt;
 * token-level embeddings and MaxSim scoring run in the Python runtime.
 */
interface SemanticLateInteractionRuntime
{
    /**
     * Rerank an already-shortlisted local candidate window with a late-interaction
     * model (e.g. ColBERT-family) inside the Python semantic_rag runtime.
     *
     * @param  array<int,array{id?:string,text:string,metadata?:array<string,mixed>}>  $documents
     * @return array<string,mixed>
     */
    public function lateInteractionRerank(array $documents, string $query, int $k = 5): array;
}
