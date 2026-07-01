<?php

declare(strict_types=1);

namespace Tests\Feature\Ai;

use App\Services\Ai\SelfConstruction\ExternalBrain\AtlasExternalBrainDecisionTraceExplainer;
use PHPUnit\Framework\TestCase;

final class AtlasExternalBrainDecisionTraceExplainerTest extends TestCase
{
    private function explainer(): AtlasExternalBrainDecisionTraceExplainer
    {
        return new AtlasExternalBrainDecisionTraceExplainer;
    }

    private function decisionRecord(array $overrides = []): array
    {
        return array_merge([
            'selected_tasks' => [
                ['task_packet_id' => 'sel-1', 'scoring_facts' => ['arena_score' => 0.9, 'top_dimension' => 'leverage']],
            ],
            'rejected_alternatives' => [
                ['task_packet_id' => 'rej-1', 'reason' => 'proxy_heavy'],
            ],
            'queue_context' => ['queue_depth' => 12],
            'scoring_facts' => ['top_dimension' => 'leverage', 'algorithm' => 'arena_v1', 'evidence_strength' => 0.8],
        ], $overrides);
    }

    // ── AC2: trace_id is stable for the same selected/rejected ids + top_signals ──

    public function test_trace_id_is_stable_for_identical_decision_record(): void
    {
        $first = $this->explainer()->explain($this->decisionRecord());
        $second = $this->explainer()->explain($this->decisionRecord());

        $this->assertSame($first['trace_id'], $second['trace_id']);
    }

    public function test_trace_id_changes_when_selected_ids_change(): void
    {
        $first = $this->explainer()->explain($this->decisionRecord());
        $second = $this->explainer()->explain($this->decisionRecord([
            'selected_tasks' => [['task_packet_id' => 'sel-2', 'scoring_facts' => ['arena_score' => 0.9]]],
        ]));

        $this->assertNotSame($first['trace_id'], $second['trace_id']);
    }

    // ── AC3: audit fields are emitted ──────────────────────────────────────

    public function test_audit_fields_are_emitted(): void
    {
        $result = $this->explainer()->explain($this->decisionRecord());

        $this->assertArrayHasKey('selected_tasks', $result);
        $this->assertArrayHasKey('top_signals', $result);
        $this->assertArrayHasKey('chosen_reasons', $result);
        $this->assertArrayHasKey('rejected_reasons', $result);
        $this->assertArrayHasKey('uncertainty', $result);
        $this->assertArrayHasKey('evidence_to_reconsider', $result);

        $this->assertNotEmpty($result['top_signals']);
        $this->assertStringContainsString('sel-1', $result['chosen_reasons'][0]);
        $this->assertSame('rej-1', $result['rejected_reasons'][0]['task_packet_id']);
        $this->assertSame('proxy_heavy', $result['rejected_reasons'][0]['reason']);
        $this->assertNotEmpty($result['evidence_to_reconsider']);
        $this->assertTrue($result['provider_safe']);
    }

    // ── AC4: private fields are stripped from every part of the output ───────

    public function test_private_fields_are_stripped_from_output(): void
    {
        $result = $this->explainer()->explain([
            'selected_tasks' => [[
                'task_packet_id' => 'sel-1',
                'prompt' => 'raw prompt text',
                'system_message' => 'you are an assistant',
                'model_response' => 'raw model output',
                'provider_response' => 'raw provider payload',
                'api_key' => 'sk-secret',
                'token' => 'abc123',
                'secret' => 'shh',
                'credential' => 'user:pass',
                'scoring_facts' => ['arena_score' => 0.5, 'api_key' => 'nested-secret'],
            ]],
            'rejected_alternatives' => [],
            'scoring_facts' => ['api_key' => 'top-level-secret', 'top_dimension' => 'leverage'],
        ]);

        $encoded = json_encode($result);

        foreach (['prompt', 'system_message', 'model_response', 'provider_response', 'api_key', 'token', 'secret', 'credential'] as $privateField) {
            $this->assertArrayNotHasKey($privateField, $result['selected_tasks'][0], "field: {$privateField}");
        }
        $this->assertStringNotContainsString('sk-secret', (string) $encoded);
        $this->assertStringNotContainsString('raw prompt text', (string) $encoded);
        $this->assertStringNotContainsString('nested-secret', (string) $encoded);
        $this->assertStringNotContainsString('top-level-secret', (string) $encoded);
    }
}
