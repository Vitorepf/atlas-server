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
}
