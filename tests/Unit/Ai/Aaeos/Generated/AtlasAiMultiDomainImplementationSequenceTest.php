<?php

declare(strict_types=1);

namespace Tests\Unit\Ai\Aaeos\Generated;

use App\Services\Ai\Aaeos\Generated\AtlasAiMultiDomainImplementationSequenceService;
use Tests\TestCase;

/**
 * Pins the documented Atlas AI Multi-Domain Implementation Sequence rules: the
 * 14-meta canonical order, the strict dependency edges (start gating), the two
 * worked examples ("build Cyber now" => blocked; "Control Plane UX before
 * runtime" => refused), the parallelism allow-list, the NEVER-parallel rules
 * and the file-boundary collision rule.
 *
 * @see docs/engineering-knowledge-base/atlas-ai-multi-domain-implementation-sequence.md
 */
class AtlasAiMultiDomainImplementationSequenceTest extends TestCase
{
    private function service(): AtlasAiMultiDomainImplementationSequenceService
    {
        return new AtlasAiMultiDomainImplementationSequenceService();
    }

    /**
     * Doc example "construir Cyber agora": Cyber (11) with an empty ready set is
     * blocked, and the missing prerequisites are exactly its documented deps
     * 1,2,3,4,5,6,8 (Mission, Contract, Policy, Evidence, Tool, Router,
     * Research) — sorted ascending.
     */
    public function test_cyber_now_blocked_lists_all_prerequisites(): void
    {
        $r = $this->service()->canStart(['meta' => 11, 'ready_metas' => []]);

        $this->assertSame('blocked', $r['verdict']);
        $this->assertFalse($r['can_start']);
        $this->assertSame([1, 2, 3, 4, 5, 6, 8], $r['missing_prerequisites']);
        $this->assertSame('open_missing_prerequisites_first', $r['required_action']);
        $this->assertSame('app/Services/Atlas/Domains/Cyber/', $r['file_boundary']);
        // This doc is a plan, not proof of readiness: it never declares "done".
        $this->assertFalse($r['declares_done']);
    }

    /**
     * Once 1-6 and 8 are all green, Cyber (11) becomes ready_to_start with no
     * missing prerequisites — the doc's "saltos so com evidencia" rule.
     */
    public function test_cyber_ready_when_all_prerequisites_green(): void
    {
        $r = $this->service()->canStart([
            'meta' => 11,
            'ready_metas' => [1, 2, 3, 4, 5, 6, 7, 8, 9, 10],
        ]);

        $this->assertSame('ready_to_start', $r['verdict']);
        $this->assertTrue($r['can_start']);
        $this->assertSame([], $r['missing_prerequisites']);
        $this->assertSame('start_meta', $r['required_action']);
    }

    /**
     * Doc example "fazer Control Plane UX antes do runtime": meta 14 depends on
     * 1..13, so with nothing ready it is refused and reports every one of the
     * thirteen runtimes it still needs.
     */
    public function test_control_plane_ux_before_runtime_is_refused(): void
    {
        $r = $this->service()->canStart(['meta' => 14, 'ready_metas' => []]);

        $this->assertSame('blocked', $r['verdict']);
        $this->assertSame(
            [1, 2, 3, 4, 5, 6, 7, 8, 9, 10, 11, 12, 13],
            $r['missing_prerequisites']
        );

        // It only flips to ready once all 1..13 are green (consumes 1..13).
        $ready = $this->service()->canStart([
            'meta' => 14,
            'ready_metas' => [1, 2, 3, 4, 5, 6, 7, 8, 9, 10, 11, 12, 13],
        ]);
        $this->assertTrue($ready['can_start']);
    }

