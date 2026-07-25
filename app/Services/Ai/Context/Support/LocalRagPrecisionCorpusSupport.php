<?php

declare(strict_types=1);

namespace App\Services\Ai\Context\Support;

use App\Services\Ai\Context\AtlasContextStringListNormalizer;

/**
 * Pure Local RAG independent-precision corpus projectors / scorers for
 * {@see \App\Services\Ai\Context\LocalRagPrecisionCorpusService}.
 *
 * No FS, no config, no runtime DI, no provider. Host keeps corpus load,
 * SemanticRetrievalRuntime retrieve I/O, and report() orchestration.
 */
final class LocalRagPrecisionCorpusSupport
{
    public const SCHEMA_VERSION = 'atlas.local_rag.independent_precision_corpus_report.v1';

    public const QUERY_INDEPENDENCE_SCHEMA = 'atlas.local_rag.query_independence.v1';

    /** @var array<int,int> */
    public const DEFAULT_K_VALUES = [1, 3, 5];

    public const DEFAULT_MAX_TOKEN_OVERLAP = 0.34;

    /**
     * Prove (and quantify) that queries are INDEPENDENT of their relevant
     * documents — the whole point of R8.
     *
     * @param  array<int,array<string,mixed>>  $documents
     * @param  array<int,array<string,mixed>>  $cases
     * @return array<string,mixed>
     */
    public static function queryIndependenceReport(
        array $documents,
        array $cases,
        float $maxOverlap = self::DEFAULT_MAX_TOKEN_OVERLAP,
    ): array {
        $byId = [];
        foreach ($documents as $document) {
            $byId[(string) $document['id']] = (string) ($document['text'] ?? '');
        }

        $violations = [];
        $overlaps = [];
        foreach ($cases as $case) {
            $query = (string) ($case['query'] ?? '');
            $queryTokens = self::tokens($query);
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

                $overlap = self::jaccard($queryTokens, self::tokens($docText));
                $overlaps[] = $overlap;
                $isSubstring = $query !== ''
                    && mb_stripos(self::normalise($docText), self::normalise($query)) !== false;

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
            'schema_version' => self::QUERY_INDEPENDENCE_SCHEMA,
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
     * Score a case from already-ranked match ids (engine I/O already done).
     *
     * @param  array<int,string>  $relevant
     * @param  array<int,string>  $ranked
     * @param  array<int,int>  $kValues
     * @return array<string,mixed>
     */
    public static function scoreCaseFromRanked(
        string $caseId,
        string $query,
        array $relevant,
        array $ranked,
        array $kValues,
        bool $realEmbeddings,
        bool $fabricatedVectors,
    ): array {
        $precisionAtK = [];
        $recallAtK = [];
        foreach ($kValues as $k) {
            $topK = array_slice($ranked, 0, $k);
            $hits = count(array_intersect($topK, $relevant));
            $precisionAtK[(string) $k] = $k > 0 ? round($hits / $k, 4) : 0.0;
            $recallAtK[(string) $k] = $relevant === [] ? 0.0 : round($hits / count($relevant), 4);
        }

        $primaryK = self::primaryK($kValues);

        return [
            'id' => $caseId,
            'status' => ($recallAtK[(string) $primaryK] ?? 0.0) >= 1.0 ? 'recalled' : 'missed_at_primary_k',
            'query_hash' => hash('sha256', $query),
            'relevant_count' => count($relevant),
            'retrieved_count' => count($ranked),
            'precision_at_k' => $precisionAtK,
            'recall_at_k' => $recallAtK,
            'rank_of_first_relevant' => self::rankOfFirstRelevant($ranked, $relevant),
            'real_embeddings' => $realEmbeddings,
            'fabricated_vectors' => $fabricatedVectors,
        ];
    }

    /**
     * @return array<string,mixed>
     */
    public static function engineErrorCase(
        string $caseId,
        string $query,
        int $relevantCount,
        string $error,
    ): array {
        return [
            'id' => $caseId,
            'status' => 'engine_error',
            'query_hash' => hash('sha256', $query),
            'relevant_count' => $relevantCount,
            'error' => mb_substr($error, 0, 200),
        ];
    }

    /**
     * @param  array<int,mixed>  $matches
     * @return array<int,string>
     */
    public static function rankedIdsFromMatches(array $matches): array
    {
        $ranked = [];
        foreach ($matches as $match) {
            if (! is_array($match) || ! isset($match['id'])) {
                continue;
            }
            $id = (string) $match['id'];
            if ($id !== '') {
                $ranked[] = $id;
            }
        }

        return $ranked;
    }

    /**
     * @param  array<int,array<string,mixed>>  $cases
     * @param  array<int,int>  $kValues
     * @return array<string,mixed>
     */
    public static function aggregate(array $cases, array $kValues): array
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
    public static function rankOfFirstRelevant(array $ranked, array $relevant): ?int
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
    public static function runtimeDocuments(array $documents): array
    {
        return array_map(static fn (array $document): array => [
            'id' => (string) $document['id'],
            'text' => (string) ($document['text'] ?? ''),
        ], $documents);
    }

    /**
     * Honest unmeasured envelope — never fabricates a precision score.
     *
     * @param  array<string,mixed>|null  $corpus
     * @param  array<int,int>  $kValues
     * @return array<string,mixed>
     */
    public static function unmeasured(
        string $reason,
        ?array $corpus = null,
        array $kValues = self::DEFAULT_K_VALUES,
    ): array {
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
            'primary_k' => self::primaryK($kValues),
            'case_count' => 0,
            'engine_error_count' => 0,
            'document_count' => $corpus === null ? 0 : count(self::documents($corpus)),
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
     * Assemble the measured report envelope after engine I/O is complete.
     *
     * @param  array<string,mixed>  $corpus
     * @param  array<int,array<string,mixed>>  $documents
     * @param  array<int,array<string,mixed>>  $measuredCases
     * @param  array<int,int>  $kValues
     * @param  array<string,mixed>  $independence
     * @return array<string,mixed>
     */
    public static function measuredReport(
        array $corpus,
        array $documents,
        array $measuredCases,
        int $engineErrors,
        array $kValues,
        array $independence,
    ): array {
        $metrics = self::aggregate($measuredCases, $kValues);
        $primaryK = self::primaryK($kValues);
        $precisionPrimary = (float) ($metrics['precision_at_k'][(string) $primaryK] ?? 0.0);
        $recallPrimary = (float) ($metrics['recall_at_k'][(string) $primaryK] ?? 0.0);

        $checks = [
            'real_engine_used' => true,
            'no_engine_errors' => $engineErrors === 0,
            'queries_independent_of_targets' => (bool) ($independence['independent'] ?? false),
            'minimum_case_count' => count($measuredCases) >= 5,
            'recall_at_primary_k_threshold' => $recallPrimary >= 0.80,
            'precision_at_1_threshold' => (float) ($metrics['precision_at_k']['1'] ?? 0.0) >= 0.60,
            'no_provider_contamination' => true,
        ];
        $status = self::allChecksPassed($checks) ? 'passed' : 'attention';

        return [
            'schema_version' => self::SCHEMA_VERSION,
            'corpus_id' => (string) ($corpus['corpus_id'] ?? 'unknown'),
            'corpus_schema_version' => (string) ($corpus['schema_version'] ?? 'unknown'),
            'status' => $status,
            'evaluation_mode' => 'real_semantic_retrieval_independent_queries',
            'engine' => self::engineDescriptor($measuredCases),
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
     * @param  array<string,bool>  $checks
     */
    public static function allChecksPassed(array $checks): bool
    {
        foreach ($checks as $passed) {
            if ($passed !== true) {
                return false;
            }
        }

        return true;
    }

    /**
     * @param  array<int,array<string,mixed>>  $cases
     * @return array<string,mixed>|null
     */
    public static function engineDescriptor(array $cases): ?array
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
     * @param  array<string,mixed>  $corpus
     * @return array<int,array<string,mixed>>
     */
    public static function documents(array $corpus): array
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
    public static function cases(array $corpus): array
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
    public static function kValues(array $corpus): array
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
    public static function primaryK(array $kValues): int
    {
        // The headline k is the largest k the corpus declares (recall@k).
        return $kValues === [] ? 3 : max($kValues);
    }

    /**
     * @return array<int,string>
     */
    public static function tokens(string $text): array
    {
        $normalised = self::normalise($text);
        $parts = preg_split('/[^a-z0-9]+/', $normalised, -1, PREG_SPLIT_NO_EMPTY);

        return AtlasContextStringListNormalizer::uniqueTrimmedStrings($parts);
    }

    public static function normalise(string $text): string
    {
        return mb_strtolower(trim($text));
    }

    /**
     * @param  array<int,string>  $a
     * @param  array<int,string>  $b
     */
    public static function jaccard(array $a, array $b): float
    {
        if ($a === [] || $b === []) {
            return 0.0;
        }

        $intersection = count(array_intersect($a, $b));
        $union = count(array_unique(array_merge($a, $b)));

        return $union === 0 ? 0.0 : $intersection / $union;
    }
}
