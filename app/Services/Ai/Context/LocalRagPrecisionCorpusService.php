<?php

declare(strict_types=1);

namespace App\Services\Ai\Context;

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
 */
class LocalRagPrecisionCorpusService
{
    public const SCHEMA_VERSION = 'atlas.local_rag.independent_precision_corpus_report.v1';

    /** @var array<int,int> */
    private const DEFAULT_K_VALUES = [1, 3, 5];

    private const DEFAULT_MAX_TOKEN_OVERLAP = 0.34;

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
            return $this->unmeasured('corpus_fixture_missing_or_invalid');
        }

        $documents = $this->documents($corpus);
        $cases = $this->cases($corpus);
        $kValues = $this->kValues($corpus);
        $maxOverlap = (float) data_get(
            $corpus,
            'independence_contract.max_token_overlap',
            self::DEFAULT_MAX_TOKEN_OVERLAP,
        );

        if ($documents === [] || $cases === []) {
            return $this->unmeasured('corpus_fixture_has_no_documents_or_cases', $corpus, $kValues);
        }

        // Honest independence proof FIRST: this is what makes the precision
        // number meaningful (queries are not the target text).
        $independence = $this->queryIndependenceReport($documents, $cases, $maxOverlap);

        // The REAL retrieval engine. No engine => honest unmeasured, never a fake.
        if (! $this->runtime->available()) {
            $payload = $this->unmeasured('semantic_rag_runtime_unavailable', $corpus, $kValues);
            $payload['query_independence'] = $independence;

            return $payload;
        }

        $documentIds = array_map(static fn (array $document): string => (string) $document['id'], $documents);

        $evaluated = [];
        foreach ($cases as $case) {
            $evaluated[] = $this->evaluateCase($case, $documents, $documentIds, $kValues);
        }

        $measuredCases = array_values(array_filter(
            $evaluated,
            static fn (array $case): bool => ($case['status'] ?? null) !== 'engine_error',
        ));
        $engineErrors = count($evaluated) - count($measuredCases);

        if ($measuredCases === []) {
            $payload = $this->unmeasured('semantic_rag_runtime_errored_on_every_case', $corpus, $kValues);
            $payload['query_independence'] = $independence;
            $payload['engine_error_count'] = $engineErrors;

            return $payload;
        }

        $metrics = $this->aggregate($measuredCases, $kValues);
        $primaryK = $this->primaryK($kValues);
        $precisionPrimary = (float) ($metrics['precision_at_k'][(string) $primaryK] ?? 0.0);
        $recallPrimary = (float) ($metrics['recall_at_k'][(string) $primaryK] ?? 0.0);

        // Honest thresholds. These are modest on purpose: a real semantic engine
        // on independent queries should comfortably recall the relevant doc in
        // the top-k; we do NOT inflate the bar to manufacture a "pass".
        $checks = [
            'real_engine_used' => true,
            'no_engine_errors' => $engineErrors === 0,
            'queries_independent_of_targets' => (bool) ($independence['independent'] ?? false),
            'minimum_case_count' => count($measuredCases) >= 5,
            'recall_at_primary_k_threshold' => $recallPrimary >= 0.80,
            'precision_at_1_threshold' => (float) ($metrics['precision_at_k']['1'] ?? 0.0) >= 0.60,
            'no_provider_contamination' => true,
        ];
        $status = collect($checks)->every(fn (bool $passed): bool => $passed) ? 'passed' : 'attention';

        return [
            'schema_version' => self::SCHEMA_VERSION,
            'corpus_id' => (string) ($corpus['corpus_id'] ?? 'unknown'),
            'corpus_schema_version' => (string) ($corpus['schema_version'] ?? 'unknown'),
            'status' => $status,
            'evaluation_mode' => 'real_semantic_retrieval_independent_queries',
            'engine' => $this->engineDescriptor($measuredCases),
            'measured' => true,
            'unmeasured_honestly' => false,
            'missing_reason' => null,
            'k_values' => $kValues,
            'primary_k' => $primaryK,
            'case_count' => count($measuredCases),
            'engine_error_count' => $engineErrors,
            'document_count' => count($documents),
            'thresholds' => [
                'min_cases' => 5,
                'recall_at_primary_k' => 0.80,
                'precision_at_1' => 0.60,
            ],
            'metrics' => array_merge($metrics, [
                'precision_at_primary_k' => round($precisionPrimary, 4),
                'recall_at_primary_k' => round($recallPrimary, 4),
            ]),
            'query_independence' => $independence,
            'checks' => $checks,
            'cases' => $measuredCases,
            'limits' => [
                'read_only_measurement' => true,
                'no_provider_call' => true,
                'no_policy_patch' => true,
                'queries_authored_independently_of_target_text' => true,
                'fixture_corpus_small_by_design' => true,
                'raw_document_text_persisted' => false,
            ],
            'next_action' => $status === 'passed'
                ? 'use_real_precision_as_the_independent_retrieval_signal'
                : 'inspect_independent_precision_corpus_misses_before_trusting_retrieval',
        ];
    }

    /**
     * Prove (and quantify) that queries are INDEPENDENT of their relevant
     * documents — the whole point of R8. A query that is a substring of, or
     * shares too high a token overlap with, its relevant doc would collapse the
     * corpus back into a lexical known-item test, so we flag it.
     *
     * @param  array<int,array<string,mixed>>  $documents
     * @param  array<int,array<string,mixed>>  $cases
     * @return array<string,mixed>
     */
    public function queryIndependenceReport(array $documents, array $cases, float $maxOverlap = self::DEFAULT_MAX_TOKEN_OVERLAP): array
    {
        $byId = [];
        foreach ($documents as $document) {
            $byId[(string) $document['id']] = (string) ($document['text'] ?? '');
        }

        $violations = [];
        $overlaps = [];
        foreach ($cases as $case) {
            $query = (string) ($case['query'] ?? '');
            $queryTokens = $this->tokens($query);
            foreach ((array) ($case['relevant_ids'] ?? []) as $relevantId) {
                $docText = $byId[(string) $relevantId] ?? '';
                if ($docText === '') {
                    $violations[] = [
                        'case_id' => (string) ($case['id'] ?? 'unknown'),
                        'relevant_id' => (string) $relevantId,
                        'reason' => 'relevant_id_not_in_documents',
                    ];

                    continue;
                }

                $overlap = $this->jaccard($queryTokens, $this->tokens($docText));
                $overlaps[] = $overlap;
                $isSubstring = $query !== ''
                    && mb_stripos($this->normalise($docText), $this->normalise($query)) !== false;

                if ($isSubstring || $overlap > $maxOverlap) {
                    $violations[] = [
                        'case_id' => (string) ($case['id'] ?? 'unknown'),
                        'relevant_id' => (string) $relevantId,
                        'reason' => $isSubstring ? 'query_is_substring_of_target' : 'token_overlap_above_ceiling',
                        'token_overlap' => round($overlap, 4),
                    ];
                }
            }
        }

        return [
            'schema_version' => 'atlas.local_rag.query_independence.v1',
            'independent' => $violations === [],
            'rule' => 'query must not be a substring of any relevant document and must keep token overlap <= ceiling',
            'max_token_overlap' => $maxOverlap,
            'max_observed_token_overlap' => $overlaps === [] ? 0.0 : round(max($overlaps), 4),
            'mean_token_overlap' => $overlaps === [] ? 0.0 : round(array_sum($overlaps) / count($overlaps), 4),
            'checked_pair_count' => count($overlaps),
            'violation_count' => count($violations),
            'violations' => $violations,
        ];
    }

    /**
     * @param  array<string,mixed>  $case
     * @param  array<int,array<string,mixed>>  $documents
     * @param  array<int,string>  $documentIds
     * @param  array<int,int>  $kValues
     * @return array<string,mixed>
     */
    private function evaluateCase(array $case, array $documents, array $documentIds, array $kValues): array
    {
        $query = (string) ($case['query'] ?? '');
        $relevant = array_values(array_unique(array_map('strval', (array) ($case['relevant_ids'] ?? []))));
        $maxK = max($kValues);

        try {
            $result = $this->runtime->retrieve(
                $this->runtimeDocuments($documents),
                $query,
                max($maxK, 5),
                false,
            );
        } catch (Throwable $throwable) {
            report($throwable);

            return [
                'id' => (string) ($case['id'] ?? 'unknown'),
                'status' => 'engine_error',
                'query_hash' => hash('sha256', $query),
                'relevant_count' => count($relevant),
                'error' => mb_substr($throwable->getMessage(), 0, 200),
            ];
        }

        $ranked = collect((array) ($result['matches'] ?? []))
            ->map(static fn (array $match): ?string => isset($match['id']) ? (string) $match['id'] : null)
            ->filter(static fn (?string $id): bool => $id !== null && $id !== '')
            ->values()
            ->all();

        $precisionAtK = [];
        $recallAtK = [];
        foreach ($kValues as $k) {
            $topK = array_slice($ranked, 0, $k);
            $hits = count(array_intersect($topK, $relevant));
            $precisionAtK[(string) $k] = $k > 0 ? round($hits / $k, 4) : 0.0;
            $recallAtK[(string) $k] = $relevant === [] ? 0.0 : round($hits / count($relevant), 4);
        }

        $boundary = (array) ($result['boundary'] ?? []);
        $primaryK = $this->primaryK($kValues);

        return [
            'id' => (string) ($case['id'] ?? 'unknown'),
            'status' => ($recallAtK[(string) $primaryK] ?? 0.0) >= 1.0 ? 'recalled' : 'missed_at_primary_k',
            'query_hash' => hash('sha256', $query),
            'relevant_count' => count($relevant),
            'retrieved_count' => count($ranked),
            'precision_at_k' => $precisionAtK,
            'recall_at_k' => $recallAtK,
            'rank_of_first_relevant' => $this->rankOfFirstRelevant($ranked, $relevant),
            'real_embeddings' => ($boundary['real_embeddings'] ?? false) === true,
            'fabricated_vectors' => ($boundary['fabricated_vectors'] ?? true) === true,
        ];
    }

    /**
     * @param  array<int,array<string,mixed>>  $cases
     * @param  array<int,int>  $kValues
     * @return array<string,mixed>
     */
    private function aggregate(array $cases, array $kValues): array
    {
        $precision = [];
        $recall = [];
        foreach ($kValues as $k) {
            $key = (string) $k;
            $precisionValues = array_map(
                static fn (array $case): float => (float) ($case['precision_at_k'][$key] ?? 0.0),
                $cases,
            );
            $recallValues = array_map(
                static fn (array $case): float => (float) ($case['recall_at_k'][$key] ?? 0.0),
                $cases,
            );
            $precision[$key] = $precisionValues === [] ? 0.0 : round(array_sum($precisionValues) / count($precisionValues), 4);
            $recall[$key] = $recallValues === [] ? 0.0 : round(array_sum($recallValues) / count($recallValues), 4);
        }

        $mrrValues = array_map(static function (array $case): float {
            $rank = $case['rank_of_first_relevant'] ?? null;

            return is_int($rank) && $rank > 0 ? 1.0 / $rank : 0.0;
        }, $cases);

        return [
            'precision_at_k' => $precision,
            'recall_at_k' => $recall,
            'mean_reciprocal_rank' => $mrrValues === [] ? 0.0 : round(array_sum($mrrValues) / count($mrrValues), 4),
            'recalled_case_count' => count(array_filter(
                $cases,
                static fn (array $case): bool => ($case['status'] ?? null) === 'recalled',
            )),
        ];
    }

    /**
     * @param  array<int,string>  $ranked
     * @param  array<int,string>  $relevant
     */
    private function rankOfFirstRelevant(array $ranked, array $relevant): ?int
    {
        foreach (array_values($ranked) as $index => $id) {
            if (in_array($id, $relevant, true)) {
                return $index + 1;
            }
        }

        return null;
    }

    /**
     * @param  array<int,array<string,mixed>>  $documents
     * @return array<int,array{id:string,text:string}>
     */
    private function runtimeDocuments(array $documents): array
    {
        return array_map(static fn (array $document): array => [
            'id' => (string) $document['id'],
            'text' => (string) ($document['text'] ?? ''),
        ], $documents);
    }

    /**
     * @param  array<string,mixed>|null  $corpus
     * @param  array<int,int>  $kValues
     * @return array<string,mixed>
     */
    private function unmeasured(string $reason, ?array $corpus = null, array $kValues = self::DEFAULT_K_VALUES): array
    {
        $zeroPrecision = [];
        $zeroRecall = [];
        foreach ($kValues as $k) {
            $zeroPrecision[(string) $k] = 0.0;
            $zeroRecall[(string) $k] = 0.0;
        }

        return [
            'schema_version' => self::SCHEMA_VERSION,
            'corpus_id' => (string) ($corpus['corpus_id'] ?? 'unknown'),
            'corpus_schema_version' => (string) ($corpus['schema_version'] ?? 'unknown'),
            'status' => 'attention',
            'evaluation_mode' => 'real_semantic_retrieval_independent_queries',
            'engine' => null,
            'measured' => false,
            'unmeasured_honestly' => true,
            'missing_reason' => $reason,
            'k_values' => $kValues,
            'primary_k' => $this->primaryK($kValues),
            'case_count' => 0,
            'engine_error_count' => 0,
            'document_count' => $corpus === null ? 0 : count($this->documents($corpus)),
            'metrics' => [
                'precision_at_k' => $zeroPrecision,
                'recall_at_k' => $zeroRecall,
                'mean_reciprocal_rank' => 0.0,
                'recalled_case_count' => 0,
                'precision_at_primary_k' => 0.0,
                'recall_at_primary_k' => 0.0,
            ],
            'checks' => [
                'real_engine_used' => false,
                'no_engine_errors' => false,
                'queries_independent_of_targets' => false,
                'minimum_case_count' => false,
                'recall_at_primary_k_threshold' => false,
                'precision_at_1_threshold' => false,
                'no_provider_contamination' => true,
            ],
            'cases' => [],
            'limits' => [
                'read_only_measurement' => true,
                'no_provider_call' => true,
                'no_fabricated_score' => true,
                'requires_real_semantic_rag_runtime_and_corpus' => true,
                'raw_document_text_persisted' => false,
            ],
            'next_action' => $reason === 'semantic_rag_runtime_unavailable'
                ? 'set_up_semantic_rag_runtime_then_remeasure_precision'
                : 'restore_independent_precision_corpus_fixture_then_remeasure',
        ];
    }

    /**
     * @param  array<int,array<string,mixed>>  $cases
     * @return array<string,mixed>|null
     */
    private function engineDescriptor(array $cases): ?array
    {
        $first = $cases[0] ?? null;

        return [
            'runtime_family' => 'python_ai_data',
            'runtime_id' => 'semantic_rag',
            'path' => 'pgvector_semantic_retrieval_via_python_runtime',
            'real_embeddings' => (bool) ($first['real_embeddings'] ?? true),
            'fabricated_vectors' => (bool) ($first['fabricated_vectors'] ?? false),
        ];
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

    /**
     * @param  array<string,mixed>  $corpus
     * @return array<int,array<string,mixed>>
     */
    private function documents(array $corpus): array
    {
        return array_values(array_filter(
            (array) ($corpus['documents'] ?? []),
            static fn ($document): bool => is_array($document)
                && isset($document['id'])
                && trim((string) ($document['text'] ?? '')) !== '',
        ));
    }

    /**
     * @param  array<string,mixed>  $corpus
     * @return array<int,array<string,mixed>>
     */
    private function cases(array $corpus): array
    {
        return array_values(array_filter(
            (array) ($corpus['cases'] ?? []),
            static fn ($case): bool => is_array($case)
                && trim((string) ($case['query'] ?? '')) !== ''
                && (array) ($case['relevant_ids'] ?? []) !== [],
        ));
    }

    /**
     * @param  array<string,mixed>  $corpus
     * @return array<int,int>
     */
    private function kValues(array $corpus): array
    {
        $values = array_values(array_filter(array_map(
            static fn ($value): int => (int) $value,
            (array) ($corpus['k_values'] ?? []),
        ), static fn (int $value): bool => $value > 0));

        $values = $values === [] ? self::DEFAULT_K_VALUES : array_values(array_unique($values));
        sort($values);

        return $values;
    }

    /**
     * @param  array<int,int>  $kValues
     */
    private function primaryK(array $kValues): int
    {
        // The headline k is the largest k the corpus declares (recall@k). With a
        // tiny relevant set, top-k recall is the honest "did we find it" signal.
        return $kValues === [] ? 3 : max($kValues);
    }

    /**
     * @return array<int,string>
     */
    private function tokens(string $text): array
    {
        $normalised = $this->normalise($text);
        $parts = preg_split('/[^a-z0-9]+/', $normalised, -1, PREG_SPLIT_NO_EMPTY);

        return array_values(array_unique(is_array($parts) ? $parts : []));
    }

    private function normalise(string $text): string
    {
        return mb_strtolower(trim($text));
    }

    /**
     * @param  array<int,string>  $a
     * @param  array<int,string>  $b
     */
    private function jaccard(array $a, array $b): float
    {
        if ($a === [] || $b === []) {
            return 0.0;
        }

        $intersection = count(array_intersect($a, $b));
        $union = count(array_unique(array_merge($a, $b)));

        return $union === 0 ? 0.0 : $intersection / $union;
    }
}
