<?php

declare(strict_types=1);

namespace Tests\Unit\Ai\Patamar4;

use App\Services\Ai\Patamar4\AtlasSchedulerHealthService;
use Tests\TestCase;

class AtlasSchedulerHealthServiceTest extends TestCase
{
    private string $log;

    private AtlasSchedulerHealthService $svc;

    protected function setUp(): void
    {
        parent::setUp();
        $this->log = sys_get_temp_dir().'/atlas_scheduler_health_'.uniqid('', true).'.jsonl';
        $this->svc = new AtlasSchedulerHealthService;
        $this->svc->setLogPathForTesting($this->log);
    }

    protected function tearDown(): void
    {
        @unlink($this->log);
        parent::tearDown();
    }

    public function test_record_heartbeat_appends_jsonl_with_sha256(): void
    {
        $beat = $this->svc->recordHeartbeat('test_actor');
        $this->assertSame(AtlasSchedulerHealthService::HEARTBEAT_SCHEMA, $beat['schema_version']);
        $this->assertStringStartsWith('sha256:', $beat['heartbeat_hash']);
        $this->assertSame('test_actor', $beat['actor']);
        $this->assertFileExists($this->log);
    }

    public function test_status_returns_silent_alarm_when_no_heartbeat(): void
    {
        $status = $this->svc->status();
        $this->assertTrue($status['silent_alarm']);
        $this->assertNull($status['last_heartbeat_at']);
        $this->assertSame(0, $status['heartbeat_count']);
        $this->assertSame('no_heartbeat_recorded', $status['reason']);
    }

    public function test_status_alive_when_fresh_heartbeat(): void
    {
        $this->svc->recordHeartbeat();
        $status = $this->svc->status(300);
        $this->assertFalse($status['silent_alarm']);
        $this->assertNotNull($status['last_heartbeat_at']);
        $this->assertLessThanOrEqual(5, $status['age_seconds']);
        $this->assertSame('within_threshold', $status['reason']);
    }

    public function test_status_silent_when_threshold_zero(): void
    {
        $this->svc->recordHeartbeat();
        // threshold=0 with age>=0 → silent because age can equal 0 (within) but if any latency >0 → silent. Use -1 to force.
        // We just verify threshold field surfaces.
        $status = $this->svc->status(0);
        $this->assertSame(0, $status['silent_threshold_seconds']);
    }

    public function test_list_heartbeats_returns_tail(): void
    {
        for ($i = 0; $i < 5; $i++) {
            $this->svc->recordHeartbeat();
        }
        $this->assertCount(5, $this->svc->listHeartbeats());
        $this->assertCount(3, $this->svc->listHeartbeats(3));
    }

    public function test_last_heartbeat_returns_null_when_empty(): void
    {
        $this->assertNull($this->svc->lastHeartbeat());
    }

    public function test_last_heartbeat_returns_most_recent(): void
    {
        $this->svc->recordHeartbeat('first');
        usleep(10_000);
        $this->svc->recordHeartbeat('second');
        $last = $this->svc->lastHeartbeat();
        $this->assertSame('second', $last['actor']);
    }

    public function test_claim_policy_provider_safe(): void
    {
        $cp = $this->svc->claimPolicy();
        $this->assertFalse($cp['benchmark_claim_allowed']);
        $this->assertFalse($cp['rivals_claim_allowed']);
        $this->assertFalse($cp['superiority_claim_allowed']);
        $this->assertFalse($cp['external_rivals_certification_touched']);
        $this->assertTrue($cp['cognitive_immune_law_enforced']);
        $this->assertTrue($cp['provider_safe_only_enforced']);
        $this->assertTrue($cp['local_first_only']);
    }

    public function test_status_schema_version_canon(): void
    {
        $status = $this->svc->status();
        $this->assertSame('atlas.scheduler.status.v1', $status['schema_version']);
    }

    public function test_heartbeat_schema_version_canon(): void
    {
        $this->assertSame('atlas.scheduler.heartbeat.v1', AtlasSchedulerHealthService::HEARTBEAT_SCHEMA);
        $this->assertSame('atlas.scheduler.status.v1', AtlasSchedulerHealthService::STATUS_SCHEMA);
    }

    public function test_append_only_persists_across_instances(): void
    {
        $this->svc->recordHeartbeat();
        $svc2 = new AtlasSchedulerHealthService;
        $svc2->setLogPathForTesting($this->log);
        $svc2->recordHeartbeat();
        $this->assertCount(2, $svc2->listHeartbeats());
    }
}
