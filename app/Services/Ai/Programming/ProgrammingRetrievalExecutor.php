<?php

namespace App\Services\Ai\Programming;

use Illuminate\Support\Arr;

class ProgrammingRetrievalExecutor
{
    public const CONTEXT_PACK_SCHEMA_VERSION = 'atlas.programming.context_pack.v1';

    public const PROFESSIONAL_CONTEXT_PACK_SCHEMA_VERSION = 'atlas.programming.context_pack.professional.v1';

    public static function focusedUnitTestPath(): string
    {
        return 'tests/Unit/Ai/Programming/ProgrammingRetrievalExecutorTest.php';
    }

    public function __construct(
        private readonly ProgrammingLocalVectorIndex $localVectorIndex,
        private readonly ProgrammingProfessionalReranker $professionalReranker,
    ) {}

    /**
     * @param  array<string,mixed>  $graph
     * @param  array<int,array<string,mixed>>  $previousReceipts
     * @return array<string,mixed>
     */
    public function contextPack(array $graph, array $previousReceipts = [], int $maxRefs = 40, int $maxChars = 20000): array
    {
        $refs = [];

        foreach ((array) ($graph['nodes'] ?? []) as $node) {
            if (! is_array($node)) {
                continue;
            }

            $path = Arr::get($node, 'path');
            $kind = (string) Arr::get($node, 'kind', 'code');
            if (! is_string($path) || $path === '') {
                continue;
            }

            $refs[] = $this->ref(
                source: $kind === 'doc' ? 'canonical_docs' : ($kind === 'test' ? 'related_tests' : 'code_symbols'),
                ref: $path,
                reason: (string) Arr::get($node, 'reason', 'semantic_code_graph_match'),
                score: match ($kind) {
                    'symbol' => 95,
                    'file' => 85,
                    'test' => 80,
                    'doc' => 75,
                    default => 70,
                },
                hashInput: $node,
            );
        }

        foreach ((array) ($graph['related_docs'] ?? []) as $doc) {
            if (is_string($doc) && $doc !== '') {
                $refs[] = $this->ref('canonical_docs', $doc, 'semantic_code_graph_related_doc', 78, ['doc' => $doc]);
            }
        }

        foreach ((array) ($graph['related_tests'] ?? []) as $test) {
            if (is_string($test) && $test !== '') {
                $refs[] = $this->ref('related_tests', $test, 'semantic_code_graph_related_test', 82, ['test' => $test]);
            }
        }

        foreach ($previousReceipts as $receipt) {
            if (! is_array($receipt)) {
                continue;
            }

            $receiptId = Arr::get($receipt, 'receipt_id');
            if (is_string($receiptId) && $receiptId !== '') {
                $refs[] = $this->ref('stage_receipts', $receiptId, 'resume_stage_receipt', 90, $receipt);
            }
        }

        $refs = collect($refs)
            ->unique(fn (array $ref): string => $ref['source'].'|'.$ref['ref'])
            ->sortByDesc('score')
            ->take(max(1, $maxRefs))
            ->values()
            ->all();

        $encoded = json_encode($refs, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) ?: '[]';
        $truncated = strlen($encoded) > $maxChars;

        return [
            'schema_version' => self::CONTEXT_PACK_SCHEMA_VERSION,
            'status' => $refs === [] ? 'empty' : ($truncated ? 'truncated' : 'ready'),
            'ranked_ref_count' => count($refs),
            'ranked_refs' => $refs,
            'budget' => [
                'max_refs' => $maxRefs,
                'max_chars' => $maxChars,
                'estimated_chars' => strlen($encoded),
                'truncated' => $truncated,
            ],
            'source_counts' => collect($refs)->countBy('source')->all(),
            'provider_safe' => true,
            'hash' => hash('sha256', $encoded),
        ];
    }

