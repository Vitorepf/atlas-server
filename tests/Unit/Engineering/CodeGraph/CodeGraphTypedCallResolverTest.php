<?php

namespace Tests\Unit\Engineering\CodeGraph;

use App\Services\Engineering\CodeGraph\CodeGraphTypedCallResolver;
use PHPUnit\Framework\TestCase;

class CodeGraphTypedCallResolverTest extends TestCase
{
    /**
     * @param  mixed  $receiver  "this"|"self"|"static"|"parent"|"unknown"|array{static_class:string}
     * @return array{path:string, caller_class:string, caller_method:string, callee_name:string, receiver:mixed}
     */
    private function call(string $callerClass, string $callerMethod, string $calleeName, mixed $receiver, string $path = 'app/A.php'): array
    {
        return [
            'path' => $path,
            'caller_class' => $callerClass,
            'caller_method' => $callerMethod,
            'callee_name' => $calleeName,
            'receiver' => $receiver,
        ];
    }

    public function test_this_receiver_resolves_to_caller_class_method_extracted(): void
    {
        $r = (new CodeGraphTypedCallResolver)->resolve(
            [$this->call('App\\A', 'run', 'helper', 'this')],
            [],
            ['App\\A' => ['parent' => null, 'methods' => ['run', 'helper'], 'namespace' => 'App']],
        );

        $this->assertSame(CodeGraphTypedCallResolver::SCHEMA, $r['schema_version']);
        $this->assertSame('atlas.code_graph.typed_call_edges.v1', $r['schema_version']);
        $this->assertCount(1, $r['edges']);

        $edge = $r['edges'][0];
        $this->assertSame('sym:App\\A::run', $edge['from_node_id']);
        $this->assertSame('sym:App\\A::helper', $edge['to_node_id']);
        $this->assertSame('calls', $edge['edge_type']);
        $this->assertSame('EXTRACTED', $edge['confidence']);
        $this->assertSame(1.0, $edge['confidence_score']);
        $this->assertSame('helper', $edge['metadata']['callee_name']);
        $this->assertSame('this', $edge['metadata']['receiver']);
        $this->assertFalse($edge['metadata']['inferred']);
        // helper IS declared on App\A -> not inherited (no inherited key)
        $this->assertArrayNotHasKey('inherited', $edge['metadata']);

        $this->assertSame(1, $r['stats']['calls']);
        $this->assertSame(1, $r['stats']['edges']);
        $this->assertSame(1, $r['stats']['extracted']);
    }

    public function test_self_and_static_receivers_also_resolve_to_caller_class(): void
    {
        $classIndex = ['App\\A' => ['parent' => null, 'methods' => ['run', 'helper'], 'namespace' => 'App']];

        foreach (['self', 'static'] as $receiver) {
            $r = (new CodeGraphTypedCallResolver)->resolve(
                [$this->call('App\\A', 'run', 'helper', $receiver)],
                [],
                $classIndex,
            );
            $this->assertCount(1, $r['edges'], "receiver={$receiver}");
            $this->assertSame('sym:App\\A::helper', $r['edges'][0]['to_node_id'], "receiver={$receiver}");
            $this->assertSame('EXTRACTED', $r['edges'][0]['confidence'], "receiver={$receiver}");
            $this->assertSame($receiver, $r['edges'][0]['metadata']['receiver'], "receiver={$receiver}");
        }
    }

    public function test_this_receiver_to_inherited_method_emits_with_inherited_flag(): void
    {
        // App\A does not declare `inheritedOp`; the type is still certain (caller_class),
        // so the edge is emitted EXTRACTED but flagged inherited=true.
        $r = (new CodeGraphTypedCallResolver)->resolve(
            [$this->call('App\\A', 'run', 'inheritedOp', 'this')],
            [],
            ['App\\A' => ['parent' => 'App\\Base', 'methods' => ['run'], 'namespace' => 'App']],
        );

        $this->assertCount(1, $r['edges']);
        $edge = $r['edges'][0];
        $this->assertSame('sym:App\\A::run', $edge['from_node_id']);
        $this->assertSame('sym:App\\A::inheritedOp', $edge['to_node_id']);
        $this->assertSame('EXTRACTED', $edge['confidence']);
        $this->assertTrue($edge['metadata']['inherited']);
        $this->assertFalse($edge['metadata']['inferred']);
        $this->assertSame(1, $r['stats']['extracted']);
    }

    public function test_parent_receiver_resolves_via_class_index_parent_extracted(): void
    {
        $r = (new CodeGraphTypedCallResolver)->resolve(
            [$this->call('App\\A', 'run', 'boot', 'parent')],
            [],
            [
                'App\\A' => ['parent' => 'App\\Base', 'methods' => ['run'], 'namespace' => 'App'],
                'App\\Base' => ['parent' => null, 'methods' => ['boot'], 'namespace' => 'App'],
            ],
        );

        $this->assertCount(1, $r['edges']);
        $edge = $r['edges'][0];
        $this->assertSame('sym:App\\A::run', $edge['from_node_id']);
        $this->assertSame('sym:App\\Base::boot', $edge['to_node_id']);
        $this->assertSame('calls', $edge['edge_type']);
        $this->assertSame('EXTRACTED', $edge['confidence']);
        $this->assertSame(1.0, $edge['confidence_score']);
        // parent calls always cross the inheritance boundary
        $this->assertTrue($edge['metadata']['inherited']);
        $this->assertSame(1, $r['stats']['extracted']);
    }

