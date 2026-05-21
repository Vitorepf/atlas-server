<?php

declare(strict_types=1);

namespace App\Services\Ai\Context;

use App\Services\Ai\AutonomousEngineering\WorldModel\WorldModelGraphRanker;
use App\Services\Ai\AutonomousEngineering\WorldModel\WorldModelRankingQuery;
use App\Services\Ai\Mission\MissionCanonicalHash;
use Illuminate\Support\Carbon;

final class AtlasGraphRetrievalNetworkService
{
    public const SCHEMA_VERSION = 'atlas.aucri.graph_retrieval_network.v1';

    public const GRAPH_QUERY_SCHEMA = 'atlas.aucri.graph_query.v1';

    public const GRAPH_EVIDENCE_SET_SCHEMA = 'atlas.aucri.graph_evidence_set.v1';

    public const GRAPH_TRAVERSAL_RECEIPT_SCHEMA = 'atlas.aucri.graph_traversal_receipt.v1';

    public function __construct(private readonly WorldModelGraphRanker $worldModelGraphRanker) {}

    /**
     * @param  array<string,mixed>  $input
     * @return array<string,mixed>
     */
    public function retrieve(array $input): array
    {
        $risk = (string) ($input['risk_level'] ?? $input['risk'] ?? 'low');
        $maxResults = max(1, min(30, (int) ($input['max_results'] ?? $input['max_refs'] ?? 8)));
        $query = $this->query($input, $risk, $maxResults);
        $ranking = $this->worldModelGraphRanker->rank(WorldModelRankingQuery::fromArray($this->rankerQueryPayload($input, $risk, $maxResults)));
        $evidence = $this->evidenceSet($ranking, $maxResults);
        $receipt = $this->traversalReceipt($query, $ranking, $evidence, $risk);
        $status = $this->status($evidence, $receipt, $risk);

        $payload = [
            'schema_version' => self::SCHEMA_VERSION,
            'status' => $status,
            'generated_at' => Carbon::now()->toIso8601String(),
            'graph_query' => $query,
            'graph_evidence_set' => $evidence,
            'graph_traversal_receipt' => $receipt,
            'policy' => [
                'bounded_traversal_only' => true,
                'global_graph_retrieval_active' => false,
                'external_graph_runtime_invoked' => false,
                'python_runtime_invoked' => false,
                'providers_invoked' => false,
                'writes' => false,
                'raw_query_exposed' => false,
                'promotion_requires_ap_review' => true,
            ],
            'claims' => [
                'global_graph_rag_ready' => false,
                'bounded_world_model_retrieval_ready' => $status === 'ready',
                'providers_invoked' => false,
                'writes' => false,
                'benchmark_run' => false,
            ],
        ];

        $hashPayload = $payload;
        unset($hashPayload['generated_at']);
        $payload['graph_retrieval_hash'] = MissionCanonicalHash::sha256($hashPayload);

        return $payload;
    }

    /**
     * @param  array<string,mixed>  $input
     * @return array<string,mixed>
     */
    private function query(array $input, string $risk, int $maxResults): array
    {
        $seeds = $this->seedTerms($input);
        $targetFiles = $this->cleanList($input['target_files'] ?? []);
        $targetFlows = $this->cleanList($input['target_flows'] ?? []);
        $targetCapabilities = $this->cleanList($input['target_capabilities'] ?? []);
        $targetRisks = $this->cleanList($input['target_risks'] ?? []);

        return [
            'schema_version' => self::GRAPH_QUERY_SCHEMA,
            'query_hash' => MissionCanonicalHash::sha256([
                'seeds' => $seeds,
                'target_files' => $targetFiles,
                'target_flows' => $targetFlows,
                'target_capabilities' => $targetCapabilities,
                'target_risks' => $targetRisks,
                'risk' => $risk,
                'max_results' => $maxResults,
            ]),
            'seed_count' => count($seeds),
            'seed_hashes' => array_map(static fn (string $seed): string => MissionCanonicalHash::sha256($seed), $seeds),
            'target_file_hashes' => array_map(static fn (string $path): string => MissionCanonicalHash::sha256($path), $targetFiles),
            'target_flows' => $targetFlows,
            'target_capabilities' => $targetCapabilities,
            'target_risks' => $targetRisks,
            'risk_level' => $risk,
            'max_results' => $maxResults,
            'graph_scope' => 'codebase_world_model_bounded',
        ];
    }

