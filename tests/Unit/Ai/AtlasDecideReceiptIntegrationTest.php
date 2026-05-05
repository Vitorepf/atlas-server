<?php

namespace Tests\Unit\Ai;

use App\Services\Ai\AtlasDecideService;
use Tests\TestCase;

class AtlasDecideReceiptIntegrationTest extends TestCase
{
    public function test_operational_decision_exposes_v2_receipt_for_auto_model_selection(): void
    {
        config([
            'atlas.ai.default_provider' => 'claude_cli',
            'atlas.ai.providers.codex_cli.allow_auto' => true,
        ]);

        $options = app(AtlasDecideService::class)->normalizeOptions([
            'source_type' => 'manual',
            'input_text' => 'implemente uma melhoria no atlas dev',
            'payload' => [
                'app_surface' => 'atlas_cli',
                'atlas_workflow_mode' => 'dev',
                'decision_mode' => 'atlas_decide',
                'operator_requested_provider' => 'auto',
            ],
        ]);

        $decision = app(AtlasDecideService::class)->operationalDecision($options)->toArray();

        $this->assertSame('atlas.decide.v2', data_get($decision, 'receipt_v2.schema_version'));
        $this->assertSame('programming', data_get($decision, 'receipt_v2.domain'));
        $this->assertSame('programming.dev', data_get($decision, 'receipt_v2.flow'));
        $this->assertSame('auto_best_allowed', data_get($decision, 'receipt_v2.provider_selection.selection_mode'));
        $this->assertSame('codex_cli', data_get($decision, 'receipt_v2.provider_selection.primary'));
        $this->assertMatchesRegularExpression('/^[a-f0-9]{64}$/', (string) data_get($decision, 'receipt_v2.receipt_hash'));
    }

    public function test_manual_provider_override_is_recorded_in_v2_receipt(): void
    {
        $options = app(AtlasDecideService::class)->normalizeOptions([
            'source_type' => 'manual',
            'input_text' => 'use claude para esta resposta',
            'provider' => 'claude_cli',
            'payload' => [
                'app_surface' => 'atlas_cli',
                'atlas_workflow_mode' => 'chat',
                'decision_mode' => 'manual_override',
                'operator_requested_provider' => 'claude_cli',
            ],
        ]);

        $decision = app(AtlasDecideService::class)->operationalDecision($options, selectedProvider: 'claude_cli', selectedModel: 'opus')->toArray();

        $this->assertSame('manual_override', data_get($decision, 'receipt_v2.provider_selection.selection_mode'));
        $this->assertSame('claude_cli', data_get($decision, 'receipt_v2.provider_selection.manual_override.requested_provider'));
        $this->assertSame('opus', data_get($decision, 'receipt_v2.provider_selection.model'));
    }
}
