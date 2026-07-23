<?php

declare(strict_types=1);

namespace Tests\Unit\Ai\SelfConstruction\Readiness;

use App\Services\Ai\SelfConstruction\Readiness\AtlasSelfConstructionReadinessService;
use App\Services\Ai\SelfConstruction\Readiness\ReadinessProjectionReleaseWriterSection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

final class ReadinessProjectionReleaseWriterSectionTest extends TestCase
{
    public function test_bound_section_rejects_dynamic_calls_to_private_mother_methods(): void
    {
        $section = (new ReadinessProjectionReleaseWriterSection)
            ->setMother(app(AtlasSelfConstructionReadinessService::class));

        $this->expectException(\BadMethodCallException::class);

        $section->stableHash(['private' => 'mother-method']);
    }

    public function test_bound_section_executes_its_writer_contract_without_reflection(): void
    {
        $contract = (new ReadinessProjectionReleaseWriterSection)
            ->setMother(app(AtlasSelfConstructionReadinessService::class))
            ->agentAutomaticDispatchSchedulerOneShotTickMutatingWriterContract();

        $this->assertArrayHasKey('schema_version', $contract);
        $this->assertArrayHasKey('agent_automatic_dispatch_scheduler_one_shot_tick_mutating_writer_contract_hash', $contract);
    }

    public function test_writer_preflight_exposes_missing_release_receipt_hash_as_a_blocker(): void
    {
        $preflight = app(AtlasSelfConstructionReadinessService::class)
            ->agentAutomaticDispatchSchedulerOneShotTickWriterPreflight();

        $this->assertSame('blocked', $preflight['status']);
        $this->assertContains(
            'release_receipt_hash_provided_not_ready',
            $preflight['agent_automatic_dispatch_scheduler_one_shot_tick_writer_preflight']['blocking_reasons']
        );
        $this->assertSame(
            'repair_signed_one_shot_scheduler_tick_writer_preflight_blockers',
            $preflight['agent_automatic_dispatch_scheduler_one_shot_tick_writer_preflight']['next_required_slice']
        );
        $this->assertStringContainsString('blocked', $preflight['human_summary']);
    }

    public function test_downstream_release_envelopes_block_when_their_source_preflight_is_blocked(): void
    {
        $readiness = app(AtlasSelfConstructionReadinessService::class);

        $releaseTemplate = $readiness->agentAutomaticDispatchSchedulerOneShotTickReleaseTemplate();
        $receiptDraft = $readiness->agentAutomaticDispatchSchedulerOneShotTickReleaseReceiptDraft();
        $persistenceContract = $readiness->agentAutomaticDispatchSchedulerOneShotTickReleaseReceiptPersistenceContract();

        $this->assertSame('blocked', $releaseTemplate['status']);
        $this->assertSame(
            'repair_signed_one_shot_scheduler_tick_release_template_preflight_blockers',
            $releaseTemplate['agent_automatic_dispatch_scheduler_one_shot_tick_release_template']['next_required_slice']
        );
        $this->assertSame('blocked', $receiptDraft['status']);
        $this->assertSame(
            'repair_signed_one_shot_scheduler_tick_release_receipt_draft_preflight_blockers',
            $receiptDraft['agent_automatic_dispatch_scheduler_one_shot_tick_release_receipt_draft']['next_required_slice']
        );
        $this->assertSame('blocked', $persistenceContract['status']);
        $this->assertSame(
            'repair_signed_one_shot_scheduler_tick_release_receipt_persistence_contract_preflight_blockers',
            $persistenceContract['agent_automatic_dispatch_scheduler_one_shot_tick_release_receipt_persistence_contract']['next_required_slice']
        );
    }

    public function test_persistence_writer_preflight_uses_live_schema_evidence_for_atomicity_requirements(): void
    {
        $readiness = app(AtlasSelfConstructionReadinessService::class);
        $contract = $readiness->agentAutomaticDispatchSchedulerOneShotTickReleaseReceiptPersistenceContract();
        $preflight = $readiness->agentAutomaticDispatchSchedulerOneShotTickReleaseReceiptPersistenceWriterPreflight();
        $storageReadiness = $preflight['agent_automatic_dispatch_scheduler_one_shot_tick_release_receipt_persistence_writer_preflight']['storage_readiness'];
        $uniqueIndexes = collect(Schema::getIndexes('atlas_self_construction_agent_dispatch_receipts'))
            ->filter(static fn (array $index): bool => ($index['unique'] ?? false) === true);

        $this->assertSame(
            ['receipt_hash'],
            $contract['agent_automatic_dispatch_scheduler_one_shot_tick_release_receipt_persistence_contract']['storage_target']['idempotency_key_fields']
        );
        $this->assertSame(DB::connection()->getDriverName(), $storageReadiness['database_driver']);
        $this->assertSame(
            $uniqueIndexes->contains(static fn (array $index): bool => ($index['columns'] ?? []) === ['receipt_hash']),
            $storageReadiness['unique_indexes']['receipt_hash']
        );
        $this->assertSame(
            $uniqueIndexes->contains(static fn (array $index): bool => ($index['columns'] ?? []) === ['receipt_key']),
            $storageReadiness['unique_indexes']['receipt_key']
        );
        $this->assertSame(
            $storageReadiness['unique_indexes']['receipt_hash'],
            $preflight['agent_automatic_dispatch_scheduler_one_shot_tick_release_receipt_persistence_writer_preflight']['preflight_checks']['receipt_hash_unique_index_ready']
        );
    }

    public function test_work_splitter_authorizes_the_canonical_readiness_service_path(): void
    {
        $splitter = app(AtlasSelfConstructionReadinessService::class)->workSplitter();
        $readinessPacket = collect($splitter['split']['packets'])
            ->firstWhere('packet_id', 'AIP-SPLIT-SELF-CONSTRUCTION-SERVICE-0004');

        $this->assertSame(
            ['app/Services/Ai/SelfConstruction/Readiness/AtlasSelfConstructionReadinessService.php'],
            $readinessPacket['allowed_files']
        );
        $this->assertFileExists(base_path($readinessPacket['allowed_files'][0]));
    }
}
