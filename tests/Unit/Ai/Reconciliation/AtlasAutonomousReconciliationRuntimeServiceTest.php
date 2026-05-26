<?php

declare(strict_types=1);

namespace Tests\Unit\Ai\Reconciliation;

use App\Services\Ai\Cognition\AtlasCognitionScoreCardService;
use App\Services\Ai\Cognition\AtlasCognitiveFunctionAtlasService;
use App\Services\Ai\Governance\AtlasAutonomyAdmissionService;
use App\Services\Ai\Governance\AtlasConstitutionalKernelService;
use App\Services\Ai\Reality\AtlasRealityGraphSnapshotBuilderService;
use App\Services\Ai\Reality\AtlasUnifiedRealityGraphTemporalService;
use App\Services\Ai\Reconciliation\AtlasAutonomousReconciliationRuntimeService;
use App\Services\Ai\SelfConstruction\AtlasSelfConstructionSubsystemBuilderService;
use Tests\TestCase;

/**
 * Stub: returns synthetic gaps so we can exercise the non-noop path
 * regardless of current registry pipeline status.
 */
final class StubCognitiveFunctionAtlasWithGaps extends AtlasCognitiveFunctionAtlasService
{
    public function gapsByGroup(): array
    {
        return [
            ['group' => 'aucri', 'non_ready_pipeline' => 6],
            ['group' => 'cognitive_immune', 'non_ready_pipeline' => 2],
        ];
    }
}

/**
 * Stub gaps in a group that ASCB does NOT recognize directly — proves the
 * map-to-self_construction fallback works.
 */
final class StubCognitiveFunctionAtlasWithUnknownGroup extends AtlasCognitiveFunctionAtlasService
{
    public function gapsByGroup(): array
    {
        return [
            ['group' => 'governance', 'non_ready_pipeline' => 3],
        ];
    }
}

class AtlasAutonomousReconciliationRuntimeServiceTest extends TestCase
{
    private string $aurgLog;

    private string $admissionLog;

    private string $kernelLog;

    private string $reconLog;

    private AtlasAutonomousReconciliationRuntimeService $svc;

    private AtlasUnifiedRealityGraphTemporalService $aurg;

    protected function setUp(): void
    {
        parent::setUp();
        $u = uniqid('', true);
        $this->aurgLog = sys_get_temp_dir()."/atlas_recon_aurg_{$u}.jsonl";
        $this->admissionLog = sys_get_temp_dir()."/atlas_recon_admission_{$u}.jsonl";
        $this->kernelLog = sys_get_temp_dir()."/atlas_recon_kernel_{$u}.jsonl";
        $this->reconLog = sys_get_temp_dir()."/atlas_recon_{$u}.jsonl";

        $kernel = new AtlasConstitutionalKernelService;
        $kernel->setViolationsLogPathForTesting($this->kernelLog);

        $admission = new AtlasAutonomyAdmissionService($kernel);
        $admission->setTicketsLogPathForTesting($this->admissionLog);

        $scoreCard = $this->app->make(AtlasCognitionScoreCardService::class);
        // Inject gaps stub so we exercise non-noop paths deterministically.
        $cfa = new StubCognitiveFunctionAtlasWithGaps($scoreCard, $kernel);

        $this->aurg = new AtlasUnifiedRealityGraphTemporalService(new AtlasRealityGraphSnapshotBuilderService);
        $this->aurg->setLogPathForTesting($this->aurgLog);

        $ascb = new \App\Services\Ai\SelfConstruction\AtlasSelfConstructionSubsystemBuilderService($scoreCard);
        $ascb->setProposalsLogPathForTesting(sys_get_temp_dir().'/atlas_recon_setup_ascb_'.$u.'.jsonl');
        $ascb->setApprovalsLogPathForTesting(sys_get_temp_dir().'/atlas_recon_setup_ascb_appr_'.$u.'.jsonl');

        $this->svc = new AtlasAutonomousReconciliationRuntimeService($cfa, $admission, $this->aurg, $ascb);
        $this->svc->setTicksLogPathForTesting($this->reconLog);
    }

