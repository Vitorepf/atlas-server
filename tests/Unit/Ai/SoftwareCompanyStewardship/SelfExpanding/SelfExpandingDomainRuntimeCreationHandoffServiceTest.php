<?php

declare(strict_types=1);

namespace Tests\Unit\Ai\SoftwareCompanyStewardship\SelfExpanding;

use App\Models\AiInboxItem;
use App\Services\Ai\DomainRuntime\DomainManifestRegistryService;
use App\Services\Ai\Kernel\Evidence\AtlasEvidenceLedger;
use App\Services\Ai\Mobile\ProposalInboxEmitter;
use App\Services\Ai\SoftwareCompanyStewardship\SelfExpanding\NewAreaProposalGateService;
use App\Services\Ai\SoftwareCompanyStewardship\SelfExpanding\SelfExpandingDomainRuntimeCreationHandoffService;
use App\Services\Ai\SoftwareCompanyStewardship\SelfExpanding\SelfExpandingSoftwareCompanyService;
use App\Services\Ai\SoftwareCompanyStewardship\StewardshipEvolution\StewardshipEvolutionDecisionLedgerService;
use App\Services\Ai\SoftwareCompanyStewardship\StewardshipEvolution\StewardshipOutcomeEvidenceBridgeService;
use App\Services\Ai\SoftwareCompanyStewardship\StewardshipEvolution\StewardshipEvolutionReadModelService;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Schema;
use Tests\Concerns\CreatesDomainRuntimeTables;
use Tests\Feature\Ai\DomainRuntime\DomainRuntimeManifestRegistryTest;
use Tests\TestCase;

final class SelfExpandingDomainRuntimeCreationHandoffServiceTest extends TestCase
{
    use CreatesDomainRuntimeTables;

    private string $tmp;

    protected function setUp(): void
    {
        parent::setUp();
        $this->tmp = sys_get_temp_dir().'/atlas_ap741_'.uniqid('', true);
        @mkdir($this->tmp, 0775, true);
        $this->createDomainRuntimeTables();
    }

    protected function tearDown(): void
    {
        Schema::dropIfExists('atlas_ledger_events');
        $this->dropDomainRuntimeTables();
        File::deleteDirectory($this->tmp);
        parent::tearDown();
    }

    /**
     * @return array{handoff:SelfExpandingDomainRuntimeCreationHandoffService,gate:NewAreaProposalGateService,ledger:StewardshipEvolutionDecisionLedgerService,outcomes:StewardshipOutcomeEvidenceBridgeService}
     */
    private function rig(): array
    {
        $ledger = app(StewardshipEvolutionDecisionLedgerService::class);
        $ledger->setStorageRootForTesting($this->tmp.'/decisions');

        $gate = new NewAreaProposalGateService(
            app(StewardshipEvolutionReadModelService::class),
            $ledger,
        );
        $selfExpanding = new SelfExpandingSoftwareCompanyService($gate);
        $outcomes = new StewardshipOutcomeEvidenceBridgeService(
            $ledger,
            $selfExpanding,
            app(AtlasEvidenceLedger::class),
            $this->fakeEmitter(),
        );
        $handoff = new SelfExpandingDomainRuntimeCreationHandoffService(
            $gate,
            $selfExpanding,
            $outcomes,
            app(DomainManifestRegistryService::class),
        );
        $handoff->setStorageRootForTesting($this->tmp.'/handoffs');

        return [
            'handoff' => $handoff,
            'gate' => $gate,
            'ledger' => $ledger,
            'outcomes' => $outcomes,
        ];
    }

    public function test_blocks_when_no_ap737_acceptance_exists(): void
    {
        ['handoff' => $handoff] = $this->rig();

        $report = $handoff->project($this->gapInput());

        $this->assertSame(SelfExpandingDomainRuntimeCreationHandoffService::REPORT_SCHEMA, $report['schema_version']);
        $this->assertSame(SelfExpandingDomainRuntimeCreationHandoffService::STATUS_NO_READY_PROPOSALS, $report['status']);
        $this->assertSame('AP-741', $report['ap_contract']);
        $this->assertSame(0, $report['ready_handoff_count']);
        $this->assertSame([], $report['handoff_packets']);
        $this->assertFalse($report['claim_policy']['creates_domain_runtime']);
    }

    public function test_requires_recorded_ap740_evidence_before_ready_handoff(): void
    {
        ['handoff' => $handoff, 'gate' => $gate] = $this->rig();
        $input = $this->gapInput('telemetry_quality');
        $proposalId = (string) $gate->evaluate($input)['gate_items'][0]['proposal_id'];
        $gate->decide($input + [
            'proposal_id' => $proposalId,
            'operator_actor' => 'operator_test',
            'decision' => 'accept',
            'rationale' => 'approved for AP-741 evidence requirement test',
        ]);

        $report = $handoff->project($input);

        $this->assertSame(SelfExpandingDomainRuntimeCreationHandoffService::STATUS_BLOCKED, $report['status']);
        $this->assertSame(0, $report['ready_handoff_count']);
        $this->assertSame('blocked', $report['handoff_packets'][0]['handoff_status']);
        $this->assertContains('ap740_recorded_evidence_required_before_handoff', $report['handoff_packets'][0]['blockers']);
    }

