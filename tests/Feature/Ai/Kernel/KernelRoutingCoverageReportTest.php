<?php

declare(strict_types=1);

namespace Tests\Feature\Ai\Kernel;

use App\Models\AiTrace;
use App\Services\Ai\Kernel\Architecture\KernelRoutingCoverageReport;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * Gap1.F5 — kernel_routed coverage gate.
 *
 * The Definition of Done says the gate must require
 * `kernel_routed == true em 100% das requests dos últimos 7d`. The
 * 7-day window itself is not testable today (wall-clock has not
 * elapsed), but the LOGIC that enforces 100% is testable now via
 * fixtures. These tests pin the contract:
 *
 *   - 100% routed -> status=ok
 *   - 99% routed (1 unrouted) -> status=failed
 *   - missing flag treated as unrouted (honest, no silent default)
 *   - zero traces -> pending_data (honest empty domain)
 *   - traces outside window are ignored
 */
class KernelRoutingCoverageReportTest extends TestCase
{
    private KernelRoutingCoverageReport $report;

    protected function setUp(): void
    {
        parent::setUp();
        // Reuse the same approach as AtlasDevPlanVisibleE2ETest — cherry-pick
        // the table the test needs because the full migration tree contains
        // Postgres-only DDL.
        if (! Schema::hasTable('ai_traces')) {
            Schema::create('ai_traces', function (Blueprint $table) {
                $table->uuid('id')->primary();
                $table->string('trace_key')->unique();
                $table->string('source_type', 32)->default('manual');
                $table->string('status', 32)->default('queued');
                $table->text('operator_input');
                $table->json('skill_versions')->default('[]');
                $table->json('context_refs')->default('{}');
                $table->json('metadata')->default('{}');
                $table->timestamps();
            });
        }
        $this->report = new KernelRoutingCoverageReport;
    }

    protected function tearDown(): void
    {
        Schema::dropIfExists('ai_traces');
        parent::tearDown();
    }

    public function test_pending_data_when_window_is_empty(): void
    {
        $snapshot = $this->report->snapshot();

        $this->assertSame('pending_data', $snapshot['status']);
        $this->assertSame(0, $snapshot['counts']['total']);
        $this->assertNull($snapshot['coverage_pct']);
        $this->assertSame(100.0, $snapshot['required_coverage_pct']);
        $this->assertStringContainsString('not measurable', $snapshot['detail']);
    }

    public function test_status_ok_when_all_traces_routed(): void
    {
        $this->makeTrace(['kernel_routed' => true]);
        $this->makeTrace(['kernel_routed' => true]);
        $this->makeTrace(['kernel_routed' => true]);

        $snapshot = $this->report->snapshot();

        $this->assertSame('ok', $snapshot['status']);
        $this->assertSame(3, $snapshot['counts']['total']);
        $this->assertSame(3, $snapshot['counts']['routed']);
        $this->assertSame(0, $snapshot['counts']['unrouted']);
        $this->assertSame(100.0, $snapshot['coverage_pct']);
    }

    public function test_status_failed_with_one_unrouted_trace(): void
    {
        $this->makeTrace(['kernel_routed' => true]);
        $this->makeTrace(['kernel_routed' => true]);
        $this->makeTrace(['kernel_routed' => false]);

        $snapshot = $this->report->snapshot();

        $this->assertSame('failed', $snapshot['status']);
        $this->assertSame(3, $snapshot['counts']['total']);
        $this->assertSame(2, $snapshot['counts']['routed']);
        $this->assertSame(1, $snapshot['counts']['unrouted']);
        $this->assertEqualsWithDelta(66.67, $snapshot['coverage_pct'], 0.01);
        $this->assertCount(1, $snapshot['sample_unrouted_traces']);
    }

    public function test_missing_kernel_routed_flag_counts_as_unrouted(): void
    {
        $this->makeTrace(['kernel_routed' => true]);
        // Trace without any kernel envelope — represents legacy path.
        $this->makeTrace(null);

        $snapshot = $this->report->snapshot();

        $this->assertSame('failed', $snapshot['status']);
        $this->assertSame(1, $snapshot['counts']['routed']);
        $this->assertSame(1, $snapshot['counts']['unrouted']);
        $this->assertSame(1, $snapshot['counts']['missing_flag']);
    }

    public function test_traces_outside_window_are_ignored(): void
    {
        // 30 days ago — outside default 7-day window.
        $old = $this->makeTrace(['kernel_routed' => false]);
        $old->created_at = now()->subDays(30);
        $old->save();

        // 1 day ago — inside window.
        $this->makeTrace(['kernel_routed' => true]);

        $snapshot = $this->report->snapshot();

        $this->assertSame('ok', $snapshot['status']);
        $this->assertSame(1, $snapshot['counts']['total']);
    }

    public function test_window_days_override(): void
    {
        // 10 days ago — outside default 7d, inside custom 30d.
        $trace = $this->makeTrace(['kernel_routed' => true]);
        $trace->created_at = now()->subDays(10);
        $trace->save();

        $sevenDay = $this->report->snapshot(null, 7);
        $thirtyDay = $this->report->snapshot(null, 30);

        $this->assertSame('pending_data', $sevenDay['status']);
        $this->assertSame('ok', $thirtyDay['status']);
        $this->assertSame(1, $thirtyDay['counts']['total']);
    }

    public function test_sample_unrouted_caps_at_10(): void
    {
        for ($i = 0; $i < 15; $i++) {
            $this->makeTrace(['kernel_routed' => false]);
        }
        $this->makeTrace(['kernel_routed' => true]);

        $snapshot = $this->report->snapshot();

        $this->assertSame(16, $snapshot['counts']['total']);
        $this->assertSame(15, $snapshot['counts']['unrouted']);
        $this->assertCount(10, $snapshot['sample_unrouted_traces']);
    }

    public function test_envelope_shape_is_stable(): void
    {
        $snapshot = $this->report->snapshot();

        $this->assertSame([
            'schema_version',
            'status',
            'window',
            'counts',
            'coverage_pct',
            'required_coverage_pct',
            'detail',
            'sample_unrouted_traces',
        ], array_keys($snapshot));
        $this->assertSame('atlas.ai.kernel_routing_coverage.v1', $snapshot['schema_version']);
    }

    public function test_failed_status_detail_explains_gap(): void
    {
        $this->makeTrace(['kernel_routed' => true]);
        $this->makeTrace(['kernel_routed' => false]);

        $snapshot = $this->report->snapshot();

        $this->assertStringContainsString('50', $snapshot['detail']);
        $this->assertStringContainsString('required', $snapshot['detail']);
    }

    /**
     * @param  array<string,mixed>|null  $kernelEnvelope
     */
    private function makeTrace(?array $kernelEnvelope): AiTrace
    {
        $metadata = $kernelEnvelope !== null ? ['kernel' => $kernelEnvelope] : [];

        return AiTrace::query()->create([
            'trace_key' => 'trace-'.Str::uuid()->toString(),
            'source_type' => 'manual',
            'status' => 'succeeded',
            'operator_input' => 'fixture trace',
            'metadata' => $metadata,
        ]);
    }
}
