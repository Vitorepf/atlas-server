<?php

declare(strict_types=1);

namespace App\Services\Ai\Context;

use App\Services\Ai\RuntimeBoundary\SemanticRetrievalRuntime;
use Illuminate\Support\Carbon;

/**
 * L4-11 measurement layer over the existing L3-6 semantic context retriever.
 *
 * It does not implement a new retriever. It temporarily enables the existing
 * AOBG semantic flag for measurement, compares semantic recall@1 against the
 * lexical baseline on ten fixed provider-safe Atlas queries, then restores the
 * caller's config value.
 */
final class AobgSemanticRetrievalLiftService
{
    public const SCHEMA_VERSION = 'atlas.aobg.semantic_retrieval_lift.v1';

    public const CASE_COUNT = 10;

    public function __construct(
        private readonly SemanticContextRetrievalService $retrieval,
        private readonly SemanticRetrievalRuntime $runtime,
    ) {}

    /**
     * @return array<string,mixed>
     */
    public function report(): array
    {
        $previousFlag = (bool) config('atlas.aobg.semantic_retrieval', false);
        $runtimeAvailable = $this->runtime->available();
        $cases = [];

        try {
            config(['atlas.aobg.semantic_retrieval' => true]);
            foreach ($this->cases() as $case) {
                $cases[] = $this->measureCase($case);
            }
        } finally {
            config(['atlas.aobg.semantic_retrieval' => $previousFlag]);
        }

        $measured = array_values(array_filter(
            $cases,
            static fn (array $case): bool => ($case['status'] ?? null) === 'measured',
        ));
        $positive = array_values(array_filter(
            $measured,
            static fn (array $case): bool => (float) ($case['lift'] ?? 0.0) > 0.0,
        ));
        $averageLift = $this->average(array_column($measured, 'lift'));
        $semanticRecall = $this->average(array_column($measured, 'semantic_recall_at_k'));
        $lexicalRecall = $this->average(array_column($measured, 'lexical_recall_at_k'));
        $shouldEnable = count($measured) === self::CASE_COUNT && $averageLift > 0.0;

        return [
            'schema_version' => self::SCHEMA_VERSION,
            'status' => count($measured) === 0
                ? 'unmeasured'
                : ($shouldEnable ? 'positive_lift' : 'no_positive_lift'),
            'generated_at' => Carbon::now()->toIso8601String(),
            'decision' => [
                'env_var' => 'ATLAS_AOBG_SEMANTIC_RETRIEVAL',
                'current_config_enabled' => $previousFlag,
                'should_enable' => $shouldEnable,
                'decision' => $shouldEnable ? 'enable_aobg_semantic_retrieval' : 'keep_current_or_disabled',
                'reason' => count($measured) === 0
                    ? 'semantic_runtime_unmeasured'
                    : ($shouldEnable ? 'average_lift_positive_across_10_measured_cases' : 'average_lift_not_positive'),
            ],
            'measurement' => [
                'case_count' => self::CASE_COUNT,
                'measured_case_count' => count($measured),
                'positive_lift_case_count' => count($positive),
                'k' => 1,
                'average_lift' => $averageLift,
                'average_semantic_recall_at_k' => $semanticRecall,
                'average_lexical_recall_at_k' => $lexicalRecall,
                'runtime_available' => $runtimeAvailable,
                'temporary_flag_enabled_for_measurement' => true,
                'case_set' => 'fable_l4_11_aobg_context_queries_v1',
            ],
            'cases' => $cases,
            'claim_policy' => [
                'read_only' => true,
                'provider_calls_made' => false,
                'external_vector_store_used' => false,
                'local_python_runtime_invoked' => count($measured) > 0,
                'raw_sensitive_context_persisted' => false,
                'semantic_enabled_only_when_lift_positive' => true,
            ],
            'commands_next' => [
                'measure' => 'php artisan atlas:aobg:semantic-lift --json',
                'activate_when_positive' => 'set ATLAS_AOBG_SEMANTIC_RETRIEVAL=true in .env',
                'verify_context_pack_mode' => 'php artisan atlas:context-pack "sign-in credential verification" --json',
            ],
        ];
    }

    /**
     * @param  array<string,mixed>  $case
     * @return array<string,mixed>
     */
    private function measureCase(array $case): array
    {
        $lift = $this->retrieval->relevanceLift(
            query: (string) $case['query'],
            items: (array) $case['items'],
            relevantIds: (array) $case['relevant_ids'],
            k: 1,
        );

        if ($lift === null) {
            return [
                'case_id' => (string) $case['case_id'],
                'query_label' => (string) $case['query_label'],
                'query_hash' => hash('sha256', (string) $case['query']),
                'status' => 'unmeasured',
                'mode' => 'unavailable',
                'relevant_ids' => array_values((array) $case['relevant_ids']),
                'candidate_count' => count((array) $case['items']),
            ];
        }

        return [
            'case_id' => (string) $case['case_id'],
            'query_label' => (string) $case['query_label'],
            'query_hash' => hash('sha256', (string) $case['query']),
            'status' => 'measured',
            'mode' => (string) ($lift['mode'] ?? 'unknown'),
            'semantic_recall_at_k' => (float) $lift['semantic_recall_at_k'],
            'lexical_recall_at_k' => (float) $lift['lexical_recall_at_k'],
            'lift' => (float) $lift['lift'],
            'relevant_ids' => array_values((array) $case['relevant_ids']),
            'candidate_count' => count((array) $case['items']),
        ];
    }

