<?php

declare(strict_types=1);

namespace Tests\Feature\Ai\Compounding;

use App\Models\AiLearningProposal;
use App\Services\Ai\AtlasDecide\AtlasConductorRoutingMemory;
use App\Services\Ai\Compounding\AtlasLearningProposalApplier;
use Tests\TestCase;

/**
 * Closes the compounding flywheel: an operator-APPROVED routing proposal, once
 * applied, actually changes the conductor's auto-routing (recommend() returns the
 * approved route) — "Atlas learns", not just "collects learning". Governed
 * (approval-gated) and reversible.
 */
class AtlasLearningProposalApplierTest extends TestCase
{
    private string $log = '';

    protected function setUp(): void
    {
        parent::setUp();
        $this->log = sys_get_temp_dir().'/atlas-routing-'.bin2hex(random_bytes(4)).'.jsonl';
    }

    protected function tearDown(): void
    {
        @unlink($this->log);
        @unlink($this->log.'.preferred.jsonl');
        parent::tearDown();
    }

    private function routing(): AtlasConductorRoutingMemory
    {
        $r = new AtlasConductorRoutingMemory();
        $r->setLogPathForTesting($this->log);

        return $r;
    }

    private function approvedRouting(string $task, string $role, string $provider, string $model = 'm'): AiLearningProposal
    {
        return (new AiLearningProposal())->forceFill([
            'kind' => 'routing',
            'status' => 'approved',
            'proposed_state' => ['task_category' => $task, 'role' => $role, 'provider' => $provider, 'model' => $model],
        ]);
    }

    public function test_applying_an_approved_routing_proposal_turns_the_flywheel(): void
    {
        $routing = $this->routing();
        $this->assertNull($routing->recommend('code_generation', 'primary'), 'precondition: no learned route yet');

        $r = (new AtlasLearningProposalApplier($routing))->apply($this->approvedRouting('code_generation', 'primary', 'hermes_cli'), 'vitor');

        $this->assertTrue($r['applied']);
        $this->assertSame('routing', $r['kind']);
        $this->assertTrue($r['reversible']);

        // The flywheel turned: the conductor's auto-routing now returns the approved route.
        $rec = $routing->recommend('code_generation', 'primary');
        $this->assertNotNull($rec);
        $this->assertSame('hermes_cli', $rec['provider']);
        $this->assertSame('operator_approved_learning', $rec['source']);
    }

    public function test_refuses_a_non_approved_proposal(): void
    {
        $proposal = $this->approvedRouting('t', 'r', 'p')->forceFill(['status' => 'proposed']);

        $r = (new AtlasLearningProposalApplier($this->routing()))->apply($proposal, 'vitor');

        $this->assertFalse($r['applied']);
        $this->assertSame('proposal_not_approved', $r['reason']);
    }

    public function test_reverse_clears_the_applied_route(): void
    {
        $routing = $this->routing();
        $applier = new AtlasLearningProposalApplier($routing);
        $proposal = $this->approvedRouting('code_generation', 'primary', 'hermes_cli');

        $applier->apply($proposal, 'vitor');
        $this->assertSame('hermes_cli', $routing->recommend('code_generation', 'primary')['provider']);

        $rev = $applier->reverse($proposal, 'vitor');

        $this->assertTrue($rev['reversed']);
        $this->assertNull($routing->recommend('code_generation', 'primary'), 'reverse clears the preferred route');
    }

    public function test_other_kinds_report_pending_applier(): void
    {
        $proposal = (new AiLearningProposal())->forceFill(['kind' => 'policy', 'status' => 'approved', 'proposed_state' => []]);

        $r = (new AtlasLearningProposalApplier($this->routing()))->apply($proposal, 'vitor');

        $this->assertFalse($r['applied']);
        $this->assertSame('kind_applier_pending:policy', $r['reason']);
    }
}
