<?php

declare(strict_types=1);

namespace Tests\Unit\Ai\SelfConstruction;

use App\Services\Ai\SelfConstruction\AtlasSelfImprovementRelevanceGate;
use PHPUnit\Framework\TestCase;

/**
 * S3.F1 — the OUT-OF-PROCESS relevance gate, as a pure decision function. This is
 * the load-bearing safety that stops the 412-line-garbage failure: it compares the
 * FACTS of the signal (the named file) against the FACTS of what was delivered (the
 * touched files), never the generation prompt — so the provider cannot game it.
 */
final class AtlasSelfImprovementRelevanceGateTest extends TestCase
{
    private function gate(): AtlasSelfImprovementRelevanceGate
    {
        return new AtlasSelfImprovementRelevanceGate;
    }

    public function test_exact_file_touch_is_on_target(): void
    {
        $v = $this->gate()->evaluate(
            ['area' => 'code', 'file' => 'app/Services/Widget.php', 'line' => 7, 'signal' => 'TODO: guard'],
            ['delivered' => true, 'branch' => 'atlas/materialize/x', 'delivery' => ['files' => ['app/Services/Widget.php']]],
        );

        $this->assertTrue($v['relevant']);
        $this->assertSame('on_target', $v['reason']);
        $this->assertSame('app/Services/Widget.php', $v['matched_file']);
    }

    public function test_sibling_under_same_dir_is_on_target(): void
    {
        // A defensible adjacent change in the same directory the operator reviews in context.
        $v = $this->gate()->evaluate(
            ['file' => 'app/Services/Widget.php', 'line' => 7],
            ['delivered' => true, 'delivery' => ['files' => ['app/Services/WidgetGuard.php']]],
        );

        $this->assertTrue($v['relevant']);
        $this->assertSame('app/Services/WidgetGuard.php', $v['matched_file']);
    }

    public function test_unrelated_file_is_off_target_rejected(): void
    {
        // The 412-line-garbage case: signal about Widget.php, generation produced an
        // unrelated Hermes Kanban driver. DEFAULT-REFUSE.
        $v = $this->gate()->evaluate(
            ['file' => 'app/Services/Widget.php', 'line' => 7, 'signal' => 'TODO: running/scheduled tasks'],
            ['delivered' => true, 'branch' => 'atlas/materialize/x', 'delivery' => ['files' => ['app/Services/Hermes/HermesKanbanDriver.php']]],
        );

        $this->assertFalse($v['relevant']);
        $this->assertSame('off_target_generation', $v['reason']);
        $this->assertNull($v['matched_file']);
        $this->assertSame('app/Services/Widget.php', $v['target_file']);
    }

    public function test_empty_delivery_is_never_relevant(): void
    {
        $v = $this->gate()->evaluate(
            ['file' => 'app/Services/Widget.php', 'line' => 7],
            ['delivered' => true, 'delivery' => ['files' => []]],
        );
        $this->assertFalse($v['relevant'], 'the gate never fabricates a pass for an empty delivery');
        $this->assertSame('no_delivery_to_check', $v['reason']);
    }

    public function test_blocked_delivery_is_never_relevant(): void
    {
        $v = $this->gate()->evaluate(
            ['file' => 'app/Services/Widget.php', 'line' => 7],
            ['delivered' => false, 'delivery' => ['files' => ['app/Services/Widget.php']]],
        );
        $this->assertFalse($v['relevant']);
        $this->assertSame('no_delivery_to_check', $v['reason']);
    }

    public function test_operator_gap_no_file_is_admitted_as_unverifiable(): void
    {
        $v = $this->gate()->evaluate(
            ['area' => 'operator', 'file' => null, 'line' => null, 'signal' => 'Harden the secret scanner'],
            ['delivered' => true, 'delivery' => ['files' => ['app/Services/Security/Scanner.php']]],
        );

        $this->assertTrue($v['relevant'], 'a fileless operator gap cannot be target-checked; admitted');
        $this->assertSame('no_target_unverifiable_admitted', $v['reason']);
        $this->assertNull($v['target_file']);
    }