    /**
     * @param  array<string,mixed>  $graph
     * @param  array<int,array<string,mixed>>  $queries
     * @param  array<int,array<string,mixed>>  $previousReceipts
     * @param  array<int,string>  $requiredSources
     * @return array<string,mixed>
     */
    public function professionalContextPack(
        string $workspace,
        string $objective,
        string $flow,
        array $graph,
        array $queries,
        array $previousReceipts,
        array $requiredSources,
        int $maxRefs = 40,
        int $maxChars = 20000,
        array $graphRagRefs = [],
    ): array {
        $legacyPack = $this->contextPack($graph, $previousReceipts, $maxRefs * 2, $maxChars);
        // Cheap LEXICAL (token-overlap) pre-filter — NOT semantic embeddings. See ProgrammingLocalVectorIndex.
        $lexicalRefs = $this->localVectorIndex->search(
            workspace: $workspace,
            objective: $objective,
            queries: $queries,
            limit: max($maxRefs, 32),
        );

        $refs = array_merge((array) ($legacyPack['ranked_refs'] ?? []), $graphRagRefs, $lexicalRefs);
        $refs = array_merge($refs, $this->professionalCompanionRefs($workspace, $objective, $flow, $refs));
        $refs = array_merge($refs, $this->auditedEmptySourceRefs($requiredSources, $previousReceipts));
        $reranked = $this->professionalReranker->rerank($refs, $requiredSources, $flow, $maxRefs);
        $rankedRefs = $reranked['ranked_refs'];

        $encoded = json_encode($rankedRefs, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) ?: '[]';
        $truncated = strlen($encoded) > $maxChars;
        if ($truncated) {
            $rankedRefs = $this->trimToBudget($rankedRefs, $maxChars);
            $encoded = json_encode($rankedRefs, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) ?: '[]';
        }

        $sourceCounts = collect($rankedRefs)->countBy('source')->all();
        $contextPackHash = hash('sha256', json_encode([
            'schema_version' => self::PROFESSIONAL_CONTEXT_PACK_SCHEMA_VERSION,
            'flow' => $flow,
            'ranked_refs' => $rankedRefs,
            'source_counts' => $sourceCounts,
        ], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) ?: $encoded);

        return [
            'schema_version' => self::PROFESSIONAL_CONTEXT_PACK_SCHEMA_VERSION,
            'context_pack_hash' => $contextPackHash,
            'retrieval_strategy' => $graphRagRefs === []
                ? 'hybrid_graph_lexical'
                : 'promoted_programming_graph_rag_lexical',
            'status' => $rankedRefs === [] ? 'empty' : ($truncated ? 'truncated' : 'ready'),
            'provider_safe' => collect($rankedRefs)->every(fn (array $ref): bool => ($ref['privacy'] ?? 'provider_safe') === 'provider_safe'),
            'ranked_ref_count' => count($rankedRefs),
            'ranked_refs' => $rankedRefs,
            'excluded_refs' => $reranked['excluded_refs'],
            'source_counts' => $sourceCounts,
            'metrics' => array_merge($reranked['metrics'], [
                'legacy_ref_count' => (int) ($legacyPack['ranked_ref_count'] ?? 0),
                'graph_rag_ref_count' => count($graphRagRefs),
                'lexical_ref_count' => count($lexicalRefs),
                'retrieval_channels' => collect($rankedRefs)
                    ->pluck('retrieval_channel')
                    ->filter()
                    ->unique()
                    ->values()
                    ->all(),
            ]),
            'budget' => [
                'max_refs' => $maxRefs,
                'max_chars' => $maxChars,
                'used_chars' => strlen($encoded),
                'truncated' => $truncated,
            ],
        ];
    }

    /**
     * @param  array<int,string>  $requiredSources
     * @param  array<int,array<string,mixed>>  $previousReceipts
     * @return array<int,array<string,mixed>>
     */
    private function auditedEmptySourceRefs(array $requiredSources, array $previousReceipts): array
    {
        $refs = [];

        if (in_array('prior_decisions', $requiredSources, true)) {
            $refs[] = $this->ref(
                source: 'prior_decisions',
                ref: 'prior_decisions:none_found',
                reason: 'audited_empty_prior_decision_source',
                score: 76,
                hashInput: ['source' => 'prior_decisions', 'status' => 'none_found'],
            ) + [
                'scope' => 'review',
                'freshness' => 'current',
                'privacy' => 'provider_safe',
                'retrieval_channel' => 'audited_empty_source',
                'empty_source_evidence' => true,
            ];
        }

        if (in_array('known_failures', $requiredSources, true)) {
            $failureReceiptCount = collect($previousReceipts)
                ->filter(fn (array $receipt): bool => str_contains((string) ($receipt['stage'] ?? ''), 'repair')
                    || str_contains((string) ($receipt['status'] ?? ''), 'failed'))
                ->count();

            if ($failureReceiptCount === 0) {
                $refs[] = $this->ref(
                    source: 'known_failures',
                    ref: 'known_failures:none_found',
                    reason: 'audited_empty_known_failure_source',
                    score: 74,
                    hashInput: ['source' => 'known_failures', 'status' => 'none_found'],
                ) + [
                    'scope' => 'repair',
                    'freshness' => 'current',
                    'privacy' => 'provider_safe',
                    'retrieval_channel' => 'audited_empty_source',
                    'empty_source_evidence' => true,
                ];
            }
        }

        return $refs;
    }

