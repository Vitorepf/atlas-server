<?php

declare(strict_types=1);

namespace Tests\Unit\CodeGraph;

use App\Services\Engineering\CodeGraph\CodeGraphAutoContextProvider;
use App\Services\Engineering\CodeGraph\CodeGraphContextPackAssembler;
use App\Services\Engineering\CodeGraph\CodeGraphReviewContextAssembler;
use Tests\TestCase;

/**
 * AP-815 · I-4 — auto-pull a graph context pack into the agent flow, FLAG-GATED default-OFF.
 *
 * Proves the provider composes the existing E-3 (budget packer) + I-3 (review/blast-radius)
 * blocks into a ready pack from a task descriptor, AND that with the flag OFF it is a true
 * no-op: a disabled/empty pack with neither assembler ever invoked (so wiring it into any
 * Dev/Forge/loop seam cannot change existing behaviour). Pure-ish + fail-safe.
 *
 * The assemblers are real (the composition is the contract under test); a spy subclass of
 * each records whether it was called so the flag-OFF no-op is verified structurally, not
 * just by the empty output.
 */
final class CodeGraphAutoContextProviderTest extends TestCase
{
    private function provider(): CodeGraphAutoContextProvider
    {
        return new CodeGraphAutoContextProvider(
            new CodeGraphContextPackAssembler,
            new CodeGraphReviewContextAssembler(new CodeGraphContextPackAssembler),
        );
    }

    /** A spy provider whose assemblers record any invocation (to prove the flag-OFF no-op). */
    private function spyProvider(): array
    {
        $packSpy = new class extends CodeGraphContextPackAssembler
        {
            public int $calls = 0;

            public function assemble(array $rankedNodes, int $tokenBudget, array $opts = []): array
            {
                $this->calls++;

                return parent::assemble($rankedNodes, $tokenBudget, $opts);
            }
        };

        $reviewSpy = new class($packSpy) extends CodeGraphReviewContextAssembler
        {
            public int $calls = 0;

            public function assemble(array $changedNodeIds, array $edges, array $nodeMeta, int $tokenBudget, array $opts = []): array
            {
                $this->calls++;

                return parent::assemble($changedNodeIds, $edges, $nodeMeta, $tokenBudget, $opts);
            }
        };

        return [new CodeGraphAutoContextProvider($packSpy, $reviewSpy), $packSpy, $reviewSpy];
    }

    /** Four symbols, A & X depend on B; each ~50 tokens. */
    private function nodes(): array
    {
        return [
            ['id' => 'sym:A', 'tokens' => 50, 'signature' => 'class WorkspaceResolver', 'file_path' => 'app/A.php'],
            ['id' => 'sym:B', 'tokens' => 50, 'signature' => 'class IdentityService', 'file_path' => 'app/B.php'],
            ['id' => 'sym:C', 'tokens' => 50, 'signature' => 'class Unrelated', 'file_path' => 'app/C.php'],
            ['id' => 'sym:X', 'tokens' => 50, 'signature' => 'class XConsumer', 'file_path' => 'app/X.php'],
        ];
    }

    private function edges(): array
    {
        return [
            ['from_node_id' => 'sym:A', 'to_node_id' => 'sym:B', 'edge_type' => 'calls'],
            ['from_node_id' => 'sym:X', 'to_node_id' => 'sym:B', 'edge_type' => 'calls'],
        ];
    }

    /**
     * @param  array<int,mixed>  $included
     * @return array<int,string>
     */
    private function ids(array $included): array
    {
        return array_map(static fn (array $n): string => (string) ($n['id'] ?? ''), $included);
    }

    // ---------------------------------------------------------------------------------
    // Flag OFF — the default. Must be a true no-op: disabled/empty pack, assemblers
    // never touched, regardless of how rich the descriptor is.
    // ---------------------------------------------------------------------------------

    public function test_flag_off_by_default_returns_disabled_empty_pack(): void
    {
        // No config set → inline default false.
        $out = $this->provider()->provide(
            ['query' => 'workspace identity', 'changed_node_ids' => ['sym:B']],
            $this->nodes(),
            $this->edges(),
        );

        $this->assertFalse($out['enabled']);
        $this->assertSame(CodeGraphAutoContextProvider::MODE_DISABLED, $out['mode']);
        $this->assertSame([], $out['pack']['included']);
        $this->assertSame(0, $out['pack']['count']);
        $this->assertSame(0, $out['pack']['estimated_tokens']);
        $this->assertFalse($out['pack']['truncated']);
        $this->assertSame(CodeGraphAutoContextProvider::SCHEMA, $out['schema_version']);
    }

