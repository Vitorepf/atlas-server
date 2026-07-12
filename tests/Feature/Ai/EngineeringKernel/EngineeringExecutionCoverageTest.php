<?php

declare(strict_types=1);

namespace Tests\Feature\Ai\EngineeringKernel;

use App\Models\AtlasLedgerEvent;
use App\Services\Ai\EngineeringKernel\Coverage\EngineeringExecutionCoverage;
use App\Services\Ai\EngineeringKernel\Coverage\EngineeringExecutionSurfaceRegistry;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Tests\TestCase;

final class EngineeringExecutionCoverageTest extends TestCase
{
    private EngineeringExecutionCoverage $coverage;

    protected function setUp(): void
    {
        parent::setUp();

        Schema::dropIfExists('atlas_ledger_events');
        Schema::create('atlas_ledger_events', function (Blueprint $table): void {
            $table->string('event_id', 32)->primary();
            $table->string('schema_version', 40)->default('atlas.ledger_event.v1');
            $table->string('tenant_id', 120)->index();
            $table->string('operator_id', 120)->index();
            $table->string('envelope_id', 80)->index();
            $table->string('receipt_id', 80)->nullable()->index();
            $table->string('trace_id', 80)->nullable()->index();
            $table->string('correlation_id', 120)->index();
            $table->string('causation_id', 80)->nullable()->index();
            $table->string('event_type', 80)->index();
            $table->string('emitter_stage', 120)->index();
            $table->string('emitter_version', 80);
            $table->json('payload');
            $table->string('payload_hash', 64)->index();
            $table->string('scope_type', 40)->nullable()->index();
            $table->string('scope_id', 80)->nullable()->index();
            $table->string('event_hash', 64)->nullable()->index();
            $table->timestampTz('occurred_at')->index();
            $table->timestampsTz();
        });

        $this->coverage = new EngineeringExecutionCoverage;
    }

    protected function tearDown(): void
    {
        Schema::dropIfExists('atlas_ledger_events');

        parent::tearDown();
    }

    public function test_record_persists_complete_coverage_event_to_atlas_ledger(): void
    {
        $this->coverage->record($this->completeEvent());

        $row = AtlasLedgerEvent::query()
            ->where('event_type', EngineeringExecutionCoverage::EVENT_TYPE)
            ->firstOrFail();

        $this->assertSame(EngineeringExecutionCoverage::SCHEMA_VERSION, $row->schema_version);
        $this->assertSame('engineering_execution_surface', $row->scope_type);
        $this->assertSame('atlas_dev.pipeline_run_executor', $row->scope_id);
        $this->assertTrue($row->payload['complete']);
        $this->assertSame([], $row->payload['missing_fields']);

        $report = $this->coverage->report(EngineeringExecutionCoverage::MODE_OBSERVE);

        $this->assertSame('ok', $report['status']);
        $this->assertSame([
            'total_events' => 1,
            'complete_events' => 1,
            'incomplete_events' => 0,
        ], $report['counts']);
        $this->assertSame(EngineeringExecutionSurfaceRegistry::all(), $report['surfaces']);
    }

    public function test_kernel_routed_without_correlated_execution_hashes_is_incomplete(): void
    {
        $this->coverage->record([
            'mode' => EngineeringExecutionCoverage::MODE_OBSERVE,
            'surface' => 'atlas_dev.pipeline_run_executor',
            'run_id' => 'run-only-routed',
            'kernel_routed' => true,
        ]);

        $report = $this->coverage->report();

        $this->assertSame('observe_incomplete', $report['status']);
        $this->assertSame(1, $report['counts']['total_events']);
        $this->assertSame(0, $report['counts']['complete_events']);
        $this->assertSame(1, $report['counts']['incomplete_events']);
        $this->assertTrue($report['incomplete_samples'][0]['kernel_routed']);
        $this->assertContains('execution_order_hash', $report['incomplete_samples'][0]['missing_fields']);
        $this->assertContains('provider_receipt', $report['incomplete_samples'][0]['missing_fields']);
        $this->assertContains('release_receipt', $report['incomplete_samples'][0]['missing_fields']);
    }

    public function test_replaying_same_event_id_is_idempotent_and_conflicting_payload_fails_closed(): void
    {
        $event = $this->completeEvent(['event_id' => 'coverage-replay-event']);

        $this->coverage->record($event);
        $this->coverage->record($event);

        $this->assertSame(1, AtlasLedgerEvent::query()
            ->where('event_id', 'coverage-replay-event')
            ->count());

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('engineering_execution_coverage_event_conflict');
        $this->coverage->record(array_replace($event, ['terminal_outcome' => 'conflict']));
    }

