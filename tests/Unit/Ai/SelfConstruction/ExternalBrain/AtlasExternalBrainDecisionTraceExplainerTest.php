<?php

declare(strict_types=1);

namespace Tests\Unit\Ai\SelfConstruction\ExternalBrain;

use App\Services\Ai\SelfConstruction\ExternalBrain\AtlasExternalBrainDecisionTraceExplainer;
use PHPUnit\Framework\TestCase;

final class AtlasExternalBrainDecisionTraceExplainerTest extends TestCase
{
    private AtlasExternalBrainDecisionTraceExplainer $explainer;

    protected function setUp(): void
    {
        $this->explainer = new AtlasExternalBrainDecisionTraceExplainer;
    }

    private function task(string $id, array $extra = []): array
    {
        return array_merge(['task_packet_id' => $id, 'objective' => 'Implement '.$id], $extra);
    }

    private function rejected(string $id, string $reason): array
    {
        return ['task_packet_id' => $id, 'reason' => $reason];
    }

    // ── AC1: required output fields ──────────────────────────────────────────

    public function test_result_has_all_required_fields(): void
    {
        $result = $this->explainer->explain([
            'selected_tasks'        => [$this->task('t1')],
            'rejected_alternatives' => [$this->rejected('t2', 'outscored_by_winner')],
        ]);

        foreach ([
            'schema', 'trace_id',
            'selected_tasks',
            'top_signals', 'chosen_reasons', 'rejected_reasons',
            'uncertainty', 'uncertainty_level',
            'evidence_to_reconsider', 'provider_safe',
        ] as $key) {
            $this->assertArrayHasKey($key, $result, "Missing key: {$key}");
        }
        $this->assertSame(AtlasExternalBrainDecisionTraceExplainer::SCHEMA, $result['schema']);
    }

    public function test_trace_id_starts_with_trace_prefix(): void
    {
        $result = $this->explainer->explain(['selected_tasks' => [$this->task('t1')]]);
        $this->assertStringStartsWith('trace_', $result['trace_id']);
    }

    public function test_provider_safe_is_always_true(): void
    {
        $result = $this->explainer->explain([]);
        $this->assertTrue($result['provider_safe']);
    }

    public function test_rejected_reasons_contains_task_packet_id_and_reason(): void
    {
        $result = $this->explainer->explain([
            'selected_tasks'        => [$this->task('winner')],
            'rejected_alternatives' => [$this->rejected('loser', 'proxy_heavy')],
        ]);

        $this->assertCount(1, $result['rejected_reasons']);
        $entry = $result['rejected_reasons'][0];
        $this->assertSame('loser', $entry['task_packet_id']);
        $this->assertSame('proxy_heavy', $entry['reason']);
    }

    // ── AC2: provider-private fields are stripped ────────────────────────────

    public function test_private_keys_are_stripped_from_trace(): void
    {
        $result = $this->explainer->explain([
            'selected_tasks' => [$this->task('t1')],
            'scoring_facts'  => [
                'top_dimension'   => 'leverage',
                'raw_prompt'      => 'SECRET system prompt',
                'model_response'  => 'SECRET provider output',
                'api_key'         => 'sk-secret-key-123',
                'evidence_strength' => 0.8,
            ],
        ]);

        // Encode result and verify no private strings leak through.
        $encoded = (string) json_encode($result);
        $this->assertStringNotContainsString('SECRET', $encoded);
        $this->assertStringNotContainsString('sk-secret-key-123', $encoded);
        $this->assertStringNotContainsString('raw_prompt', $encoded);
        $this->assertStringNotContainsString('model_response', $encoded);
    }

    public function test_provider_safe_facts_are_included_in_top_signals(): void
    {
        $result = $this->explainer->explain([
            'selected_tasks' => [$this->task('t1')],
            'scoring_facts'  => ['top_dimension' => 'leverage', 'algorithm' => 'arena_v1'],
            'queue_context'  => ['queue_depth' => 12],
        ]);

        $signals = implode(' ', $result['top_signals']);
        $this->assertStringContainsString('leverage', $signals);
        $this->assertStringContainsString('arena_v1', $signals);
        $this->assertStringContainsString('12', $signals);
    }

    // ── Uncertainty ──────────────────────────────────────────────────────────

    public function test_uncertainty_low_when_strong_evidence_and_few_alternatives(): void
    {
        $result = $this->explainer->explain([
            'selected_tasks'        => [$this->task('t1')],
            'rejected_alternatives' => [$this->rejected('t2', 'outscored')],
            'scoring_facts'         => ['evidence_strength' => 0.90],
        ]);

        $this->assertSame('low', $result['uncertainty']);
    }

