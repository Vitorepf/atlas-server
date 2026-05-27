<?php

declare(strict_types=1);

namespace Tests\Unit\Ai\SoftwareCompanyStewardship\StewardshipEvolution;

use App\Services\Ai\Kernel\Evidence\AtlasEvidenceLedger;
use App\Services\Ai\Mobile\ProposalInboxEmitter;
use App\Services\Ai\SoftwareCompanyStewardship\ProductMode\ProductModeRuntimeResultEventService;
use App\Services\Ai\SoftwareCompanyStewardship\StewardshipEvolution\StewardshipRuntimeResultBridgeService;
use Illuminate\Support\Facades\File;
use Tests\TestCase;

final class StewardshipRuntimeResultBridgeServiceTest extends TestCase
{
    private string $tmp;

    private ProductModeRuntimeResultEventService $events;

    protected function setUp(): void
    {
        parent::setUp();
        $this->tmp = sys_get_temp_dir().'/atlas_ap765_'.uniqid('', true);
        @mkdir($this->tmp, 0775, true);
    }

    protected function tearDown(): void
    {
        File::deleteDirectory($this->tmp);
        parent::tearDown();
    }

    private function service(): StewardshipRuntimeResultBridgeService
    {
        $this->events = new ProductModeRuntimeResultEventService();
        $this->events->setStorageRootForTesting($this->tmp.'/events');

        $service = new StewardshipRuntimeResultBridgeService(
            app(AtlasEvidenceLedger::class),
            app(ProposalInboxEmitter::class),
            $this->events,
        );
        $service->setStorageRootForTesting($this->tmp.'/bridge');

        return $service;
    }

    public function test_builds_structured_evidence_pack_from_result_fixture(): void
    {
        $report = $this->service()->project($this->input());

        $this->assertSame(StewardshipRuntimeResultBridgeService::REPORT_SCHEMA, $report['schema_version']);
        $this->assertSame(StewardshipRuntimeResultBridgeService::STATUS_READY, $report['status']);
        $this->assertSame('AP-765', $report['ap_contract']);
        $this->assertNotSame('', (string) $report['result_bridge_id']);

        $pack = $report['evidence_pack'];
        $this->assertSame(StewardshipRuntimeResultBridgeService::EVIDENCE_PACK_SCHEMA, $pack['schema_version']);
        foreach ([
            'finding_refs', 'spec_refs', 'handoff_refs', 'branch_refs', 'worktree_refs',
            'changed_files', 'tests_run', 'test_results', 'validation_commands', 'risks', 'rollback',
        ] as $field) {
            $this->assertArrayHasKey($field, $pack, "evidence pack missing {$field}");
        }
        $this->assertContains('aff_fixture', $pack['finding_refs']);
        $this->assertContains('spec_fixture', $pack['spec_refs']);
        $this->assertContains('app/Services/Ai/Example.php', $pack['changed_files']);
        $this->assertNotEmpty($pack['tests_run']);
        $this->assertNotEmpty($pack['test_results']);
        $this->assertNotSame('', (string) $pack['rollback']);
        $this->assertTrue($pack['no_auto_merge']);
        $this->assertSame($report['evidence_pack_id'], $pack['pack_id']);
        $this->assertSame('projected', $report['evidence_ledger_status']);
    }

    public function test_builds_light_inbox_item_plan_bridging_to_detail(): void
    {
        $report = $this->service()->project($this->input());
        $inbox = $report['inbox_item'];

        $this->assertSame(StewardshipRuntimeResultBridgeService::INBOX_ITEM_SCHEMA, $inbox['schema_version']);
        $this->assertSame('unread', $inbox['status']);
        $this->assertTrue($inbox['operator_review_required']);

        $actionIds = array_column($inbox['available_actions'], 'id');
        $this->assertContains('mark_reviewed', $actionIds);
        $this->assertContains('discuss', $actionIds);
        $this->assertContains('snooze', $actionIds);
        $this->assertContains('discard', $actionIds);
        // branch_ref present in fixture -> review_patch offered
        $this->assertContains('review_patch', $actionIds);

        // Light list payload: bridge to detail only, NOT the heavy arrays.
        $payload = $inbox['payload'];
        $this->assertArrayNotHasKey('changed_files', $payload);
        $this->assertArrayNotHasKey('tests', $payload);
        $this->assertArrayNotHasKey('test_results', $payload);
        $this->assertArrayHasKey('result_bridge_id', $payload);
        $this->assertArrayHasKey('evidence_pack_id', $payload);
        $this->assertArrayHasKey('product_mode_event_id', $payload);
        $this->assertArrayHasKey('detail_command', $payload);
        $this->assertSame(1, $payload['changed_file_count']);
        $this->assertLessThan(1400, strlen((string) json_encode($payload)), 'inbox list payload must stay small');

        // Not emitted in projection mode (no DB), but the plan is ready.
        $this->assertNull($report['inbox_item_id']);
    }

