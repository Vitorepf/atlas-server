<?php

declare(strict_types=1);

namespace Tests\Feature\Ai\Kernel;

use App\Services\Ai\Kernel\Repair\AtlasRepairPlaybookLedger;
use Tests\TestCase;

/**
 * T4-S3 foundation: the repair OUTCOME corpus must credit a domain's repair as
 * resolved ONLY when a task that got a decision actually passes later, and must
 * degrade to `unmeasured` (never a fabricated rate) when empty. This is the
 * honest signal that was missing (RepairResult has no success flag).
 */
class AtlasRepairPlaybookLedgerTest extends TestCase
{
    private function ledger(): AtlasRepairPlaybookLedger
    {
        $path = tempnam(sys_get_temp_dir(), 'repair_playbook_').'.jsonl';
        @unlink($path);

        return new AtlasRepairPlaybookLedger($path);
    }

    public function test_decision_then_pass_yields_measured_resolution_rate(): void
    {
        $ledger = $this->ledger();

        $ledger->recordDecision('atlas_task:1', 'rag_gate', 'requery_world_model');
        $ledger->recordResolved('atlas_task:1'); // task 1 later passed
        $ledger->recordDecision('atlas_task:2', 'rag_gate', 'requery_world_model'); // still open

        $pb = $ledger->playbookFor('rag_gate');

        $this->assertSame('measured', $pb['status']);
        $this->assertSame(2, $pb['attempts']);
        $this->assertSame(1, $pb['resolved']);
        $this->assertSame(0.5, $pb['resolve_rate']);
        $strat = collect($pb['by_strategy'])->firstWhere('strategy', 'requery_world_model');
        $this->assertSame(2, $strat['attempts']);
        $this->assertSame(1, $strat['resolved']);
    }

    public function test_unmeasured_when_corpus_empty(): void
    {
        $pb = $this->ledger()->playbookFor('rag_gate');

        $this->assertSame('unmeasured', $pb['status']);
        $this->assertSame(0, $pb['attempts']);
        $this->assertSame(0.0, $pb['resolve_rate']);
    }

    public function test_resolution_without_prior_decision_is_noop(): void
    {
        $ledger = $this->ledger();

        $ledger->recordResolved('atlas_task:99'); // no decision was ever recorded

        $pb = $ledger->playbookFor('rag_gate');
        $this->assertSame(0, $pb['resolved']);
        $this->assertSame(0, $pb['attempts']);
    }

    public function test_double_pass_credits_resolution_once(): void
    {
        $ledger = $this->ledger();

        $ledger->recordDecision('atlas_task:7', 'compilation', 'create_repair_step');
        $ledger->recordResolved('atlas_task:7');
        $ledger->recordResolved('atlas_task:7'); // second pass: already resolved → no-op

        $pb = $ledger->playbookFor('compilation');
        $this->assertSame(1, $pb['attempts']);
        $this->assertSame(1, $pb['resolved']);
        $this->assertSame(1.0, $pb['resolve_rate']);
    }
}