    public function test_flag_off_explicit_false_is_disabled(): void
    {
        config()->set('atlas.code_graph.auto_context', false);

        $this->assertFalse($this->provider()->enabled());
        $this->assertFalse($this->provider()->provide(['query' => 'x'], $this->nodes())['enabled']);
    }

    public function test_flag_off_never_invokes_either_assembler(): void
    {
        config()->set('atlas.code_graph.auto_context', false);
        [$provider, $packSpy, $reviewSpy] = $this->spyProvider();

        // A descriptor that WOULD hit both modes if enabled.
        $provider->provide(['query' => 'identity', 'changed_node_ids' => ['sym:B']], $this->nodes(), $this->edges());

        $this->assertSame(0, $packSpy->calls, 'E-3 packer must not be touched when the flag is OFF');
        $this->assertSame(0, $reviewSpy->calls, 'I-3 review assembler must not be touched when the flag is OFF');
    }

    // ---------------------------------------------------------------------------------
    // Flag ON — composes the real assemblers.
    // ---------------------------------------------------------------------------------

    public function test_flag_on_query_mode_returns_keyword_ranked_pack(): void
    {
        config()->set('atlas.code_graph.auto_context', true);

        $out = $this->provider()->provide(['query' => 'identity service', 'budget' => 4000], $this->nodes());

        $this->assertTrue($out['enabled']);
        $this->assertSame(CodeGraphAutoContextProvider::MODE_QUERY, $out['mode']);
        // 'IdentityService' (sym:B) matches both terms → ranks first in the pack.
        $this->assertSame('sym:B', $this->ids($out['pack']['included'])[0]);
        $this->assertSame(4, $out['stats']['candidates']);
        $this->assertLessThanOrEqual(4000, $out['pack']['estimated_tokens']);
    }

    public function test_flag_on_query_mode_invokes_only_the_pack_assembler(): void
    {
        config()->set('atlas.code_graph.auto_context', true);
        [$provider, $packSpy, $reviewSpy] = $this->spyProvider();

        $provider->provide(['query' => 'identity'], $this->nodes());

        $this->assertSame(1, $packSpy->calls, 'query mode packs once via E-3');
        $this->assertSame(0, $reviewSpy->calls, 'query mode must not invoke the review assembler');
    }

    public function test_flag_on_review_mode_from_changed_node_ids_includes_blast_radius(): void
    {
        config()->set('atlas.code_graph.auto_context', true);

        $out = $this->provider()->provide(
            ['changed_node_ids' => ['sym:B'], 'budget' => 4000],
            $this->nodes(),
            $this->edges(),
        );

        $this->assertTrue($out['enabled']);
        $this->assertSame(CodeGraphAutoContextProvider::MODE_REVIEW, $out['mode']);

        $ids = $this->ids($out['pack']['included']);
        $this->assertContains('sym:B', $ids, 'the changed node is in the pack');
        $this->assertContains('sym:A', $ids, 'A depends on B → blast-radius');
        $this->assertContains('sym:X', $ids, 'X depends on B → blast-radius');
        $this->assertNotContains('sym:C', $ids, 'C does not depend on B → not pulled');

        $this->assertSame(1, $out['stats']['changed']);
        $this->assertSame(2, $out['stats']['blast_radius']);
    }

    public function test_flag_on_review_mode_resolves_changed_files_to_node_ids(): void
    {
        config()->set('atlas.code_graph.auto_context', true);

        // Describe the task by the FILE it touches; the provider maps app/B.php → sym:B.
        $out = $this->provider()->provide(
            ['changed_files' => ['app/B.php'], 'budget' => 4000],
            $this->nodes(),
            $this->edges(),
        );

        $this->assertSame(CodeGraphAutoContextProvider::MODE_REVIEW, $out['mode']);
        $ids = $this->ids($out['pack']['included']);
        $this->assertContains('sym:B', $ids);
        $this->assertContains('sym:A', $ids, 'blast-radius resolved through the file-derived changed node');
        $this->assertSame(1, $out['stats']['changed']);
    }

    public function test_review_mode_wins_over_query_when_both_present(): void
    {
        config()->set('atlas.code_graph.auto_context', true);
        [$provider, , $reviewSpy] = $this->spyProvider();

        $out = $provider->provide(
            ['query' => 'identity', 'changed_node_ids' => ['sym:B']],
            $this->nodes(),
            $this->edges(),
        );

        $this->assertSame(CodeGraphAutoContextProvider::MODE_REVIEW, $out['mode']);
        $this->assertSame(1, $reviewSpy->calls, 'a non-empty changed set routes to review mode');
    }