    /**
     * @param  array<int,mixed>  $values
     */
    private function average(array $values): float
    {
        $numbers = array_values(array_filter($values, 'is_numeric'));
        if ($numbers === []) {
            return 0.0;
        }

        return round(array_sum(array_map('floatval', $numbers)) / count($numbers), 4);
    }

    /**
     * @return list<array{case_id:string,query_label:string,query:string,relevant_ids:list<string>,items:list<array{id:string,text:string}>}>
     */
    private function cases(): array
    {
        return [
            $this->case('auth', 'Sign-in credential verification', 'sign-in credential verification',
                'z_auth_relevant', 'authenticate the operator and issue a signed access token for the session',
                'a_auth_lexical_decoy', 'seasonal revenue forecast for harvest planning',
                'm_auth_other', 'procurement invoices grouped by supplier and month'),
            $this->case('drift', 'Restart worker after source changes', 'daemon reload after patch',
                'z_drift_relevant', 'supervisor exits on code drift so keepalive relaunches the fresh process',
                'a_drift_lexical_decoy', 'quarterly procurement invoices grouped by supplier',
                'm_drift_other', 'semantic vectors reorder context memory by cosine similarity'),
            $this->case('digest', 'Morning autonomous activity summary', 'yesterday automation recap',
                'z_digest_relevant', 'daily digest summarizes loop merges canaries costs keepalive and pending reviews',
                'a_digest_lexical_decoy', 'provider topology prepares dispatch decisions without execution',
                'm_digest_other', 'attachment index tables remain outside the current local scope'),
            $this->case('review', 'Blocked operator review queue', 'human approval inbox',
                'z_review_relevant', 'parked_for_operator_review proposals wait for approve or reject decisions',
                'a_review_lexical_decoy', 'runtime dispatch prepares provider topology without a call',
                'm_review_other', 'daily feeder converts residual signals into backlog intents'),
            $this->case('spend', 'Provider spend proof gate', 'paid inference audit',
                'z_spend_relevant', 'real provider invocation requires cost confirmation dispatch confirmation and receipt evidence',
                'a_spend_lexical_decoy', 'seasonal revenue forecast for facilities planning',
                'm_spend_other', 'database trigger protects governed main writes'),
            $this->case('lift', 'Semantic retrieval evidence lift', 'meaning match beats keywords',
                'z_lift_relevant', 'relevanceLift compares semantic recall at k against the lexical baseline',
                'a_lift_lexical_decoy', 'procurement invoices grouped by supplier and calendar month',
                'm_lift_other', 'Forge scheduler assigns workers to non overlapping files'),
            $this->case('merge', 'Never merge safety valve', 'unsafe branch guard',
                'z_merge_relevant', 'database trigger permits merged_to_main only under the governed merge session flag',
                'a_merge_lexical_decoy', 'context pack memory can be reranked by vectors',
                'm_merge_other', 'daily digest summarizes loop activity and canary status'),
            $this->case('receipt', 'Impact receipt quality value', 'change value audit',
                'z_receipt_relevant', 'impact receipt categorizes each merge by category target kind and score',
                'a_receipt_lexical_decoy', 'semantic runtime embeds local documents inside Python',
                'm_receipt_other', 'operator review proposals wait for approval or rejection'),
            $this->case('pack', 'Context pack memory rerank', 'remembered notes priority',
                'z_pack_relevant', 'AOBG context pack reorders recalled memory by local semantic vector scores',
                'a_pack_lexical_decoy', 'governed merge trigger protects main branch writes',
                'm_pack_other', 'provider invocation requires cost and dispatch confirmation'),
            $this->case('backlog', 'Backlog auto feed residual signals', 'future work seeds',
                'z_backlog_relevant', 'daily feeder converts loss observer scorecard and residual sweep signals into backlog intents',
                'a_backlog_lexical_decoy', 'context pack memory can be reranked by vectors',
                'm_backlog_other', 'real provider invocation requires receipt evidence'),
        ];
    }

    private function case(
        string $id,
        string $label,
        string $query,
        string $relevantId,
        string $relevantText,
        string $decoyId,
        string $decoyText,
        string $otherId,
        string $otherText,
    ): array {
        return [
            'case_id' => 'l4_11_'.$id,
            'query_label' => $label,
            'query' => $query,
            'relevant_ids' => [$relevantId],
            'items' => [
                ['id' => $relevantId, 'text' => $relevantText],
                ['id' => $decoyId, 'text' => $decoyText],
                ['id' => $otherId, 'text' => $otherText],
            ],
        ];
    }
}
