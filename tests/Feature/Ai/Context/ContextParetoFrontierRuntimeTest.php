<?php

declare(strict_types=1);

namespace Tests\Feature\Ai\Context;

use App\Services\Ai\Context\AtlasContextParetoFrontierRuntimeService;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Tests\TestCase;

final class ContextParetoFrontierRuntimeTest extends TestCase
{
    public function test_report_is_shadow_only_and_selects_frontier_candidates(): void
    {
        $payload = app(AtlasContextParetoFrontierRuntimeService::class)->report();

        $this->assertSame(AtlasContextParetoFrontierRuntimeService::SCHEMA_VERSION, $payload['schema_version']);
        $this->assertSame('ready', $payload['status']);
        $this->assertSame(15, $payload['summary']['total_candidates']);
        $this->assertSame(5, $payload['summary']['selected_candidates']);
        $this->assertGreaterThanOrEqual(5, $payload['summary']['frontier_candidates']);
        $this->assertTrue($payload['claims']['shadow_only']);
        $this->assertFalse($payload['claims']['providers_invoked']);
        $this->assertFalse($payload['claims']['promotes_runtime_change']);
        $this->assertMatchesRegularExpression('/^[a-f0-9]{64}$/', $payload['frontier_hash']);
    }

    public function test_candidates_with_must_keep_below_one_are_blocked(): void
    {
        $payload = app(AtlasContextParetoFrontierRuntimeService::class)->report();

        $blocked = array_values(array_filter(
            $payload['candidates'],
            static fn (array $candidate): bool => $candidate['promotion_status'] === 'blocked',
        ));

        $this->assertNotEmpty($blocked);

        foreach ($blocked as $candidate) {
            $this->assertLessThan(1.0, $candidate['must_keep_coverage']);
            $this->assertContains('must_keep_coverage_below_1_0', $candidate['blockers']);
        }
    }

    public function test_pareto_frontier_excludes_dominated_and_blocked_candidates(): void
    {
        $service = app(AtlasContextParetoFrontierRuntimeService::class);
        $payload = $service->report();

        foreach ($payload['frontier'] as $candidate) {
            $this->assertFalse($candidate['pareto_dominated']);
            $this->assertNotSame('blocked', $candidate['promotion_status']);
        }

        $custom = $service->paretoFrontier([
            [
                'candidate_id' => 'a',
                'case_id' => 'case',
                'quality_score' => 0.90,
                'input_tokens' => 8000,
                'cost_units' => 1.0,
                'latency_ms' => 900,
                'must_keep_coverage' => 1.0,
                'promotion_status' => 'shadow_candidate',
                'pareto_dominated' => false,
            ],
            [
                'candidate_id' => 'b',
                'case_id' => 'case',
                'quality_score' => 0.91,
                'input_tokens' => 7000,
                'cost_units' => 0.8,
                'latency_ms' => 800,
                'must_keep_coverage' => 1.0,
                'promotion_status' => 'shadow_candidate',
                'pareto_dominated' => false,
            ],
        ]);

        $this->assertCount(1, $custom);
        $this->assertSame('b', $custom[0]['candidate_id']);
    }

    public function test_command_emits_json(): void
    {
        $exitCode = Artisan::call('atlas:context:pareto-frontier', [
            '--json' => true,
        ]);

        $this->assertSame(0, $exitCode);

        $payload = json_decode(Artisan::output(), true, flags: JSON_THROW_ON_ERROR);

        $this->assertSame(AtlasContextParetoFrontierRuntimeService::SCHEMA_VERSION, $payload['schema_version']);
        $this->assertSame(5, $payload['summary']['selected_candidates']);
    }

