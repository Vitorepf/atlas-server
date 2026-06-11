<?php

declare(strict_types=1);

namespace App\Services\Ai\Context;

use App\Services\Ai\Mission\MissionCanonicalHash;
use Carbon\CarbonImmutable;

final class AtlasContextCacheCompilerRuntimeService
{
    public const SCHEMA_VERSION = 'atlas.context_cache.compiler.v1';

    public const MERKLE_PACK_SCHEMA = 'atlas.context_merkle_pack.v1';

    public const CACHE_WARMUP_SCHEMA = 'atlas.prompt_cache_warmup.v1';

    public const DELTA_REQUEST_SCHEMA = 'atlas.delta_context_request.v1';

    public const STATUS_READY = 'ready';

    public const STATUS_BLOCKED = 'blocked';

    /** @var list<string> */
    private const CACHEABLE_ZONES = ['system_contract_zone', 'governance_zone', 'tool_schema_zone', 'project_map_zone'];

    /**
     * @param  array<string,mixed>  $input
     * @return array<string,mixed>
     */
    public function warm(array $input = []): array
    {
        $flowId = (string) ($input['flow_id'] ?? 'atlas_dev');
        $provider = $this->provider((string) ($input['provider'] ?? 'gpt'));
        $workspace = (string) ($input['workspace'] ?? 'atlas');
        $nodes = $this->nodes($input);
        $merklePack = $this->merklePack($flowId, $provider, $workspace, $nodes);
        $warmup = $this->warmupReceipt($flowId, $provider, $merklePack, $input);
        $delta = $this->deltaRequest($flowId, $provider, $merklePack, $input);
        $drift = $this->prefixDrift($merklePack, $input);
        $blockers = [];
        if ($drift['drift_detected']) {
            $blockers[] = 'prompt_prefix_drift';
        }
        if ($warmup['cache_status'] === 'poisoned') {
            $blockers[] = 'cache_poisoned';
        }
        if ($this->mustKeepCoverage($nodes) < 1.0) {
            $blockers[] = 'must_keep_coverage_below_one';
        }

        $payload = [
            'schema_version' => self::SCHEMA_VERSION,
            'status' => $blockers === [] ? self::STATUS_READY : self::STATUS_BLOCKED,
            'generated_at' => CarbonImmutable::now()->toJSON(),
            'flow_id' => $flowId,
            'provider' => $provider,
            'workspace' => $workspace,
            'merkle_pack' => $merklePack,
            'warmup_receipt' => $warmup,
            'delta_request' => $delta,
            'prompt_prefix_drift' => $drift,
            'quality_contract' => [
                'must_keep_coverage' => $this->mustKeepCoverage($nodes),
                'cacheable_zone_count' => count(self::CACHEABLE_ZONES),
                'task_delta_mutable' => true,
                'raw_text_exposed' => false,
            ],
            'blockers' => $blockers,
            'claim_policy' => [
                'providers_invoked' => false,
                'writes' => false,
                'enforcement_applied' => false,
                'cache_hit_claimed_as_quality_gain' => false,
            ],
        ];
        $payload['context_cache_hash'] = MissionCanonicalHash::sha256(array_diff_key($payload, ['generated_at' => true]));

        return $payload;
    }

    /**
     * @param  array<string,mixed>  $input
     * @return list<array<string,mixed>>
     */
    private function nodes(array $input): array
    {
        $nodes = (array) ($input['nodes'] ?? []);
        if ($nodes === []) {
            $nodes = [
                ['zone' => 'system_contract_zone', 'kind' => 'system_contract', 'source_path' => 'AGENTS.md', 'authority_level' => 'projection', 'tokens' => 900, 'must_keep' => true],
                ['zone' => 'governance_zone', 'kind' => 'owner_doc', 'source_path' => 'docs/engineering-knowledge-base/atlas-ai-knowledge-governance-system.md', 'authority_level' => 'repo_doc', 'tokens' => 1600, 'must_keep' => true],
                ['zone' => 'tool_schema_zone', 'kind' => 'tool_contract', 'source_path' => 'app/Console/Commands', 'authority_level' => 'code', 'tokens' => 1200, 'must_keep' => true],
                ['zone' => 'project_map_zone', 'kind' => 'code_map', 'source_path' => 'engineering_code_intelligence', 'authority_level' => 'read_model', 'tokens' => 2400, 'must_keep' => true],
                ['zone' => 'task_delta_zone', 'kind' => 'task_delta', 'source_path' => 'operator_request', 'authority_level' => 'operator', 'tokens' => 800, 'must_keep' => true],
            ];
        }

        return array_values(array_map(fn (mixed $node, int $index): array => $this->node(is_array($node) ? $node : [], $index), $nodes, array_keys($nodes)));
    }

