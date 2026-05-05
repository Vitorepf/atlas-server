<?php

namespace Tests\Unit\Ai;

use App\Services\Ai\AtlasDecideService;
use App\Services\Ai\Kernel\Provider\ProviderRequestHasher;
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
        $this->assertTrue(data_get($decision, 'kernel_contracts.valid'));
        $this->assertTrue(data_get($decision, 'kernel_contracts.execution_allowed'));
        $this->assertSame([], data_get($decision, 'kernel_contracts.blocking_errors'));
        $this->assertSame('atlas_cli_dev', data_get($decision, 'kernel_contracts.surface.surface_id'));
        $this->assertSame('normalized', data_get($decision, 'kernel_contracts.surface.status'));
        $this->assertSame('prepared', data_get($decision, 'kernel_contracts.provider.status'));
        $this->assertSame('codex_cli', data_get($decision, 'kernel_contracts.provider.provider_id'));
        $this->assertSame(ProviderRequestHasher::HASH_ALGORITHM, data_get($decision, 'kernel_contracts.provider.request_hash_algorithm'));
        $this->assertSame(ProviderRequestHasher::PREPARED_REQUEST_CANONICALIZATION, data_get($decision, 'kernel_contracts.provider.request_hash_canonicalization'));
        $this->assertTrue(data_get($decision, 'kernel_contracts.provider.validation.ok'));
        $this->assertSame([], data_get($decision, 'kernel_contracts.provider.validation.errors'));
        $this->assertMatchesRegularExpression('/^[a-f0-9]{64}$/', (string) data_get($decision, 'kernel_contracts.provider.identity_fragment_hash'));
        $this->assertContains(data_get($decision, 'kernel_contracts.provider.identity_fragment_source'), [
            'atlas_ai_master_prompt_projection',
            'atlas_ai_master_prompt_fallback',
        ]);
        $this->assertIsBool(data_get($decision, 'kernel_contracts.provider.identity_fragment_fallback'));
        $this->assertSame(
            data_get($decision, 'kernel_contracts.provider.identity_fragment_hash'),
            data_get($decision, 'receipt_v2.metadata.kernel_contracts.provider.identity_fragment_hash'),
        );
        $this->assertSame(
            data_get($decision, 'kernel_contracts.provider.request_hash_canonicalization'),
            data_get($decision, 'receipt_v2.metadata.kernel_contracts.provider.request_hash_canonicalization'),
        );
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
        $this->assertSame('atlas_cli_chat', data_get($decision, 'kernel_contracts.surface.surface_id'));
        $this->assertSame('claude_cli', data_get($decision, 'kernel_contracts.provider.provider_id'));
        $this->assertSame('opus', data_get($decision, 'kernel_contracts.provider.model'));
    }

    public function test_receipt_for_trace_persists_kernel_contracts_for_gateway_metadata(): void
    {
        config([
            'atlas.ai.default_provider' => 'claude_cli',
            'atlas.ai.providers.codex_cli.allow_auto' => true,
        ]);

        $options = app(AtlasDecideService::class)->normalizeOptions([
            'source_type' => 'manual',
            'input_text' => 'corrija um bug no atlas forge',
            'payload' => [
                'app_surface' => 'atlas_cli',
                'atlas_workflow_mode' => 'forge',
                'decision_mode' => 'atlas_decide',
                'operator_requested_provider' => 'auto',
            ],
        ]);

        $receipt = app(AtlasDecideService::class)->receiptForTrace($options, 'codex_cli', 'gpt-5.5');

        $this->assertSame('atlas_cli_forge', data_get($receipt, 'kernel_contracts.surface.surface_id'));
        $this->assertTrue(data_get($receipt, 'kernel_contracts.valid'));
        $this->assertTrue(data_get($receipt, 'kernel_contracts.execution_allowed'));
        $this->assertSame('prepared', data_get($receipt, 'kernel_contracts.provider.status'));
        $this->assertSame('codex_cli', data_get($receipt, 'kernel_contracts.provider.provider_id'));
        $this->assertTrue(data_get($receipt, 'kernel_contracts.provider.validation.ok'));
        $this->assertSame(
            data_get($receipt, 'kernel_contracts.provider.request_hash'),
            data_get($receipt, 'receipt_v2.metadata.kernel_contracts.provider.request_hash'),
        );
        $this->assertSame(
            data_get($receipt, 'kernel_contracts.provider.request_hash_algorithm'),
            data_get($receipt, 'receipt_v2.metadata.kernel_contracts.provider.request_hash_algorithm'),
        );
    }

    public function test_provider_contract_receipt_preserves_validator_warnings(): void
    {
        $options = app(AtlasDecideService::class)->normalizeOptions([
            'source_type' => 'manual',
            'input_text' => 'rode uma analise com modelo experimental',
            'payload' => [
                'app_surface' => 'atlas_cli',
                'atlas_workflow_mode' => 'dev',
                'decision_mode' => 'manual_override',
                'operator_requested_provider' => 'codex_cli',
                'requested_provider' => 'codex_cli',
            ],
        ]);

        $receipt = app(AtlasDecideService::class)->receiptForTrace($options, 'codex_cli', 'future-codex-model');

        $this->assertSame('prepared', data_get($receipt, 'kernel_contracts.provider.status'));
        $this->assertTrue(data_get($receipt, 'kernel_contracts.provider.validation.ok'));
        $this->assertContains(
            'model_not_declared_in_supported_models',
            data_get($receipt, 'kernel_contracts.provider.validation.warnings'),
        );
    }

    public function test_council_provider_has_formal_provider_driver_contract(): void
    {
        $options = app(AtlasDecideService::class)->normalizeOptions([
            'source_type' => 'manual',
            'input_text' => 'rode o conselho claude + codex para revisar uma arquitetura critica',
            'provider' => 'claude_codex',
            'payload' => [
                'app_surface' => 'atlas_cli',
                'atlas_workflow_mode' => 'dev',
                'decision_mode' => 'manual_override',
                'operator_requested_provider' => 'claude_codex',
                'requested_provider' => 'claude_codex',
            ],
        ]);

        $receipt = app(AtlasDecideService::class)->receiptForTrace($options, 'claude_codex', 'council_default');

        $this->assertSame('council_dual_review', $receipt['execution_strategy']);
        $this->assertTrue(data_get($receipt, 'kernel_contracts.valid'));
        $this->assertTrue(data_get($receipt, 'kernel_contracts.execution_allowed'));
        $this->assertSame('prepared', data_get($receipt, 'kernel_contracts.provider.status'));
        $this->assertSame('claude_codex', data_get($receipt, 'kernel_contracts.provider.provider_id'));
        $this->assertSame('council_default', data_get($receipt, 'kernel_contracts.provider.model'));
        $this->assertTrue(data_get($receipt, 'kernel_contracts.provider.validation.ok'));
        $this->assertSame([], data_get($receipt, 'kernel_contracts.provider.validation.errors'));
        $this->assertSame(
            data_get($receipt, 'kernel_contracts.provider.identity_fragment_hash'),
            data_get($receipt, 'receipt_v2.metadata.kernel_contracts.provider.identity_fragment_hash'),
        );
    }

    public function test_unknown_provider_is_blocked_by_kernel_contract_summary(): void
    {
        $options = app(AtlasDecideService::class)->normalizeOptions([
            'source_type' => 'manual',
            'input_text' => 'teste provider externo ainda nao registrado',
            'payload' => [
                'app_surface' => 'atlas_cli',
                'atlas_workflow_mode' => 'chat',
                'decision_mode' => 'atlas_decide',
                'operator_requested_provider' => 'auto',
            ],
        ]);

        $receipt = app(AtlasDecideService::class)->receiptForTrace($options, 'openai_http', 'gpt-test');

        $this->assertFalse(data_get($receipt, 'kernel_contracts.valid'));
        $this->assertFalse(data_get($receipt, 'kernel_contracts.execution_allowed'));
        $this->assertSame('unregistered_provider_driver', data_get($receipt, 'kernel_contracts.provider.status'));
        $this->assertContains('provider_contract_not_prepared', data_get($receipt, 'kernel_contracts.blocking_errors'));
    }
}