    /**
     * @param  array<string,mixed>  $input
     * @return array<string,mixed>
     */
    private function rankerQueryPayload(array $input, string $risk, int $maxResults): array
    {
        return [
            'textual_seeds' => $this->seedTerms($input),
            'target_files' => $this->cleanList($input['target_files'] ?? []),
            'target_flows' => $this->cleanList($input['target_flows'] ?? []),
            'target_capabilities' => $this->cleanList($input['target_capabilities'] ?? []),
            'target_risks' => $this->cleanList($input['target_risks'] ?? []),
            'task_risk_level' => $this->riskForRanker($risk),
            'boost_docs' => (bool) ($input['boost_docs'] ?? true),
            'boost_tests' => (bool) ($input['boost_tests'] ?? in_array((string) ($input['task_type'] ?? ''), ['debug', 'review', 'quality_repair'], true)),
            'world_model_id' => $this->optionalString($input['world_model_id'] ?? null),
            'max_results' => $maxResults,
        ];
    }

    /**
     * @param  array<string,mixed>  $ranking
     * @return array<string,mixed>
     */
    private function evidenceSet(array $ranking, int $maxResults): array
    {
        $nodes = array_slice((array) ($ranking['ranked_nodes'] ?? []), 0, $maxResults);
        $sources = array_slice((array) ($ranking['ranked_sources'] ?? []), 0, $maxResults);
        $evidence = array_values(array_map(fn (array $node): array => $this->evidenceFromNode($node), $nodes));

        return [
            'schema_version' => self::GRAPH_EVIDENCE_SET_SCHEMA,
            'status' => $evidence === [] ? 'empty' : 'ready',
            'world_model_id' => (string) ($ranking['world_model_id'] ?? ''),
            'graph_version_hash' => (string) ($ranking['graph_version'] ?? ''),
            'graph_hash' => (string) ($ranking['graph_hash'] ?? ''),
            'node_count' => (int) ($ranking['node_count'] ?? 0),
            'edge_count' => (int) ($ranking['edge_count'] ?? 0),
            'evidence_count' => count($evidence),
            'source_count' => count($sources),
            'evidence' => $evidence,
            'sources' => array_values(array_map(static fn (array $source): array => [
                'path' => (string) ($source['path'] ?? ''),
                'path_hash' => MissionCanonicalHash::sha256((string) ($source['path'] ?? '')),
                'source_kind' => (string) ($source['source_kind'] ?? 'unknown'),
                'top_score' => round((float) ($source['top_score'] ?? 0.0), 4),
                'top_confidence' => round((float) ($source['top_confidence'] ?? 0.0), 4),
                'node_count' => (int) ($source['node_count'] ?? 0),
                'reasons' => array_values((array) ($source['reasons'] ?? [])),
            ], $sources)),
        ];
    }

    /**
     * @param  array<string,mixed>  $node
     * @return array<string,mixed>
     */
    private function evidenceFromNode(array $node): array
    {
        $relationPath = array_values(array_map(static fn (array $edge): array => [
            'from_hash' => MissionCanonicalHash::sha256((string) ($edge['from'] ?? '')),
            'to_hash' => MissionCanonicalHash::sha256((string) ($edge['to'] ?? '')),
            'edge_type' => (string) ($edge['edge_type'] ?? 'unknown'),
            'direction' => (string) ($edge['direction'] ?? 'unknown'),
        ], (array) ($node['relation_path'] ?? [])));

        return [
            'node_id_hash' => MissionCanonicalHash::sha256((string) ($node['node_id'] ?? '')),
            'node_type' => (string) ($node['node_type'] ?? 'unknown'),
            'path' => (string) ($node['path'] ?? ''),
            'path_hash' => MissionCanonicalHash::sha256((string) ($node['path'] ?? '')),
            'flow_id' => (string) ($node['flow_id'] ?? ''),
            'score' => round((float) ($node['score'] ?? 0.0), 4),
            'confidence' => round((float) ($node['confidence'] ?? 0.0), 4),
            'text_score' => round((float) ($node['text_score'] ?? 0.0), 4),
            'graph_score' => round((float) ($node['graph_score'] ?? 0.0), 4),
            'reasons' => array_values((array) ($node['reasons'] ?? [])),
            'relation_path' => $relationPath,
            'relation_count' => count($relationPath),
        ];
    }

