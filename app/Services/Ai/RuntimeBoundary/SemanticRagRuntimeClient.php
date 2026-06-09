<?php

declare(strict_types=1);

namespace App\Services\Ai\RuntimeBoundary;

use Illuminate\Support\Facades\File;
use RuntimeException;
use Symfony\Component\Process\Process;
use Throwable;

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
final class SemanticRagRuntimeClient implements SemanticRetrievalRuntime
{
    private const RUNTIME_ROOT = 'runtimes/python/semantic_rag';

    public function available(): bool
    {
        return File::exists($this->venvPython()) && File::exists($this->entrypoint());
    }

    private function venvPython(): string
    {
        return base_path(self::RUNTIME_ROOT.'/.venv/bin/python');
    }

    private function entrypoint(): string
    {
        return base_path(self::RUNTIME_ROOT.'/main.py');
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
        if (! $this->available()) {
            throw new RuntimeException(
                'semantic_rag Python runtime is not set up — run scripts/setup-semantic-rag-runtime.sh. '
                .'The canon forbids a PHP embedding/RAG fallback; this is an explicit failure, not a silent stand-in.'
            );
        }

        $manifestPath = storage_path('app/atlas-semantic-rag-'.hash('sha256', (string) json_encode($manifest)).'.json');
        File::ensureDirectoryExists(dirname($manifestPath));
        File::put($manifestPath, (string) json_encode($manifest, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));

        $process = new Process(
            [$this->venvPython(), $this->entrypoint(), $manifestPath],
            base_path(self::RUNTIME_ROOT),
        );
        $process->setTimeout(120);
        $process->run();

        try {
            File::delete($manifestPath);
        } catch (Throwable) {
            // best-effort temp cleanup
        }

        $payload = json_decode($process->getOutput(), true);
        if (! $process->isSuccessful() || ! is_array($payload) || ($payload['ok'] ?? false) !== true) {
            $detail = $process->getErrorOutput() !== '' ? $process->getErrorOutput() : $process->getOutput();

            throw new RuntimeException('semantic_rag runtime failed: '.substr($detail, 0, 500));
        }

        $result = is_array($payload['result'] ?? null) ? $payload['result'] : [];

        // Boundary enforcement: a result is only accepted if it proves real, in-Python,
        // non-fabricated embeddings. This is where a fake would be rejected.
        $boundary = is_array($result['boundary'] ?? null) ? $result['boundary'] : [];
        if (($boundary['real_embeddings'] ?? false) !== true
            || ($boundary['fabricated_vectors'] ?? true) !== false
            || ($boundary['embeddings_engine_in_python'] ?? false) !== true) {
            throw new RuntimeException('semantic_rag returned a non-real-embedding boundary receipt — refusing (anti-fake guard).');
        }

        return $result;
    }
}
