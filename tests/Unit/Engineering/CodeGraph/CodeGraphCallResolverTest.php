<?php

namespace Tests\Unit\Engineering\CodeGraph;

use App\Services\Engineering\CodeGraph\CodeGraphCallResolver;
use PHPUnit\Framework\TestCase;

class CodeGraphCallResolverTest extends TestCase
{
    /**
     * @return array{caller:string, callee:string, path:string, language:string}
     */
    private function call(string $caller, string $callee, string $path = 'app/Svc.php', string $language = 'php'): array
    {
        return ['caller' => $caller, 'callee' => $callee, 'path' => $path, 'language' => $language];
    }

    public function test_single_candidate_both_ends_resolves_to_calls_edge(): void
    {
        $r = (new CodeGraphCallResolver)->resolveCalls(
            [$this->call('run', 'helper')],
            [
                'run' => ['App\\Svc::run'],
                'helper' => ['App\\Svc::helper'],
            ],
        );

        $this->assertSame(CodeGraphCallResolver::SCHEMA, $r['schema_version']);
        $this->assertCount(1, $r['edges']);

        $edge = $r['edges'][0];
        $this->assertSame('sym:App\\Svc::run', $edge['from_node_id']);
        $this->assertSame('sym:App\\Svc::helper', $edge['to_node_id']);
        $this->assertSame('calls', $edge['edge_type']);
        $this->assertSame('INFERRED', $edge['confidence']);
        $this->assertSame(0.7, $edge['confidence_score']);
        $this->assertSame('helper', $edge['metadata']['callee_name']);
        $this->assertTrue($edge['metadata']['inferred']);
        $this->assertSame(1, $edge['metadata']['occurrences']);
        $this->assertSame(1, $r['stats']['edges']);
        $this->assertSame(1, $r['stats']['inferred']);
    }

    public function test_builtin_callee_is_never_resolved(): void
    {
        // `trim` is a PHP builtin; even if a single user method named trim exists,
        // a call to trim() must NOT become an edge (precision over recall).
        $r = (new CodeGraphCallResolver)->resolveCalls(
            [$this->call('run', 'trim')],
            ['run' => ['App\\Svc::run'], 'trim' => ['App\\Other::trim']],
        );

        $this->assertCount(0, $r['edges']);
        $this->assertSame(1, $r['stats']['skipped_builtin_callee']);
    }

    public function test_ambiguous_callee_is_skipped(): void
    {
        $r = (new CodeGraphCallResolver)->resolveCalls(
            [$this->call('run', 'helper')],
            [
                'run' => ['App\\Svc::run'],
                // two defining methods for the callee name -> not single-candidate
                'helper' => ['App\\Svc::helper', 'App\\Other::helper'],
            ],
        );

        $this->assertSame([], $r['edges']);
        $this->assertSame(1, $r['stats']['skipped_ambiguous_callee']);
        $this->assertSame(0, $r['stats']['edges']);
    }

    public function test_unknown_callee_is_skipped(): void
    {
        $r = (new CodeGraphCallResolver)->resolveCalls(
            [$this->call('run', 'doesNotExist')],
            ['run' => ['App\\Svc::run']],
        );

        $this->assertSame([], $r['edges']);
        $this->assertSame(1, $r['stats']['skipped_unresolved_callee']);
    }

    public function test_unresolved_caller_is_skipped(): void
    {
        // callee resolves, but the enclosing caller (e.g. a file-path top-level call)
        // is not a known method -> no method->method edge.
        $r = (new CodeGraphCallResolver)->resolveCalls(
            [$this->call('app/Svc.php', 'helper')],
            ['helper' => ['App\\Svc::helper']],
        );

        $this->assertSame([], $r['edges']);
        $this->assertSame(1, $r['stats']['skipped_unresolved_caller']);
    }

    public function test_ambiguous_caller_is_skipped(): void
    {
        $r = (new CodeGraphCallResolver)->resolveCalls(
            [$this->call('run', 'helper')],
            [
                'run' => ['App\\Svc::run', 'App\\Other::run'],
                'helper' => ['App\\Svc::helper'],
            ],
        );

        $this->assertSame([], $r['edges']);
        $this->assertSame(1, $r['stats']['skipped_ambiguous_caller']);
    }

    public function test_self_call_is_skipped(): void
    {
        // caller and callee resolve to the SAME method FQN (recursion).
        $r = (new CodeGraphCallResolver)->resolveCalls(
            [$this->call('recurse', 'recurse')],
            ['recurse' => ['App\\Svc::recurse']],
        );

        $this->assertSame([], $r['edges']);
        $this->assertSame(1, $r['stats']['skipped_self']);
    }

    public function test_duplicate_calls_dedup_with_occurrence_count(): void
    {
        $r = (new CodeGraphCallResolver)->resolveCalls(
            [
                $this->call('run', 'helper'),
                $this->call('run', 'helper'),
                $this->call('run', 'helper'),
            ],
            [
                'run' => ['App\\Svc::run'],
                'helper' => ['App\\Svc::helper'],
            ],
        );

        $this->assertCount(1, $r['edges']);
        $this->assertSame(3, $r['stats']['calls']);
        $this->assertSame(1, $r['stats']['edges']);
        $this->assertSame(2, $r['stats']['deduped']);
        $this->assertSame(3, $r['edges'][0]['metadata']['occurrences']);
    }

    public function test_deterministic_edge_order_independent_of_input(): void
    {
        $index = [
            'run' => ['App\\Svc::run'],
            'helper' => ['App\\Svc::helper'],
            'compute' => ['App\\Svc::compute'],
        ];
        $a = (new CodeGraphCallResolver)->resolveCalls(
            [$this->call('run', 'helper'), $this->call('run', 'compute')],
            $index,
        );
        $b = (new CodeGraphCallResolver)->resolveCalls(
            [$this->call('run', 'compute'), $this->call('run', 'helper')],
            $index,
        );

        $this->assertSame($a['edges'], $b['edges']);
        $this->assertCount(2, $a['edges']);
        // sorted by (from, to, edge_type): compute target sorts before helper target
        $this->assertSame('sym:App\\Svc::compute', $a['edges'][0]['to_node_id']);
        $this->assertSame('sym:App\\Svc::helper', $a['edges'][1]['to_node_id']);
    }

    public function test_empty_inputs_are_safe(): void
    {
        $r = (new CodeGraphCallResolver)->resolveCalls([], []);
        $this->assertSame([], $r['edges']);
        $this->assertSame(0, $r['stats']['calls']);
    }

    public function test_leading_backslash_in_index_is_normalized(): void
    {
        // index FQNs with a leading backslash resolve to the same node id as without.
        $r = (new CodeGraphCallResolver)->resolveCalls(
            [$this->call('run', 'helper')],
            [
                'run' => ['\\App\\Svc::run'],
                'helper' => ['\\App\\Svc::helper'],
            ],
        );

        $this->assertCount(1, $r['edges']);
        $this->assertSame('sym:App\\Svc::run', $r['edges'][0]['from_node_id']);
        $this->assertSame('sym:App\\Svc::helper', $r['edges'][0]['to_node_id']);
    }
}
