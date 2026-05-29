<?php

declare(strict_types=1);

namespace Tests\Unit\Ai\SoftwareCompanyStewardship\AreaFocusLoop;

use App\Services\Ai\SoftwareCompanyStewardship\AreaFocusLoop\LongHorizonLoopDeliveryLedgerService;
use Tests\TestCase;

final class LongHorizonLoopDeliveryLedgerServiceTest extends TestCase
{
    private string $storageRoot;

    protected function setUp(): void
    {
        parent::setUp();
        $this->storageRoot = sys_get_temp_dir().'/atlas-lhl00-'.bin2hex(random_bytes(6));
    }

    protected function tearDown(): void
    {
        $this->removeDir($this->storageRoot);
        parent::tearDown();
    }

    private function service(): LongHorizonLoopDeliveryLedgerService
    {
        $service = app(LongHorizonLoopDeliveryLedgerService::class);
        $service->setStorageRootForTesting($this->storageRoot);

        return $service;
    }

    /**
     * A clean substrate baseline driven entirely by input seams (no real git /
     * provider). Tests mutate a copy of this to drive the negative cases.
     *
     * @return array<string,mixed>
     */
    private function cleanInput(): array
    {
        return [
            'area' => 'agentic_engineering_os',
            'focus' => 'dev_forge',
            'main_sha' => 'aaaa111122223333444455556666777788889999',
            'integration_lane' => [
                'present' => true,
                'sha' => 'bbbb111122223333444455556666777788889999',
                'ref' => 'atlas/integration-lane',
            ],
            'kill_switch_active' => false,
            'stale_lock' => false,
            'loop_lock_held' => false,
            'worktree_count' => 2,
            'worktree_list' => ['/tmp/wt-a', '/tmp/wt-b'],
            'provider_processes' => 0,
            'backlog_depth' => 7,
            'ap805_readiness_status' => 'ready',
            'ap806_autonomy_status' => 'partial',
        ];
    }

    public function test_baseline_returns_ok_and_records_main_lane_locks_worktrees_processes(): void
    {
        $report = $this->service()->baseline($this->cleanInput());

        $this->assertSame(LongHorizonLoopDeliveryLedgerService::REPORT_SCHEMA, $report['schema_version']);
        $this->assertSame('AP-810', $report['ap_contract']);
        $this->assertSame('LHL-00', $report['slice_id']);
        $this->assertSame(LongHorizonLoopDeliveryLedgerService::STATUS_OK, $report['status']);
        $this->assertSame('continue', $report['next_action']);
        $this->assertSame([], $report['blockers']);

        $reality = $report['reality'];
        $this->assertSame('aaaa111122223333444455556666777788889999', $reality['main_sha']);
        $this->assertTrue($reality['integration_lane']['present']);
        $this->assertSame('bbbb111122223333444455556666777788889999', $reality['integration_lane']['sha']);
        $this->assertSame('atlas/integration-lane', $reality['integration_lane']['ref']);
        $this->assertFalse($reality['kill_switch_active']);
        $this->assertFalse($reality['loop_locks']['loop_lock_held']);
        $this->assertFalse($reality['loop_locks']['stale_lock']);
        $this->assertSame(2, $reality['worktrees']['count']);
        $this->assertSame(['/tmp/wt-a', '/tmp/wt-b'], $reality['worktrees']['list']);
        $this->assertSame(0, $reality['provider_processes']['count']);
        $this->assertSame(7, $reality['backlog_depth']);
    }

    public function test_records_ap805_and_ap806_status_from_input_seams(): void
    {
        $report = $this->service()->baseline($this->cleanInput());

        $this->assertSame('ready', $report['reality']['ap805_readiness']);
        $this->assertSame('partial', $report['reality']['ap806_autonomy']);

        // An unknown / unsupplied status is reported `unknown`, never upgraded.
        $input = $this->cleanInput();
        unset($input['ap805_readiness_status'], $input['ap806_autonomy_status']);
        $bare = $this->service()->baseline($input);
        $this->assertSame('unknown', $bare['reality']['ap805_readiness']);
        $this->assertSame('unknown', $bare['reality']['ap806_autonomy']);
    }

    public function test_seeds_twenty_slice_rows_lhl00_through_lhl19(): void
    {
        $service = $this->service();
        $report = $service->baseline($this->cleanInput());

        $this->assertSame(20, $report['delivery_ledger']['slice_count']);
        $this->assertTrue($report['delivery_ledger']['seeded']);

        $ledger = $service->slices('agentic_engineering_os', 'dev_forge');
        $this->assertSame(20, $ledger['slice_count']);

        $ids = array_map(static fn (array $s): string => (string) $s['slice_id'], $ledger['slices']);
        $expected = array_map(static fn (int $i): string => sprintf('LHL-%02d', $i), range(0, 19));
        $this->assertSame($expected, $ids);

        // Seeded rows are planned — never counted as delivered.
        foreach ($ledger['slices'] as $slice) {
            $this->assertSame(LongHorizonLoopDeliveryLedgerService::SLICE_STATUS_PLANNED, $slice['slice_status']);
        }
        $this->assertSame(20, $ledger['status_counts'][LongHorizonLoopDeliveryLedgerService::SLICE_STATUS_PLANNED]);
        $this->assertSame(0, $ledger['status_counts'][LongHorizonLoopDeliveryLedgerService::SLICE_STATUS_DELIVERED]);
    }