    /**
     * @param  array<int,array<string,mixed>>  $refs
     * @return array<int,array<string,mixed>>
     */
    private function trimToBudget(array $refs, int $maxChars): array
    {
        $trimmed = [];
        $used = 2;
        foreach ($refs as $ref) {
            $encoded = json_encode($ref, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) ?: '{}';
            if (($used + strlen($encoded) + 1) > $maxChars) {
                break;
            }

            $trimmed[] = $ref;
            $used += strlen($encoded) + 1;
        }

        return $trimmed;
    }

    /**
     * @param  array<int,array<string,mixed>>  $refs
     * @return array<int,array<string,mixed>>
     */
    private function professionalCompanionRefs(string $workspace, string $objective, string $flow, array $refs): array
    {
        $knownRefs = collect($refs)->pluck('ref')->filter()->values()->all();
        $objective = strtolower($objective);
        $wantsAgenticRag = str_contains($objective, 'agentic')
            || str_contains($objective, 'rag')
            || str_contains($objective, 'retrieval')
            || collect($knownRefs)->contains('app/Services/Ai/Programming/ProgrammingRetrievalPlanner.php');
        $wantsRepair = str_contains($flow, 'repair') || str_contains($objective, 'repair') || str_contains($objective, 'failed');
        $wantsRuntimeContracts = str_contains($objective, 'stage')
            || str_contains($objective, 'resume')
            || str_contains($objective, 'learning')
            || str_contains($objective, 'enterprise runtime');

        $paths = [];
        if ($wantsAgenticRag) {
            $paths = array_merge($paths, [
                'app/Services/Ai/Programming/ProgrammingRetrievalExecutor.php',
                'app/Services/Ai/Programming/ProgrammingProfessionalReranker.php',
                'app/Services/Ai/Programming/ProgrammingGapCritic.php',
                'app/Services/Ai/Programming/ProgrammingContextPackStore.php',
                'app/Services/Ai/Programming/ProgrammingRetrievalEvaluator.php',
            ]);
        }
        if ($wantsRepair) {
            $paths = array_merge($paths, [
                'docs/engineering-knowledge-base/domains/programming-repair-contract.md',
                'app/Services/Ai/Programming/ProgrammingRepairExecutor.php',
                'app/Services/Ai/Programming/ProgrammingRepairAttemptStore.php',
            ]);
        }
        if ($wantsRuntimeContracts) {
            $paths = array_merge($paths, [
                'app/Services/Ai/Programming/ProgrammingStageReceiptStore.php',
                'app/Services/Ai/Programming/ProgrammingResumeService.php',
                'app/Services/Ai/Programming/ProgrammingLearningCandidateProjector.php',
                'docs/engineering-knowledge-base/domains/programming-enterprise-implementation-plan.md',
            ]);
        }

        return collect($paths)
            ->unique()
            ->filter(fn (string $path): bool => is_file($workspace.DIRECTORY_SEPARATOR.$path))
            ->map(fn (string $path): array => $this->ref(
                source: str_starts_with($path, 'docs/') ? 'canonical_docs' : 'code_symbols',
                ref: $path,
                reason: 'professional_programming_companion',
                score: 93,
                hashInput: [
                    'path' => $path,
                    'channel' => 'professional_companion_expansion',
                ],
            ) + [
                'scope' => 'review',
                'freshness' => 'current',
                'privacy' => 'provider_safe',
                'retrieval_channel' => 'professional_companion_expansion',
            ])
            ->values()
            ->all();
    }

    /**
     * @param  array<string,mixed>  $hashInput
     * @return array<string,mixed>
     */
    private function ref(string $source, string $ref, string $reason, int|float $score, array $hashInput): array
    {
        return [
            'source' => $source,
            'ref' => $ref,
            'reason' => $reason,
            'score' => $score,
            'hash' => hash('sha256', json_encode($hashInput, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) ?: $ref),
        ];
    }
}
