<?php

namespace Tests\Feature\Ai\Programming\Forge;

use App\Services\Ai\Mission\MissionCanonicalHash;
use App\Services\Ai\Programming\Forge\ForgeMultiAgentScheduleCanon;
use App\Services\Ai\Programming\Forge\ForgeMultiAgentSchedulerException;
use App\Services\Ai\Programming\Forge\ForgeMultiAgentSchedulerService;
use Tests\Concerns\CreatesForgeMultiAgentScheduleTable;
use Tests\TestCase;

class ForgeMultiAgentSchedulerServiceTest extends TestCase
{
    use CreatesForgeMultiAgentScheduleTable;

    private ForgeMultiAgentSchedulerService $scheduler;

    protected function setUp(): void
    {
        parent::setUp();
        $this->createForgeMultiAgentScheduleTable();
        $this->scheduler = app(ForgeMultiAgentSchedulerService::class);
    }

    protected function tearDown(): void
    {
        $this->dropForgeMultiAgentScheduleTable();
        parent::tearDown();
    }

    public function test_simple_task_recommends_single_worker(): void
    {
        $schedule = $this->scheduler->plan(
            taskSummary: 'Fix typo in README',
            workPackets: [],
            riskBand: ForgeMultiAgentScheduleCanon::RISK_LOW,
        );

        $this->assertSame(ForgeMultiAgentScheduleCanon::STATUS_DEGENERATE_NO_PACKETS, $schedule['status']);
        $this->assertSame(1, $schedule['recommended_agent_count']);
        $this->assertSame(
            ForgeMultiAgentScheduleCanon::INTEGRATION_SINGLE_WORKER,
            $schedule['integration_plan'],
        );
        $this->assertSame(1, $schedule['roles_summary'][ForgeMultiAgentScheduleCanon::ROLE_WORKER]);
        $this->assertArrayNotHasKey(ForgeMultiAgentScheduleCanon::ROLE_VERIFIER, $schedule['roles_summary']);
    }

    public function test_independent_work_packets_recommend_one_worker_per_packet_with_parallel_no_overlap(): void
    {
        $schedule = $this->scheduler->plan(
            taskSummary: 'Refactor provider router and billing engine independently',
            workPackets: [
                [
                    'packet_id' => 'wp-router',
                    'objective' => 'router refactor',
                    'expected_files' => ['app/Services/Ai/Provider/Router.php'],
                    'dependencies' => [],
                ],
                [
                    'packet_id' => 'wp-billing',
                    'objective' => 'billing engine migration',
                    'expected_files' => ['app/Services/Billing/Engine.php'],
                    'dependencies' => [],
                ],
            ],
            riskBand: ForgeMultiAgentScheduleCanon::RISK_MEDIUM,
        );

        $this->assertSame(ForgeMultiAgentScheduleCanon::STATUS_READY, $schedule['status']);
        $this->assertSame(2, $schedule['recommended_agent_count']);
        $this->assertSame(
            ForgeMultiAgentScheduleCanon::INTEGRATION_PARALLEL_NO_OVERLAP,
            $schedule['integration_plan'],
        );
        $this->assertSame(2, $schedule['roles_summary'][ForgeMultiAgentScheduleCanon::ROLE_WORKER]);
        $this->assertSame([], $schedule['conflict_risks']);
        $this->assertEqualsCanonicalizing(['wp-router', 'wp-billing'], $schedule['dependency_order']);
    }

    public function test_high_risk_adds_verifier_role(): void
    {
        $schedule = $this->scheduler->plan(
            taskSummary: 'Migrate auth middleware',
            workPackets: [
                [
                    'packet_id' => 'wp-1',
                    'objective' => 'auth migration',
                    'expected_files' => ['app/Http/Middleware/Auth.php'],
                    'dependencies' => [],
                ],
            ],
            riskBand: ForgeMultiAgentScheduleCanon::RISK_HIGH,
        );

        $this->assertSame(ForgeMultiAgentScheduleCanon::STATUS_READY, $schedule['status']);
        $this->assertSame(2, $schedule['recommended_agent_count'], '1 worker + 1 verifier');
        $this->assertSame(1, $schedule['roles_summary'][ForgeMultiAgentScheduleCanon::ROLE_VERIFIER]);
        $this->assertSame(1, $schedule['verification_plan']['verifier_count']);
        $this->assertContains('risk_review_passed', $schedule['verification_plan']['gates_required']);
    }

    public function test_critical_risk_adds_researcher_and_verifier(): void
    {
        $schedule = $this->scheduler->plan(
            taskSummary: 'Replace storage layer (critical)',
            workPackets: [
                [
                    'packet_id' => 'wp-1',
                    'objective' => 'storage swap',
                    'expected_files' => ['app/Storage/Driver.php'],
                    'dependencies' => [],
                ],
            ],
            riskBand: ForgeMultiAgentScheduleCanon::RISK_CRITICAL,
        );

        $this->assertSame(1, $schedule['roles_summary'][ForgeMultiAgentScheduleCanon::ROLE_VERIFIER]);
        $this->assertSame(1, $schedule['roles_summary'][ForgeMultiAgentScheduleCanon::ROLE_RESEARCHER]);
    }