    public function test_seed_is_idempotent_and_does_not_duplicate_rows(): void
    {
        $service = $this->service();
        $first = $service->baseline($this->cleanInput());
        $this->assertTrue($first['delivery_ledger']['seeded']);

        $second = $service->baseline($this->cleanInput());
        $this->assertFalse($second['delivery_ledger']['seeded'], 'a second baseline must not re-seed an existing ledger');
        $this->assertSame(20, $second['delivery_ledger']['slice_count']);

        $this->assertSame(20, $service->slices('agentic_engineering_os', 'dev_forge')['slice_count']);
    }

    public function test_record_slice_updates_a_slice_status(): void
    {
        $service = $this->service();
        $service->baseline($this->cleanInput());

        $result = $service->recordSlice([
            'area' => 'agentic_engineering_os',
            'focus' => 'dev_forge',
            'slice_id' => 'LHL-01',
            'slice_status' => LongHorizonLoopDeliveryLedgerService::SLICE_STATUS_DELIVERED,
            'note' => 'firewall shipped',
            'evidence_refs' => ['LoopPreflightCycleFirewallServiceTest'],
        ]);

        $this->assertTrue($result['recorded']);
        $this->assertSame(LongHorizonLoopDeliveryLedgerService::STATUS_OK, $result['status']);
        $this->assertSame('LHL-01', $result['slice']['slice_id']);
        $this->assertSame(LongHorizonLoopDeliveryLedgerService::SLICE_STATUS_DELIVERED, $result['slice']['slice_status']);

        // slices() reflects the latest state (append-only history => last wins);
        // still exactly 20 logical rows, LHL-01 now delivered.
        $ledger = $service->slices('agentic_engineering_os', 'dev_forge');
        $this->assertSame(20, $ledger['slice_count']);
        $byId = [];
        foreach ($ledger['slices'] as $slice) {
            $byId[$slice['slice_id']] = $slice;
        }
        $this->assertSame(LongHorizonLoopDeliveryLedgerService::SLICE_STATUS_DELIVERED, $byId['LHL-01']['slice_status']);
        $this->assertSame('firewall shipped', $byId['LHL-01']['note']);
        $this->assertSame(1, $ledger['status_counts'][LongHorizonLoopDeliveryLedgerService::SLICE_STATUS_DELIVERED]);
        $this->assertSame(LongHorizonLoopDeliveryLedgerService::SLICE_STATUS_PLANNED, $byId['LHL-02']['slice_status']);
    }

    public function test_record_slice_refuses_unknown_slice_id_never_dressed_as_ok(): void
    {
        $service = $this->service();
        $service->baseline($this->cleanInput());

        $result = $service->recordSlice([
            'slice_id' => 'LHL-99',
            'slice_status' => LongHorizonLoopDeliveryLedgerService::SLICE_STATUS_DELIVERED,
        ]);

        $this->assertFalse($result['recorded']);
        $this->assertSame(LongHorizonLoopDeliveryLedgerService::STATUS_BLOCKED, $result['status']);
        $this->assertSame('unknown_or_missing_slice_id', $result['error']);
    }

    public function test_detects_lhl00_implemented_and_absent_sibling_via_class_exists(): void
    {
        // LHL-00 (this service) really exists; an unimplemented sibling is `absent`.
        // The detection is real class_exists, never a chat claim.
        $report = $this->service()->baseline($this->cleanInput());

        $byId = [];
        foreach ($report['lhl_services'] as $svc) {
            $byId[$svc['slice_id']] = $svc;
        }
        $this->assertSame('implemented', $byId['LHL-00']['state']);
        $this->assertTrue($byId['LHL-00']['implemented']);
        $this->assertSame('AP-810', $byId['LHL-00']['owner']);

        // Force an override so the test is independent of which siblings exist yet.
        $input = $this->cleanInput();
        $input['lhl_service_overrides'] = ['LoopPostCycleAuditorService' => false];
        $overridden = $this->service()->baseline($input);
        $byId2 = [];
        foreach ($overridden['lhl_services'] as $svc) {
            $byId2[$svc['slice_id']] = $svc;
        }
        $this->assertSame('absent', $byId2['LHL-02']['state']);
        $this->assertFalse($byId2['LHL-02']['implemented']);
    }

