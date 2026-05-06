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