    public function test_ownership_conflict_blocks_parallelism_by_default(): void
    {
        $schedule = $this->scheduler->plan(
            taskSummary: 'Two packets writing the same file',
            workPackets: [
                [
                    'packet_id' => 'wp-a',
                    'objective' => 'edit shared',
                    'expected_files' => ['app/Shared.php'],
                    'dependencies' => [],
                ],
                [
                    'packet_id' => 'wp-b',
                    'objective' => 'edit shared again',
                    'expected_files' => ['app/Shared.php'],
                    'dependencies' => [],
                ],
            ],
            riskBand: ForgeMultiAgentScheduleCanon::RISK_MEDIUM,
        );

        $this->assertSame(
            ForgeMultiAgentScheduleCanon::STATUS_BLOCKED_BY_OWNERSHIP_CONFLICT,
            $schedule['status'],
        );
        $this->assertSame(ForgeMultiAgentScheduleCanon::INTEGRATION_BLOCKED, $schedule['integration_plan']);
        $this->assertSame(0, $schedule['recommended_agent_count']);
        $this->assertNotEmpty($schedule['conflict_risks']);
        $this->assertSame(
            ForgeMultiAgentScheduleCanon::CONFLICT_KIND_FILE_OVERLAP,
            $schedule['conflict_risks'][0]['kind'],
        );
        $this->assertContains('app/Shared.php', $schedule['conflict_risks'][0]['paths']);
        $this->assertSame('file_overlap_without_serialize_consent', $schedule['blocker_reason']);
    }

    public function test_allow_serialize_overrides_overlap_into_serial_merge(): void
    {
        $schedule = $this->scheduler->plan(
            taskSummary: 'Two packets writing the same file, serialised',
            workPackets: [
                [
                    'packet_id' => 'wp-a',
                    'objective' => 'edit shared',
                    'expected_files' => ['app/Shared.php'],
                    'dependencies' => [],
                ],
                [
                    'packet_id' => 'wp-b',
                    'objective' => 'edit shared again',
                    'expected_files' => ['app/Shared.php'],
                    'dependencies' => ['wp-a'],
                ],
            ],
            riskBand: ForgeMultiAgentScheduleCanon::RISK_MEDIUM,
            options: ['allow_serialize' => true],
        );

        $this->assertSame(ForgeMultiAgentScheduleCanon::STATUS_READY, $schedule['status']);
        $this->assertSame(
            ForgeMultiAgentScheduleCanon::INTEGRATION_SERIAL_MERGE_ON_OVERLAP,
            $schedule['integration_plan'],
        );
        $this->assertSame(['wp-a', 'wp-b'], $schedule['dependency_order']);
        $this->assertNotEmpty($schedule['non_overlap_constraints']);
        $this->assertSame('run_serially_in_dependency_order', $schedule['non_overlap_constraints'][0]['rule']);
    }

    public function test_unscoped_packet_blocks_multi_agent_unless_serialised(): void
    {
        $schedule = $this->scheduler->plan(
            taskSummary: 'One scoped + one unscoped',
            workPackets: [
                ['packet_id' => 'wp-a', 'objective' => 'scoped', 'expected_files' => ['a.php']],
                ['packet_id' => 'wp-b', 'objective' => 'unscoped', 'expected_files' => []],
            ],
            riskBand: ForgeMultiAgentScheduleCanon::RISK_MEDIUM,
        );

        $this->assertSame(
            ForgeMultiAgentScheduleCanon::STATUS_BLOCKED_BY_OWNERSHIP_CONFLICT,
            $schedule['status'],
        );
        $kinds = array_map(static fn ($r): string => (string) $r['kind'], $schedule['conflict_risks']);
        $this->assertContains(ForgeMultiAgentScheduleCanon::CONFLICT_KIND_UNSCOPED_PACKET, $kinds);
    }

    public function test_dependency_order_respects_topology(): void
    {
        $schedule = $this->scheduler->plan(
            taskSummary: 'three packets with chain dep',
            workPackets: [
                ['packet_id' => 'wp-c', 'objective' => 'c', 'expected_files' => ['c.php'], 'dependencies' => ['wp-b']],
                ['packet_id' => 'wp-a', 'objective' => 'a', 'expected_files' => ['a.php']],
                ['packet_id' => 'wp-b', 'objective' => 'b', 'expected_files' => ['b.php'], 'dependencies' => ['wp-a']],
            ],
            riskBand: ForgeMultiAgentScheduleCanon::RISK_LOW,
        );

        $this->assertSame(['wp-a', 'wp-b', 'wp-c'], $schedule['dependency_order']);
    }

