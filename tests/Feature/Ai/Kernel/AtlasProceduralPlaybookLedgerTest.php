<?php

declare(strict_types=1);

namespace Tests\Feature\Ai\Kernel;

use App\Services\Ai\Kernel\Procedural\AtlasProceduralPlaybookLedger;
use App\Services\Ai\Kernel\Procedural\ProceduralPlaybook;
use Tests\TestCase;

/**
 * ATLAS BUILD #3 SLICE 1 gate: a general, task-agnostic procedural playbook
 * survives a define→retrieve round-trip with all FIVE canonical fields intact,
 * matches by task category (case/space-insensitive), and degrades to null
 * (never a fabricated playbook) when nothing matches.
 */
class AtlasProceduralPlaybookLedgerTest extends TestCase
{
    private function ledger(): AtlasProceduralPlaybookLedger
    {
        $path = tempnam(sys_get_temp_dir(), 'procedural_playbook_').'.jsonl';
        @unlink($path);

        return new AtlasProceduralPlaybookLedger($path);
    }

    public function test_define_then_retrieve_preserves_all_five_fields(): void
    {
        $ledger = $this->ledger();

        $ledger->define(new ProceduralPlaybook(
            taskCategory: 'backend_bugfix',
            objective: 'Fix the bug at its root, not the symptom',
            steps: ['reproduce with a failing test', 'grep all callers', 'fix the shared function'],
            postconditions: ['the failing test now passes', 'no sibling caller regressed'],
            forbiddenActions: ['edit the test to hide the bug', 'patch only the reported path'],
            priorCorrections: ['step "skip the repro" failed → always reproduce first'],
        ));

        $pb = $ledger->retrieve('backend_bugfix');

        $this->assertNotNull($pb);
        $this->assertSame('backend_bugfix', $pb->taskCategory);
        $this->assertSame('Fix the bug at its root, not the symptom', $pb->objective);
        $this->assertCount(3, $pb->steps);
        $this->assertSame('grep all callers', $pb->steps[1]);
        $this->assertCount(2, $pb->postconditions);
        $this->assertCount(2, $pb->forbiddenActions);
        $this->assertSame(['step "skip the repro" failed → always reproduce first'], $pb->priorCorrections);
    }

    public function test_match_is_case_and_space_insensitive(): void
    {
        $ledger = $this->ledger();

        $ledger->define(new ProceduralPlaybook(
            taskCategory: 'Frontend_Refactor',
            objective: 'refactor safely',
            steps: ['run tests'],
            postconditions: ['green suite'],
            forbiddenActions: ['skip verification'],
        ));

        $this->assertNotNull($ledger->retrieve('  frontend_refactor '));
        $this->assertSame('refactor safely', $ledger->retrieve('FRONTEND_REFACTOR')->objective);
    }

    public function test_latest_definition_supersedes(): void
    {
        $ledger = $this->ledger();

        $ledger->define(new ProceduralPlaybook('migration', 'v1', ['old step'], [], []));
        $ledger->define(new ProceduralPlaybook('migration', 'v2', ['new step'], ['migrated'], []));

        $pb = $ledger->retrieve('migration');
        $this->assertSame('v2', $pb->objective);
        $this->assertSame(['new step'], $pb->steps);
    }

    public function test_retrieve_unknown_category_is_null_not_fabricated(): void
    {
        $this->assertNull($this->ledger()->retrieve('never_defined'));
    }

    public function test_empty_category_is_never_stored(): void
    {
        $ledger = $this->ledger();
        $ledger->define(new ProceduralPlaybook('   ', 'objective', ['step'], [], []));

        $this->assertNull($ledger->retrieve('   '));
    }
}
