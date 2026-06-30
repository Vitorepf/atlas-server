<?php

declare(strict_types=1);

namespace Tests\Feature\Ai;

use App\Services\Ai\SelfConstruction\ExternalBrain\AtlasExternalBrainLocalClientHeadlessCapabilityProbe;
use Tests\TestCase;

final class AtlasExternalBrainLocalClientHeadlessCapabilityProbeTest extends TestCase
{
    private AtlasExternalBrainLocalClientHeadlessCapabilityProbe $probe;

    protected function setUp(): void
    {
        parent::setUp();
        $this->probe = new AtlasExternalBrainLocalClientHeadlessCapabilityProbe;
    }

    private function readyFacts(array $overrides = []): array
    {
        return array_merge([
            'binary_exists' => true,
            'login_state_known' => true,
            'print_mode_supported' => true,
            'noninteractive_prompt_supported' => true,
            'patch_output_supported' => true,
            'workspace_scoping_supported' => true,
            'paid_api_required' => false,
        ], $overrides);
    }

    public function test_all_facts_satisfied_is_ready_for_headless_trial(): void
    {
        $result = $this->probe->probe($this->readyFacts());

        $this->assertSame(AtlasExternalBrainLocalClientHeadlessCapabilityProbe::SCHEMA, $result['schema']);
        $this->assertSame('ready_for_headless_trial', $result['status']);
        $this->assertTrue($result['ready_for_headless_trial']);
        $this->assertSame([], $result['blockers']);
    }

    public function test_missing_binary_blocks(): void
    {
        $result = $this->probe->probe($this->readyFacts(['binary_exists' => false]));

        $this->assertContains('binary_missing', $result['blockers']);
        $this->assertFalse($result['ready_for_headless_trial']);
    }

    public function test_missing_login_state_blocks(): void
    {
        $result = $this->probe->probe($this->readyFacts(['login_state_known' => false]));

        $this->assertContains('missing_login_state', $result['blockers']);
    }

    public function test_no_print_mode_is_ui_only_client(): void
    {
        $result = $this->probe->probe($this->readyFacts(['print_mode_supported' => false]));

        $this->assertContains('ui_only_client', $result['blockers']);
    }

    public function test_no_noninteractive_prompt_is_ui_only_client(): void
    {
        $result = $this->probe->probe($this->readyFacts(['noninteractive_prompt_supported' => false]));

        $this->assertContains('ui_only_client', $result['blockers']);
    }

    public function test_no_patch_output_blocks(): void
    {
        $result = $this->probe->probe($this->readyFacts(['patch_output_supported' => false]));

        $this->assertContains('patch_output_unsupported', $result['blockers']);
    }

    public function test_no_workspace_scoping_blocks(): void
    {
        $result = $this->probe->probe($this->readyFacts(['workspace_scoping_supported' => false]));

        $this->assertContains('workspace_scoping_unsupported', $result['blockers']);
    }

    public function test_paid_api_required_blocks_even_with_full_headless_capability(): void
    {
        $result = $this->probe->probe($this->readyFacts(['paid_api_required' => true]));

        $this->assertContains('paid_api_required', $result['blockers']);
        $this->assertFalse($result['ready_for_headless_trial']);
    }

    public function test_never_grants_execution_or_spend(): void
    {
        $result = $this->probe->probe($this->readyFacts());

        $this->assertFalse($result['provider_call_allowed']);
        $this->assertFalse($result['token_spend_allowed']);
        $this->assertFalse($result['adapter_execution_allowed']);
    }

    public function test_empty_input_reports_all_blockers_except_paid_api(): void
    {
        $result = $this->probe->probe([]);

        $this->assertFalse($result['ready_for_headless_trial']);
        $this->assertContains('binary_missing', $result['blockers']);
        $this->assertContains('missing_login_state', $result['blockers']);
        $this->assertContains('ui_only_client', $result['blockers']);
        $this->assertContains('patch_output_unsupported', $result['blockers']);
        $this->assertContains('workspace_scoping_unsupported', $result['blockers']);
        $this->assertNotContains('paid_api_required', $result['blockers']);
    }
}
