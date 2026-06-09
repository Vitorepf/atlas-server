<?php

declare(strict_types=1);

namespace App\Services\Ai\RuntimeBoundary;

/**
 * The minimal read-only contract the kernel needs to run REAL semantic
 * retrieval through the Python semantic_rag runtime (the pgvector path's
 * embedding/RAG engine). Implemented by {@see SemanticRagRuntimeClient}.
 *
 * Exists so honest measurement harnesses (e.g. the R8 independent
 * retrieval-precision corpus, App\Services\Ai\Context\LocalRagPrecisionCorpusService)
 * can depend on the retrieval boundary by interface and be tested with a fake
 * engine — without weakening the real client's anti-fake boundary enforcement
 * or its no-PHP-fallback canon.
 */
interface SemanticRetrievalRuntime
{
    /**
     * Whether the real Python runtime is set up and invocable. When false,
     * callers MUST fail honest (no fabricated vectors / scores), never fall back
     * to a hash/lexical stand-in.
     */
    public function available(): bool;

    /**
     * Semantic (+ optional graph) RAG retrieval over real embeddings.
     *
     * @param  array<int,array{id?:string,text:string,metadata?:array<string,mixed>}>  $documents
     * @return array<string,mixed>
     */
    public function retrieve(
        array $documents,
        string $query,
        int $k = 5,
        bool $graphExpand = false,
        float $graphThreshold = 0.6,
    ): array;
}
