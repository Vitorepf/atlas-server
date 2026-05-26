<?php

declare(strict_types=1);

namespace Tests\Unit\Ai\Patamar4;

use App\Services\Ai\Patamar4\AtlasEmbodimentIntegrationService;
use Tests\TestCase;

class AtlasEmbodimentIntegrationServiceTest extends TestCase
{
    private string $log;

    private AtlasEmbodimentIntegrationService $svc;

    protected function setUp(): void
    {
        parent::setUp();
        $u = uniqid('', true);
        $this->log = sys_get_temp_dir()."/atlas_embodiment_{$u}.jsonl";
        $this->svc = $this->app->make(AtlasEmbodimentIntegrationService::class);
        $this->svc->setLogPathForTesting($this->log);
    }

    protected function tearDown(): void
    {
        @unlink($this->log);
        parent::tearDown();
    }

    public function test_snapshot_carries_canonical_envelope(): void
    {
        $env = $this->svc->snapshot();
        $this->assertSame('atlas.embodiment.envelope.v1', $env['schema_version']);
        $this->assertStringStartsWith('sha256:', $env['embodiment_hash']);
        $this->assertArrayHasKey('loci', $env);
        $this->assertSame(['mac', 'voice', 'stackchan', 'cartography'], array_keys($env['loci']));
    }

    public function test_default_active_locus_is_mac(): void
    {
        $env = $this->svc->snapshot();
        $this->assertSame('mac', $env['active_locus']);
    }

    public function test_set_active_locus_works(): void
    {
        $this->svc->setActiveLocus('voice');
        $env = $this->svc->snapshot();
        $this->assertSame('voice', $env['active_locus']);
    }

    public function test_unknown_locus_throws(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->svc->setActiveLocus('hologram');
    }

    public function test_set_readiness_persists(): void
    {
        $this->svc->setLocusReadiness('stackchan', 'ready');
        $env = $this->svc->snapshot();
        $this->assertSame('ready', $env['loci']['stackchan']);
    }

    public function test_all_ready_when_all_loci_ready(): void
    {
        foreach (AtlasEmbodimentIntegrationService::LOCI as $locus) {
            $this->svc->setLocusReadiness($locus, 'ready');
        }
        $this->assertTrue($this->svc->allReady());
        $env = $this->svc->snapshot();
        $this->assertTrue($env['all_loci_ready']);
    }

    public function test_priority_announcement_clamped_to_280(): void
    {
        $long = str_repeat('a', 500);
        $this->svc->setPriorityAnnouncement($long);
        $env = $this->svc->snapshot();
        $this->assertSame(280, mb_strlen($env['priority_announcement']));
    }

    public function test_unknown_readiness_throws(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->svc->setLocusReadiness('voice', 'transcendent');
    }

    public function test_jsonl_persists_snapshots(): void
    {
        $this->svc->snapshot();
        $this->svc->snapshot();
        $this->assertCount(2, $this->svc->listSnapshots());
    }

    public function test_claim_policy_sensitive_local_first(): void
    {
        $cp = $this->svc->claimPolicy();
        $this->assertTrue($cp['sensitive_data_never_leaves_mac']);
        $this->assertTrue($cp['local_first_only']);
        $this->assertFalse($cp['benchmark_claim_allowed']);
    }
}