    /**
     * @param  array<string,mixed>  $node
     * @return array<string,mixed>
     */
    private function node(array $node, int $index): array
    {
        $zone = (string) ($node['zone'] ?? 'task_delta_zone');
        $kind = (string) ($node['kind'] ?? 'context');
        $sourcePath = (string) ($node['source_path'] ?? 'node:'.$index);
        $authority = (string) ($node['authority_level'] ?? 'repo_doc');
        $content = is_scalar($node['content'] ?? null) ? (string) $node['content'] : $sourcePath;
        $sourceHash = (string) ($node['source_hash'] ?? MissionCanonicalHash::sha256([$sourcePath, $content]));
        $nodePayload = [
            'node_id' => 'ctx-node-'.($index + 1),
            'zone' => $zone,
            'kind' => $kind,
            'authority_level' => $authority,
            'source_path' => $sourcePath,
            'source_hash' => $sourceHash,
            'content_hash' => MissionCanonicalHash::sha256($content),
            'tokens' => max(1, (int) ($node['tokens'] ?? 1000)),
            'must_keep' => (bool) ($node['must_keep'] ?? $zone !== 'task_delta_zone'),
            'freshness' => (string) ($node['freshness'] ?? 'fresh'),
            'redaction_status' => 'hashed',
            'depends_on' => AtlasContextStringListNormalizer::stringsFromArrayCast($node['depends_on'] ?? []),
            'cacheable' => in_array($zone, self::CACHEABLE_ZONES, true),
        ];
        $nodePayload['node_hash'] = MissionCanonicalHash::sha256($nodePayload);

        return $nodePayload;
    }

    /**
     * @param  list<array<string,mixed>>  $nodes
     * @return array<string,mixed>
     */
    private function merklePack(string $flowId, string $provider, string $workspace, array $nodes): array
    {
        $zones = [];
        foreach ($nodes as $node) {
            $zone = (string) $node['zone'];
            $zones[$zone][] = (string) $node['node_hash'];
        }
        $zoneHashes = [];
        foreach ($zones as $zone => $hashes) {
            $zoneHashes[$zone] = MissionCanonicalHash::sha256([$zone, $hashes]);
        }
        $cacheablePrefixHash = MissionCanonicalHash::sha256(array_intersect_key($zoneHashes, array_flip(self::CACHEABLE_ZONES)));
        $pack = [
            'schema_version' => self::MERKLE_PACK_SCHEMA,
            'flow_id' => $flowId,
            'provider' => $provider,
            'workspace' => $workspace,
            'nodes' => $nodes,
            'zone_hashes' => $zoneHashes,
            'cacheable_prefix_hash' => $cacheablePrefixHash,
            'task_delta_hash' => $zoneHashes['task_delta_zone'] ?? '',
        ];
        $pack['context_pack_hash'] = MissionCanonicalHash::sha256($pack);

        return $pack;
    }