    public function test_builds_ready_packet_after_acceptance_and_recorded_evidence(): void
    {
        $this->createLedgerTable();
        ['handoff' => $handoff, 'gate' => $gate, 'outcomes' => $outcomes] = $this->rig();
        $input = $this->gapInput('telemetry_quality');
        $proposalId = (string) $gate->evaluate($input)['gate_items'][0]['proposal_id'];
        $gate->decide($input + [
            'proposal_id' => $proposalId,
            'operator_actor' => 'operator_test',
            'decision' => 'accept',
            'rationale' => 'approved for AP-741 ready handoff test',
        ]);
        $outcomeBridge = $outcomes->project($input + ['record_evidence' => true, 'actor' => 'operator_test']);

        $report = $handoff->project($input + ['outcome_bridge' => $outcomeBridge]);

        $this->assertSame(SelfExpandingDomainRuntimeCreationHandoffService::STATUS_READY, $report['status']);
        $this->assertSame(1, $report['ready_handoff_count']);
        $packet = $report['handoff_packets'][0];
        $this->assertSame(SelfExpandingDomainRuntimeCreationHandoffService::PACKET_SCHEMA, $packet['schema_version']);
        $this->assertSame('ready_for_domain_runtime_creation_gate', $packet['handoff_status']);
        $this->assertSame('telemetry_quality', $packet['candidate_area']);
        $this->assertTrue($packet['evidence_gate']['recorded_ready']);
        $this->assertSame('atlas.domain.creation_proposal.v1', $packet['domain_creation_proposal_envelope']['schema']);
        $this->assertFalse($packet['claim_policy']['creates_domain_runtime']);
        $this->assertTrue($packet['target_gate']['dual_signature_required']);
    }

    public function test_record_handoff_is_append_only_and_idempotent(): void
    {
        $this->createLedgerTable();
        ['handoff' => $handoff, 'gate' => $gate, 'outcomes' => $outcomes] = $this->rig();
        $input = $this->gapInput('telemetry_quality');
        $proposalId = (string) $gate->evaluate($input)['gate_items'][0]['proposal_id'];
        $gate->decide($input + [
            'proposal_id' => $proposalId,
            'operator_actor' => 'operator_test',
            'decision' => 'accept',
            'rationale' => 'approved for AP-741 record handoff test',
        ]);
        $outcomeBridge = $outcomes->project($input + ['record_evidence' => true, 'actor' => 'operator_test']);

        $first = $handoff->project($input + ['outcome_bridge' => $outcomeBridge, 'record_handoff' => true]);
        $second = $handoff->project($input + ['outcome_bridge' => $outcomeBridge, 'record_handoff' => true]);

        $this->assertSame('recorded', $first['handoff_packets'][0]['handoff_storage_status']);
        $this->assertSame('existing', $second['handoff_packets'][0]['handoff_storage_status']);
        $this->assertFileExists($handoff->packetFilePath('agentic_engineering_os'));
        $this->assertCount(1, file($handoff->packetFilePath('agentic_engineering_os'), FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES));
    }

    public function test_existing_domain_manifest_blocks_handoff_even_after_acceptance(): void
    {
        $this->createLedgerTable();
        app(DomainManifestRegistryService::class)->register(
            DomainRuntimeManifestRegistryTest::validManifestAttributes('telemetry_quality'),
        );
        ['handoff' => $handoff, 'gate' => $gate, 'outcomes' => $outcomes] = $this->rig();
        $input = $this->gapInput('telemetry_quality');
        $proposalId = (string) $gate->evaluate($input)['gate_items'][0]['proposal_id'];
        $gate->decide($input + [
            'proposal_id' => $proposalId,
            'operator_actor' => 'operator_test',
            'decision' => 'accept',
            'rationale' => 'approved but should be blocked by manifest collision',
        ]);
        $outcomeBridge = $outcomes->project($input + ['record_evidence' => true, 'actor' => 'operator_test']);

        $report = $handoff->project($input + ['outcome_bridge' => $outcomeBridge]);

        $this->assertSame(SelfExpandingDomainRuntimeCreationHandoffService::STATUS_BLOCKED, $report['status']);
        $this->assertContains('domain_manifest_already_exists', $report['handoff_packets'][0]['blockers']);
        $this->assertSame('exists', $report['handoff_packets'][0]['domain_registry_check']['status']);
    }

    private function fakeEmitter(): ProposalInboxEmitter
    {
        return new class extends ProposalInboxEmitter
        {
            public function __construct() {}

            public function emit(array $data): ?AiInboxItem
            {
                $item = new AiInboxItem;
                $item->id = '00000000-0000-0000-0000-000000000741';
                $item->payload = $data['payload'] ?? [];

                return $item;
            }
        };
    }

    /**
     * @return array<string,mixed>
     */
    private function gapInput(string $candidate = 'telemetry_quality'): array
    {
        return [
            'area_id' => 'agentic_engineering_os',
            'portfolio_id' => 'atlas_software_company',
            'observed_gaps' => [
                [
                    'gap_id' => 'gap_'.$candidate,
                    'summary' => str_replace('_', ' ', $candidate).' lacks an accountable steward',
                    'candidate_area' => $candidate,
                ],
            ],
        ];
    }

    private function createLedgerTable(): void
    {
        Schema::dropIfExists('atlas_ledger_events');
        (require database_path('migrations/2026_05_05_020000_create_atlas_ledger_events_table.php'))->up();
        (require database_path('migrations/2026_05_19_050000_extend_atlas_ledger_events_with_timeline_fields.php'))->up();
    }
}