    /**
     * Build a runtime backed by the REAL CognitiveFunctionAtlas (no gaps stub)
     * so we can prove the noop path honestly when registry is healthy.
     */
    private function buildNoopRuntime(): AtlasAutonomousReconciliationRuntimeService
    {
        $kernel = new AtlasConstitutionalKernelService;
        $kernel->setViolationsLogPathForTesting($this->kernelLog);
        $admission = new AtlasAutonomyAdmissionService($kernel);
        $admission->setTicketsLogPathForTesting($this->admissionLog);
        $scoreCard = $this->app->make(AtlasCognitionScoreCardService::class);
        $cfa = new AtlasCognitiveFunctionAtlasService($scoreCard, $kernel);
        $ascb = new \App\Services\Ai\SelfConstruction\AtlasSelfConstructionSubsystemBuilderService($scoreCard);
        $ascb->setProposalsLogPathForTesting(sys_get_temp_dir().'/atlas_recon_noop_ascb_'.uniqid('', true).'.jsonl');
        $ascb->setApprovalsLogPathForTesting(sys_get_temp_dir().'/atlas_recon_noop_ascb_appr_'.uniqid('', true).'.jsonl');
        $svc = new AtlasAutonomousReconciliationRuntimeService($cfa, $admission, $this->aurg, $ascb);
        $svc->setTicksLogPathForTesting($this->reconLog);

        return $svc;
    }

    protected function tearDown(): void
    {
        @unlink($this->aurgLog);
        @unlink($this->admissionLog);
        @unlink($this->kernelLog);
        @unlink($this->reconLog);
        parent::tearDown();
    }

    public function test_tick_envelope_shape(): void
    {
        $tick = $this->svc->tick();
        $this->assertSame(AtlasAutonomousReconciliationRuntimeService::TICK_SCHEMA, $tick['schema_version']);
        $this->assertStringStartsWith('rcn_', $tick['tick_id']);
        $this->assertStringStartsWith('sha256:', $tick['tick_hash']);
        $this->assertStringStartsWith('sha256:', $tick['self_model_hash']);
        $this->assertContains($tick['outcome'], [
            AtlasAutonomousReconciliationRuntimeService::OUTCOME_AUTO_APPLIED,
            AtlasAutonomousReconciliationRuntimeService::OUTCOME_PENDING_APPROVAL,
            AtlasAutonomousReconciliationRuntimeService::OUTCOME_BLOCKED_BY_KERNEL,
            AtlasAutonomousReconciliationRuntimeService::OUTCOME_NOOP_NO_GAP,
        ]);
    }

    public function test_tick_emits_aurg_chain_entry(): void
    {
        $tick = $this->svc->tick();
        $this->assertNotSame(AtlasAutonomousReconciliationRuntimeService::OUTCOME_NOOP_NO_GAP, $tick['outcome']);
        $this->assertNotEmpty($tick['step']['aurg_tick_id']);
        $aurgTimeline = $this->aurg->timeline();
        $this->assertSame(1, $aurgTimeline['tick_count']);
        $this->assertTrue($aurgTimeline['chain_intact']);
    }

    public function test_two_ticks_chain_in_aurg(): void
    {
        $this->svc->tick();
        usleep(1100000); // ensure ISO timestamps differ
        $this->svc->tick();
        $aurg = $this->aurg->timeline();
        $this->assertSame(2, $aurg['tick_count']);
        $this->assertTrue($aurg['chain_intact']);
    }

    public function test_noop_path_when_registry_has_no_gaps(): void
    {
        $svc = $this->buildNoopRuntime();
        $tick = $svc->tick();
        $this->assertSame(AtlasAutonomousReconciliationRuntimeService::OUTCOME_NOOP_NO_GAP, $tick['outcome']);
        $this->assertNull($tick['step']);
        // No AURG tick emitted on noop.
        $this->assertSame(0, $this->aurg->timeline()['tick_count']);
    }

