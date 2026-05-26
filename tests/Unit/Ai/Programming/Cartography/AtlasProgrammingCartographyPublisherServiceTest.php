<?php

declare(strict_types=1);

namespace Tests\Unit\Ai\Programming\Cartography;

use App\Services\Ai\Programming\Cartography\AtlasProgrammingCartographyPublisherService;
use Tests\TestCase;

class AtlasProgrammingCartographyPublisherServiceTest extends TestCase
{
    private string $log;

    private AtlasProgrammingCartographyPublisherService $svc;

    protected function setUp(): void
    {
        parent::setUp();
        $this->log = sys_get_temp_dir().'/atlas_carto_'.uniqid('', true).'.jsonl';
        $this->svc = new AtlasProgrammingCartographyPublisherService;
        $this->svc->setSnapshotsLogPathForTesting($this->log);
    }

    protected function tearDown(): void
    {
        @unlink($this->log);
        parent::tearDown();
    }

    public function test_publish_returns_canonical_envelope(): void
    {
        $s = $this->svc->publish();
        $this->assertSame(AtlasProgrammingCartographyPublisherService::SCHEMA_VERSION, $s['schema_version']);
        $this->assertStringStartsWith('carto_', $s['snapshot_id']);
        $this->assertStringStartsWith('sha256:', $s['snapshot_hash']);
        $this->assertArrayHasKey('nodes', $s);
        $this->assertArrayHasKey('edges', $s);
        $this->assertArrayHasKey('stats', $s);
    }

    public function test_publish_defensive_when_no_data(): void
    {
        $s = $this->svc->publish();
        // Even when DB tables are empty, publisher returns honest empty graph.
        $this->assertIsArray($s['nodes']);
        $this->assertIsArray($s['edges']);
        $this->assertGreaterThanOrEqual(0, $s['stats']['node_count']);
        $this->assertGreaterThanOrEqual(0, $s['stats']['edge_count']);
    }

    public function test_claim_policy_safe(): void
    {
        $s = $this->svc->publish();
        $cp = $s['claim_policy'];
        $this->assertFalse($cp['benchmark_claim_allowed']);
        $this->assertFalse($cp['rivals_claim_allowed']);
        $this->assertFalse($cp['superiority_claim_allowed']);
        $this->assertFalse($cp['aggregate_winner_claim_allowed']);
    }

    public function test_stats_by_kind_has_all_canon_kinds(): void
    {
        $s = $this->svc->publish();
        $expected = [
            AtlasProgrammingCartographyPublisherService::NODE_KIND_WORK_ITEM,
            AtlasProgrammingCartographyPublisherService::NODE_KIND_SPEC,
            AtlasProgrammingCartographyPublisherService::NODE_KIND_TASK,
            AtlasProgrammingCartographyPublisherService::NODE_KIND_FILE,
            AtlasProgrammingCartographyPublisherService::NODE_KIND_EVIDENCE,
            AtlasProgrammingCartographyPublisherService::NODE_KIND_DRIFT,
        ];
        foreach ($expected as $k) {
            $this->assertArrayHasKey($k, $s['stats']['by_kind']);
        }
    }

    public function test_workspace_filter_recorded_in_scope(): void
    {
        $s = $this->svc->publish(['workspace' => '/repos/atlas']);
        $this->assertSame('/repos/atlas', $s['scope']['workspace']);
    }

    public function test_limit_clamped(): void
    {
        $s = $this->svc->publish(['limit' => 9999]);
        $this->assertLessThanOrEqual(500, $s['scope']['limit']);
        $s = $this->svc->publish(['limit' => 0]);
        $this->assertGreaterThanOrEqual(1, $s['scope']['limit']);
    }

    public function test_snapshots_persisted_append_only(): void
    {
        $this->svc->publish();
        $this->svc->publish();
        $this->assertCount(2, $this->svc->listSnapshots());
    }

    public function test_latest_snapshot_returns_last(): void
    {
        $this->assertNull($this->svc->latestSnapshot());
        $a = $this->svc->publish(['workspace' => 'w1']);
        $b = $this->svc->publish(['workspace' => 'w2']);
        $last = $this->svc->latestSnapshot();
        $this->assertSame($b['snapshot_id'], $last['snapshot_id']);
    }

    public function test_node_kinds_constants_match_schema(): void
    {
        $this->assertSame('work_item', AtlasProgrammingCartographyPublisherService::NODE_KIND_WORK_ITEM);
        $this->assertSame('spec', AtlasProgrammingCartographyPublisherService::NODE_KIND_SPEC);
        $this->assertSame('task', AtlasProgrammingCartographyPublisherService::NODE_KIND_TASK);
        $this->assertSame('file', AtlasProgrammingCartographyPublisherService::NODE_KIND_FILE);
        $this->assertSame('evidence', AtlasProgrammingCartographyPublisherService::NODE_KIND_EVIDENCE);
        $this->assertSame('drift_report', AtlasProgrammingCartographyPublisherService::NODE_KIND_DRIFT);
    }

    public function test_edge_kinds_constants(): void
    {
        $this->assertSame('has_spec', AtlasProgrammingCartographyPublisherService::EDGE_HAS_SPEC);
        $this->assertSame('contains_task', AtlasProgrammingCartographyPublisherService::EDGE_CONTAINS_TASK);
        $this->assertSame('touches', AtlasProgrammingCartographyPublisherService::EDGE_TOUCHES);
        $this->assertSame('evidenced_by', AtlasProgrammingCartographyPublisherService::EDGE_EVIDENCED_BY);
        $this->assertSame('has_drift', AtlasProgrammingCartographyPublisherService::EDGE_HAS_DRIFT);
    }
}
