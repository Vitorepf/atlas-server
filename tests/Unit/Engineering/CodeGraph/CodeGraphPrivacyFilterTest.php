<?php

namespace Tests\Unit\Engineering\CodeGraph;

use App\Services\Engineering\CodeGraph\CodeGraphPrivacyFilter;
use PHPUnit\Framework\TestCase;

class CodeGraphPrivacyFilterTest extends TestCase
{
    /**
     * @param  array<string,mixed>  $extra
     * @return array<string,mixed>
     */
    private function node(string $id, ?string $path = null, array $extra = []): array
    {
        return array_merge([
            'node_id' => $id,
            'path' => $path ?? $id,
        ], $extra);
    }

    /**
     * @return array<string,mixed>
     */
    private function edge(string $from, string $to, string $type = 'depends_on'): array
    {
        return ['from_node_id' => $from, 'to_node_id' => $to, 'edge_type' => $type];
    }

    public function test_sensitive_node_is_removed_and_its_edges_dropped(): void
    {
        $graph = [
            'schema_version' => 'atlas.code_graph.unified.v1',
            'nodes' => [
                $this->node('node:app/Services/Router', 'app/Services/Router.php'),
                $this->node('node:.env', '.env'),
                $this->node('node:config/secret', 'config/secret.php'),
            ],
            'edges' => [
                // public -> sensitive : must be dropped (touches a removed node).
                $this->edge('node:app/Services/Router', 'node:.env'),
                // sensitive -> sensitive : dropped.
                $this->edge('node:.env', 'node:config/secret'),
            ],
        ];

        $result = (new CodeGraphPrivacyFilter)->filter($graph, [], []);

        $ids = array_column($result['nodes'], 'node_id');
        $this->assertSame(['node:app/Services/Router'], $ids, 'sensitive nodes (.env + secret) must be gone');
        $this->assertSame([], $result['edges'], 'every edge touched a removed node, so all are dropped');
        $this->assertSame(2, $result['redaction']['removed_nodes']);
        $this->assertSame(2, $result['redaction']['removed_edges']);
    }

    public function test_tombstoned_node_is_removed_and_its_edges_dropped(): void
    {
        $graph = [
            'nodes' => [
                $this->node('node:keep', 'app/Keep.php'),
                $this->node('node:dead', 'app/Dead.php'),
                $this->node('node:other', 'app/Other.php'),
            ],
            'edges' => [
                $this->edge('node:keep', 'node:dead'),    // dropped: to is tombstoned
                $this->edge('node:dead', 'node:other'),   // dropped: from is tombstoned
                $this->edge('node:keep', 'node:other'),   // kept: neither removed
            ],
        ];

        $result = (new CodeGraphPrivacyFilter)->filter($graph, [], ['node:dead']);

        $ids = array_column($result['nodes'], 'node_id');
        $this->assertSame(['node:keep', 'node:other'], $ids);
        $this->assertCount(1, $result['edges'], 'only the keep->other edge survives');
        $this->assertSame($this->edge('node:keep', 'node:other'), $result['edges'][0]);
        $this->assertSame(1, $result['redaction']['removed_nodes']);
        $this->assertSame(2, $result['redaction']['removed_edges']);
    }

    public function test_caller_supplied_sensitive_path_is_removed_case_insensitively(): void
    {
        $graph = [
            'nodes' => [
                $this->node('node:public', 'app/Public.php'),
                // No default marker matches this path; it is sensitive only because
                // the operator pinned it. Match must be case-insensitive.
                $this->node('node:vault', 'storage/Vault/Operator.php'),
            ],
            'edges' => [
                $this->edge('node:public', 'node:vault'),
            ],
        ];

        $result = (new CodeGraphPrivacyFilter)->filter($graph, ['storage/vault/operator.php'], []);

        $ids = array_column($result['nodes'], 'node_id');
        $this->assertSame(['node:public'], $ids);
        $this->assertSame([], $result['edges']);
        $this->assertSame(1, $result['redaction']['removed_nodes']);
        $this->assertSame(1, $result['redaction']['removed_edges']);
    }