    public function test_real_trace_shadow_uses_metric_summaries_without_writes(): void
    {
        $this->createMetricSummaryTable();
        $traceId = Str::uuid()->toString();

        DB::table('ai_trace_metric_summaries')->insert([
            'id' => Str::uuid()->toString(),
            'trace_id' => $traceId,
            'surface' => 'desktop',
            'runtime' => 'atlas_dev',
            'provider' => 'claude_cli',
            'model' => 'claude',
            'agent_slug' => 'atlas_dev',
            'task_type' => 'programming',
            'status' => 'succeeded',
            'prompt_tokens' => 10000,
            'completion_tokens' => 1500,
            'total_tokens' => 11500,
            'estimated_tokens' => 11500,
            'context_tokens' => 6000,
            'cost_microusd' => 2400,
            'total_latency_ms' => 4200,
            'final_quality_score' => 92,
            'auto_quality_score' => 90,
            'first_pass_success' => true,
            'needed_remediation' => false,
            'computed_at' => Carbon::now(),
            'created_at' => Carbon::now(),
            'updated_at' => Carbon::now(),
        ]);

        $payload = app(AtlasContextParetoFrontierRuntimeService::class)->report(hours: 24);

        $this->assertSame('ready', $payload['real_trace_shadow']['status']);
        $this->assertSame(1, $payload['real_trace_shadow']['summary']['source_traces']);
        $this->assertSame(2, $payload['real_trace_shadow']['summary']['total_candidates']);
        $this->assertSame(1, $payload['real_trace_shadow']['summary']['selected_candidates']);
        $this->assertGreaterThan(0, $payload['real_trace_shadow']['summary']['estimated_token_savings']);
        $this->assertSame($traceId, data_get($payload, 'real_trace_shadow.selected.0.trace_ref.trace_id'));
        $this->assertTrue($payload['claims']['shadow_only']);
        $this->assertFalse($payload['writes']);
    }

    public function test_real_trace_shadow_blocks_low_confidence_trace_variants(): void
    {
        $this->createMetricSummaryTable();

        DB::table('ai_trace_metric_summaries')->insert([
            'id' => Str::uuid()->toString(),
            'trace_id' => Str::uuid()->toString(),
            'surface' => 'desktop',
            'runtime' => 'atlas_forge',
            'task_type' => 'forge',
            'status' => 'succeeded',
            'prompt_tokens' => 14000,
            'completion_tokens' => 1800,
            'total_tokens' => 15800,
            'estimated_tokens' => 15800,
            'context_tokens' => 8000,
            'cost_microusd' => 3200,
            'total_latency_ms' => 6100,
            'final_quality_score' => 68,
            'first_pass_success' => false,
            'needed_remediation' => true,
            'computed_at' => Carbon::now(),
            'created_at' => Carbon::now(),
            'updated_at' => Carbon::now(),
        ]);

        $payload = app(AtlasContextParetoFrontierRuntimeService::class)->report(hours: 24);

        $this->assertSame('ready', $payload['real_trace_shadow']['status']);
        $this->assertSame(2, $payload['real_trace_shadow']['summary']['blocked_candidates']);
        $this->assertSame(0, $payload['real_trace_shadow']['summary']['selected_candidates']);
        $this->assertContains(
            'real_trace_quality_or_remediation_requires_review',
            data_get($payload, 'real_trace_shadow.candidates.1.blockers', []),
        );
    }

    private function createMetricSummaryTable(): void
    {
        Schema::dropIfExists('ai_trace_metric_summaries');

        Schema::create('ai_trace_metric_summaries', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->uuid('trace_id')->nullable()->index();
            $table->string('surface', 32)->nullable();
            $table->string('runtime', 80)->nullable();
            $table->string('provider', 80)->nullable();
            $table->string('model', 120)->nullable();
            $table->string('agent_slug', 120)->nullable();
            $table->string('task_type', 80)->nullable();
            $table->string('status', 32)->nullable();
            $table->integer('prompt_tokens')->nullable();
            $table->integer('completion_tokens')->nullable();
            $table->integer('total_tokens')->nullable();
            $table->integer('estimated_tokens')->nullable();
            $table->bigInteger('cost_microusd')->nullable();
            $table->integer('context_tokens')->nullable();
            $table->integer('total_latency_ms')->nullable();
            $table->integer('provider_latency_ms')->nullable();
            $table->integer('final_quality_score')->nullable();
            $table->integer('auto_quality_score')->nullable();
            $table->boolean('first_pass_success')->nullable();
            $table->boolean('needed_remediation')->default(false);
            $table->timestamp('computed_at')->nullable();
            $table->timestamps();
        });
    }
}