    public function test_records_product_mode_visibility_event(): void
    {
        $service = $this->service();
        $report = $service->project($this->input(['record_event' => true]));

        $this->assertSame('recorded', $report['product_mode_event_status']);
        $event = $report['product_mode_event'];
        $this->assertSame(ProductModeRuntimeResultEventService::EVENT_SCHEMA, $event['schema_version']);
        // Cockpit chain: area, loop, sandbox, owner, execution, result, evidence, inbox.
        $this->assertSame('agentic_engineering_os', $event['area_id']);
        $this->assertSame('aff_fixture', $event['loop']['finding_id']);
        $this->assertSame('afsb_fixture', $event['sandbox']['sandbox_id']);
        $this->assertSame('atlas_dev', $event['owner']);
        $this->assertSame('completed', $event['execution']['result_status']);
        $this->assertSame($report['evidence_pack_id'], $event['evidence']['evidence_pack_id']);
        $this->assertArrayHasKey('inbox', $event);

        // Read-model is updated and replayable.
        $listed = $this->events->list('agentic_engineering_os');
        $this->assertSame(1, $listed['event_count']);
        $replayed = $this->events->replay($report['product_mode_event_id']);
        $this->assertNotNull($replayed);
        $this->assertSame($report['result_bridge_id'], $replayed['result_bridge_id']);
    }

    public function test_produces_portfolio_signal_in_canonical_feed_shape(): void
    {
        $report = $this->service()->project($this->input());
        $feed = $report['portfolio_signal'];

        $this->assertSame(StewardshipRuntimeResultBridgeService::PORTFOLIO_FEED_SCHEMA, $feed['schema_version']);
        $this->assertContains('AP-751', $feed['source_ap_contracts']);
        $this->assertSame('live', $feed['integration']);
        $this->assertSame(1, $feed['areas'][0]['owner_runtime_result_count']);
        $this->assertSame(1, $feed['areas'][0]['completed_result_count']);
        $this->assertNotSame('', (string) $report['portfolio_signal_id']);
    }

    public function test_never_auto_approves_or_auto_merges(): void
    {
        $report = $this->service()->project($this->input());

        $this->assertSame(['accept', 'reject', 'defer', 'request_changes'], $report['acceptance_options']['options']);
        $this->assertFalse($report['acceptance_options']['accept_executes']);
        $this->assertTrue($report['acceptance_options']['accept_requires_owner_execution']);
        $this->assertFalse($report['acceptance_options']['auto_approval']);
        $this->assertTrue($report['safety_summary']['no_auto_merge']);
        $this->assertTrue($report['safety_summary']['no_auto_deploy']);
        $this->assertTrue($report['claim_policy']['no_auto_merge']);
        $this->assertFalse($report['claim_policy']['auto_approval']);
        $this->assertFalse($report['claim_policy']['provider_invoked_by_bridge']);
    }

    public function test_blocks_when_result_has_no_tests_or_evidence(): void
    {
        $report = $this->service()->project($this->input([
            'execution_result' => [
                'result_status' => 'completed',
                'summary' => 'Owner says it worked.',
                'changed_files' => ['app/Foo.php'],
                // no tests / test_results / validation_commands
            ],
        ]));

        $this->assertSame(StewardshipRuntimeResultBridgeService::STATUS_BLOCKED, $report['status']);
        $this->assertSame('result_evidence_insufficient', $report['reason']);
        $this->assertContains('tests_or_test_results_required', $report['evidence_check']['missing']);
    }