    public function test_path_normalisation_handles_leading_slash_and_dot(): void
    {
        $v = $this->gate()->evaluate(
            ['file' => './app/Services/Widget.php', 'line' => 7],
            ['delivered' => true, 'delivery' => ['files' => ['/app/Services/Widget.php']]],
        );
        $this->assertTrue($v['relevant'], 'normalised paths must compare equal');
        $this->assertSame('app/Services/Widget.php', $v['matched_file']);
    }

    public function test_accepts_orchestrator_shape_with_path_keyed_files(): void
    {
        // The orchestrator's raw shape can carry {path:...} entries — gate must read both.
        $v = $this->gate()->evaluate(
            ['file' => 'app/Services/Widget.php', 'line' => 7],
            ['delivered' => true, 'files' => [['path' => 'app/Services/Widget.php']]],
        );
        $this->assertTrue($v['relevant']);
    }

    // ------------------------------------------------------------------
    // S3.F2 — content_relevance dimension (the second, independent CHECK).
    // ------------------------------------------------------------------

    /**
     * (a) THE EXACT 412-LINE FAILURE, reproduced as a fixture with CONTENT. A TODO about
     * scheduling in fileA; the generation produced an unrelated Hermes Kanban driver in a
     * DIFFERENT directory (fileB). The gate REJECTS on target_match alone (path 0) — and
     * never even reaches content. This is the assertion that the 412-line-style garbage is
     * rejected.
     */
    public function test_412_line_garbage_is_rejected_on_target_match(): void
    {
        $v = $this->gate()->evaluate(
            [
                'area' => 'code',
                'file' => 'app/Console/Commands/RunScheduledTasks.php',
                'line' => 42,
                'signal' => 'TODO: handle running and scheduled tasks lifecycle here',
            ],
            [
                'delivered' => true,
                'branch' => 'atlas/materialize/garbage',
                'delivery' => ['files' => [[
                    'path' => 'app/Services/Hermes/HermesKanbanDriver.php',
                    // ~412 lines of an unrelated Kanban board driver.
                    'content' => $this->kanbanDriverGarbage(),
                ]]],
            ],
        );

        $this->assertFalse($v['relevant'], 'the 412-line off-target garbage MUST be rejected');
        $this->assertSame('off_target_generation', $v['reason']);
        $this->assertSame(0.0, $v['target_match'], 'unrelated path scores 0 on target');
        $this->assertNull($v['matched_file']);
    }

    /**
     * (b) An ON-TARGET fix in the signal's OWN file with CONTENT that matches the concern
     * → the gate PASSES both dimensions.
     */
    public function test_on_target_file_with_matching_content_passes(): void
    {
        $v = $this->gate()->evaluate(
            [
                'area' => 'code',
                'file' => 'app/Services/Scheduler/TaskScheduler.php',
                'line' => 12,
                'signal' => 'TODO: guard the scheduled task lifecycle when a task is already running',
            ],
            [
                'delivered' => true,
                'branch' => 'atlas/materialize/fix',
                'delivery' => ['files' => [[
                    'path' => 'app/Services/Scheduler/TaskScheduler.php',
                    'content' => "<?php\n\nnamespace App\\Services\\Scheduler;\n\n"
                        ."class TaskScheduler\n{\n"
                        ."    // Guard the scheduled task lifecycle: skip when a task is already running.\n"
                        ."    public function dispatchScheduledTask(Task \$task): void\n    {\n"
                        ."        if (\$this->isRunning(\$task)) {\n            return; // already running\n        }\n"
                        ."        \$this->runScheduledTask(\$task);\n    }\n}\n",
                ]]],
            ],
        );

        $this->assertTrue($v['relevant'], 'on-target file with on-concern content passes');
        $this->assertSame('on_target', $v['reason']);
        $this->assertSame(1.0, $v['target_match']);
        $this->assertNotNull($v['content_relevance']);
        $this->assertGreaterThanOrEqual(0.15, $v['content_relevance']);
    }