    /**
     * @param  array<string,mixed>  $query
     * @param  array<string,mixed>  $ranking
     * @param  array<string,mixed>  $evidence
     * @return array<string,mixed>
     */
    private function traversalReceipt(array $query, array $ranking, array $evidence, string $risk): array
    {
        $fallbackReason = data_get($ranking, 'metrics.fallback_reason');
        $edgeTypes = array_values((array) data_get($ranking, 'metrics.edge_types_used', []));

        return [
            'schema_version' => self::GRAPH_TRAVERSAL_RECEIPT_SCHEMA,
            'status' => $fallbackReason !== null ? 'blocked' : ($evidence['status'] === 'ready' ? 'passed' : 'degraded'),
            'query_hash' => (string) ($query['query_hash'] ?? ''),
            'world_model_id' => (string) ($ranking['world_model_id'] ?? ''),
            'query_signature' => (string) ($ranking['query_signature'] ?? ''),
            'ranker_result_hash' => (string) ($ranking['result_hash'] ?? ''),
            'bounded_traversal' => true,
            'max_depth' => 1,
            'max_results' => (int) ($query['max_results'] ?? 0),
            'edge_types_used' => $edgeTypes,
            'graph_changed_top' => (bool) data_get($ranking, 'metrics.graph_changed_top', false),
            'fallback_reason' => $fallbackReason,
            'risk_level' => $risk,
            'promotion' => [
                'global_graph_active' => false,
                'requires_ap_review' => true,
                'requires_privacy_review' => true,
                'requires_rollback_plan' => true,
                'requires_golden_set' => true,
            ],
        ];
    }

    /**
     * @param  array<string,mixed>  $evidence
     * @param  array<string,mixed>  $receipt
     */
    private function status(array $evidence, array $receipt, string $risk): string
    {
        if ((string) ($receipt['status'] ?? '') === 'blocked') {
            return in_array($risk, ['high', 'critical', 'irreversible'], true) ? 'blocked' : 'degraded';
        }

        return (string) ($evidence['status'] ?? '') === 'ready' ? 'ready' : 'degraded';
    }

    /**
     * @param  array<string,mixed>  $input
     * @return array<int,string>
     */
    private function seedTerms(array $input): array
    {
        $raw = trim((string) ($input['objective'] ?? $input['prompt'] ?? $input['query'] ?? ''));
        $terms = $this->cleanList($input['seeds'] ?? []);

        foreach (preg_split('/[^A-Za-z0-9_\\/.:-]+/', strtolower($raw)) ?: [] as $term) {
            $term = trim($term);
            if ($term === '' || strlen($term) < 3) {
                continue;
            }
            $terms[] = $term;
        }

        return array_values(array_slice(array_unique($terms), 0, 20));
    }

    /**
     * @return array<int,string>
     */
    private function cleanList(mixed $value): array
    {
        if (! is_array($value)) {
            return [];
        }

        return array_values(array_unique(array_filter(array_map(
            static fn (mixed $item): string => is_scalar($item) ? strtolower(trim((string) $item)) : '',
            $value,
        ))));
    }

    private function riskForRanker(string $risk): string
    {
        return match ($risk) {
            'critical', 'irreversible' => 'critical',
            'high' => 'high',
            'medium' => 'medium',
            default => 'low',
        };
    }

    private function optionalString(mixed $value): ?string
    {
        if (! is_scalar($value)) {
            return null;
        }

        $value = trim((string) $value);

        return $value === '' ? null : $value;
    }
}