    public function test_dependency_cycle_throws(): void
    {
        $this->expectException(ForgeMultiAgentSchedulerException::class);
        $this->scheduler->plan(
            taskSummary: 'cycle',
            workPackets: [
                ['packet_id' => 'wp-a', 'objective' => 'a', 'expected_files' => ['a.php'], 'dependencies' => ['wp-b']],
                ['packet_id' => 'wp-b', 'objective' => 'b', 'expected_files' => ['b.php'], 'dependencies' => ['wp-a']],
            ],
        );
    }

    public function test_worker_threshold_adds_coordinator_planner(): void
    {
        $packets = [];
        for ($i = 1; $i <= 6; $i++) {
            $packets[] = [
                'packet_id' => 'wp-'.$i,
                'objective' => 'feature '.$i,
                'expected_files' => ['app/Mod'.$i.'/X.php'],
                'dependencies' => [],
            ];
        }

        $schedule = $this->scheduler->plan(
            taskSummary: 'six independent features',
            workPackets: $packets,
            riskBand: ForgeMultiAgentScheduleCanon::RISK_MEDIUM,
        );

        $this->assertSame(6, $schedule['roles_summary'][ForgeMultiAgentScheduleCanon::ROLE_WORKER]);
        $this->assertSame(1, $schedule['roles_summary'][ForgeMultiAgentScheduleCanon::ROLE_PLANNER]);
    }

    public function test_failure_context_adds_debugger(): void
    {
        $schedule = $this->scheduler->plan(
            taskSummary: 'Recurring CI failure on auth flow',
            workPackets: [
                ['packet_id' => 'wp-1', 'objective' => 'auth fix', 'expected_files' => ['app/Auth.php']],
            ],
            riskBand: ForgeMultiAgentScheduleCanon::RISK_MEDIUM,
            options: ['failure_context' => true],
        );

        $this->assertSame(1, $schedule['roles_summary'][ForgeMultiAgentScheduleCanon::ROLE_DEBUGGER]);
    }

    public function test_reviewer_required_adds_reviewer(): void
    {
        $schedule = $this->scheduler->plan(
            taskSummary: 'Compliance-sensitive change',
            workPackets: [['packet_id' => 'wp-1', 'objective' => 'x', 'expected_files' => ['app/X.php']]],
            options: ['reviewer_required' => true],
        );

        $this->assertSame(1, $schedule['roles_summary'][ForgeMultiAgentScheduleCanon::ROLE_REVIEWER]);
    }

    public function test_schedule_serializes_to_stable_json_and_hash_is_deterministic(): void
    {
        $args = [
            'task_summary' => 'stable hash test',
            'work_packets' => [
                ['packet_id' => 'wp-1', 'objective' => 'a', 'expected_files' => ['a.php']],
                ['packet_id' => 'wp-2', 'objective' => 'b', 'expected_files' => ['b.php']],
            ],
            'risk_band' => ForgeMultiAgentScheduleCanon::RISK_MEDIUM,
        ];

        $a = $this->scheduler->plan(
            $args['task_summary'],
            $args['work_packets'],
            $args['risk_band'],
        );
        $b = $this->scheduler->plan(
            $args['task_summary'],
            $args['work_packets'],
            $args['risk_band'],
        );

        // schedule_uuid + created_at differ each call; hash payload excludes them.
        $this->assertSame($a['schedule_hash'], $b['schedule_hash']);

        $json = json_encode($a, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        $this->assertNotFalse($json);
        $decoded = json_decode((string) $json, true);
        $this->assertSame(ForgeMultiAgentScheduleCanon::SCHEMA_VERSION, $decoded['schema']);
        $recomputed = MissionCanonicalHash::sha256($this->hashPayload($decoded));
        $this->assertSame($a['schedule_hash'], $recomputed);
    }

    public function test_plan_and_persist_writes_a_row(): void
    {
        $row = $this->scheduler->planAndPersist(
            taskSummary: 'persist test',
            workPackets: [['packet_id' => 'wp-1', 'objective' => 'a', 'expected_files' => ['a.php']]],
            riskBand: ForgeMultiAgentScheduleCanon::RISK_LOW,
        );

        $this->assertSame(ForgeMultiAgentScheduleCanon::STATUS_READY, $row->status);
        $this->assertSame(1, (int) $row->recommended_agent_count);
        $this->assertSame(64, strlen((string) $row->schedule_hash));
    }

    public function test_empty_task_summary_throws(): void
    {
        $this->expectException(ForgeMultiAgentSchedulerException::class);
        $this->scheduler->plan('   ');
    }

    public function test_invalid_risk_band_throws(): void
    {
        $this->expectException(ForgeMultiAgentSchedulerException::class);
        $this->scheduler->plan('summary', [], 'apocalyptic');
    }

    /**
     * @param  array<string,mixed>  $decoded
     * @return array<string,mixed>
     */
    private function hashPayload(array $decoded): array
    {
        unset($decoded['schedule_hash'], $decoded['created_at'], $decoded['schedule_uuid']);

        return $decoded;
    }
}