    /**
     * (c) The sqlite/no-engine TOKEN-OVERLAP fallback works AND is labelled honestly — it
     * is NEVER called "semantic". (The unit gate is constructed with no EmbeddingService,
     * so it always takes the deterministic fallback path.)
     */
    public function test_token_overlap_fallback_works_and_is_labelled_honestly(): void
    {
        $v = $this->gate()->evaluate(
            [
                'file' => 'app/Services/Scheduler/TaskScheduler.php',
                'line' => 1,
                'signal' => 'TODO: guard the scheduled task lifecycle when already running',
            ],
            [
                'delivered' => true,
                'delivery' => ['files' => [[
                    'path' => 'app/Services/Scheduler/TaskScheduler.php',
                    'content' => 'Guard the scheduled task lifecycle; skip the run when already running.',
                ]]],
            ],
        );

        $this->assertTrue($v['relevant']);
        $this->assertSame(
            AtlasSelfImprovementRelevanceGate::METHOD_TOKEN_OVERLAP,
            $v['content_method'],
            'no engine + no pgsql => the deterministic fallback, labelled lexical (never semantic)',
        );
        $this->assertNotSame(AtlasSelfImprovementRelevanceGate::METHOD_SEMANTIC, $v['content_method']);
    }

    /**
     * (d) NOT GAMEABLE BY ECHOING: a generated file that merely echoes the signal text as
     * a comment but lives in the WRONG directory still scores 0 on target_match → rejected.
     * Content mimicry cannot rescue an off-target path because target_match runs first.
     */
    public function test_echoing_signal_in_wrong_file_is_still_rejected(): void
    {
        $signalText = 'guard the scheduled task lifecycle when a task is already running';

        $v = $this->gate()->evaluate(
            [
                'file' => 'app/Services/Scheduler/TaskScheduler.php',
                'line' => 1,
                'signal' => $signalText,
            ],
            [
                'delivered' => true,
                'branch' => 'atlas/materialize/echo',
                'delivery' => ['files' => [[
                    // WRONG directory — a generic generated snippet.
                    'path' => 'app/Generated/AtlasGeneratedSnippet.php',
                    // It LITERALLY echoes the concern as a docblock, but does nothing relevant.
                    'content' => "<?php\n\n// {$signalText}\n// {$signalText}\n\nclass AtlasGeneratedSnippet { public function noop(): void {} }\n",
                ]]],
            ],
        );

        $this->assertFalse($v['relevant'], 'echoing the signal in the wrong file must NOT pass');
        $this->assertSame('off_target_generation', $v['reason']);
        $this->assertSame(0.0, $v['target_match'], 'wrong directory => 0 target_match regardless of content echo');
        $this->assertNull($v['matched_file']);
    }

    /**
     * On-target PATH but OFF-CONCERN CONTENT: a file lands in the signal's own directory
     * (sibling) but its content has nothing to do with the concern → rejected on the
     * content dimension. This is the second half of the 412-style failure (right place,
     * wrong thing) that the F1 path-only gate could not catch.
     */
    public function test_on_target_path_but_off_concern_content_is_rejected(): void
    {
        $v = $this->gate()->evaluate(
            [
                'file' => 'app/Services/Scheduler/TaskScheduler.php',
                'line' => 1,
                'signal' => 'TODO: guard the scheduled task lifecycle when a task is already running',
            ],
            [
                'delivered' => true,
                'branch' => 'atlas/materialize/wrongthing',
                'delivery' => ['files' => [[
                    // Sibling in the SAME directory => passes target_match (0.5)...
                    'path' => 'app/Services/Scheduler/HermesKanbanDriver.php',
                    // ...but the CONTENT is an unrelated Kanban board driver.
                    'content' => $this->kanbanDriverGarbage(),
                ]]],
            ],
        );

        $this->assertFalse($v['relevant'], 'right place, wrong thing must be rejected on content');
        $this->assertSame('off_concern_content', $v['reason']);
        $this->assertSame(0.5, $v['target_match'], 'sibling-in-dir passes the path dimension...');
        $this->assertNotNull($v['content_relevance']);
        $this->assertLessThan(0.15, $v['content_relevance'], '...but the content overlap is below the floor');
        $this->assertSame(AtlasSelfImprovementRelevanceGate::METHOD_TOKEN_OVERLAP, $v['content_method']);
    }