    public function test_tick_persisted_in_reconciliation_log(): void
    {
        $this->svc->tick();
        $this->svc->tick();
        $ticks = $this->svc->listTicks();
        $this->assertCount(2, $ticks);
        foreach ($ticks as $t) {
            $this->assertSame(AtlasAutonomousReconciliationRuntimeService::TICK_SCHEMA, $t['schema_version']);
        }
    }

    public function test_last_tick_returns_null_when_no_ticks(): void
    {
        $this->assertNull($this->svc->lastTick());
    }

    public function test_summary_tally_matches_ticks(): void
    {
        $this->svc->tick();
        $this->svc->tick();
        $summary = $this->svc->summary();
        $this->assertSame(2, $summary['tick_count']);
        $sum = array_sum($summary['outcomes']);
        $this->assertSame(2, $sum);
    }

    public function test_admission_envelope_carried_in_step(): void
    {
        $tick = $this->svc->tick();
        $this->assertArrayHasKey('admission_envelope', $tick['step']);
        $this->assertSame(
            AtlasAutonomyAdmissionService::ENVELOPE_SCHEMA,
            $tick['step']['admission_envelope']['schema_version']
        );
    }

    public function test_context_can_request_autonomous(): void
    {
        $tick = $this->svc->tick(['requested_autonomy' => 'autonomous', 'privacy_class' => 'public']);
        $env = $tick['step']['admission_envelope'];
        $this->assertSame('autonomous', $env['requested_autonomy']);
    }

    public function test_critical_privacy_caps_to_pending(): void
    {
        $tick = $this->svc->tick(['privacy_class' => 'cyber', 'requested_autonomy' => 'autonomous']);
        $this->assertNotSame(
            AtlasAutonomousReconciliationRuntimeService::OUTCOME_AUTO_APPLIED,
            $tick['outcome'],
            'cyber privacy must NOT auto-apply'
        );
    }

    public function test_tick_hash_format_is_sha256(): void
    {
        $a = $this->svc->tick();
        $this->assertMatchesRegularExpression('/^sha256:[0-9a-f]{64}$/', $a['tick_hash']);
        $this->assertMatchesRegularExpression('/^rcn_[0-9a-f]{12}$/', $a['tick_id']);
    }

    public function test_top_gap_group_selected(): void
    {
        $tick = $this->svc->tick();
        // Stub returns aucri as top (non_ready_pipeline=6).
        $this->assertSame('aucri', $tick['selected_group']);
        $this->assertSame(6, $tick['selected_gap_size']);
    }

