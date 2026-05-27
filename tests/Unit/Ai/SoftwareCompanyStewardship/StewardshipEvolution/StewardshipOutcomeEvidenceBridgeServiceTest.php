<?php

declare(strict_types=1);

namespace Tests\Unit\Ai\SoftwareCompanyStewardship\StewardshipEvolution;

use App\Models\AiInboxItem;
use App\Services\Ai\Kernel\Evidence\AtlasEvidenceLedger;
use App\Services\Ai\Mobile\ProposalInboxEmitter;
use App\Services\Ai\SoftwareCompanyStewardship\SelfExpanding\NewAreaProposalGateService;
use App\Services\Ai\SoftwareCompanyStewardship\SelfExpanding\SelfExpandingSoftwareCompanyService;
use App\Services\Ai\SoftwareCompanyStewardship\StewardshipEvolution\StewardshipEvolutionDecisionLedgerService;
use App\Services\Ai\SoftwareCompanyStewardship\StewardshipEvolution\StewardshipEvolutionReadModelService;
use App\Services\Ai\SoftwareCompanyStewardship\StewardshipEvolution\StewardshipOutcomeEvidenceBridgeService;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

class StewardshipOutcomeEvidenceBridgeServiceTest extends TestCase
{
    private string $tmp;

    protected function setUp(): void
    {
        parent::setUp();
        $this->tmp = sys_get_temp_dir().'/atlas_ap740_'.uniqid('', true);
        @mkdir($this->tmp, 0775, true);
    }

    protected function tearDown(): void
    {
        Schema::dropIfExists('atlas_ledger_events');
        foreach ((array) glob($this->tmp.'/*') as $file) {
            @unlink((string) $file);
        }
        @rmdir($this->tmp);
        parent::tearDown();
    }

    /**
     * @return array{service:StewardshipOutcomeEvidenceBridgeService,ledger:StewardshipEvolutionDecisionLedgerService,emitter:ProposalInboxEmitter}
     */
    private function bridge(?ProposalInboxEmitter $emitter = null): array
    {
        $ledger = app(StewardshipEvolutionDecisionLedgerService::class);
        $ledger->setStorageRootForTesting($this->tmp);

        $gate = new NewAreaProposalGateService(
            app(StewardshipEvolutionReadModelService::class),
            $ledger,
        );

        $service = new StewardshipOutcomeEvidenceBridgeService(
            $ledger,
            new SelfExpandingSoftwareCompanyService($gate),
            app(AtlasEvidenceLedger::class),
            $emitter ?? $this->fakeEmitter(),
        );

        return [
            'service' => $service,
            'ledger' => $ledger,
            'emitter' => $emitter ?? $this->fakeEmitter(),
        ];
    }

    private function fakeEmitter(): ProposalInboxEmitter
    {
        return new class extends ProposalInboxEmitter
        {
            /** @var list<array<string,mixed>> */
            public array $emitted = [];

            public function __construct() {}

            public function emit(array $data): ?AiInboxItem
            {
                $this->emitted[] = $data;

                $item = new AiInboxItem;
                $item->id = '00000000-0000-0000-0000-'.str_pad((string) count($this->emitted), 12, '0', STR_PAD_LEFT);
                $item->payload = $data['payload'] ?? [];

                return $item;
            }
        };
    }

