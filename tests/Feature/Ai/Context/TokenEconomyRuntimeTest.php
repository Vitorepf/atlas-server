<?php

declare(strict_types=1);

namespace Tests\Feature\Ai\Context;

use App\Services\Ai\Context\AtlasTokenEconomyRuntimeService;
use Illuminate\Support\Facades\Artisan;
use Tests\TestCase;

final class TokenEconomyRuntimeTest extends TestCase
{
    public function test_low_risk_context_reduces_tokens_with_quality_gate_passed(): void
    {
        $payload = app(AtlasTokenEconomyRuntimeService::class)->optimize([
            'provider' => 'gpt',
            'risk_level' => 'low',
        ]);

        $this->assertSame(AtlasTokenEconomyRuntimeService::SCHEMA_VERSION, $payload['schema_version']);
        $this->assertSame('ready', $payload['status']);
        $this->assertSame('passed', data_get($payload, 'quality_check.quality_gate_status'));
        $this->assertSame(1.0, data_get($payload, 'quality_check.must_keep_coverage'));
        $this->assertGreaterThan(0, data_get($payload, 'compression_receipt.savings_estimate'));
        $this->assertLessThan(data_get($payload, 'compression_receipt.input_tokens_before'), data_get($payload, 'compression_receipt.input_tokens_after'));
        $this->assertSame(AtlasTokenEconomyRuntimeService::CONTEXT_DELIVERY_POLICY_SCHEMA, data_get($payload, 'context_delivery_policy.schema_version'));
        $this->assertSame('inactive', data_get($payload, 'context_delivery_policy.status'));
        $this->assertSame('standard_compiled_pack', data_get($payload, 'context_delivery_policy.delivery_mode'));
        $this->assertSame(data_get($payload, 'compression_receipt.input_tokens_after'), data_get($payload, 'context_delivery_policy.initial_context_token_budget'));
        $this->assertSame(0, data_get($payload, 'context_delivery_policy.expansion_token_reserve'));
        $this->assertFalse(data_get($payload, 'claims.providers_invoked'));
    }

    public function test_feedback_impact_creates_staged_minimal_context_delivery_policy(): void
    {
        $payload = app(AtlasTokenEconomyRuntimeService::class)->optimize([
            'provider' => 'gpt',
            'risk_level' => 'low',
            'feedback_impact_report' => [
                'status' => 'active',
                'source' => 'input_feedback_hint',
                'selected_set_changed' => true,
                'rank_position_change_count' => 2,
                'score_delta_total_abs' => 0.4,
                'newly_selected_refs' => [
                    ['source_type' => 'vector_retrieval', 'source_ref_hash' => 'hash-vector'],
                ],
                'dropped_refs' => [
                    ['source_type' => 'evidence_replay', 'source_ref_hash' => 'hash-evidence'],
                ],
                'promoted_refs' => [
                    ['source_type' => 'vector_retrieval', 'source_ref_hash' => 'hash-vector'],
                ],
                'demoted_refs' => [
                    ['source_type' => 'evidence_replay', 'source_ref_hash' => 'hash-evidence'],
                ],
                'coverage_delta' => [
                    'gained_required_sources' => ['vector_retrieval'],
                    'lost_required_sources' => [],
                ],
            ],
        ]);

        $policy = data_get($payload, 'context_delivery_policy');

        $this->assertSame('active', $policy['status']);
        $this->assertSame('staged_minimal_targeted_expansion', $policy['delivery_mode']);
        $this->assertSame('input_feedback_hint', $policy['source']);
        $this->assertContains('vector_retrieval', $policy['initial_source_types']);
        $this->assertContains('evidence_replay', $policy['deferred_source_types']);
        $this->assertContains('required_source_coverage_changed', $policy['expansion_triggers']);
        $this->assertLessThan(data_get($payload, 'compression_receipt.input_tokens_after'), $policy['initial_context_token_budget']);
        $this->assertGreaterThan(0, $policy['expansion_token_reserve']);
        $this->assertSame($policy['delivery_mode'], data_get($payload, 'budget.context_delivery_mode'));
        $this->assertSame($policy['initial_context_token_budget'], data_get($payload, 'budget.initial_context_token_budget'));
        $this->assertFalse(data_get($policy, 'policy.raw_text_exposed'));
        $this->assertFalse(data_get($policy, 'policy.providers_invoked'));
        $this->assertTrue(data_get($policy, 'advisory_only'));
    }

    public function test_lost_required_source_makes_delivery_policy_guarded(): void
    {
        $payload = app(AtlasTokenEconomyRuntimeService::class)->optimize([
            'provider' => 'gpt',
            'risk_level' => 'low',
            'feedback_impact_report' => [
                'status' => 'active',
                'source' => 'latest_flow_feedback',
                'selected_set_changed' => true,
                'rank_position_change_count' => 3,
                'dropped_refs' => [
                    ['source_type' => 'evidence_replay', 'source_ref_hash' => 'hash-evidence'],
                ],
                'demoted_refs' => [
                    ['source_type' => 'evidence_replay', 'source_ref_hash' => 'hash-evidence'],
                ],
                'coverage_delta' => [
                    'gained_required_sources' => [],
                    'lost_required_sources' => ['evidence_replay'],
                ],
            ],
        ]);

        $policy = data_get($payload, 'context_delivery_policy');

        $this->assertSame('guarded_required_source_recheck', $policy['delivery_mode']);
        $this->assertSame('required_source_recheck_before_implementation', $policy['quality_gate_hint']);
        $this->assertContains('evidence_replay', $policy['guarded_required_source_types']);
        $this->assertContains('required_source_recheck_before_implementation', $policy['expansion_triggers']);
        $this->assertGreaterThan(0, $policy['expansion_token_reserve']);
        $this->assertGreaterThan(4, $policy['initial_ref_limit']);
    }