    /**
     * Mission Foundation (1) has no dependencies, so it is always ready to start
     * — it is the pre-requisite of every other meta, not gated by any.
     */
    public function test_mission_foundation_has_no_prerequisites(): void
    {
        $r = $this->service()->canStart(['meta' => 1, 'ready_metas' => []]);

        $this->assertTrue($r['can_start']);
        $this->assertSame([], $r['depends_on']);
        $this->assertSame('Mission Foundation', $r['meta_name']);
        $this->assertSame(2, $this->service()->nextMeta(1));
        $this->assertNull($this->service()->nextMeta(14));
    }

    /**
     * Parallelism: the doc example "tres Claudes em Research, Finance e
     * Marketing" — 8/9/10 share a co-runnable group with distinct file
     * boundaries, so each pair parallelizes. But a meta never parallelizes with
     * itself ("Uma meta = um owner por janela").
     */
    public function test_research_finance_marketing_parallelize_but_not_with_self(): void
    {
        $rf = $this->service()->canParallelize(8, 9);
        $this->assertSame('parallel_allowed', $rf['verdict']);
        $this->assertTrue($rf['allowed']);
        $this->assertSame([], $rf['reasons']);

        $fm = $this->service()->canParallelize(9, 10);
        $this->assertTrue($fm['allowed']);

        $self = $this->service()->canParallelize(8, 8);
        $this->assertFalse($self['allowed']);
        $this->assertContains('same_meta_one_owner_per_window', $self['reasons']);
    }

    /**
     * NEVER-parallel rules win over the allow-list:
     *  - 1 (Mission Foundation) never parallelizes with any domain;
     *  - 3 (Policy) never parallelizes with sensitive domains 9..12;
     *  - 14 (Control Plane UX) never parallelizes with a runtime it governs;
     *  - 5 (Tool Registry) never parallelizes with 13 (Automation).
     * And two metas that are not in any shared group cannot parallelize.
     */
    public function test_never_parallel_rules_block_forbidden_pairs(): void
    {
        $this->assertFalse($this->service()->canParallelize(1, 7)['allowed']);

        $policyFinance = $this->service()->canParallelize(3, 9);
        $this->assertFalse($policyFinance['allowed']);
        $this->assertContains('documented_never_parallel', $policyFinance['reasons']);

        $uxRuntime = $this->service()->canParallelize(13, 14);
        $this->assertFalse($uxRuntime['allowed']);
        $this->assertContains('documented_never_parallel', $uxRuntime['reasons']);

        $toolAutomation = $this->service()->canParallelize(5, 13);
        $this->assertFalse($toolAutomation['allowed']);

        // 7 and 9 are in no shared co-runnable group -> forbidden.
        $unrelated = $this->service()->canParallelize(7, 9);
        $this->assertFalse($unrelated['allowed']);
        $this->assertContains('not_in_a_shared_parallel_group', $unrelated['reasons']);
    }

    /**
     * The start plan opens missing prerequisites first, in canonical order, then
     * the target. Unknown meta ids are rejected, and the ready set drops
     * unknown entries.
     */
    public function test_start_plan_orders_prereqs_then_target_and_rejects_unknown(): void
    {
        $plan = $this->service()->startPlan(9, [1, 2]);
        // Finance (9) deps are 1,2,3,4,5,6,8; 1 and 2 are ready -> open 3,4,5,6,8 then 9.
        $this->assertSame([3, 4, 5, 6, 8], $plan['open_first']);
        $this->assertSame([3, 4, 5, 6, 8, 9], $plan['sequence']);

        $unknown = $this->service()->canStart(['meta' => 99, 'ready_metas' => []]);
        $this->assertNull($unknown['meta']);
        $this->assertSame('reject_unknown_meta', $unknown['required_action']);

        // Unknown ready entries are ignored; only real metas count as ready.
        $this->assertFalse(
            $this->service()->mayStart(7, [1, 2, 4, 'banana', 999])
        );
        // With its real deps 1,2,4,6 ready, Programming (7) may start.
        $this->assertTrue($this->service()->mayStart(7, [1, 2, 4, 6]));
    }
}