    public function test_projects_ap731_and_ap738_outcomes_without_side_effects_by_default(): void
    {
        ['service' => $service, 'ledger' => $ledger] = $this->bridge();
        $ledger->record($this->decisionInput());

        $report = $service->project();

        $this->assertSame(StewardshipOutcomeEvidenceBridgeService::REPORT_SCHEMA, $report['schema_version']);
        $this->assertSame(StewardshipOutcomeEvidenceBridgeService::STATUS_READY, $report['status']);
        $this->assertSame('AP-740', $report['ap_contract']);
        $this->assertFalse($report['record_evidence_requested']);
        $this->assertFalse($report['emit_inbox_requested']);
        $this->assertSame(1, $report['decision_count']);
        $this->assertGreaterThanOrEqual(2, $report['evidence_item_count']);
        $this->assertGreaterThanOrEqual(1, $report['morning_inbox_item_count']);
        $this->assertSame('projected', $report['evidence_items'][0]['ledger_status']);
        $this->assertSame('projected', $report['morning_inbox_items'][0]['inbox_status']);
        $this->assertFalse($report['claim_policy']['provider_invoked']);
        $this->assertFalse($report['claim_policy']['creates_domain_runtime']);
        $this->assertFalse(Schema::hasTable('atlas_ledger_events'));
    }

    public function test_record_evidence_appends_idempotent_canonical_ledger_events(): void
    {
        $this->createLedgerTable();
        ['service' => $service, 'ledger' => $ledger] = $this->bridge();
        $ledger->record($this->decisionInput());

        $first = $service->project(['record_evidence' => true, 'actor' => 'operator_test']);
        $this->assertSame('recorded', $first['evidence_items'][0]['ledger_status']);
        $this->assertDatabaseCount('atlas_ledger_events', $first['evidence_item_count']);
        $this->assertDatabaseHas('atlas_ledger_events', [
            'event_type' => 'EVIDENCE_PACKED',
            'emitter_stage' => 'atlas.software_company_stewardship.ap740',
            'operator_id' => 'operator_test',
        ]);

        $second = $service->project(['record_evidence' => true, 'actor' => 'operator_test']);
        $this->assertSame('existing', $second['evidence_items'][0]['ledger_status']);
        $this->assertDatabaseCount('atlas_ledger_events', $first['evidence_item_count']);
    }

    public function test_emit_inbox_reuses_proposal_inbox_emitter_with_stable_dedupe_keys(): void
    {
        $emitter = $this->fakeEmitter();
        ['service' => $service, 'ledger' => $ledger] = $this->bridge($emitter);
        $ledger->record($this->decisionInput());

        $report = $service->project(['emit_inbox' => true]);

        $this->assertTrue($report['emit_inbox_requested']);
        $this->assertGreaterThanOrEqual(1, count($emitter->emitted));
        $this->assertSame('emitted_or_existing', $report['morning_inbox_items'][0]['inbox_status']);
        $this->assertSame(
            StewardshipOutcomeEvidenceBridgeService::MORNING_INBOX_SCHEMA,
            data_get($emitter->emitted[0], 'metadata.schema_version'),
        );
        $this->assertStringStartsWith('stewardship:', (string) data_get($emitter->emitted[0], 'dedupe_key'));
        $this->assertTrue((bool) data_get($emitter->emitted[0], 'payload.operator_review_required', true));
        $this->assertFalse((bool) data_get($emitter->emitted[0], 'payload.autoimplementation_allowed', false));
    }

    public function test_projects_ap747_release_outcomes_into_evidence_inbox_and_portfolio_feed(): void
    {
        ['service' => $service, 'ledger' => $ledger] = $this->bridge();
        $ledger->record($this->decisionInput());

        $report = $service->project([
            'release_reports' => [$this->releaseReport()],
        ]);

        $this->assertContains('AP-747', $report['source_ap_contracts']);
        $this->assertContains('AP-748', $report['source_ap_contracts']);
        $this->assertSame(1, $report['release_outcome_summary']['release_count']);
        $this->assertSame(1, $report['release_outcome_summary']['owner_queue_pending_count']);
        $this->assertSame(['atlas_dev' => 1], $report['release_outcome_summary']['owner_counts']);
        $this->assertSame(1, count($report['portfolio_feed']['areas']));
        $this->assertSame('agentic_engineering_os', $report['portfolio_feed']['areas'][0]['area_id']);
        $this->assertTrue(collect($report['evidence_items'])->contains(
            fn (array $item): bool => ($item['source_kind'] ?? '') === 'ap747_owner_queue_release'
        ));
        $this->assertTrue(collect($report['morning_inbox_items'])->contains(
            fn (array $item): bool => ($item['kind'] ?? '') === 'ap747_owner_queue_release_review'
        ));
        $this->assertFalse($report['claim_policy']['dev_invoked']);
        $this->assertFalse($report['claim_policy']['forge_invoked']);
    }