    /**
     * @param  array<string,mixed>  $merklePack
     * @param  array<string,mixed>  $input
     * @return array<string,mixed>
     */
    private function warmupReceipt(string $flowId, string $provider, array $merklePack, array $input): array
    {
        $previousPrefixHash = (string) ($input['previous_prefix_hash'] ?? '');
        $cacheFresh = (bool) ($input['cache_fresh'] ?? true);
        $poisoned = (bool) ($input['cache_poisoned'] ?? false);
        $prefixHash = (string) $merklePack['cacheable_prefix_hash'];
        $status = match (true) {
            $poisoned => 'poisoned',
            $previousPrefixHash !== '' && ! hash_equals($previousPrefixHash, $prefixHash) => 'stale',
            $previousPrefixHash !== '' && $cacheFresh => 'hit',
            default => 'warm',
        };
        $receipt = [
            'schema_version' => self::CACHE_WARMUP_SCHEMA,
            'flow_id' => $flowId,
            'provider' => $provider,
            'cache_status' => $status,
            'cacheable_prefix_hash' => $prefixHash,
            'context_pack_hash' => (string) $merklePack['context_pack_hash'],
            'estimated_cacheable_tokens' => $this->tokenSum($merklePack['nodes'], true),
            'estimated_delta_tokens' => $this->tokenSum($merklePack['nodes'], false),
            'freshness_required' => true,
            'freshness_passed' => $cacheFresh && ! $poisoned,
        ];
        $receipt['receipt_hash'] = MissionCanonicalHash::sha256($receipt);

        return $receipt;
    }

    /**
     * @param  array<string,mixed>  $merklePack
     * @param  array<string,mixed>  $input
     * @return array<string,mixed>
     */
    private function deltaRequest(string $flowId, string $provider, array $merklePack, array $input): array
    {
        $taskNodes = array_values(array_filter($merklePack['nodes'], static fn (array $node): bool => (string) $node['zone'] === 'task_delta_zone'));
        $receipt = [
            'schema_version' => self::DELTA_REQUEST_SCHEMA,
            'flow_id' => $flowId,
            'provider' => $provider,
            'context_pack_hash' => (string) $merklePack['context_pack_hash'],
            'cacheable_prefix_hash' => (string) $merklePack['cacheable_prefix_hash'],
            'delta_node_hashes' => array_values(array_map(static fn (array $node): string => (string) $node['node_hash'], $taskNodes)),
            'changed_file_refs' => AtlasContextStringListNormalizer::stringsFromArrayCast($input['changed_file_refs'] ?? []),
            'evidence_refs' => AtlasContextStringListNormalizer::stringsFromArrayCast($input['evidence_refs'] ?? []),
            'raw_text_exposed' => false,
        ];
        $receipt['delta_hash'] = MissionCanonicalHash::sha256($receipt);

        return $receipt;
    }

    /**
     * @param  array<string,mixed>  $merklePack
     * @param  array<string,mixed>  $input
     * @return array<string,mixed>
     */
    private function prefixDrift(array $merklePack, array $input): array
    {
        $expected = (string) ($input['expected_prefix_hash'] ?? '');
        $actual = (string) $merklePack['cacheable_prefix_hash'];

        return [
            'expected_prefix_hash' => $expected,
            'actual_prefix_hash' => $actual,
            'drift_detected' => $expected !== '' && ! hash_equals($expected, $actual),
        ];
    }

    /**
     * @param  list<array<string,mixed>>  $nodes
     */
    private function mustKeepCoverage(array $nodes): float
    {
        $mustKeep = array_values(array_filter($nodes, static fn (array $node): bool => (bool) $node['must_keep']));
        if ($mustKeep === []) {
            return 1.0;
        }

        $fresh = array_filter($mustKeep, static fn (array $node): bool => (string) $node['freshness'] === 'fresh');

        return round(count($fresh) / count($mustKeep), 4);
    }

    private function tokenSum(mixed $nodes, bool $cacheable): int
    {
        return array_sum(array_map(static fn (array $node): int => (bool) $node['cacheable'] === $cacheable ? (int) $node['tokens'] : 0, is_array($nodes) ? $nodes : []));
    }

    private function provider(string $provider): string
    {
        return in_array($provider, ['claude', 'gpt', 'gemini', 'local'], true) ? $provider : 'gpt';
    }
}