    public function test_must_keep_loss_blocks_token_savings(): void
    {
        $payload = app(AtlasTokenEconomyRuntimeService::class)->optimize([
            'provider' => 'gpt',
            'risk_level' => 'low',
            'must_keep_coverage' => 0.99,
        ]);

        $this->assertSame('blocked', $payload['status']);
        $this->assertSame('blocked', data_get($payload, 'quality_check.quality_gate_status'));
        $this->assertContains('must_keep_coverage_below_one', data_get($payload, 'quality_check.blockers'));
    }

    public function test_high_risk_does_not_select_cheap_local_provider(): void
    {
        $payload = app(AtlasTokenEconomyRuntimeService::class)->optimize([
            'provider' => 'local',
            'risk_level' => 'high',
        ]);

        $this->assertSame('ready', $payload['status']);
        $this->assertSame('gpt', data_get($payload, 'provider_model_selection.selected_provider'));
        $this->assertTrue(data_get($payload, 'provider_model_selection.cheap_provider_blocked_by_risk'));
    }

    public function test_local_prereasoning_can_avoid_provider_for_simple_tasks(): void
    {
        $payload = app(AtlasTokenEconomyRuntimeService::class)->optimize([
            'provider' => 'gpt',
            'risk_level' => 'low',
            'task_type' => 'count',
        ]);

        $this->assertTrue(data_get($payload, 'local_prereasoning.can_resolve_locally'));
        $this->assertTrue(data_get($payload, 'local_prereasoning.provider_call_avoidable'));
        $this->assertSame('none_local_only', data_get($payload, 'provider_model_selection.selected_provider'));
    }

    public function test_hash_is_deterministic(): void
    {
        $service = app(AtlasTokenEconomyRuntimeService::class);

        $first = $service->optimize(['provider' => 'gpt', 'risk_level' => 'medium']);
        $second = $service->optimize(['provider' => 'gpt', 'risk_level' => 'medium']);

        $this->assertSame($first['token_economy_hash'], $second['token_economy_hash']);
        $this->assertSame(data_get($first, 'quality_check.receipt_hash'), data_get($second, 'quality_check.receipt_hash'));
    }

    public function test_command_emits_json(): void
    {
        $exit = Artisan::call('atlas:context:token-economy', [
            '--provider' => 'gpt',
            '--risk' => 'low',
            '--json' => true,
        ]);
        $payload = json_decode(Artisan::output(), true, 512, JSON_THROW_ON_ERROR);

        $this->assertSame(0, $exit);
        $this->assertSame(AtlasTokenEconomyRuntimeService::SCHEMA_VERSION, $payload['schema_version']);
        $this->assertSame('ready', $payload['status']);
    }

    public function test_command_accepts_json_input_payload(): void
    {
        $exit = Artisan::call('atlas:context:token-economy', [
            '--input' => json_encode([
                'provider' => 'gpt',
                'risk_level' => 'low',
                'task_type' => 'count',
            ], JSON_THROW_ON_ERROR),
            '--json' => true,
        ]);
        $payload = json_decode(Artisan::output(), true, 512, JSON_THROW_ON_ERROR);

        $this->assertSame(0, $exit);
        $this->assertSame(AtlasTokenEconomyRuntimeService::SCHEMA_VERSION, $payload['schema_version']);
        $this->assertSame('ready', $payload['status']);
        $this->assertSame('none_local_only', data_get($payload, 'provider_model_selection.selected_provider'));
        $this->assertSame('inactive', data_get($payload, 'context_delivery_policy.status'));
    }

    public function test_command_json_input_accepts_feedback_impact_for_context_delivery_policy(): void
    {
        $exit = Artisan::call('atlas:context:token-economy', [
            '--input' => json_encode([
                'provider' => 'gpt',
                'risk_level' => 'low',
                'feedback_impact_report' => [
                    'status' => 'active',
                    'source' => 'input_feedback_hint',
                    'selected_set_changed' => false,
                    'rank_position_change_count' => 1,
                    'promoted_refs' => [
                        ['source_type' => 'canonical_doc', 'source_ref_hash' => 'hash-doc'],
                    ],
                    'coverage_delta' => [
                        'gained_required_sources' => [],
                        'lost_required_sources' => [],
                    ],
                ],
            ], JSON_THROW_ON_ERROR),
            '--json' => true,
        ]);
        $payload = json_decode(Artisan::output(), true, 512, JSON_THROW_ON_ERROR);

        $this->assertSame(0, $exit);
        $this->assertSame('active', data_get($payload, 'context_delivery_policy.status'));
        $this->assertSame('compact_rank_adjusted', data_get($payload, 'context_delivery_policy.delivery_mode'));
        $this->assertContains('canonical_doc', data_get($payload, 'context_delivery_policy.initial_source_types'));
    }
}