    public function test_ap747_release_inbox_emit_reuses_existing_morning_inbox_owner(): void
    {
        $emitter = $this->fakeEmitter();
        ['service' => $service] = $this->bridge($emitter);

        $report = $service->project([
            'release_reports' => [$this->releaseReport()],
            'emit_inbox' => true,
        ]);

        $releaseInbox = collect($report['morning_inbox_items'])->first(
            fn (array $item): bool => ($item['kind'] ?? '') === 'ap747_owner_queue_release_review'
        );

        $this->assertIsArray($releaseInbox);
        $this->assertSame('emitted_or_existing', $releaseInbox['inbox_status']);
        $this->assertTrue(collect($emitter->emitted)->contains(
            fn (array $item): bool => str_contains((string) ($item['dedupe_key'] ?? ''), 'stewardship:ap747:')
        ));
    }

    public function test_record_evidence_degrades_to_projection_when_ledger_table_is_missing(): void
    {
        ['service' => $service, 'ledger' => $ledger] = $this->bridge();
        $ledger->record($this->decisionInput());

        $report = $service->project(['record_evidence' => true]);

        $this->assertSame('skipped_missing_atlas_ledger_events_table', $report['evidence_items'][0]['ledger_status']);
        $this->assertFalse(Schema::hasTable('atlas_ledger_events'));
    }

    /**
     * @return array<string,mixed>
     */
    private function decisionInput(): array
    {
        return [
            'area_id' => 'agentic_engineering_os',
            'portfolio_id' => 'atlas_software_company',
            'target_type' => 'new_area_proposal',
            'target_id' => 'proposal_ap740_test',
            'target_hash' => 'sha256:'.hash('sha256', 'proposal_ap740_test'),
            'operator_actor' => 'operator_test',
            'decision' => 'accept',
            'risk' => 'medium',
            'rationale' => 'approved for AP-740 evidence and inbox bridge test only',
        ];
    }

    /**
     * @return array<string,mixed>
     */
    private function releaseReport(array $overrides = []): array
    {
        return array_replace_recursive([
            'schema_version' => 'atlas.software_company_stewardship.area_focus_dev_forge_release_record.v1',
            'ap_contract' => 'AP-747',
            'status' => 'owner_queue_recorded',
            'area_id' => 'agentic_engineering_os',
            'release_id' => 'afrel_ap748_test',
            'release_hash' => 'sha256:release_ap748',
            'target_owner' => 'atlas_dev',
            'target_runtime_schema' => 'atlas.dev_runtime.v1',
            'source_refs' => [
                'handoff_hash' => 'sha256:handoff_ap748',
                'work_order_id' => 'awo_ap748',
            ],
            'release_receipt' => [
                'operator_actor' => 'operator_test',
            ],
            'queue_item' => [
                'schema_version' => 'atlas.software_company_stewardship.area_focus_atlas_dev_queue_item.v1',
                'target_owner' => 'atlas_dev',
                'queue_item_id' => 'afq_ap748_test',
                'handoff_hash' => 'sha256:handoff_ap748',
                'work_order_id' => 'awo_ap748',
                'runtime_execution_started' => false,
                'provider_invoked' => false,
            ],
        ], $overrides);
    }

    private function createLedgerTable(): void
    {
        Schema::dropIfExists('atlas_ledger_events');
        (require database_path('migrations/2026_05_05_020000_create_atlas_ledger_events_table.php'))->up();
        (require database_path('migrations/2026_05_19_050000_extend_atlas_ledger_events_with_timeline_fields.php'))->up();
    }
}