    public function test_report_recomputes_completeness_instead_of_trusting_payload_complete_flag(): void
    {
        $this->insertLedgerPayload([
            'schema_version' => EngineeringExecutionCoverage::SCHEMA_VERSION,
            'mode' => EngineeringExecutionCoverage::MODE_OBSERVE,
            'surface' => 'atlas_dev.pipeline_run_executor',
            'run_id' => 'run-forged-complete',
            'kernel_routed' => true,
            'complete' => true,
            'missing_fields' => [],
        ]);

        $report = $this->coverage->report();

        $this->assertSame('observe_incomplete', $report['status']);
        $this->assertSame(0, $report['counts']['complete_events']);
        $this->assertContains('workspace_delta_hash', $report['incomplete_samples'][0]['missing_fields']);
    }

    public function test_enforce_mode_fails_when_any_event_is_incomplete(): void
    {
        $this->coverage->record($this->completeEvent([
            'mode' => EngineeringExecutionCoverage::MODE_ENFORCE,
            'run_id' => 'run-enforce-complete',
        ]));
        $this->coverage->record([
            'mode' => EngineeringExecutionCoverage::MODE_ENFORCE,
            'surface' => 'atlas_autonomos.native_worker',
            'run_id' => 'run-enforce-incomplete',
            'execution_order_hash' => 'order-hash',
            'kernel_routed' => true,
        ]);

        $report = $this->coverage->report(EngineeringExecutionCoverage::MODE_ENFORCE);

        $this->assertSame('failed', $report['status']);
        $this->assertSame(2, $report['counts']['total_events']);
        $this->assertSame(1, $report['counts']['complete_events']);
        $this->assertSame(1, $report['counts']['incomplete_events']);
    }

    public function test_enforce_mode_is_ok_when_all_events_are_complete(): void
    {
        $this->coverage->record($this->completeEvent([
            'mode' => EngineeringExecutionCoverage::MODE_ENFORCE,
            'run_id' => 'run-enforce-ok-1',
        ]));
        $this->coverage->record($this->completeEvent([
            'mode' => EngineeringExecutionCoverage::MODE_ENFORCE,
            'surface' => 'engineering_kernel.merge_actuator',
            'run_id' => 'run-enforce-ok-2',
        ]));

        $report = $this->coverage->report(EngineeringExecutionCoverage::MODE_ENFORCE);

        $this->assertSame('ok', $report['status']);
        $this->assertSame(2, $report['counts']['complete_events']);
        $this->assertSame([], $report['incomplete_samples']);
    }

    public function test_pending_data_when_no_events_exist_for_mode(): void
    {
        $report = $this->coverage->report(EngineeringExecutionCoverage::MODE_ENFORCE);

        $this->assertSame(EngineeringExecutionCoverage::SCHEMA_VERSION, $report['schema_version']);
        $this->assertSame(EngineeringExecutionCoverage::MODE_ENFORCE, $report['mode']);
        $this->assertSame('pending_data', $report['status']);
        $this->assertSame(0, $report['counts']['total_events']);
    }

    public function test_record_and_report_fail_open_when_ledger_table_is_missing(): void
    {
        Schema::dropIfExists('atlas_ledger_events');

        $this->coverage->record($this->completeEvent());
        $report = $this->coverage->report();

        $this->assertSame('pending_data', $report['status']);
        $this->assertSame(0, $report['counts']['total_events']);
    }

    /**
     * @param  array<string,mixed>  $overrides
     * @return array<string,mixed>
     */
    private function completeEvent(array $overrides = []): array
    {
        return array_replace([
            'mode' => EngineeringExecutionCoverage::MODE_OBSERVE,
            'surface' => 'atlas_dev.pipeline_run_executor',
            'run_id' => 'run-complete',
            'execution_order_hash' => str_repeat('a', 64),
            'provider_receipt' => 'provider-receipt-hash',
            'workspace_delta_hash' => str_repeat('b', 64),
            'acceptance_receipt' => 'acceptance-receipt-hash',
            'release_receipt' => 'release-receipt-hash',
            'terminal_outcome' => 'accepted',
            'kernel_routed' => true,
        ], $overrides);
    }

    /**
     * @param  array<string,mixed>  $payload
     */
    private function insertLedgerPayload(array $payload): void
    {
        AtlasLedgerEvent::query()->create([
            'event_id' => (string) Str::ulid(),
            'schema_version' => EngineeringExecutionCoverage::SCHEMA_VERSION,
            'tenant_id' => 'default',
            'operator_id' => 'system',
            'envelope_id' => 'engineering_execution:test',
            'receipt_id' => null,
            'trace_id' => null,
            'correlation_id' => (string) ($payload['run_id'] ?? Str::ulid()),
            'causation_id' => null,
            'event_type' => EngineeringExecutionCoverage::EVENT_TYPE,
            'emitter_stage' => 'test',
            'emitter_version' => EngineeringExecutionCoverage::SCHEMA_VERSION,
            'payload' => $payload,
            'payload_hash' => hash('sha256', json_encode($payload, JSON_THROW_ON_ERROR)),
            'scope_type' => 'engineering_execution_surface',
            'scope_id' => is_string($payload['surface'] ?? null) ? $payload['surface'] : null,
            'event_hash' => null,
            'occurred_at' => now(),
        ]);
    }
}
