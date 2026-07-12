<?php

declare(strict_types=1);

namespace App\Services\Ai\RuntimeBoundary;

/**
 * PHP adapter to the REAL Python semantic/graph-RAG data runtime
 * (runtimes/python/semantic_rag). By the runtime_language_boundary canon the
 * PHP kernel must NOT compute embeddings/vectors/RAG — it invokes this Python
 * runtime instead. This client is the governed bridge: it writes a manifest,
 * runs the runtime under its own venv (where numpy + the local embedding model
 * live), parses the receipt, and REFUSES any result that is not a real
 * in-Python embedding (boundary.real_embeddings !== true) — so a fake can never
 * pass back through the boundary.
 *
 * There is NO PHP fallback by design: if the runtime is not set up the client
 * raises explicitly (run scripts/setup-semantic-rag-runtime.sh). That is the
 * canon: a real Python engine or an honest failure, never a hash/token stand-in.
 */
final class SemanticRagRuntimeClient implements SemanticLateInteractionRuntime, SemanticRetrievalRuntime
{
    private const RUNTIME_ROOT = 'runtimes/python/semantic_rag';

    private const MANIFEST_PREFIX = 'atlas-semantic-rag';

    private const SETUP_MESSAGE = 'semantic_rag Python runtime is not set up — run scripts/setup-semantic-rag-runtime.sh. '
        .'The canon forbids a PHP embedding/RAG fallback; this is an explicit failure, not a silent stand-in.';

    private const RUNTIME_LABEL = 'semantic_rag';

    private const BOUNDARY_REQUIRED_TRUE = ['real_embeddings', 'embeddings_engine_in_python'];

    private const BOUNDARY_REQUIRED_FALSE = ['fabricated_vectors'];

    private const BOUNDARY_REFUSAL = 'semantic_rag returned a non-real-embedding boundary receipt — refusing (anti-fake guard).';

    private readonly PythonManifestRuntimeClient $runtime;

    public function __construct(?PythonManifestRuntimeClient $runtime = null)
    {
        $this->runtime = $runtime ?? new PythonManifestRuntimeClient(
            self::RUNTIME_ROOT,
            self::MANIFEST_PREFIX,
            self::SETUP_MESSAGE,
            self::RUNTIME_LABEL,
        );
    }

    public function available(): bool
    {
        return $this->runtime->available();
    }

    /**
     * Embed texts with the real Python model (for pgvector storage, etc.).
     *
     * @param  array<int,string>  $texts
     * @return array<string,mixed>
     */
    public function embed(array $texts): array
    {
        return $this->run(['operation' => 'embed', 'texts' => array_values($texts)]);
    }

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
    ): array {
        return $this->run([
            'operation' => 'retrieve',
            'documents' => array_values($documents),
            'query' => $query,
            'k' => $k,
            'graph_expand' => $graphExpand,
            'graph_threshold' => $graphThreshold,
        ]);
    }

    /**
     * Late-interaction rerank over an already-shortlisted candidate window.
     *
     * @param  array<int,array{id?:string,text:string,metadata?:array<string,mixed>}>  $documents
     * @return array<string,mixed>
     */
    public function lateInteractionRerank(array $documents, string $query, int $k = 5): array
    {
        return $this->run([
            'operation' => 'late_interaction_rerank',
            'documents' => array_values($documents),
            'query' => $query,
            'k' => $k,
        ]);
    }

    /**
     * The embedding-similarity graph over a document set.
     *
     * @param  array<int,array{id?:string,text:string}>  $documents
     * @return array<string,mixed>
     */
    public function graph(array $documents, float $graphThreshold = 0.6): array
    {
        return $this->run([
            'operation' => 'graph',
            'documents' => array_values($documents),
            'graph_threshold' => $graphThreshold,
        ]);
    }

    /**
     * @param  array<string,mixed>  $manifest
     * @return array<string,mixed>
     */
    private function run(array $manifest): array
    {
        $result = null;
        if ((bool) config('atlas.semantic_memory.embedding_daemon_enabled', true)) {
            $result = $this->runtime->runResident(
                $manifest,
                (string) config('atlas.semantic_memory.embedding_daemon_socket_path', ''),
                (string) config('atlas.semantic_memory.embedding_daemon_manifest_path', ''),
                (bool) config('atlas.semantic_memory.embedding_daemon_auto_start', true),
                (int) config('atlas.semantic_memory.embedding_daemon_connect_timeout_ms', 200),
                (int) config('atlas.semantic_memory.embedding_daemon_startup_timeout_ms', 1000),
                (int) config('atlas.semantic_memory.embedding_daemon_idle_timeout_seconds', 300),
            );
        }

        if ($result === null) {
            $result = $this->runtime->run($manifest);
        }

        PythonBoundaryReceiptGuard::assertReal(
            $result,
            self::BOUNDARY_REQUIRED_TRUE,
            self::BOUNDARY_REQUIRED_FALSE,
            self::BOUNDARY_REFUSAL,
        );

        return $result;
    }
}