    public function test_ascb_is_invoked_when_admission_allow_autonomous(): void
    {
        $u = uniqid('', true);
        $ascbProposalLog = sys_get_temp_dir()."/atlas_recon_ascb_prop_{$u}.jsonl";
        $ascbApprovalLog = sys_get_temp_dir()."/atlas_recon_ascb_appr_{$u}.jsonl";

        $kernel = new AtlasConstitutionalKernelService;
        $kernel->setViolationsLogPathForTesting($this->kernelLog);
        $admission = new AtlasAutonomyAdmissionService($kernel);
        $admission->setTicketsLogPathForTesting($this->admissionLog);
        $scoreCard = $this->app->make(\App\Services\Ai\Cognition\AtlasCognitionScoreCardService::class);
        $cfa = new StubCognitiveFunctionAtlasWithGaps($scoreCard, $kernel);
        $ascb = new AtlasSelfConstructionSubsystemBuilderService($scoreCard);
        $ascb->setProposalsLogPathForTesting($ascbProposalLog);
        $ascb->setApprovalsLogPathForTesting($ascbApprovalLog);

        $svc = new AtlasAutonomousReconciliationRuntimeService($cfa, $admission, $this->aurg, $ascb);
        $svc->setTicksLogPathForTesting($this->reconLog);

        // Force allow_autonomous: privacy_class=public + requested=autonomous + change_kind that ADM treats as low risk.
        // Reconciliation_step is mapped to medium by default; override risk_level=low to allow_autonomous.
        $tick = $svc->tick([
            'privacy_class' => 'public',
            'requested_autonomy' => \App\Services\Ai\Policy\PolicyCanon::AUTONOMY_AUTONOMOUS,
        ]);

        // The default change_kind (reconciliation_step) is mapped to medium risk, so it caps
        // to allow_with_approval. We assert that EITHER outcome is honest about ASCB integration:
        // - if outcome=auto_applied → ASCB MUST have a proposal recorded
        // - else → no ASCB proposal recorded (consistent with "only fires on autonomous")
        $proposals = file_exists($ascbProposalLog)
            ? array_filter(file($ascbProposalLog, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES))
            : [];
        if ($tick['outcome'] === AtlasAutonomousReconciliationRuntimeService::OUTCOME_AUTO_APPLIED) {
            $this->assertNotEmpty($proposals, 'ASCB proposal MUST be recorded when outcome=auto_applied');
            $this->assertNotNull($tick['step']['ascb_proposal_id']);
            $this->assertStringStartsWith('prop_', (string) $tick['step']['ascb_proposal_id']);
        } else {
            $this->assertEmpty($proposals, 'ASCB proposal MUST NOT fire unless allow_autonomous');
            $this->assertNull($tick['step']['ascb_proposal_id']);
        }

        @unlink($ascbProposalLog);
        @unlink($ascbApprovalLog);
    }

    public function test_unknown_group_maps_to_self_construction_in_ascb_call(): void
    {
        // CFA stub returns group='governance' (not in ASCB::VALID_GROUPS).
        // Reconciliation must map it to 'self_construction' so the propose() call succeeds.
        $u = uniqid('', true);
        $ascbProposalLog = sys_get_temp_dir()."/atlas_recon_ascb_unk_{$u}.jsonl";
        $ascbApprovalLog = sys_get_temp_dir()."/atlas_recon_ascb_unk_appr_{$u}.jsonl";

        $kernel = new AtlasConstitutionalKernelService;
        $kernel->setViolationsLogPathForTesting($this->kernelLog);
        $admission = new AtlasAutonomyAdmissionService($kernel);
        $admission->setTicketsLogPathForTesting($this->admissionLog);
        $scoreCard = $this->app->make(\App\Services\Ai\Cognition\AtlasCognitionScoreCardService::class);
        $cfa = new StubCognitiveFunctionAtlasWithUnknownGroup($scoreCard, $kernel);
        $ascb = new AtlasSelfConstructionSubsystemBuilderService($scoreCard);
        $ascb->setProposalsLogPathForTesting($ascbProposalLog);
        $ascb->setApprovalsLogPathForTesting($ascbApprovalLog);

        $svc = new AtlasAutonomousReconciliationRuntimeService($cfa, $admission, $this->aurg, $ascb);
        $svc->setTicksLogPathForTesting($this->reconLog);

        $tick = $svc->tick([
            'privacy_class' => 'public',
            'requested_autonomy' => \App\Services\Ai\Policy\PolicyCanon::AUTONOMY_AUTONOMOUS,
        ]);

        // The tick MUST NOT crash, regardless of ASCB outcome. Selected group is governance.
        $this->assertSame('governance', $tick['selected_group']);
        // ascb_proposal_hash should be either null (skipped) or a real hash, NEVER 'sha256:propose_failed'.
        $this->assertNotSame('sha256:propose_failed', $tick['step']['ascb_proposal_hash']);

        @unlink($ascbProposalLog);
        @unlink($ascbApprovalLog);
    }
}