    public function test_uncertainty_high_when_no_selected_tasks(): void
    {
        $result = $this->explainer->explain([
            'selected_tasks'        => [],
            'rejected_alternatives' => array_map(fn ($i) => $this->rejected("t{$i}", 'proxy_heavy'), range(1, 6)),
        ]);

        $this->assertSame('high', $result['uncertainty']);
    }

    public function test_uncertainty_high_when_evidence_strength_weak(): void
    {
        $result = $this->explainer->explain([
            'selected_tasks' => [$this->task('t1')],
            'scoring_facts'  => ['evidence_strength' => 0.30],
        ]);

        $this->assertSame('high', $result['uncertainty']);
    }

    // ── evidence_to_reconsider ───────────────────────────────────────────────

    public function test_evidence_to_reconsider_mentions_proxy_alternative(): void
    {
        $result = $this->explainer->explain([
            'selected_tasks'        => [$this->task('winner')],
            'rejected_alternatives' => [$this->rejected('proxy-task', 'proxy_heavy')],
        ]);

        $evidenceStr = implode(' ', $result['evidence_to_reconsider']);
        $this->assertStringContainsString('proxy-task', $evidenceStr);
    }

    public function test_evidence_to_reconsider_mentions_missing_evidence_path(): void
    {
        $result = $this->explainer->explain([
            'selected_tasks'        => [$this->task('winner')],
            'rejected_alternatives' => [$this->rejected('blind-task', 'no_runnable_evidence_path')],
        ]);

        $evidenceStr = implode(' ', $result['evidence_to_reconsider']);
        $this->assertStringContainsString('blind-task', $evidenceStr);
    }

    // ── AC1: selected_tasks in output ────────────────────────────────────────

    public function test_selected_tasks_is_list_of_safe_task_objects(): void
    {
        $result = $this->explainer->explain([
            'selected_tasks' => [
                $this->task('t1', ['arena_score' => 0.9]),
                $this->task('t2', ['arena_score' => 0.7]),
            ],
        ]);

        $this->assertIsArray($result['selected_tasks']);
        $this->assertCount(2, $result['selected_tasks']);
        $this->assertSame('t1', $result['selected_tasks'][0]['task_packet_id']);
        $this->assertSame('t2', $result['selected_tasks'][1]['task_packet_id']);
    }

    public function test_selected_tasks_strips_private_fields(): void
    {
        $result = $this->explainer->explain([
            'selected_tasks' => [
                $this->task('t1', ['raw_prompt' => 'SECRET', 'api_key' => 'sk-abc']),
            ],
        ]);

        $encoded = (string) json_encode($result['selected_tasks']);
        $this->assertStringNotContainsString('SECRET', $encoded);
        $this->assertStringNotContainsString('sk-abc', $encoded);
        $this->assertStringContainsString('t1', $encoded);
    }

    public function test_selected_tasks_is_empty_when_no_tasks_selected(): void
    {
        $result = $this->explainer->explain(['selected_tasks' => []]);

        $this->assertSame([], $result['selected_tasks']);
    }

    // ── AC1: uncertainty_level ────────────────────────────────────────────────

    public function test_uncertainty_level_matches_uncertainty(): void
    {
        $result = $this->explainer->explain([
            'selected_tasks'        => [$this->task('t1')],
            'rejected_alternatives' => [$this->rejected('t2', 'outscored')],
            'scoring_facts'         => ['evidence_strength' => 0.90],
        ]);

        $this->assertSame($result['uncertainty'], $result['uncertainty_level']);
    }

    public function test_uncertainty_level_high_when_weak_evidence(): void
    {
        $result = $this->explainer->explain([
            'selected_tasks' => [$this->task('t1')],
            'scoring_facts'  => ['evidence_strength' => 0.20],
        ]);

        $this->assertSame('high', $result['uncertainty_level']);
    }

    // ── Determinism ──────────────────────────────────────────────────────────

    public function test_trace_id_is_stable_for_same_input(): void
    {
        $input = [
            'selected_tasks'        => [$this->task('t1')],
            'rejected_alternatives' => [$this->rejected('t2', 'outscored')],
        ];

        $this->assertSame(
            $this->explainer->explain($input)['trace_id'],
            $this->explainer->explain($input)['trace_id'],
        );
    }

    // ── AC2: enqueue traces include evidence, leverage reason, rejected alternatives ──