    public function test_parent_receiver_with_unknown_parent_is_skipped(): void
    {
        $r = (new CodeGraphTypedCallResolver)->resolve(
            [$this->call('App\\A', 'run', 'boot', 'parent')],
            [],
            ['App\\A' => ['parent' => null, 'methods' => ['run'], 'namespace' => 'App']],
        );

        $this->assertSame([], $r['edges']);
        $this->assertSame(1, $r['stats']['skipped_unresolved_parent']);
        $this->assertSame(0, $r['stats']['edges']);
    }

    public function test_static_class_resolves_via_imports_to_fqn_method_extracted(): void
    {
        // Helper::make() in app/A.php, where `Helper` is imported as App\Y\Helper.
        $r = (new CodeGraphTypedCallResolver)->resolve(
            [$this->call('App\\A', 'run', 'make', ['static_class' => 'Helper'])],
            ['app/A.php' => ['Helper' => 'App\\Y\\Helper']],
            [
                'App\\A' => ['parent' => null, 'methods' => ['run'], 'namespace' => 'App'],
                'App\\Y\\Helper' => ['parent' => null, 'methods' => ['make'], 'namespace' => 'App\\Y'],
            ],
        );

        $this->assertCount(1, $r['edges']);
        $edge = $r['edges'][0];
        $this->assertSame('sym:App\\A::run', $edge['from_node_id']);
        $this->assertSame('sym:App\\Y\\Helper::make', $edge['to_node_id']);
        $this->assertSame('calls', $edge['edge_type']);
        $this->assertSame('EXTRACTED', $edge['confidence']);
        $this->assertSame(1.0, $edge['confidence_score']);
        $this->assertSame('static_class:App\\Y\\Helper', $edge['metadata']['receiver']);
        $this->assertFalse($edge['metadata']['inferred']);
        $this->assertSame(1, $r['stats']['extracted']);
    }

    public function test_static_class_resolves_via_same_namespace_when_not_imported(): void
    {
        // No import for `Sibling`; resolve against caller_class namespace (App) -> App\Sibling,
        // which exists in the index -> EXTRACTED.
        $r = (new CodeGraphTypedCallResolver)->resolve(
            [$this->call('App\\A', 'run', 'go', ['static_class' => 'Sibling'])],
            [],
            [
                'App\\A' => ['parent' => null, 'methods' => ['run'], 'namespace' => 'App'],
                'App\\Sibling' => ['parent' => null, 'methods' => ['go'], 'namespace' => 'App'],
            ],
        );

        $this->assertCount(1, $r['edges']);
        $this->assertSame('sym:App\\Sibling::go', $r['edges'][0]['to_node_id']);
        $this->assertSame('EXTRACTED', $r['edges'][0]['confidence']);
    }

    public function test_static_class_failing_existence_gate_is_skipped(): void
    {
        // `Unknown` resolves to App\Unknown by namespace, but it is NOT in the class index
        // -> existence gate rejects it (never invent a target).
        $r = (new CodeGraphTypedCallResolver)->resolve(
            [$this->call('App\\A', 'run', 'go', ['static_class' => 'Unknown'])],
            [],
            ['App\\A' => ['parent' => null, 'methods' => ['run'], 'namespace' => 'App']],
        );

        $this->assertSame([], $r['edges']);
        $this->assertSame(1, $r['stats']['skipped_unresolved_static']);
        $this->assertSame(0, $r['stats']['edges']);
    }

    public function test_unknown_receiver_is_skipped_as_dynamic(): void
    {
        $r = (new CodeGraphTypedCallResolver)->resolve(
            [$this->call('App\\A', 'run', 'whatever', 'unknown')],
            [],
            ['App\\A' => ['parent' => null, 'methods' => ['run'], 'namespace' => 'App']],
        );

        $this->assertSame([], $r['edges']);
        $this->assertSame(1, $r['stats']['skipped_dynamic']);
        $this->assertSame(0, $r['stats']['edges']);
    }

    public function test_self_call_is_skipped(): void
    {
        // $this->run() inside App\A::run -> caller FQN == callee FQN (recursion).
        $r = (new CodeGraphTypedCallResolver)->resolve(
            [$this->call('App\\A', 'run', 'run', 'this')],
            [],
            ['App\\A' => ['parent' => null, 'methods' => ['run'], 'namespace' => 'App']],
        );

        $this->assertSame([], $r['edges']);
        $this->assertSame(1, $r['stats']['skipped_self']);
        $this->assertSame(0, $r['stats']['edges']);
    }