    public function test_public_nodes_and_edges_are_kept_untouched(): void
    {
        $graph = [
            'schema_version' => 'atlas.code_graph.unified.v1',
            'stats' => ['files' => 3],
            'nodes' => [
                $this->node('node:a', 'app/A.php'),
                $this->node('node:b', 'app/B.php'),
                $this->node('node:c', 'app/C.php'),
            ],
            'edges' => [
                $this->edge('node:a', 'node:b'),
                $this->edge('node:b', 'node:c'),
            ],
        ];

        $result = (new CodeGraphPrivacyFilter)->filter($graph, [], []);

        $this->assertSame(3, count($result['nodes']), 'no node is sensitive/tombstoned');
        $this->assertSame(2, count($result['edges']));
        $this->assertSame(0, $result['redaction']['removed_nodes']);
        $this->assertSame(0, $result['redaction']['removed_edges']);
        // Passthrough keys preserved.
        $this->assertSame('atlas.code_graph.unified.v1', $result['schema_version']);
        $this->assertSame(['files' => 3], $result['stats']);
    }

    public function test_sensitive_match_on_node_id_when_path_absent(): void
    {
        // Some graph results carry only node_id (path-derived) and no `path` key.
        $graph = [
            'nodes' => [
                ['node_id' => 'node:app/Http/Controller'],
                ['node_id' => 'node:.env.production'],
            ],
            'edges' => [
                $this->edge('node:app/Http/Controller', 'node:.env.production'),
            ],
        ];

        $result = (new CodeGraphPrivacyFilter)->filter($graph, [], []);

        $ids = array_column($result['nodes'], 'node_id');
        $this->assertSame(['node:app/Http/Controller'], $ids);
        $this->assertSame([], $result['edges']);
        $this->assertSame(1, $result['redaction']['removed_nodes']);
    }

    public function test_redaction_and_privacy_audit_block_are_reported(): void
    {
        $graph = [
            'nodes' => [
                $this->node('node:keep', 'app/Keep.php'),
                $this->node('node:secret-store', 'app/SecretStore.php'),
                $this->node('node:gone', 'app/Gone.php'),
            ],
            'edges' => [
                $this->edge('node:keep', 'node:secret-store'),
                $this->edge('node:keep', 'node:gone'),
            ],
        ];

        $result = (new CodeGraphPrivacyFilter)->filter($graph, [], ['node:gone']);

        $this->assertSame(2, $result['redaction']['removed_nodes']);
        $this->assertSame(2, $result['redaction']['removed_edges']);

        $privacy = $result['privacy'];
        $this->assertSame(CodeGraphPrivacyFilter::SCHEMA, $privacy['filter']);
        $this->assertTrue($privacy['sensitive_filtered']);
        $this->assertContains('app/SecretStore.php', $privacy['removed_sensitive_paths']);
        $this->assertSame(['node:gone'], $privacy['removed_tombstoned_node_ids']);
        $this->assertSame(2, $privacy['removed_node_count']);
        $this->assertSame(2, $privacy['removed_edge_count']);
    }

    public function test_is_pure_and_deterministic(): void
    {
        $graph = [
            'nodes' => [
                $this->node('node:a', 'app/A.php'),
                $this->node('node:env', '.env.local'),
                $this->node('node:b', 'app/B.php'),
                $this->node('node:dead', 'app/Dead.php'),
            ],
            'edges' => [
                $this->edge('node:a', 'node:env'),
                $this->edge('node:a', 'node:b'),
                $this->edge('node:b', 'node:dead'),
            ],
        ];
        $frozen = $graph;

        $first = (new CodeGraphPrivacyFilter)->filter($graph, ['app/dead.php'], ['node:dead']);
        $second = (new CodeGraphPrivacyFilter)->filter($graph, ['app/dead.php'], ['node:dead']);

        // Determinism: identical input -> byte-identical output.
        $this->assertSame($first, $second);
        // Purity: input is not mutated.
        $this->assertSame($frozen, $graph);
        // Surviving order is preserved (a, b).
        $this->assertSame(['node:a', 'node:b'], array_column($first['nodes'], 'node_id'));
        $this->assertSame([$this->edge('node:a', 'node:b')], $first['edges']);
    }

    public function test_missing_nodes_and_edges_keys_degrade_safely(): void
    {
        $result = (new CodeGraphPrivacyFilter)->filter([], [], []);

        $this->assertSame([], $result['nodes']);
        $this->assertSame([], $result['edges']);
        $this->assertSame(0, $result['redaction']['removed_nodes']);
        $this->assertSame(0, $result['redaction']['removed_edges']);
    }
}
