<?php

declare(strict_types=1);

namespace App\Services\Ai\Context;

use App\Services\Ai\Context\Support\LocalRagPrecisionCorpusSupport;
use App\Services\Ai\RuntimeBoundary\SemanticRetrievalRuntime;
use Illuminate\Support\Facades\File;
use Throwable;

/**
 * R8 — HONEST labeled retrieval-precision corpus harness.
 *
 * The audit found that the only existing "real" retrieval metric in this
 * codebase ({@see LocalRagBenchmarkService::memoryRecallCorpusReport()}) is a
 * KNOWN-ITEM LEXICAL test: it builds each fixture query out of the target's own
 * title+summary and then checks the target's own id comes back. That is
 * near-tautological — it measures "does the store return the row whose text I
 * just pasted in", not whether semantic retrieval actually works on an
 * INDEPENDENT question.
 *
 * This harness fixes that. It loads a small hand-authored corpus of
 * {@see https paraphrased / conceptual} queries whose text is INDEPENDENT of the
 * relevant documents (the system did not author the documents from the queries),
 * runs them through the REAL semantic retrieval path (the Python semantic_rag
 * runtime that backs the pgvector / SemanticSearchService path — real local
 * learned embeddings, anti-fake boundary enforced), and computes REAL
 * precision@k / recall@k.
 *
 * Anti-over-claim contract (Atlas value #1):
 *  - If the real engine is absent it returns status `attention` with metrics
 *    honestly 0.0 and an `unmeasured_honestly` marker + `missing_reason`. It
 *    NEVER fabricates a score and NEVER falls back to a lexical/hash stand-in.
 *  - Query independence is itself measured and asserted: a query that is a
 *    substring of (or shares too high a token overlap with) its relevant
 *    document is flagged, so the corpus cannot silently rot back into a lexical
 *    known-item test.
 *
 * This is read-only measurement: no writes, no provider calls, no policy
 * mutation, no promotion authority.
 *
 * Pure scoring / independence / envelope projectors live in
 * {@see LocalRagPrecisionCorpusSupport}. This host owns FS corpus load +
 * SemanticRetrievalRuntime I/O + report() orchestration.
 */
class LocalRagPrecisionCorpusService
{
    public const SCHEMA_VERSION = LocalRagPrecisionCorpusSupport::SCHEMA_VERSION;

    public function __construct(
        private readonly SemanticRetrievalRuntime $runtime,
    ) {}

    /**
     * @return array<string,mixed>
     */
    public function report(): array
    {
        $corpus = $this->loadCorpus();
        if ($corpus === null) {
            return LocalRagPrecisionCorpusSupport::unmeasured('corpus_fixture_missing_or_invalid');
        }

        $documents = LocalRagPrecisionCorpusSupport::documents($corpus);
        $cases = LocalRagPrecisionCorpusSupport::cases($corpus);
        $kValues = LocalRagPrecisionCorpusSupport::kValues($corpus);
        $maxOverlap = (float) data_get(
            $corpus,
            'independence_contract.max_token_overlap',
            LocalRagPrecisionCorpusSupport::DEFAULT_MAX_TOKEN_OVERLAP,
        );

        if ($documents === [] || $cases === []) {
            return LocalRagPrecisionCorpusSupport::unmeasured(
                'corpus_fixture_has_no_documents_or_cases',
                $corpus,
                $kValues,
            );
        }

        // Honest independence proof FIRST: this is what makes the precision
        // number meaningful (queries are not the target text).
        $independence = LocalRagPrecisionCorpusSupport::queryIndependenceReport(
            $documents,
            $cases,
            $maxOverlap,
        );

        // The REAL retrieval engine. No engine => honest unmeasured, never a fake.
        if (! $this->runtime->available()) {
            $payload = LocalRagPrecisionCorpusSupport::unmeasured(
                'semantic_rag_runtime_unavailable',
                $corpus,
                $kValues,
            );
            $payload['query_independence'] = $independence;

            return $payload;
        }

        $evaluated = [];
        foreach ($cases as $case) {
            $evaluated[] = $this->evaluateCase($case, $documents, $kValues);
        }

        $measuredCases = array_values(array_filter(
            $evaluated,
            static fn (array $case): bool => ($case['status'] ?? null) !== 'engine_error',
        ));
        $engineErrors = count($evaluated) - count($measuredCases);

        if ($measuredCases === []) {
            $payload = LocalRagPrecisionCorpusSupport::unmeasured(
                'semantic_rag_runtime_errored_on_every_case',
                $corpus,
                $kValues,
            );
            $payload['query_independence'] = $independence;
            $payload['engine_error_count'] = $engineErrors;

            return $payload;
        }

        return LocalRagPrecisionCorpusSupport::measuredReport(
            $corpus,
            $documents,
            $measuredCases,
            $engineErrors,
            $kValues,
            $independence,
        );
    }

    /**
     * Public surface for independence proof (used by feature path + operators).
     * Pure implementation lives on Support.
     *
     * @param  array<int,array<string,mixed>>  $documents
     * @param  array<int,array<string,mixed>>  $cases
     * @return array<string,mixed>
     */
    public function queryIndependenceReport(
        array $documents,
        array $cases,
        float $maxOverlap = LocalRagPrecisionCorpusSupport::DEFAULT_MAX_TOKEN_OVERLAP,
    ): array {
        return LocalRagPrecisionCorpusSupport::queryIndependenceReport($documents, $cases, $maxOverlap);
    }

    /**
     * Engine I/O residual: retrieve via SemanticRetrievalRuntime, then pure score.
     *
     * @param  array<string,mixed>  $case
     * @param  array<int,array<string,mixed>>  $documents
     * @param  array<int,int>  $kValues
     * @return array<string,mixed>
     */
    private function evaluateCase(array $case, array $documents, array $kValues): array
    {
        $query = (string) ($case['query'] ?? '');
        $relevant = array_values(array_unique(array_map('strval', (array) ($case['relevant_ids'] ?? []))));
        $maxK = max($kValues);
        $caseId = (string) ($case['id'] ?? 'unknown');

        try {
            $result = $this->runtime->retrieve(
                LocalRagPrecisionCorpusSupport::runtimeDocuments($documents),
                $query,
                max($maxK, 5),
                false,
            );
        } catch (Throwable $throwable) {
            report($throwable);

            return LocalRagPrecisionCorpusSupport::engineErrorCase(
                $caseId,
                $query,
                count($relevant),
                $throwable->getMessage(),
            );
        }

        $ranked = LocalRagPrecisionCorpusSupport::rankedIdsFromMatches((array) ($result['matches'] ?? []));
        $boundary = (array) ($result['boundary'] ?? []);

        return LocalRagPrecisionCorpusSupport::scoreCaseFromRanked(
            $caseId,
            $query,
            $relevant,
            $ranked,
            $kValues,
            ($boundary['real_embeddings'] ?? false) === true,
            ($boundary['fabricated_vectors'] ?? true) === true,
        );
    }

    /**
     * @return array<string,mixed>|null
     */
    private function loadCorpus(): ?array
    {
        $path = $this->corpusPath();
        if (! File::exists($path)) {
            return null;
        }

        try {
            $decoded = json_decode(File::get($path), true, flags: JSON_THROW_ON_ERROR);
        } catch (Throwable) {
            return null;
        }

        return is_array($decoded) ? $decoded : null;
    }

    private function corpusPath(): string
    {
        $configured = config('atlas.semantic_memory.independent_precision_corpus_path');
        if (is_string($configured) && trim($configured) !== '') {
            return $configured;
        }

        return resource_path('atlas/local_rag/independent_precision_corpus.v1.json');
    }
}