    public function test_duplicate_typed_calls_dedup_with_occurrence_count(): void
    {
        $classIndex = ['App\\A' => ['parent' => null, 'methods' => ['run', 'helper'], 'namespace' => 'App']];
        $r = (new CodeGraphTypedCallResolver)->resolve(
            [
                $this->call('App\\A', 'run', 'helper', 'this'),
                $this->call('App\\A', 'run', 'helper', 'this'),
                $this->call('App\\A', 'run', 'helper', 'this'),
            ],
            [],
            $classIndex,
        );

        $this->assertCount(1, $r['edges']);
        $this->assertSame(3, $r['stats']['calls']);
        $this->assertSame(1, $r['stats']['edges']);
        $this->assertSame(2, $r['stats']['deduped']);
        $this->assertSame(3, $r['edges'][0]['metadata']['occurrences']);
    }

    public function test_deterministic_edge_order_independent_of_input(): void
    {
        $classIndex = ['App\\A' => ['parent' => null, 'methods' => ['run', 'helper', 'compute'], 'namespace' => 'App']];
        $a = (new CodeGraphTypedCallResolver)->resolve(
            [$this->call('App\\A', 'run', 'helper', 'this'), $this->call('App\\A', 'run', 'compute', 'this')],
            [],
            $classIndex,
        );
        $b = (new CodeGraphTypedCallResolver)->resolve(
            [$this->call('App\\A', 'run', 'compute', 'this'), $this->call('App\\A', 'run', 'helper', 'this')],
            [],
            $classIndex,
        );

        $this->assertSame($a['edges'], $b['edges']);
        $this->assertCount(2, $a['edges']);
        // sorted by (from, to, edge_type): compute target sorts before helper target
        $this->assertSame('sym:App\\A::compute', $a['edges'][0]['to_node_id']);
        $this->assertSame('sym:App\\A::helper', $a['edges'][1]['to_node_id']);
    }

    public function test_missing_caller_method_or_callee_is_skipped_as_dynamic(): void
    {
        $r = (new CodeGraphTypedCallResolver)->resolve(
            [
                ['path' => 'app/A.php', 'caller_class' => 'App\\A', 'caller_method' => '', 'callee_name' => 'x', 'receiver' => 'this'],
                ['path' => 'app/A.php', 'caller_class' => 'App\\A', 'caller_method' => 'run', 'callee_name' => '', 'receiver' => 'this'],
            ],
            [],
            ['App\\A' => ['parent' => null, 'methods' => ['run'], 'namespace' => 'App']],
        );

        $this->assertSame([], $r['edges']);
        $this->assertSame(2, $r['stats']['calls']);
        $this->assertSame(2, $r['stats']['skipped_dynamic']);
    }

    public function test_leading_backslash_in_inputs_is_normalized(): void
    {
        // caller_class and the import FQN carry a leading backslash; node ids must match the
        // de-backslashed scheme so they collide with the symbol/call resolver nodes.
        $r = (new CodeGraphTypedCallResolver)->resolve(
            [$this->call('\\App\\A', 'run', 'make', ['static_class' => 'Helper'])],
            ['app/A.php' => ['Helper' => '\\App\\Y\\Helper']],
            [
                'App\\A' => ['parent' => null, 'methods' => ['run'], 'namespace' => 'App'],
                'App\\Y\\Helper' => ['parent' => null, 'methods' => ['make'], 'namespace' => 'App\\Y'],
            ],
        );

        $this->assertCount(1, $r['edges']);
        $this->assertSame('sym:App\\A::run', $r['edges'][0]['from_node_id']);
        $this->assertSame('sym:App\\Y\\Helper::make', $r['edges'][0]['to_node_id']);
    }

    public function test_long_fqn_node_id_uses_hashed_tail_scheme(): void
    {
        // FQN whose 'sym:'+fqn exceeds 160 chars must fall back to the hashed-tail node id,
        // identical to CodeGraphSymbolResolver/CodeGraphCallResolver.
        $longClass = 'App\\'.str_repeat('VeryLongNamespaceSegment\\', 8).'Service'; // > 160 once prefixed
        $callerFqn = $longClass.'::run';
        $expected = 'sym:'.substr($callerFqn, -130).'#'.substr(sha1($callerFqn), 0, 12);

        $r = (new CodeGraphTypedCallResolver)->resolve(
            [$this->call($longClass, 'run', 'helper', 'this')],
            [],
            [$longClass => ['parent' => null, 'methods' => ['run', 'helper'], 'namespace' => 'App']],
        );

        $this->assertCount(1, $r['edges']);
        $this->assertSame($expected, $r['edges'][0]['from_node_id']);
        $this->assertLessThanOrEqual(160, strlen($r['edges'][0]['from_node_id']));
    }

    public function test_empty_inputs_are_safe(): void
    {
        $r = (new CodeGraphTypedCallResolver)->resolve([], [], []);
        $this->assertSame('atlas.code_graph.typed_call_edges.v1', $r['schema_version']);
        $this->assertSame([], $r['edges']);
        $this->assertSame(0, $r['stats']['calls']);
        $this->assertSame(0, $r['stats']['edges']);
        $this->assertSame(0, $r['stats']['extracted']);
    }
}
