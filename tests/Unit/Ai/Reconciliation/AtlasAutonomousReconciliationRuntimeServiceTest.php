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

        $this->svc = new AtlasAutonomousReconciliationRuntimeService($cfa, $admission, $this->aurg);
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
        $svc = new AtlasAutonomousReconciliationRuntimeService($cfa, $admission, $this->aurg);
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
}