    /**
     * No readable content carried for the matched file => the content dimension cannot
     * bite and is admitted honestly; target_match alone governs (preserves the F1
     * path-only behaviour). The method is reported as no-content, never as a score.
     */
    public function test_no_content_means_content_dimension_does_not_bite(): void
    {
        $v = $this->gate()->evaluate(
            ['file' => 'app/Services/Widget.php', 'line' => 7, 'signal' => 'TODO: guard'],
            ['delivered' => true, 'delivery' => ['files' => ['app/Services/Widget.php']]], // path only, no content
        );

        $this->assertTrue($v['relevant']);
        $this->assertSame('on_target', $v['reason']);
        $this->assertNull($v['content_relevance'], 'no content to score => no fabricated number');
        $this->assertSame(AtlasSelfImprovementRelevanceGate::METHOD_NONE, $v['content_method']);
    }

    public function test_root_level_target_vs_unrelated_root_file_is_rejected(): void
    {
        $v = $this->gate()->evaluate(
            ['file' => 'composer.json', 'line' => 1, 'signal' => 'update dependency'],
            ['delivered' => true, 'delivery' => ['files' => ['README.md']]],
        );

        $this->assertFalse($v['relevant'], 'unrelated root file must NOT match a root target');
        $this->assertSame('off_target_generation', $v['reason']);
        $this->assertNull($v['matched_file']);
        $this->assertSame(0.0, $v['target_match']);
    }

    // ------------------------------------------------------------------
    // verifyProposal() — proxy-rejection for self-improvement proposals
    // ------------------------------------------------------------------

    private function concreteProposal(): array
    {
        return [
            'proposal_type' => 'capability_addition',
            'leverage_evidence' => 'Profiled: 43% of loop cycles exit early because X gate has no short-circuit; adding it reduces p99 by ~300ms measured on prod trace.',
            'implementation_surface' => ['app/Services/Ai/SelfConstruction/XGate.php'],
            'verification_path' => '/opt/homebrew/bin/php artisan test --filter=XGateTest',
            'before_after_outcome_delta' => 'before: 0 short-circuits / cycle; after: early-exit on 43% of cycles',
        ];
    }

    public function test_task_count_proposal_is_rejected_as_proxy(): void
    {
        $p = $this->concreteProposal();
        $p['proposal_type'] = 'task_count_optimization';
        $r = $this->gate()->verifyProposal($p);
        $this->assertFalse($r['relevant']);
        $this->assertContains('proxy_proposal_type:task_count_optimization', $r['blockers']);
    }

    public function test_cosmetic_docs_proposal_is_rejected_as_proxy(): void
    {
        $p = $this->concreteProposal();
        $p['proposal_type'] = 'cosmetic_docs';
        $r = $this->gate()->verifyProposal($p);
        $this->assertFalse($r['relevant']);
        $this->assertContains('proxy_proposal_type:cosmetic_docs', $r['blockers']);
    }

    public function test_vague_refactor_proposal_is_rejected_as_proxy(): void
    {
        $p = $this->concreteProposal();
        $p['proposal_type'] = 'vague_refactor';
        $r = $this->gate()->verifyProposal($p);
        $this->assertFalse($r['relevant']);
        $this->assertContains('proxy_proposal_type:vague_refactor', $r['blockers']);
    }

    public function test_proposal_with_all_required_evidence_fields_passes(): void
    {
        $r = $this->gate()->verifyProposal($this->concreteProposal());
        $this->assertTrue($r['relevant']);
        $this->assertSame('proposal_has_concrete_evidence', $r['reason']);
        $this->assertSame([], $r['blockers']);
    }

    public function test_proposal_missing_leverage_evidence_is_blocked(): void
    {
        $p = $this->concreteProposal();
        unset($p['leverage_evidence']);
        $r = $this->gate()->verifyProposal($p);
        $this->assertFalse($r['relevant']);
        $this->assertContains('leverage_evidence_missing', $r['blockers']);
    }

    /** ~412 lines of an unrelated Hermes Kanban board driver (the real failure's shape). */
    private function kanbanDriverGarbage(): string
    {
        $lines = ["<?php", "", "namespace App\\Services\\Hermes;", "", "class HermesKanbanDriver", "{"];
        for ($i = 0; $i < 400; $i++) {
            $lines[] = "    public function moveCard{$i}(string \$column, int \$position): bool { return true; }";
        }
        $lines[] = "}";

        return implode("\n", $lines);
    }
}
