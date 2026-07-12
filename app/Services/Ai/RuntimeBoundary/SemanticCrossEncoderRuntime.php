<?php

declare(strict_types=1);

namespace App\Services\Ai\RuntimeBoundary;

/**
 * Optional semantic_rag boundary for MAXB-06 local cross-encoder rerank.
 *
 * PHP only chooses the bounded candidate window and verifies the receipt; the
 * pairwise query/document scoring runs inside the local Python runtime.
 */
interface SemanticCrossEncoderRuntime
{
    /**
     * Rerank an already-shortlisted local candidate window with a cross-encoder
     * model inside the Python semantic_rag runtime.
     *
     * @param  array<int,array{id?:string,text:string,metadata?:array<string,mixed>}>  $documents
     * @return array<string,mixed>
     */
    public function crossEncoderRerank(array $documents, string $query, int $k = 3): array;
}
