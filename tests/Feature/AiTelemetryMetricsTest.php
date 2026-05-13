<?php

namespace Tests\Feature;

use App\Models\AiContextSnapshot;
use App\Models\AiJob;
use App\Models\AiOutcomeLink;
use App\Models\AiProviderCostRate;
use App\Models\AiQualityEvaluation;
use App\Models\AiTelemetryEvent;
use App\Models\AiTrace;
use App\Models\AiTraceMetricSummary;
use App\Services\Ai\AiProviderModelResolver;
use App\Services\Ai\Telemetry\AiProviderCostRateService;
use App\Services\Ai\Telemetry\AiTelemetryWindowInput;
use App\Services\Ai\Telemetry\AiTraceMetricAggregator;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Tests\TestCase;

class AiTelemetryMetricsTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        config()->set('atlas.token', 'testing-atlas-token-with-enough-length');
        $this->createTables();
    }

    protected function tearDown(): void
    {
        foreach ([
            'ai_tool_events',
            'ai_trace_metric_summaries',
            'ai_outcome_links',
            'ai_provider_cost_rates',
            'ai_context_snapshots',
            'ai_telemetry_events',
            'ai_quality_actions',
            'ai_quality_evaluations',
            'ai_job_attempts',
            'ai_jobs',
            'ai_stream_events',
            'ai_traces',
            'ai_sessions',
            'ai_threads',
        ] as $table) {
            Schema::dropIfExists($table);
        }

        parent::tearDown();
    }

    public function test_aggregator_recomputes_trace_metric_summary_from_events_quality_and_cost(): void
    {
        $trace = $this->seedCompletedTrace();

        $summary = app(AiTraceMetricAggregator::class)->recomputeTrace($trace->id);

        $this->assertSame($trace->id, $summary->trace_id);
        $this->assertSame('mobile', $summary->surface);
        $this->assertSame('claude_cli', $summary->provider);
        $this->assertSame(180, $summary->app_send_to_accept_ms);
        $this->assertSame(350, $summary->app_send_to_visible_ms);
        $this->assertTrue($summary->recovered_from_pending);
        $this->assertTrue($summary->backgrounded_during_run);
        $this->assertSame('provider_usage', $summary->token_source);
        $this->assertSame('estimated', $summary->cost_confidence);
        $this->assertSame('cli_provider_usage_estimate', $summary->cost_source);
        $this->assertSame('operational_estimate', $summary->cost_mode);
        $this->assertGreaterThan(0, $summary->cost_microusd);
        $this->assertSame(1200, $summary->context_tokens);
        $this->assertTrue($summary->compaction_used);
        $this->assertGreaterThanOrEqual(70, $summary->final_quality_score);
        $this->assertGreaterThanOrEqual(60, $summary->final_efficiency_score);
    }

    public function test_aggregator_does_not_mix_events_from_other_submissions_on_same_client(): void
    {
        $trace = $this->seedCompletedTrace();

        AiTelemetryEvent::query()->create([
            'event_key' => 'mobile:foreign-submission:'.Str::uuid(),
            'correlation_id' => (string) Str::uuid(),
            'trace_id' => null,
            'thread_id' => (string) Str::uuid(),
            'session_id' => $trace->session_id,
            'client_id' => data_get($trace->metadata, 'client_id'),
            'surface' => 'mobile',
            'runtime' => 'ios',
            'provider' => 'claude_cli',
            'model' => 'test-model',
            'agent_slug' => 'orquestrador',
            'event_name' => 'interaction_accepted',
            'received_at' => now()->subMinutes(10),
            'duration_ms' => 9999,
            'metadata' => [],
            'privacy' => [],
        ]);

        $summary = app(AiTraceMetricAggregator::class)->recomputeTrace($trace->id);

        $this->assertSame(180, $summary->app_send_to_accept_ms);
    }

    public function test_feedback_records_human_outcome_and_recomputes_metric_summary(): void
    {
        $trace = $this->seedCompletedTrace();
        app(AiTraceMetricAggregator::class)->recomputeTrace($trace->id);

        $this
            ->withHeader('X-Atlas-Token', 'testing-atlas-token-with-enough-length')
            ->postJson("/ai/interactions/{$trace->id}/feedback", [
                'feedback_score' => 1,
                'feedback_action' => 'wrong_context',
                'feedback_comment' => 'Perdeu continuidade.',
            ])
            ->assertOk()
            ->assertJsonPath('trace.feedback_action', 'wrong_context');

        $this->assertDatabaseHas('ai_outcome_links', [
            'trace_id' => $trace->id,
            'outcome_type' => 'human_marked_wrong_context',
            'target_type' => 'ai_trace',
            'target_id' => $trace->id,
            'value_score' => 20,
            'source' => 'human_feedback',
        ]);

        $summary = AiTraceMetricSummary::query()->where('trace_id', $trace->id)->firstOrFail();
        $this->assertSame(20, $summary->human_feedback_score);
        $this->assertLessThan(80, $summary->final_quality_score);
    }

    public function test_cost_rate_api_and_cli_upsert_rates_without_hardcoded_prices(): void
    {
        $this
            ->withHeader('X-Atlas-Token', 'testing-atlas-token-with-enough-length')
            ->postJson('/ai/telemetry/cost-rates', [
                'provider' => 'claude_cli',
                'model' => 'api-test-model',
                'input_microusd_per_1k' => 1234,
                'output_microusd_per_1k' => 5678,
                'effective_from' => now()->subMinute()->toJSON(),
            ])
            ->assertCreated()
            ->assertJsonPath('rate.provider', 'claude_cli')
            ->assertJsonPath('rate.model', 'api-test-model')
            ->assertJsonPath('rate.input_microusd_per_1k', 1234)
            ->assertJsonPath('rate.currency', 'USD');

        $this->artisan('atlas:ai:telemetry:cost-rates', [
            '--provider' => 'codex_cli',
            '--model' => 'cli-test-model',
            '--input-microusd' => 2000,
            '--output-microusd' => 3000,
            '--json' => true,
        ])->assertExitCode(0);

        $this->assertDatabaseHas('ai_provider_cost_rates', [
            'provider' => 'codex_cli',
            'model' => 'cli-test-model',
            'input_microusd_per_1k' => 2000,
            'output_microusd_per_1k' => 3000,
        ]);
    }

    public function test_cost_rate_upsert_rejects_negative_input_and_output_rates(): void
    {
        $rates = app(AiProviderCostRateService::class);

        foreach (['input_microusd_per_1k', 'output_microusd_per_1k'] as $field) {
            try {
                $rates->upsert([
                    'provider' => 'claude_cli',
                    'model' => 'invalid-negative-'.$field,
                    'input_microusd_per_1k' => $field === 'input_microusd_per_1k' ? -1 : 0,
                    'output_microusd_per_1k' => $field === 'output_microusd_per_1k' ? -1 : 0,
                ]);
            } catch (\InvalidArgumentException $exception) {
                $this->assertSame("{$field} must be greater than or equal to 0.", $exception->getMessage());

                continue;
            }

            $this->fail("Expected {$field} validation to reject a negative cost rate.");
        }
    }

    public function test_cost_rate_upsert_rejects_non_integer_and_out_of_range_rates(): void
    {
        $rates = app(AiProviderCostRateService::class);

        foreach ([
            ['value' => '1.5', 'message' => 'input_microusd_per_1k must be an integer.'],
            ['value' => '4294967296', 'message' => 'input_microusd_per_1k must be less than or equal to 4294967295.'],
        ] as $case) {
            try {
                $rates->upsert([
                    'provider' => 'claude_cli',
                    'model' => 'invalid-integer-'.$case['value'],
                    'input_microusd_per_1k' => $case['value'],
                    'output_microusd_per_1k' => 0,
                ]);
            } catch (\InvalidArgumentException $exception) {
                $this->assertSame($case['message'], $exception->getMessage());

                continue;
            }

            $this->fail('Expected integer cost rate validation to reject '.$case['value'].'.');
        }
    }

    public function test_cost_rate_upsert_rejects_empty_provider_and_model(): void
    {
        $rates = app(AiProviderCostRateService::class);

        foreach (['provider' => '', 'model' => ''] as $field => $value) {
            try {
                $rates->upsert([
                    'provider' => $field === 'provider' ? $value : 'claude_cli',
                    'model' => $field === 'model' ? $value : 'test-model',
                    'input_microusd_per_1k' => 0,
                    'output_microusd_per_1k' => 0,
                ]);
            } catch (\InvalidArgumentException $exception) {
                $this->assertSame("{$field} is required.", $exception->getMessage());

                continue;
            }

            $this->fail("Expected {$field} validation to reject an empty value.");
        }
    }

    public function test_cost_rate_upsert_rejects_provider_and_model_that_exceed_storage_limits(): void
    {
        $rates = app(AiProviderCostRateService::class);

        foreach ([
            ['field' => 'provider', 'value' => str_repeat('p', 81), 'message' => 'provider must be 80 characters or fewer.'],
            ['field' => 'model', 'value' => str_repeat('m', 121), 'message' => 'model must be 120 characters or fewer.'],
        ] as $case) {
            try {
                $rates->upsert([
                    'provider' => $case['field'] === 'provider' ? $case['value'] : 'claude_cli',
                    'model' => $case['field'] === 'model' ? $case['value'] : 'test-model',
                    'input_microusd_per_1k' => 0,
                    'output_microusd_per_1k' => 0,
                ]);
            } catch (\InvalidArgumentException $exception) {
                $this->assertSame($case['message'], $exception->getMessage());

                continue;
            }

            $this->fail("Expected {$case['field']} validation to reject an oversized value.");
        }
    }

    public function test_cost_rate_upsert_defaults_empty_currency_but_rejects_invalid_currency(): void
    {
        $rates = app(AiProviderCostRateService::class);

        $rate = $rates->upsert([
            'provider' => 'claude_cli',
            'model' => 'empty-currency-model',
            'input_microusd_per_1k' => 0,
            'output_microusd_per_1k' => 0,
            'currency' => '',
        ]);

        $this->assertSame('USD', $rate->currency);

        foreach ([false, 'US', 'US-DOLLAR'] as $currency) {
            try {
                $rates->upsert([
                    'provider' => 'claude_cli',
                    'model' => 'invalid-currency-'.(is_string($currency) ? $currency : 'bool'),
                    'input_microusd_per_1k' => 0,
                    'output_microusd_per_1k' => 0,
                    'currency' => $currency,
                ]);
            } catch (\InvalidArgumentException $exception) {
                $this->assertSame('currency must be a 3 to 8 character code.', $exception->getMessage());

                continue;
            }

            $this->fail('Expected currency validation to reject invalid currency input.');
        }
    }

    public function test_cost_rate_upsert_rejects_effective_until_before_effective_from(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('effective_until must not be before effective_from.');

        app(AiProviderCostRateService::class)->upsert([
            'provider' => 'claude_cli',
            'model' => 'invalid-window-model',
            'input_microusd_per_1k' => 0,
            'output_microusd_per_1k' => 0,
            'effective_from' => '2026-05-06T12:00:00Z',
            'effective_until' => '2026-05-06T11:59:59Z',
        ]);
    }

    public function test_cost_rate_upsert_rejects_invalid_effective_datetimes_with_field_names(): void
    {
        $rates = app(AiProviderCostRateService::class);

        foreach ([
            ['field' => 'effective_from', 'value' => 'not-a-date'],
            ['field' => 'effective_until', 'value' => 'not-a-date'],
        ] as $case) {
            try {
                $rates->upsert([
                    'provider' => 'claude_cli',
                    'model' => 'invalid-date-'.$case['field'],
                    'input_microusd_per_1k' => 0,
                    'output_microusd_per_1k' => 0,
                    $case['field'] => $case['value'],
                ]);
            } catch (\InvalidArgumentException $exception) {
                $this->assertSame("{$case['field']} must be a valid datetime.", $exception->getMessage());

                continue;
            }

            $this->fail("Expected {$case['field']} validation to reject an invalid datetime.");
        }
    }

    public function test_cost_rate_command_reports_invalid_input_as_json_and_human_error(): void
    {
        $jsonExitCode = Artisan::call('atlas:ai:telemetry:cost-rates', [
            '--provider' => 'claude_cli',
            '--input-microusd' => 0,
            '--output-microusd' => 0,
            '--json' => true,
        ]);
        $jsonOutput = Artisan::output();

        $this->assertSame(1, $jsonExitCode);
        $this->assertStringContainsString('"ok": false', $jsonOutput);
        $this->assertStringContainsString('model is required.', $jsonOutput);

        $humanExitCode = Artisan::call('atlas:ai:telemetry:cost-rates', [
            '--model' => 'human-invalid-model',
            '--input-microusd' => 0,
            '--output-microusd' => 0,
        ]);
        $humanOutput = Artisan::output();

        $this->assertSame(1, $humanExitCode);
        $this->assertStringContainsString('Invalid cost rate input: provider is required.', $humanOutput);

        $dateExitCode = Artisan::call('atlas:ai:telemetry:cost-rates', [
            '--provider' => 'claude_cli',
            '--model' => 'invalid-date-model',
            '--input-microusd' => 0,
            '--output-microusd' => 0,
            '--effective-from' => 'not-a-date',
            '--json' => true,
        ]);
        $dateOutput = Artisan::output();

        $this->assertSame(1, $dateExitCode);
        $this->assertStringContainsString('effective_from must be a valid datetime.', $dateOutput);
    }

    public function test_cost_rate_import_reports_indexed_validation_errors(): void
    {
        $importPath = storage_path('framework/testing/atlas-ai-cost-rates-invalid-'.Str::uuid().'.json');
        File::put($importPath, json_encode([
            'rates' => [
                [
                    'provider' => 'claude_cli',
                    'model' => 'valid-import-model',
                    'input_microusd_per_1k' => 0,
                    'output_microusd_per_1k' => 0,
                    'currency' => 'EUR',
                    'effective_from' => now()->subMinute()->toJSON(),
                ],
                null,
                [
                    'provider' => 'claude_cli',
                    'model' => 'invalid-negative-import-model',
                    'input_microusd_per_1k' => -1,
                    'output_microusd_per_1k' => 0,
                    'effective_from' => now()->subMinute()->toJSON(),
                ],
                [
                    'provider' => 'claude_cli',
                    'model' => 'invalid-float-import-model',
                    'input_microusd_per_1k' => 1.5,
                    'output_microusd_per_1k' => 0,
                    'effective_from' => now()->subMinute()->toJSON(),
                ],
            ],
        ]));

        $exitCode = Artisan::call('atlas:ai:telemetry:cost-rates', [
            '--import' => $importPath,
            '--json' => true,
        ]);
        $payload = json_decode(Artisan::output(), true);

        $this->assertSame(1, $exitCode);
        $this->assertFalse($payload['ok']);
        $this->assertSame(1, $payload['imported']['upserted']);
        $this->assertSame(1, $payload['imported']['errors'][0]['index']);
        $this->assertSame('Rate row must be an object.', $payload['imported']['errors'][0]['message']);
        $this->assertSame(2, $payload['imported']['errors'][1]['index']);
        $this->assertSame('input_microusd_per_1k must be greater than or equal to 0.', $payload['imported']['errors'][1]['message']);
        $this->assertSame(3, $payload['imported']['errors'][2]['index']);
        $this->assertSame('input_microusd_per_1k must be an integer.', $payload['imported']['errors'][2]['message']);

        $this->assertDatabaseHas('ai_provider_cost_rates', [
            'provider' => 'claude_cli',
            'model' => 'valid-import-model',
            'currency' => 'EUR',
        ]);
        $this->assertDatabaseMissing('ai_provider_cost_rates', [
            'provider' => 'claude_cli',
            'model' => 'invalid-negative-import-model',
        ]);
        $this->assertDatabaseMissing('ai_provider_cost_rates', [
            'provider' => 'claude_cli',
            'model' => 'invalid-float-import-model',
        ]);
    }

    public function test_cost_rate_sync_config_reports_indexed_validation_errors(): void
    {
        config()->set('atlas.ai_metrics.cost_rates', [
            [
                'provider' => 'claude_cli',
                'model' => 'valid-config-model',
                'input_microusd_per_1k' => 0,
                'output_microusd_per_1k' => 0,
                'effective_from' => now()->subMinute()->toJSON(),
            ],
            null,
            [
                'provider' => 'claude_cli',
                'model' => 'invalid-config-model',
                'input_microusd_per_1k' => 0,
                'output_microusd_per_1k' => 1.5,
                'effective_from' => now()->subMinute()->toJSON(),
            ],
        ]);

        $exitCode = Artisan::call('atlas:ai:telemetry:cost-rates', [
            '--sync-config' => true,
            '--json' => true,
        ]);
        $payload = json_decode(Artisan::output(), true);

        $this->assertSame(1, $exitCode);
        $this->assertFalse($payload['ok']);
        $this->assertSame(
            'Configured cost rates contain invalid rows: row 1: Rate row must be an object.; row 2: output_microusd_per_1k must be an integer.',
            $payload['error']['message'],
        );

        $this->assertDatabaseHas('ai_provider_cost_rates', [
            'provider' => 'claude_cli',
            'model' => 'valid-config-model',
        ]);
        $this->assertDatabaseMissing('ai_provider_cost_rates', [
            'provider' => 'claude_cli',
            'model' => 'invalid-config-model',
        ]);
    }

    public function test_missing_cost_rates_can_be_detected_exported_and_imported(): void
    {
        $trace = $this->seedCompletedTrace();
        AiProviderCostRate::query()->delete();
        app(AiTraceMetricAggregator::class)->recomputeTrace($trace->id);

        $this
            ->withHeader('X-Atlas-Token', 'testing-atlas-token-with-enough-length')
            ->getJson('/ai/telemetry/cost-rates/missing?hours=24')
            ->assertOk()
            ->assertJsonPath('available', true)
            ->assertJsonPath('missing_rates.0.provider', 'claude_cli')
            ->assertJsonPath('missing_rates.0.model', 'test-model')
            ->assertJsonPath('missing_rates.0.traces', 1);

        $templatePath = storage_path('framework/testing/atlas-ai-cost-rates-'.Str::uuid().'.template.json');
        $this->artisan('atlas:ai:telemetry:cost-rates', [
            '--missing' => true,
            '--hours' => 24,
            '--write-template' => $templatePath,
            '--json' => true,
        ])->assertExitCode(0);

        $template = json_decode(File::get($templatePath), true);
        $this->assertSame('claude_cli', data_get($template, 'rates.0.provider'));
        $this->assertSame('<fill_current_input_microusd_per_1k>', data_get($template, 'rates.0.input_microusd_per_1k'));

        $importPath = storage_path('framework/testing/atlas-ai-cost-rates-'.Str::uuid().'.json');
        File::put($importPath, json_encode([
            'rates' => [[
                'provider' => 'claude_cli',
                'model' => 'test-model',
                'input_microusd_per_1k' => 111,
                'output_microusd_per_1k' => 222,
                'effective_from' => now()->subMinute()->toJSON(),
            ]],
        ]));

        $this->artisan('atlas:ai:telemetry:cost-rates', [
            '--import' => $importPath,
            '--json' => true,
        ])->assertExitCode(0);

        $this->assertDatabaseHas('ai_provider_cost_rates', [
            'provider' => 'claude_cli',
            'model' => 'test-model',
            'input_microusd_per_1k' => 111,
            'output_microusd_per_1k' => 222,
        ]);

        $this
            ->withHeader('X-Atlas-Token', 'testing-atlas-token-with-enough-length')
            ->postJson('/ai/telemetry/cost-rates/import', [
                'rates' => [[
                    'provider' => 'codex_cli',
                    'model' => 'api-import-model',
                    'input_microusd_per_1k' => 333,
                    'output_microusd_per_1k' => 444,
                    'effective_from' => now()->subMinute()->toJSON(),
                ]],
            ])
            ->assertCreated()
            ->assertJsonPath('upserted.0.provider', 'codex_cli')
            ->assertJsonPath('errors', []);
    }

    public function test_telemetry_list_apis_use_canonical_limit_contracts(): void
    {
        $headers = ['X-Atlas-Token' => 'testing-atlas-token-with-enough-length'];

        $this
            ->withHeaders($headers)
            ->getJson('/ai/telemetry/summaries?limit='.(AiTelemetryWindowInput::MAX_SUMMARY_LIMIT + 1))
            ->assertUnprocessable();

        $this
            ->withHeaders($headers)
            ->getJson('/ai/telemetry/cost-rates?limit='.(AiTelemetryWindowInput::MAX_COST_RATE_LIMIT + 1))
            ->assertUnprocessable();

        $this
            ->withHeaders($headers)
            ->getJson('/ai/telemetry/cost-rates/missing?limit='.(AiTelemetryWindowInput::MAX_COST_RATE_LIMIT + 1))
            ->assertUnprocessable();

        $this
            ->withHeaders($headers)
            ->getJson('/ai/telemetry/outcomes?limit='.(AiTelemetryWindowInput::MAX_OUTCOME_LIMIT + 1))
            ->assertUnprocessable();
    }

    public function test_rollup_resolves_provider_default_model_identity_for_costing(): void
    {
        config()->set('atlas.ai.providers.claude_cli.model', null);
        config()->set('atlas.ai.providers.claude_cli.model_identity', 'claude_cli_default');

        $trace = $this->seedCompletedTrace();
        $trace->update(['model' => null]);
        AiJob::query()->where('trace_id', $trace->id)->update(['model' => null]);
        AiProviderCostRate::query()->delete();
        AiProviderCostRate::query()->create([
            'provider' => 'claude_cli',
            'model' => 'claude_cli_default',
            'input_microusd_per_1k' => 1000,
            'output_microusd_per_1k' => 2000,
            'metadata' => [],
        ]);

        $summary = app(AiTraceMetricAggregator::class)->recomputeTrace($trace->id);

        $this->assertSame('claude_cli_default', $summary->model);
        $this->assertSame('estimated', $summary->cost_confidence);
        $this->assertSame('operational_estimate', $summary->cost_mode);
        $this->assertGreaterThan(0, $summary->cost_microusd);
    }

    public function test_cli_estimated_cost_rate_clears_unknown_cost_health_issue(): void
    {
        config()->set('atlas.ai_metrics.health_min_traces', 1);
        $trace = $this->seedCompletedTrace();
        $trace->update(['model' => null]);
        AiJob::query()->where('trace_id', $trace->id)->update(['model' => null]);
        $resolvedModel = app(AiProviderModelResolver::class)->resolve('claude_cli', null);
        AiProviderCostRate::query()->delete();
        AiProviderCostRate::query()->create([
            'provider' => 'claude_cli',
            'model' => $resolvedModel,
            'input_microusd_per_1k' => 1000,
            'output_microusd_per_1k' => 2000,
            'metadata' => ['source' => 'test_estimate'],
        ]);

        $this
            ->withHeader('X-Atlas-Token', 'testing-atlas-token-with-enough-length')
            ->getJson('/ai/telemetry/health?hours=24&recompute=1')
            ->assertOk()
            ->assertJsonPath('health.status', 'healthy')
            ->assertJsonPath('health.scorecard.totals.unknown_cost_count', 0)
            ->assertJsonPath('health.scorecard.totals.estimated_cost_count', 1)
            ->assertJsonPath('health.scorecard.totals.operational_estimate_cost_count', 1);
    }

    public function test_ledger_projection_trace_without_provider_is_not_actionable_missing_cost_rate(): void
    {
        $trace = $this->seedCompletedTrace();
        $trace->update([
            'provider' => null,
            'model' => null,
            'metadata' => [
                'schema_version' => 'atlas.ledger_projection.metadata.v1',
                'projection_id' => 'ai_traces',
                'ledger_event_type' => 'OPERATION_COMPLETED',
                'ledger_event_id' => (string) Str::uuid(),
                'emitter_stage' => 'atlas.self_improvement',
            ],
        ]);
        AiJob::query()->where('trace_id', $trace->id)->delete();
        AiProviderCostRate::query()->delete();

        $summary = app(AiTraceMetricAggregator::class)->recomputeTrace($trace->id);

        $this->assertNull($summary->provider);
        $this->assertNull($summary->model);
        $this->assertNull($summary->cost_microusd);
        $this->assertSame('estimated', $summary->cost_confidence);
        $this->assertSame('provider_not_applicable', $summary->cost_source);
        $this->assertSame('not_applicable', $summary->cost_mode);

        $summary->update([
            'cost_confidence' => 'unknown',
            'cost_source' => 'missing_cost_rate',
            'cost_mode' => 'unknown',
        ]);

        $missing = app(AiProviderCostRateService::class)->missingRates(now()->subDay());

        $this->assertSame([], $missing);
    }

    public function test_regular_trace_without_provider_stays_actionable_missing_cost_rate(): void
    {
        $trace = $this->seedCompletedTrace();
        $trace->update([
            'provider' => null,
            'model' => null,
            'metadata' => ['source' => 'test_no_provider_identity'],
        ]);
        AiJob::query()->where('trace_id', $trace->id)->delete();
        AiProviderCostRate::query()->delete();

        $summary = app(AiTraceMetricAggregator::class)->recomputeTrace($trace->id);

        $this->assertNull($summary->provider);
        $this->assertNull($summary->model);
        $this->assertNull($summary->cost_microusd);
        $this->assertSame('unknown', $summary->cost_confidence);
        $this->assertSame('missing_cost_rate', $summary->cost_source);
        $this->assertSame('unknown', $summary->cost_mode);
    }

    public function test_scorecard_endpoint_can_recompute_and_return_provider_buckets(): void
    {
        $this->seedCompletedTrace();

        $this
            ->withHeader('X-Atlas-Token', 'testing-atlas-token-with-enough-length')
            ->getJson('/ai/telemetry/scorecard?hours=24&recompute=1')
            ->assertOk()
            ->assertJsonPath('recomputed', 1)
            ->assertJsonPath('scorecard.available', true)
            ->assertJsonPath('scorecard.totals.traces', 1)
            ->assertJsonPath('health.available', true)
            ->assertJsonPath('scorecard.by_provider.0.bucket', 'claude_cli');
    }

    public function test_health_gate_reports_actionable_warning_from_scorecard(): void
    {
        config()->set('atlas.ai_metrics.health_min_traces', 1);
        config()->set('atlas.ai_metrics.health_quality_warning_below', 95);
        config()->set('atlas.ai_metrics.health_quality_critical_below', 40);
        $this->seedCompletedTrace();

        $this
            ->withHeader('X-Atlas-Token', 'testing-atlas-token-with-enough-length')
            ->getJson('/ai/telemetry/health?hours=24&recompute=1')
            ->assertOk()
            ->assertJsonPath('health.available', true)
            ->assertJsonPath('health.status', 'warning')
            ->assertJsonPath('health.issues.0.key', 'final_quality_avg')
            ->assertJsonPath('insight.reason', 'dry_run_would_emit');

        $this->artisan('atlas:ai:telemetry:health', [
            '--hours' => 24,
            '--json' => true,
        ])->assertExitCode(0);
    }

    private function seedCompletedTrace(): AiTrace
    {
        $clientId = (string) Str::uuid();
        $correlationId = (string) Str::uuid();
        $threadId = (string) Str::uuid();
        $sessionId = (string) Str::uuid();
        $now = now();

        DB::table('ai_threads')->insert([
            'id' => $threadId,
            'title' => 'Atlas AI metrics test',
            'summary' => 'Thread usada por testes de metricas.',
            'status' => 'active',
            'surface' => 'atlas_ai_sheet',
            'workspace' => null,
            'source_type' => 'test',
            'source_id' => null,
            'last_trace_id' => null,
            'last_provider' => 'claude_cli',
            'message_count' => 1,
            'last_message_at' => $now,
            'metadata' => json_encode([]),
            'created_at' => $now,
            'updated_at' => $now,
        ]);

        DB::table('ai_sessions')->insert([
            'id' => $sessionId,
            'thread_id' => $threadId,
            'status' => 'active',
            'purpose' => 'test',
            'provider_primary' => 'claude_cli',
            'provider_last' => 'claude_cli',
            'started_at' => $now,
            'ended_at' => null,
            'message_count' => 1,
            'token_estimate' => 1200,
            'metadata' => json_encode([]),
            'created_at' => $now,
            'updated_at' => $now,
        ]);

        $trace = AiTrace::query()->create([
            'trace_key' => 'trace_'.Str::uuid(),
            'thread_id' => $threadId,
            'session_id' => $sessionId,
            'source_type' => 'app',
            'status' => 'succeeded',
            'operator_input' => 'Explique o status do Atlas AI.',
            'intent' => 'direct',
            'agent_slug' => 'orquestrador',
            'provider' => 'claude_cli',
            'model' => 'test-model',
            'skill_versions' => [],
            'context_refs' => [['type' => 'thread'], ['type' => 'state']],
            'response_text' => 'Atlas AI esta operacional.',
            'latency_ms' => 4000,
            'feedback_score' => 5,
            'completed_at' => $now,
            'metadata' => [
                'app_surface' => 'atlas_ai_sheet',
                'atlas_workflow_mode' => 'direct',
                'client_id' => $clientId,
            ],
        ]);

        $job = AiJob::query()->create([
            'trace_id' => $trace->id,
            'client_id' => $clientId,
            'kind' => 'interaction',
            'status' => 'succeeded',
            'priority' => 10,
            'agent_slug' => 'orquestrador',
            'provider' => 'claude_cli',
            'model' => 'test-model',
            'input_text' => 'Explique o status do Atlas AI.',
            'prompt' => str_repeat('prompt ', 100),
            'context_refs' => [],
            'payload' => ['app_surface' => 'atlas_ai_sheet'],
            'result_text' => 'Atlas AI esta operacional.',
            'result_json' => [
                'usage' => [
                    'prompt_tokens' => 1000,
                    'completion_tokens' => 200,
                    'total_tokens' => 1200,
                ],
            ],
            'available_at' => $now->copy()->subSeconds(8),
            'reserved_at' => $now->copy()->subSeconds(6),
            'started_at' => $now->copy()->subSeconds(5),
            'finished_at' => $now->copy()->subSecond(),
            'attempts' => 1,
            'max_attempts' => 2,
            'timeout_seconds' => 300,
            'metadata' => [],
        ]);

        AiProviderCostRate::query()->create([
            'provider' => 'claude_cli',
            'model' => 'test-model',
            'input_microusd_per_1k' => 1000,
            'output_microusd_per_1k' => 2000,
            'metadata' => [],
        ]);

        AiQualityEvaluation::query()->create([
            'trace_id' => $trace->id,
            'thread_id' => $threadId,
            'session_id' => $sessionId,
            'provider' => 'claude_cli',
            'model' => 'test-model',
            'agent_slug' => 'orquestrador',
            'evaluator_version' => 'test',
            'score' => 88,
            'status' => 'passed',
            'dimensions' => [],
            'flags' => [],
            'suggested_actions' => [],
            'metadata' => [],
        ]);

        AiContextSnapshot::query()->create([
            'trace_id' => $trace->id,
            'thread_id' => $threadId,
            'session_id' => $sessionId,
            'provider' => 'claude_cli',
            'model' => 'test-model',
            'context_pack' => [],
            'messages_included' => [],
            'compaction_id' => (string) Str::uuid(),
            'token_estimate' => 1200,
            'metadata' => ['useful_context_refs_count' => 1],
            'created_at' => $now,
        ]);

        foreach ([
            ['message_send_pressed', 0, null],
            ['pending_submission_recovered', 30, null],
            ['interaction_accepted', 180, 180],
            ['trace_visible_in_ui', 350, 350],
            ['app_backgrounded_during_trace', 500, null],
        ] as [$eventName, $offsetMs, $duration]) {
            AiTelemetryEvent::query()->create([
                'event_key' => 'mobile:test:'.$eventName.':'.Str::uuid(),
                'correlation_id' => $correlationId,
                'trace_id' => in_array($eventName, ['interaction_accepted', 'trace_visible_in_ui'], true) ? $trace->id : null,
                'thread_id' => $threadId,
                'session_id' => $sessionId,
                'ai_job_id' => $job->id,
                'client_id' => $clientId,
                'surface' => 'mobile',
                'runtime' => 'ios',
                'provider' => 'claude_cli',
                'model' => 'test-model',
                'agent_slug' => 'orquestrador',
                'event_name' => $eventName,
                'received_at' => $now->copy()->addMilliseconds((int) $offsetMs),
                'duration_ms' => $duration,
                'metadata' => [],
                'privacy' => [],
            ]);
        }

        AiOutcomeLink::query()->create([
            'trace_id' => $trace->id,
            'thread_id' => $threadId,
            'session_id' => $sessionId,
            'outcome_type' => 'conversation_continued',
            'value_score' => 75,
            'confidence' => 0.9,
            'source' => 'test',
            'metadata' => [],
        ]);

        return $trace;
    }

    private function createTables(): void
    {
        $this->tearDownTablesOnly();

        Schema::create('ai_traces', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->string('trace_key')->unique();
            $table->uuid('thread_id')->nullable();
            $table->uuid('session_id')->nullable();
            $table->string('source_type')->default('app');
            $table->uuid('source_id')->nullable();
            $table->string('status')->default('queued');
            $table->text('operator_input');
            $table->string('intent')->nullable();
            $table->string('agent_slug');
            $table->string('provider')->nullable();
            $table->string('model')->nullable();
            $table->json('skill_versions')->default('{}');
            $table->json('context_refs')->default('[]');
            $table->string('prompt_hash')->nullable();
            $table->string('response_hash')->nullable();
            $table->text('response_text')->nullable();
            $table->integer('latency_ms')->nullable();
            $table->smallInteger('feedback_score')->nullable();
            $table->string('feedback_action')->nullable();
            $table->text('feedback_comment')->nullable();
            $table->timestamp('completed_at')->nullable();
            $table->json('metadata')->default('{}');
            $table->timestamps();
        });

        Schema::create('ai_threads', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->string('title')->nullable();
            $table->text('summary')->nullable();
            $table->string('status')->default('active');
            $table->string('surface')->nullable();
            $table->string('workspace')->nullable();
            $table->string('source_type')->nullable();
            $table->uuid('source_id')->nullable();
            $table->uuid('last_trace_id')->nullable();
            $table->string('last_provider')->nullable();
            $table->integer('message_count')->default(0);
            $table->timestamp('last_message_at')->nullable();
            $table->json('metadata')->default('{}');
            $table->timestamps();
        });

        Schema::create('ai_sessions', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->uuid('thread_id')->nullable();
            $table->string('status')->default('active');
            $table->string('purpose')->nullable();
            $table->string('provider_primary')->nullable();
            $table->string('provider_last')->nullable();
            $table->timestamp('started_at')->nullable();
            $table->timestamp('ended_at')->nullable();
            $table->integer('message_count')->default(0);
            $table->integer('token_estimate')->nullable();
            $table->json('metadata')->default('{}');
            $table->timestamps();
        });

        Schema::create('ai_jobs', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->uuid('trace_id')->nullable();
            $table->uuid('client_id')->nullable();
            $table->string('kind')->default('interaction');
            $table->string('status')->default('queued');
            $table->smallInteger('priority')->default(50);
            $table->string('agent_slug');
            $table->string('provider')->nullable();
            $table->string('model')->nullable();
            $table->text('input_text');
            $table->text('prompt');
            $table->json('context_refs')->default('[]');
            $table->json('payload')->default('{}');
            $table->text('result_text')->nullable();
            $table->json('result_json')->nullable();
            $table->string('error_code')->nullable();
            $table->text('error_message')->nullable();
            $table->timestamp('available_at')->nullable();
            $table->timestamp('reserved_at')->nullable();
            $table->timestamp('started_at')->nullable();
            $table->timestamp('finished_at')->nullable();
            $table->integer('attempts')->default(0);
            $table->integer('max_attempts')->default(2);
            $table->integer('timeout_seconds')->default(300);
            $table->string('worker_id')->nullable();
            $table->json('metadata')->default('{}');
            $table->timestamps();
        });

        Schema::create('ai_job_attempts', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->uuid('ai_job_id');
            $table->integer('attempt_number');
            $table->string('worker_id');
            $table->string('provider');
            $table->string('model')->nullable();
            $table->json('command')->default('[]');
            $table->string('command_hash')->nullable();
            $table->string('prompt_hash');
            $table->string('response_hash')->nullable();
            $table->string('status')->default('processing');
            $table->integer('exit_code')->nullable();
            $table->integer('duration_ms')->nullable();
            $table->text('output_text')->nullable();
            $table->text('stdout_excerpt')->nullable();
            $table->text('stderr_excerpt')->nullable();
            $table->string('error_code')->nullable();
            $table->text('error_message')->nullable();
            $table->timestamp('started_at')->nullable();
            $table->timestamp('finished_at')->nullable();
            $table->json('metadata')->default('{}');
            $table->timestamps();
        });

        Schema::create('ai_quality_evaluations', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->uuid('trace_id');
            $table->uuid('thread_id')->nullable();
            $table->uuid('session_id')->nullable();
            $table->string('provider')->nullable();
            $table->string('model')->nullable();
            $table->string('agent_slug')->nullable();
            $table->string('evaluator_version');
            $table->integer('score');
            $table->string('status');
            $table->json('dimensions')->default('{}');
            $table->json('flags')->default('[]');
            $table->json('suggested_actions')->default('[]');
            $table->json('metadata')->default('{}');
            $table->timestamps();
        });

        Schema::create('ai_quality_actions', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->uuid('evaluation_id')->nullable();
            $table->uuid('trace_id')->nullable();
            $table->uuid('remediation_trace_id')->nullable();
            $table->uuid('thread_id')->nullable();
            $table->uuid('session_id')->nullable();
            $table->string('action_type');
            $table->string('status')->default('queued');
            $table->integer('priority')->default(50);
            $table->text('reason');
            $table->json('flags')->default('[]');
            $table->json('payload')->default('{}');
            $table->json('result')->default('{}');
            $table->text('error_message')->nullable();
            $table->string('dedupe_key')->nullable();
            $table->timestamp('completed_at')->nullable();
            $table->timestamps();
        });

        Schema::create('ai_stream_events', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->uuid('trace_id')->nullable();
            $table->uuid('ai_job_id')->nullable();
            $table->uuid('ai_job_attempt_id')->nullable();
            $table->integer('sequence');
            $table->string('event_type');
            $table->string('channel')->nullable();
            $table->text('content')->default('');
            $table->json('metadata')->default('{}');
            $table->timestamp('occurred_at')->useCurrent();
            $table->timestamp('created_at')->useCurrent();
        });

        Schema::create('ai_context_snapshots', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->uuid('trace_id')->nullable();
            $table->uuid('thread_id')->nullable();
            $table->uuid('session_id')->nullable();
            $table->string('provider')->nullable();
            $table->string('model')->nullable();
            $table->string('prompt_hash')->nullable();
            $table->json('context_pack')->default('{}');
            $table->json('messages_included')->default('[]');
            $table->uuid('compaction_id')->nullable();
            $table->uuid('provider_handoff_id')->nullable();
            $table->integer('token_estimate')->nullable();
            $table->json('metadata')->default('{}');
            $table->timestamp('created_at')->useCurrent();
        });

        (require database_path('migrations/2026_05_01_000000_create_ai_telemetry_events_table.php'))->up();
        (require database_path('migrations/2026_05_01_001000_create_ai_metric_summary_tables.php'))->up();
    }

    private function tearDownTablesOnly(): void
    {
        foreach ([
            'ai_tool_events',
            'ai_trace_metric_summaries',
            'ai_outcome_links',
            'ai_provider_cost_rates',
            'ai_context_snapshots',
            'ai_telemetry_events',
            'ai_quality_actions',
            'ai_quality_evaluations',
            'ai_job_attempts',
            'ai_jobs',
            'ai_stream_events',
            'ai_traces',
            'ai_sessions',
            'ai_threads',
        ] as $table) {
            Schema::dropIfExists($table);
        }
    }
}
