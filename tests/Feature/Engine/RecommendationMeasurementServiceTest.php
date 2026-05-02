<?php

namespace Tests\Feature\Engine;

use App\Models\AiPerformanceRecommendation;
use App\Models\AiTrace;
use App\Models\AiTraceMetricSummary;
use Carbon\CarbonImmutable;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Tests\TestCase;

class RecommendationMeasurementServiceTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        config()->set('atlas.report.recommendation_measure_min_samples', 2);
        config()->set('atlas.report.recommendation_min_effect_fraction', 0.02);
        $this->bootSchema();
    }

    protected function tearDown(): void
    {
        foreach ([
            'ai_performance_recommendations',
            'ai_trace_metric_summaries',
            'ai_outcome_links',
            'ai_provider_cost_rates',
            'ai_traces',
        ] as $table) {
            Schema::dropIfExists($table);
        }

        parent::tearDown();
    }

    public function test_measure_command_resolves_effective_applied_recommendation(): void
    {
        $recommendation = $this->seedRecommendation([
            'baseline_snapshot' => ['metric' => 'final_quality_avg', 'mean' => 70.0, 'sample_n' => 12],
        ]);

        $this->seedTraceAt('2026-05-02T10:00:00Z', provider: 'claude_cli', quality: 82);
        $this->seedTraceAt('2026-05-03T10:00:00Z', provider: 'claude_cli', quality: 88);
        $this->seedTraceAt('2026-05-03T11:00:00Z', provider: 'openai', quality: 20);

        $this->artisan('atlas:ai:recommendations:measure', [
            '--now' => '2026-05-04T07:00:00Z',
            '--json' => true,
        ])->assertExitCode(0);

        $recommendation->refresh();
        $this->assertSame('resolved', $recommendation->state);
        $this->assertSame('measured_effective', $recommendation->closed_reason);
        $this->assertTrue((bool) data_get($recommendation->observed_impact, 'effective'));
        $this->assertEqualsWithDelta(85.0, data_get($recommendation->observed_impact, 'observed_value'), 0.0001);
        $this->assertSame(2, data_get($recommendation->observed_impact, 'sample_n'),
            'Only provider=claude_cli traces should count for this recommendation measurement.');
    }

    public function test_measure_command_defers_when_sample_is_too_small(): void
    {
        config()->set('atlas.report.recommendation_measure_min_samples', 3);
        $recommendation = $this->seedRecommendation([
            'baseline_snapshot' => ['metric' => 'final_quality_avg', 'mean' => 70.0, 'sample_n' => 12],
        ]);

        $this->seedTraceAt('2026-05-02T10:00:00Z', provider: 'claude_cli', quality: 82);

        $this->artisan('atlas:ai:recommendations:measure', [
            '--now' => '2026-05-04T07:00:00Z',
            '--json' => true,
        ])->assertExitCode(0);

        $recommendation->refresh();
        $this->assertSame('applied', $recommendation->state);
        $this->assertSame('2026-05-05T07:00:00.000000Z', $recommendation->measurement_due_at?->toJSON());
        $this->assertSame('measurement_deferred_insufficient_sample', data_get($recommendation->state_history, '1.reason'));
    }

    public function test_measure_command_marks_unapplied_recommendation_self_healed_when_metric_recovers(): void
    {
        $recommendation = $this->seedRecommendation([
            'state' => 'proposed',
            'state_history' => [[
                'state' => 'proposed',
                'at' => '2026-05-01T07:00:00Z',
                'reason' => 'test_proposed',
            ]],
            'measurement_due_at' => null,
            'baseline_snapshot' => ['metric' => 'final_quality_avg', 'mean' => 70.0, 'sample_n' => 12],
        ]);

        $this->seedTraceAt('2026-05-03T10:00:00Z', provider: 'claude_cli', quality: 82);
        $this->seedTraceAt('2026-05-03T11:00:00Z', provider: 'claude_cli', quality: 86);

        $this->artisan('atlas:ai:recommendations:measure', [
            '--now' => '2026-05-04T07:00:00Z',
            '--json' => true,
        ])->assertExitCode(0);

        $recommendation->refresh();
        $this->assertSame('self_healed', $recommendation->state);
        $this->assertSame('self_healed', $recommendation->closed_reason);
        $this->assertTrue((bool) data_get($recommendation->observed_impact, 'effective'));
    }

    /**
     * @param  array<string,mixed>  $overrides
     */
    private function seedRecommendation(array $overrides = []): AiPerformanceRecommendation
    {
        return AiPerformanceRecommendation::query()->create(array_merge([
            'user_id' => 'vitor',
            'origin_report_date' => '2026-05-01',
            'kind' => 'quality_drop',
            'target_metric' => 'final_quality_avg',
            'target_dimension' => ['provider' => 'claude_cli'],
            'expected_impact' => ['direction' => 'increase', 'magnitude' => 0.10],
            'state' => 'applied',
            'state_history' => [[
                'state' => 'applied',
                'at' => '2026-05-01T07:00:00Z',
                'reason' => 'test_applied',
            ]],
            'baseline_snapshot' => ['metric' => 'final_quality_avg', 'mean' => 70.0],
            'measurement_due_at' => '2026-05-03T07:00:00Z',
            'measurement_window_days' => 2,
            'priority_score' => 80,
        ], $overrides));
    }

    private function seedTraceAt(string $timestamp, string $provider, int $quality): void
    {
        $traceId = (string) Str::uuid();
        $createdAt = CarbonImmutable::parse($timestamp);

        $trace = new AiTrace();
        $trace->forceFill([
            'id' => $traceId,
            'trace_key' => 'trace-'.$traceId,
            'thread_id' => (string) Str::uuid(),
            'session_id' => (string) Str::uuid(),
            'source_type' => 'app',
            'status' => 'succeeded',
            'operator_input' => 'Teste recommendation measurement',
            'agent_slug' => 'orquestrador',
            'provider' => $provider,
            'model' => 'model',
            'skill_versions' => [],
            'context_refs' => [],
            'response_text' => 'ok',
            'completed_at' => $createdAt->addSecond(),
            'metadata' => [],
            'created_at' => $createdAt,
            'updated_at' => $createdAt,
        ]);
        $trace->save();

        AiTraceMetricSummary::query()->create([
            'trace_id' => $traceId,
            'surface' => 'mobile',
            'provider' => $provider,
            'model' => 'model',
            'agent_slug' => 'orquestrador',
            'task_type' => 'chat',
            'status' => 'succeeded',
            'final_quality_score' => $quality,
            'final_efficiency_score' => 80,
            'first_pass_success' => true,
            'needed_remediation' => false,
            'cost_microusd' => 0,
            'cost_confidence' => 'metered',
            'cost_mode' => 'metered_estimate',
            'score_components' => [],
            'metadata' => ['aggregator_version' => 'ai_trace_metric_aggregator_v2'],
            'computed_at' => $createdAt,
        ]);
    }

    private function bootSchema(): void
    {
        foreach ([
            'ai_performance_recommendations',
            'ai_trace_metric_summaries',
            'ai_outcome_links',
            'ai_provider_cost_rates',
            'ai_traces',
        ] as $table) {
            Schema::dropIfExists($table);
        }

        Schema::create('ai_traces', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->string('trace_key')->unique();
            $table->uuid('thread_id')->nullable();
            $table->uuid('session_id')->nullable();
            $table->string('source_type')->default('app');
            $table->string('status')->default('queued');
            $table->text('operator_input');
            $table->string('agent_slug');
            $table->string('provider')->nullable();
            $table->string('model')->nullable();
            $table->json('skill_versions')->default('{}');
            $table->json('context_refs')->default('[]');
            $table->text('response_text')->nullable();
            $table->timestamp('completed_at')->nullable();
            $table->json('metadata')->default('{}');
            $table->timestamps();
        });

        (require database_path('migrations/2026_05_01_001000_create_ai_metric_summary_tables.php'))->up();
        (require database_path('migrations/2026_05_01_011000_create_ai_performance_recommendations.php'))->up();
    }
}
