<?php

namespace Tests\Feature\Ai\MarketingDomain;

use App\Services\Ai\MarketingDomain\CampaignPlanService;
use App\Services\Ai\MarketingDomain\CopyBriefService;
use App\Services\Ai\MarketingDomain\FunnelPlanService;
use App\Services\Ai\MarketingDomain\ICPPositioningService;
use App\Services\Ai\MarketingDomain\MarketingControlPlaneProjection;
use App\Services\Ai\MarketingDomain\MarketingDomainCanon;
use App\Services\Ai\MarketingDomain\MarketingRuntimeService;
use Tests\Concerns\CreatesMarketingDomainTables;
use Tests\TestCase;

class MarketingRuntimeSmokeTest extends TestCase
{
    use CreatesMarketingDomainTables;

    protected function setUp(): void
    {
        parent::setUp();
        $this->createMarketingDomainTables();
    }

    protected function tearDown(): void
    {
        $this->dropMarketingDomainTables();
        parent::tearDown();
    }

    public function test_smoke_run_produces_all_required_artifacts_and_certifies(): void
    {
        $payload = app(MarketingRuntimeService::class)->smokeRun();
        $run = $payload['run'];

        $this->assertSame(MarketingDomainCanon::CERT_PASSED, $run->certification_status);
        $this->assertSame(MarketingDomainCanon::STATUS_COMPLETED, $run->status);
        $this->assertNotEmpty($run->certification_hash);
        $this->assertNotEmpty($run->evidence_pack_hash);

        $artifactTypes = $run->artifacts()->pluck('artifact_type')->unique()->all();
        foreach (MarketingDomainCanon::REQUIRED_ARTIFACT_TYPES as $required) {
            $this->assertContains($required, $artifactTypes, "missing required artifact [{$required}]");
        }

        $gates = $run->approvalGates()->pluck('gate_type')->unique()->all();
        $this->assertContains(MarketingDomainCanon::GATE_PUBLISH, $gates);
        $this->assertContains(MarketingDomainCanon::GATE_PAID_MEDIA, $gates);
        $this->assertContains(MarketingDomainCanon::GATE_LAUNCH_EXPERIMENT, $gates);
    }

    public function test_certification_fails_when_campaign_lacks_approval_gate(): void
    {
        $service = app(MarketingRuntimeService::class);
        $run = $service->open('p', 'o');
        app(ICPPositioningService::class)->defineICP($run, [
            'segment' => 'X', 'pains' => ['a'], 'jobs_to_be_done' => ['b'], 'gains' => ['c'], 'channels' => ['d'],
        ]);
        app(ICPPositioningService::class)->definePositioning($run, [
            'promise' => 'p', 'differentiation' => 'd', 'proof_points' => ['x'],
        ]);
        app(CampaignPlanService::class)->plan($run, [
            'name' => 'C', 'objective' => 'O',
            'channels' => ['email'], 'kpis' => [['name' => 'x', 'target' => 1]],
            'budget_proposed' => 100.0,
        ]);
        app(CopyBriefService::class)->draft($run, [
            'headline' => 'H', 'audience' => 'A', 'channel' => 'c', 'message' => 'M', 'call_to_action' => 'CTA',
        ]);
        app(FunnelPlanService::class)->plan($run, [
            'stages' => [
                ['name' => 'a', 'metric' => 'm', 'target_conversion' => 1],
                ['name' => 'b', 'metric' => 'm', 'target_conversion' => 0.5],
                ['name' => 'c', 'metric' => 'm', 'target_conversion' => 0.1],
            ],
        ]);

        $run = $service->certify($run);
        $this->assertSame(MarketingDomainCanon::CERT_FAILED, $run->certification_status);
        $this->assertNotEmpty($run->missing_requirements);
        $found = false;
        foreach ($run->missing_requirements as $reason) {
            if (str_starts_with((string) $reason, 'campaign_missing_approval_gate')) {
                $found = true;
                break;
            }
        }
        $this->assertTrue($found, 'expected campaign_missing_approval_gate in missing_requirements');
    }

    public function test_control_plane_snapshot_aggregates_smoke_data(): void
    {
        app(MarketingRuntimeService::class)->smokeRun();
        $snapshot = app(MarketingControlPlaneProjection::class)->snapshot();

        $this->assertSame('atlas.ai.marketing_domain.control_plane.v1', $snapshot['schema']);
        $this->assertSame(1, $snapshot['runs']['count']);
        $this->assertGreaterThanOrEqual(7, $snapshot['artifacts']['count']);
        $this->assertSame(1, $snapshot['experiments']['count']);
        $this->assertSame(3, $snapshot['approval_gates']['count']);
        $this->assertArrayHasKey(MarketingDomainCanon::GATE_PAID_MEDIA, $snapshot['approval_gates']['by_type']);
    }
}