    public function test_blocks_when_substrate_is_unsafe(): void
    {
        // A leftover provider process with NO cleanup plan blocks honestly.
        $input = $this->cleanInput();
        $input['provider_processes'] = 3;
        $input['provider_leftover_cleanup_planned'] = false;
        $report = $this->service()->baseline($input);
        $this->assertSame(LongHorizonLoopDeliveryLedgerService::STATUS_BLOCKED, $report['status']);
        $this->assertContains('provider_process_leftovers_present', $report['blockers']);
        $this->assertSame('stop_substrate_blocked', $report['next_action']);

        // Kill switch + stale lock are also hard substrate blockers.
        $unsafe = $this->cleanInput();
        $unsafe['kill_switch_active'] = true;
        $unsafe['stale_lock'] = true;
        $r2 = $this->service()->baseline($unsafe);
        $this->assertSame(LongHorizonLoopDeliveryLedgerService::STATUS_BLOCKED, $r2['status']);
        $this->assertContains('kill_switch_active', $r2['blockers']);
        $this->assertContains('stale_loop_lock_held', $r2['blockers']);

        // A leftover process WITH a cleanup plan is only a warning, not a block.
        $planned = $this->cleanInput();
        $planned['provider_processes'] = 1;
        $planned['provider_leftover_cleanup_planned'] = true;
        $r3 = $this->service()->baseline($planned);
        $this->assertSame(LongHorizonLoopDeliveryLedgerService::STATUS_OK, $r3['status']);
        $this->assertContains('provider_leftovers_cleanup_planned', $r3['warnings']);
    }

    public function test_emits_a_stable_report_hash_excluding_volatile(): void
    {
        $service = $this->service();
        $input = $this->cleanInput();

        $first = $service->baseline($input);
        // Second call against the SAME storage (now seeded) must still hash identically:
        // volatile/stateful fields (checked_at, seeded, ledger_path) are excluded.
        $second = $service->baseline($input);

        $this->assertStringStartsWith('sha256:', $first['report_hash']);
        $this->assertSame(
            $first['report_hash'],
            $second['report_hash'],
            'same input must produce an identical report_hash (volatile + ledger state stripped)',
        );

        // A different substrate reality must produce a different hash.
        $changed = $input;
        $changed['main_sha'] = 'ffff000011112222333344445555666677778888';
        $blocked = $service->baseline($changed);
        $this->assertNotSame($first['report_hash'], $blocked['report_hash']);
    }

    public function test_no_provider_or_destructive_claims_in_policy(): void
    {
        $report = $this->service()->baseline($this->cleanInput());

        $policy = $report['claim_policy'];
        $this->assertTrue($policy['read_only']);
        $this->assertFalse($policy['runs_provider']);
        $this->assertFalse($policy['runs_loop']);
        $this->assertFalse($policy['runs_merge']);
        $this->assertFalse($policy['deletes_branches']);
        $this->assertTrue($policy['blocked_never_dressed_as_ready']);
    }

    public function test_default_empty_input_does_not_crash(): void
    {
        // Diagnostic default: empty state seeds the ledger and reports a baseline
        // without crashing, even with no git / no seams supplied.
        $report = $this->service()->baseline();

        $this->assertSame(LongHorizonLoopDeliveryLedgerService::REPORT_SCHEMA, $report['schema_version']);
        $this->assertSame('LHL-00', $report['slice_id']);
        $this->assertContains($report['status'], [
            LongHorizonLoopDeliveryLedgerService::STATUS_OK,
            LongHorizonLoopDeliveryLedgerService::STATUS_BLOCKED,
        ]);
        $this->assertSame(20, $report['delivery_ledger']['slice_count']);
        $this->assertIsArray($report['reality']['integration_lane']);
        $this->assertArrayHasKey('main_sha', $report['reality']);
    }

    public function test_slices_read_is_corruption_tolerant_for_absent_ledger(): void
    {
        // Reading an area/focus with no ledger yet returns an empty, well-formed report.
        $ledger = $this->service()->slices('never_seeded_area', 'nope');

        $this->assertSame(LongHorizonLoopDeliveryLedgerService::SLICE_SCHEMA, $ledger['schema_version']);
        $this->assertSame(0, $ledger['slice_count']);
        $this->assertSame([], $ledger['slices']);
        $this->assertSame(0, $ledger['corrupted_line_count']);
    }

    private function removeDir(string $dir): void
    {
        if (! is_dir($dir)) {
            return;
        }
        $items = scandir($dir) ?: [];
        foreach ($items as $item) {
            if ($item === '.' || $item === '..') {
                continue;
            }
            $path = $dir.DIRECTORY_SEPARATOR.$item;
            is_dir($path) ? $this->removeDir($path) : @unlink($path);
        }
        @rmdir($dir);
    }
}
