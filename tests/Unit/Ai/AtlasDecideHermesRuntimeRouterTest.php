<?php

namespace Tests\Unit\Ai;

use App\Services\Ai\AtlasDecideService;
use Tests\TestCase;

/**
 * Integration coverage for the governed Hermes auto-routing gate wired into
 * AtlasDecideService::candidateProvider / operationalDecision. Proves the
 * wrapper invariant: Hermes only becomes a candidate under an explicit
 * allow_auto policy, never for sensitive/secret tasks, never pre-empting the
 * programming executor, and always with an executive_runtime DecisionReceipt.
 */
class AtlasDecideHermesRuntimeRouterTest extends TestCase
{
    /**
     * @param  array<string,mixed>  $payloadOverrides
     * @return array<string,mixed>
     */
    private function opsOptions(array $payloadOverrides = []): array
    {
        return app(AtlasDecideService::class)->normalizeOptions([
            'source_type' => 'manual',
            'input_text' => 'colete metricas de operacao e reinicie o pipeline',
            'payload' => array_merge([
                'app_surface' => 'atlas_cli',
                'routing_task' => 'ops',
                'decision_mode' => 'atlas_decide',
                'operator_requested_provider' => 'auto',
            ], $payloadOverrides),
        ]);
    }

    public function test_allow_auto_false_keeps_hermes_off_for_compatible_ops_task(): void
    {
        config([
            'atlas.ai.default_provider' => 'claude_cli',
            'atlas.ai.providers.hermes_cli.allow_auto' => false,
            'atlas.ai.providers.claude_cli.allow_auto' => true,
        ]);

        $decision = app(AtlasDecideService::class)->operationalDecision($this->opsOptions())->toArray();

        $this->assertNotSame('hermes_cli', data_get($decision, 'provider_selection.candidate_provider'));
        $this->assertNotSame('hermes_cli', data_get($decision, 'provider_selection.selected_provider'));
    }

    public function test_allow_auto_true_plus_compatible_ops_task_selects_hermes(): void
    {
        config([
            'atlas.ai.default_provider' => 'claude_cli',
            'atlas.ai.providers.hermes_cli.allow_auto' => true,
            'atlas.ai.providers.claude_cli.allow_auto' => true,
        ]);

        $decision = app(AtlasDecideService::class)->operationalDecision($this->opsOptions())->toArray();

        $this->assertSame('hermes_cli', data_get($decision, 'provider_selection.candidate_provider'));
        $this->assertSame('hermes_cli', data_get($decision, 'provider_selection.selected_provider'));

        $router = data_get($decision, 'provider_selection.selection_explanation.hermes_runtime_router');
        $this->assertIsArray($router);
        $this->assertSame('executive_runtime', data_get($router, 'runtime_role'));
        $this->assertTrue((bool) data_get($router, 'auto_routing_allowed_now'));
        $this->assertIsString(data_get($router, 'reason'));
        $this->assertNotSame('', trim((string) data_get($router, 'reason')));
        $this->assertNotEmpty(data_get($router, 'receipt_hash'));
    }

    public function test_sensitive_privacy_blocks_hermes_even_when_allow_auto_true(): void
    {
        config([
            'atlas.ai.default_provider' => 'claude_cli',
            'atlas.ai.providers.hermes_cli.allow_auto' => true,
            'atlas.ai.providers.claude_cli.allow_auto' => true,
        ]);

        $decision = app(AtlasDecideService::class)
            ->operationalDecision($this->opsOptions(['privacy' => ['sensitivity' => 'sensitive']]))
            ->toArray();

        $this->assertNotSame('hermes_cli', data_get($decision, 'provider_selection.candidate_provider'));
        $this->assertNotSame('hermes_cli', data_get($decision, 'provider_selection.selected_provider'));
    }

    public function test_programming_task_still_routes_codex_not_hermes_when_allow_auto_true(): void
    {
        config([
            'atlas.ai.default_provider' => 'claude_cli',
            'atlas.ai.providers.hermes_cli.allow_auto' => true,
            'atlas.ai.providers.codex_cli.allow_auto' => true,
        ]);

        $options = app(AtlasDecideService::class)->normalizeOptions([
            'source_type' => 'manual',
            'input_text' => 'implemente a feature e rode os testes',
            'payload' => [
                'app_surface' => 'atlas_cli',
                'atlas_workflow_mode' => 'dev',
                'decision_mode' => 'atlas_decide',
                'operator_requested_provider' => 'auto',
            ],
        ]);

        $decision = app(AtlasDecideService::class)->operationalDecision($options)->toArray();

        $this->assertSame('codex_cli', data_get($decision, 'provider_selection.candidate_provider'));
    }
}