    public function test_blocks_when_execution_result_missing(): void
    {
        $report = $this->service()->project(['area_id' => 'agentic_engineering_os']);

        $this->assertSame(StewardshipRuntimeResultBridgeService::STATUS_BLOCKED, $report['status']);
        $this->assertSame('execution_result_required', $report['reason']);
    }

    public function test_blocks_irreversible_action_without_operator_approval(): void
    {
        $report = $this->service()->project($this->input([
            'execution_result' => $this->resultFixture(['merge_performed' => true]),
        ]));

        $this->assertSame(StewardshipRuntimeResultBridgeService::STATUS_BLOCKED, $report['status']);
        $this->assertSame('irreversible_action_without_operator_approval', $report['reason']);
        $this->assertContains('merge_performed', $report['irreversible_check']['triggered_flags']);
    }

    public function test_irreversible_action_allowed_only_with_explicit_operator_approval(): void
    {
        $report = $this->service()->project($this->input([
            'execution_result' => $this->resultFixture(['merge_performed' => true]),
            'irreversible_approval_receipt' => [
                'decision' => 'approve_irreversible_result',
                'operator_actor' => 'operator',
            ],
        ]));

        $this->assertSame(StewardshipRuntimeResultBridgeService::STATUS_READY, $report['status']);
        $this->assertTrue($report['safety_summary']['irreversible_operator_approval_present']);
        // Even when approved, the bridge itself performs no merge.
        $this->assertTrue($report['claim_policy']['no_auto_merge']);
    }

    public function test_record_cycle_is_append_only_and_idempotent(): void
    {
        $service = $this->service();
        $first = $service->project($this->input(['record_cycle' => true]));
        $second = $service->project($this->input(['record_cycle' => true]));

        $this->assertSame(StewardshipRuntimeResultBridgeService::STATUS_RECORDED, $first['status']);
        $this->assertSame('recorded', $first['cycle_storage_status']);
        $this->assertSame('existing', $second['cycle_storage_status']);
        $this->assertSame($first['result_bridge_id'], $second['result_bridge_id']);

        $path = $service->bridgeFilePath('agentic_engineering_os');
        $this->assertFileExists($path);
        $this->assertCount(1, file($path, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES));
    }

    /**
     * @param  array<string,mixed>  $overrides
     * @return array<string,mixed>
     */
    private function input(array $overrides = []): array
    {
        return array_replace([
            'area_id' => 'agentic_engineering_os',
            'portfolio_id' => 'atlas_software_company',
            'owner' => 'atlas_dev',
            'sandbox_id' => 'afsb_fixture',
            'finding_id' => 'aff_fixture',
            'spec_id' => 'spec_fixture',
            'handoff_id' => 'afho_fixture',
            'actor' => 'operator',
            'execution_result' => $this->resultFixture(),
        ], $overrides);
    }

    /**
     * @param  array<string,mixed>  $overrides
     * @return array<string,mixed>
     */
    private function resultFixture(array $overrides = []): array
    {
        return array_replace([
            'execution_id' => 'afexec_fixture',
            'owner' => 'atlas_dev',
            'result_status' => 'completed',
            'summary' => 'Atlas Dev implemented the finding under branch isolation; tests green, no merge.',
            'branch_ref' => 'atlas/area-focus/agentic_engineering_os/fixture',
            'worktree_path' => 'storage/atlas/.../worktrees/afsb_fixture',
            'changed_files' => ['app/Services/Ai/Example.php'],
            'tests' => ['php artisan test --filter=Example'],
            'test_results' => [['command' => 'php artisan test --filter=Example', 'status' => 'passed']],
            'validation_commands' => ['php artisan atlas:ai:architecture-validate --json'],
            'risks' => ['Isolated change; no schema/route impact.'],
            'runtime_execution_started' => true,
            'provider_invoked' => true,
            'merge_performed' => false,
            'deploy_performed' => false,
            'external_push_performed' => false,
            'secret_access' => false,
            'destructive_change' => false,
        ], $overrides);
    }
}
