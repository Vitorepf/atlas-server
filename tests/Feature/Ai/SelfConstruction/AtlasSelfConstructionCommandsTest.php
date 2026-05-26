<?php

declare(strict_types=1);

namespace Tests\Feature\Ai\SelfConstruction;

use App\Services\Ai\SelfConstruction\AtlasSelfConstructionSubsystemBuilderService;
use Illuminate\Support\Facades\Artisan;
use Symfony\Component\Console\Output\BufferedOutput;
use Tests\TestCase;

class AtlasSelfConstructionCommandsTest extends TestCase
{
    private string $tmpRoot;

    protected function setUp(): void
    {
        parent::setUp();
        $this->tmpRoot = sys_get_temp_dir().'/atlas_self_cli_'.uniqid('', true);
        @mkdir($this->tmpRoot, 0775, true);
        $svc = $this->app->make(AtlasSelfConstructionSubsystemBuilderService::class);
        $svc->setProposalsLogPathForTesting($this->tmpRoot.'/proposals.jsonl');
        $svc->setApprovalsLogPathForTesting($this->tmpRoot.'/approvals.jsonl');
        $this->app->instance(AtlasSelfConstructionSubsystemBuilderService::class, $svc);
    }

    protected function tearDown(): void
    {
        foreach (glob($this->tmpRoot.'/*') ?: [] as $f) {
            @unlink($f);
        }
        @rmdir($this->tmpRoot);
        parent::tearDown();
    }

    public function test_detect_gaps_command_runs(): void
    {
        $out = new BufferedOutput;
        $code = Artisan::call('atlas:self-construction:detect-gaps', ['--json' => true], $out);
        $this->assertSame(0, $code);
        $decoded = json_decode($out->fetch(), true);
        $this->assertSame('detect-gaps', $decoded['action']);
        $this->assertArrayHasKey('gap_count', $decoded);
    }

    public function test_propose_plan_does_not_persist(): void
    {
        $out = new BufferedOutput;
        $code = Artisan::call('atlas:self-construction:propose-subsystem', [
            '--gap-kind' => 'operator_request',
            '--subsystem-acronym' => 'TEST',
            '--group' => 'self_construction',
            '--mode' => 'plan',
            '--json' => true,
        ], $out);
        $this->assertSame(0, $code);
        $decoded = json_decode($out->fetch(), true);
        $this->assertSame('planned', $decoded['status']);
    }

    public function test_propose_apply_persists_proposal(): void
    {
        $out = new BufferedOutput;
        $code = Artisan::call('atlas:self-construction:propose-subsystem', [
            '--gap-kind' => 'operator_request',
            '--subsystem-acronym' => 'TESTAPPLY',
            '--group' => 'self_construction',
            '--mode' => 'apply',
            '--check' => 'self-construction-propose',
            '--confirm' => true,
            '--json' => true,
        ], $out);
        $this->assertSame(0, $code);
        $decoded = json_decode($out->fetch(), true);
        $this->assertSame('applied', $decoded['status']);
        $this->assertArrayHasKey('proposal', $decoded['actions']);
        $this->assertSame('TESTAPPLY', $decoded['actions']['proposal']['proposed_subsystem']['acronym']);
    }

    public function test_list_proposals_command_reports_persisted_proposals(): void
    {
        Artisan::call('atlas:self-construction:propose-subsystem', [
            '--gap-kind' => 'operator_request',
            '--subsystem-acronym' => 'LISTME',
            '--group' => 'self_construction',
            '--mode' => 'apply',
            '--check' => 'self-construction-propose',
            '--confirm' => true,
            '--json' => true,
        ], new BufferedOutput);

        $out = new BufferedOutput;
        $code = Artisan::call('atlas:self-construction:list-proposals', ['--json' => true], $out);

        $this->assertSame(0, $code);
        $decoded = json_decode($out->fetch(), true);
        $this->assertSame('list-proposals', $decoded['action']);
        $this->assertSame(1, $decoded['proposal_count']);
        $this->assertSame('LISTME', $decoded['proposals'][0]['proposed_subsystem']['acronym']);
    }

    public function test_approve_apply_persists_approval_receipt(): void
    {
        $proposal = $this->app->make(AtlasSelfConstructionSubsystemBuilderService::class)->propose([
            'gap_kind' => AtlasSelfConstructionSubsystemBuilderService::GAP_OPERATOR_REQUEST,
            'subsystem_acronym' => 'APPROVEME',
            'group' => 'self_construction',
            'rationale' => 'test approval path',
        ]);

        $out = new BufferedOutput;
        $code = Artisan::call('atlas:self-construction:approve-proposal', [
            '--proposal-id' => $proposal['proposal_id'],
            '--proposal-hash' => $proposal['proposal_hash'],
            '--action' => 'approve',
            '--mode' => 'apply',
            '--check' => 'self-construction-approve',
            '--confirm' => true,
            '--json' => true,
        ], $out);

        $this->assertSame(0, $code);
        $decoded = json_decode($out->fetch(), true);
        $this->assertSame('applied', $decoded['status']);
        $this->assertSame('approve', $decoded['actions']['approval_receipt']['action']);
    }
}