    public function test_flag_on_budget_is_honoured_and_pack_truncates(): void
    {
        config()->set('atlas.code_graph.auto_context', true);

        // Budget ~1 node (50): the highest-ranked changed node survives, rest truncated.
        $out = $this->provider()->provide(
            ['changed_node_ids' => ['sym:B'], 'budget' => 50],
            $this->nodes(),
            $this->edges(),
        );

        $this->assertSame(['sym:B'], $this->ids($out['pack']['included']));
        $this->assertTrue($out['pack']['truncated']);
        $this->assertLessThanOrEqual(50, $out['pack']['estimated_tokens']);
    }

    public function test_flag_on_opts_fill_gaps_reaches_pack_in_query_mode(): void
    {
        config()->set('atlas.code_graph.auto_context', true);

        $nodes = [
            ['id' => 'alpha', 'tokens' => 100, 'signature' => 'match'],
            ['id' => 'beta', 'tokens' => 500, 'signature' => 'match'],
            ['id' => 'gamma', 'tokens' => 50, 'signature' => 'match'],
        ];

        $out = $this->provider()->provide(
            ['query' => 'match', 'budget' => 250, 'opts' => ['fill_gaps' => true]],
            $nodes,
        );

        // All three match equally → input order preserved; fill_gaps lets gamma in past beta.
        $ids = $this->ids($out['pack']['included']);
        $this->assertContains('alpha', $ids);
        $this->assertContains('gamma', $ids);
        $this->assertNotContains('beta', $ids, 'the 500-token node does not fit the 250 budget');
    }

    // ---------------------------------------------------------------------------------
    // Enabled-but-empty + fail-safety + determinism.
    // ---------------------------------------------------------------------------------

    public function test_flag_on_no_query_no_changes_is_enabled_but_empty(): void
    {
        config()->set('atlas.code_graph.auto_context', true);

        $out = $this->provider()->provide([], $this->nodes(), $this->edges());

        $this->assertTrue($out['enabled'], 'enabled — distinguishes from the flag-OFF disabled state');
        $this->assertSame([], $out['pack']['included']);
        $this->assertSame(0, $out['stats']['included']);
    }

    public function test_flag_on_with_no_nodes_is_safe_empty_pack(): void
    {
        config()->set('atlas.code_graph.auto_context', true);

        $out = $this->provider()->provide(['changed_node_ids' => ['sym:B']], [], []);

        $this->assertTrue($out['enabled']);
        $this->assertSame(CodeGraphAutoContextProvider::MODE_REVIEW, $out['mode']);
        // No node metadata + no edges → just the changed node, no blast.
        $this->assertSame(['sym:B'], $this->ids($out['pack']['included']));
        $this->assertSame(0, $out['stats']['blast_radius']);
    }

    public function test_flag_on_tolerates_garbage_descriptor_values(): void
    {
        config()->set('atlas.code_graph.auto_context', true);

        $out = $this->provider()->provide(
            [
                'query' => '%%%',                       // only symbols → zero usable terms
                'changed_node_ids' => 'not-an-array',   // non-array → ignored
                'changed_files' => [null, '', '  '],    // all blank → no resolution
                'budget' => 'garbage',                  // non-numeric → default budget
                'opts' => 'not-an-array',               // non-array → no overrides
            ],
            $this->nodes(),
            $this->edges(),
        );

        // Nothing usable to retrieve, but enabled and never throws.
        $this->assertTrue($out['enabled']);
        $this->assertSame([], $out['pack']['included']);
        $this->assertSame(4000, $out['pack']['budget'], 'garbage budget falls back to the 4000 default');
    }

    public function test_flag_on_drops_non_array_and_idless_nodes(): void
    {
        config()->set('atlas.code_graph.auto_context', true);

        $nodes = [
            'garbage-string',
            42,
            ['no_id' => true, 'tokens' => 10],
            ['id' => 'sym:B', 'tokens' => 50, 'signature' => 'IdentityService'],
        ];

        $out = $this->provider()->provide(['query' => 'identity'], $nodes);

        $ids = $this->ids($out['pack']['included']);
        $this->assertContains('sym:B', $ids);
        $this->assertNotContains('', $ids, 'id-less / non-array nodes never enter the pack as a node');
    }

    public function test_output_is_deterministic_across_repeated_calls(): void
    {
        config()->set('atlas.code_graph.auto_context', true);

        $descriptor = ['changed_node_ids' => ['sym:B'], 'budget' => 4000];
        $first = $this->provider()->provide($descriptor, $this->nodes(), $this->edges());
        $second = $this->provider()->provide($descriptor, $this->nodes(), $this->edges());

        $this->assertSame($first, $second);
    }

    public function test_enabled_reflects_the_flag(): void
    {
        config()->set('atlas.code_graph.auto_context', false);
        $this->assertFalse($this->provider()->enabled());

        config()->set('atlas.code_graph.auto_context', true);
        $this->assertTrue($this->provider()->enabled());
    }
}