    public function test_enqueue_trace_includes_evidence_leverage_reason_and_rejected_alternatives(): void
    {
        $result = $this->explainer->explain([
            'decision_type'         => 'enqueue',
            'selected_tasks'        => [$this->task('winner', ['arena_score' => 0.9, 'top_dimension' => 'leverage'])],
            'rejected_alternatives' => [$this->rejected('loser', 'outscored_by_winner')],
            'scoring_facts'         => ['top_dimension' => 'leverage', 'evidence_strength' => 0.85],
        ]);

        $this->assertSame('enqueue', $result['decision_type']);
        $this->assertStringContainsString('leverage', implode(' ', $result['top_signals']));
        $this->assertStringContainsString('winner', $result['chosen_reasons'][0]);
        $this->assertStringContainsString('top_dim:leverage', $result['chosen_reasons'][0]);
        $this->assertSame('loser', $result['rejected_reasons'][0]['task_packet_id']);
    }

    // ── AC3: hold/retire traces include blocking condition and next safe action ──

    public function test_hold_trace_includes_blocking_condition_and_next_safe_action(): void
    {
        $result = $this->explainer->explain([
            'decision_type'      => 'hold',
            'blocking_condition' => 'verification_court_verdict is not server_side_green',
            'next_safe_action'   => 'rerun_verification_and_await_green',
        ]);

        $this->assertSame('hold', $result['decision_type']);
        $this->assertSame('verification_court_verdict is not server_side_green', $result['blocking_condition']);
        $this->assertSame('rerun_verification_and_await_green', $result['next_safe_action']);
    }

    public function test_retire_trace_includes_blocking_condition_and_next_safe_action(): void
    {
        $result = $this->explainer->explain([
            'decision_type'      => 'retire',
            'blocking_condition' => 'capability superseded with no active consumers',
            'next_safe_action'   => 'confirm_replacement_tested_then_remove_from_queue',
        ]);

        $this->assertSame('retire', $result['decision_type']);
        $this->assertSame('capability superseded with no active consumers', $result['blocking_condition']);
        $this->assertSame('confirm_replacement_tested_then_remove_from_queue', $result['next_safe_action']);
    }

    public function test_blocking_condition_with_embedded_secret_is_redacted(): void
    {
        $result = $this->explainer->explain([
            'decision_type'      => 'hold',
            'blocking_condition' => 'blocked because API_KEY=sk-live-abc123 is required',
        ]);

        $this->assertStringNotContainsString('sk-live-abc123', $result['blocking_condition']);
        $this->assertStringContainsString('[REDACTED]', $result['blocking_condition']);
    }

    public function test_enqueue_trace_has_empty_blocking_fields_by_default(): void
    {
        $result = $this->explainer->explain(['selected_tasks' => [$this->task('t1')]]);

        $this->assertSame('', $result['blocking_condition']);
        $this->assertSame('', $result['next_safe_action']);
    }

    // ── AC4: anti_goodhart_checks present without exposing provider-sensitive data ──

    public function test_anti_goodhart_checks_includes_verdict_from_scoring_facts(): void
    {
        $result = $this->explainer->explain([
            'selected_tasks' => [$this->task('t1')],
            'scoring_facts'  => ['anti_goodhart_verdict' => 'pass'],
        ]);

        $this->assertContains('anti_goodhart_verdict:pass', $result['anti_goodhart_checks']);
    }

    public function test_anti_goodhart_checks_includes_declared_checks(): void
    {
        $result = $this->explainer->explain([
            'selected_tasks'        => [$this->task('t1')],
            'anti_goodhart_checks'  => ['proxy_gaming_check:pass', 'template_farm_check:pass'],
        ]);

        $this->assertContains('proxy_gaming_check:pass', $result['anti_goodhart_checks']);
        $this->assertContains('template_farm_check:pass', $result['anti_goodhart_checks']);
    }

    public function test_anti_goodhart_checks_redacts_provider_sensitive_content(): void
    {
        $result = $this->explainer->explain([
            'selected_tasks'       => [$this->task('t1')],
            'anti_goodhart_checks' => ['verified_with TOKEN=ghp_secretvalue123'],
        ]);

        $encoded = (string) json_encode($result['anti_goodhart_checks']);
        $this->assertStringNotContainsString('ghp_secretvalue123', $encoded);
        $this->assertStringContainsString('[REDACTED]', $encoded);
    }

    public function test_anti_goodhart_checks_empty_when_no_signal_present(): void
    {
        $result = $this->explainer->explain(['selected_tasks' => [$this->task('t1')]]);

        $this->assertSame([], $result['anti_goodhart_checks']);
    }
}
