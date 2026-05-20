<?php

namespace Tests\Unit\Ai\Kernel;

use App\Services\Ai\Kernel\Decision\ModelSelectionContractFactory;
use Tests\TestCase;

class ModelSelectionContractFactoryTest extends TestCase
{
    public function test_cli_dev_contract_defaults_to_auto_best_allowed_under_decide_authority(): void
    {
        $contract = app(ModelSelectionContractFactory::class)->forCliDev(
            provider: null,
            modelSelection: null,
            modelOverride: null,
            fairMode: false,
        );

        $this->assertSame('atlas.cli_dev.model_selection_contract.v1', $contract['schema_version']);
        $this->assertSame('atlas_cli_dev', $contract['surface']);
        $this->assertSame('atlas_decide', $contract['authority']);
        $this->assertSame('auto_best_allowed', $contract['selection_mode']);
        $this->assertSame(['auto_best_allowed', 'auto_best_available', 'manual_override'], $contract['available_selection_modes']);
        $this->assertSame('auto', $contract['operator_requested_provider']);
        $this->assertNull($contract['requested_model']);
        $this->assertNull($contract['specialist_profile']);
        $this->assertFalse($contract['fair_mode']);
    }

    public function test_ai_chat_contract_preserves_manual_provider_model_and_alias(): void
    {
        $contract = app(ModelSelectionContractFactory::class)->forAiChat(
            provider: 'codex_cli',
            modelSelection: [
                'alias' => 'codex-premium',
                'source' => 'catalog',
            ],
            modelOverride: 'gpt-5.5',
            fairMode: false,
        );

        $this->assertSame('atlas.ai_chat.model_selection_contract.v1', $contract['schema_version']);
        $this->assertSame('atlas_ai_chat', $contract['surface']);
        $this->assertSame('atlas_decide', $contract['authority']);
        $this->assertSame('manual_override', $contract['selection_mode']);
        $this->assertSame('codex_cli', $contract['operator_requested_provider']);
        $this->assertSame('gpt-5.5', $contract['requested_model']);
        $this->assertSame('codex-premium', $contract['requested_model_alias']);
        $this->assertSame('catalog', $contract['requested_model_source']);
    }

    public function test_contract_carries_specialist_profile_without_changing_decide_authority(): void
    {
        $contract = app(ModelSelectionContractFactory::class)->forCliDev(
            provider: null,
            modelSelection: null,
            modelOverride: null,
            fairMode: false,
            context: [
                'domain' => 'programming',
                'flow' => 'programming.visual',
                'task' => 'corrigir layout frontend mobile',
            ],
        );

        $this->assertSame('atlas_decide', $contract['authority']);
        $this->assertSame('auto_best_allowed', $contract['selection_mode']);
        $this->assertSame('programming', $contract['domain']);
        $this->assertSame('programming.visual', $contract['flow']);
        $this->assertSame('programming.frontend', $contract['specialist_profile']);
        $this->assertSame('flow_or_task_inference', $contract['specialist_profile_source']);
    }

    public function test_contract_carries_compute_effort_with_provider_mapping(): void
    {
        $contract = app(ModelSelectionContractFactory::class)->forAiChat(
            provider: 'codex_cli',
            modelSelection: null,
            modelOverride: null,
            fairMode: false,
            context: [
                'domain' => 'programming',
                'flow' => 'programming.dev',
                'compute_effort' => 'max',
            ],
        );

        $this->assertSame('atlas.compute_effort_contract.v1', data_get($contract, 'compute_effort.schema_version'));
        $this->assertSame('max', data_get($contract, 'compute_effort.atlas_level'));
        $this->assertSame('model_reasoning_effort', data_get($contract, 'compute_effort.provider_mapping.name'));
        $this->assertSame('xhigh', data_get($contract, 'compute_effort.provider_mapping.value'));
        $this->assertSame('enforced', data_get($contract, 'compute_effort.provider_mapping.control_status'));
    }

    public function test_gemini_contract_records_selected_alias_model_family_and_tier(): void
    {
        config()->set('atlas.ai.providers.gemini_cli.models.gemini_flash.model', 'gemini-3.5-flash');
        config()->set('atlas.ai.providers.gemini_cli.models.gemini_pro.model', 'gemini-3.1-pro-preview');

        $contract = app(ModelSelectionContractFactory::class)->forAiChat(
            provider: 'gemini_cli',
            modelSelection: ['alias' => 'auto', 'source' => 'operator'],
            modelOverride: null,
            fairMode: false,
            context: [
                'domain' => 'programming',
                'flow' => 'programming.architecture',
                'compute_effort' => 'deep',
            ],
        );

        $this->assertSame('gemini-3.1-pro-preview', $contract['selected_model']);
        $this->assertSame('gemini_pro', $contract['selected_model_alias']);
        $this->assertSame('gemini', $contract['model_family']);
        $this->assertSame('premium', $contract['model_tier']);
        $this->assertSame('atlas_decide', $contract['selection_source']);
    }

    public function test_fair_mode_is_audited_as_manual_override_without_provider(): void
    {
        $contract = app(ModelSelectionContractFactory::class)->forCliDev(
            provider: null,
            modelSelection: null,
            modelOverride: null,
            fairMode: true,
        );

        $this->assertSame('manual_override', $contract['selection_mode']);
        $this->assertSame('auto', $contract['operator_requested_provider']);
        $this->assertTrue($contract['fair_mode']);
    }
}
