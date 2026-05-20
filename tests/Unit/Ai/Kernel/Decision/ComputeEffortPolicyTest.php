<?php

namespace Tests\Unit\Ai\Kernel\Decision;

use App\Services\Ai\Kernel\Decision\ComputeEffortPolicy;
use Tests\TestCase;

class ComputeEffortPolicyTest extends TestCase
{
    public function test_maps_atlas_effort_to_claude_codex_and_gemini_controls(): void
    {
        $policy = app(ComputeEffortPolicy::class);

        $claude = $policy->contract('max', 'claude_cli');
        $codex = $policy->contract('deep', 'codex_cli');
        $gemini = $policy->contract('balanced', 'gemini_cli');

        $this->assertSame('max', data_get($claude, 'atlas_level'));
        $this->assertSame('--effort', data_get($claude, 'provider_mapping.control'));
        $this->assertSame('max', data_get($claude, 'provider_mapping.value'));

        $this->assertSame('deep', data_get($codex, 'atlas_level'));
        $this->assertSame('model_reasoning_effort', data_get($codex, 'provider_mapping.control'));
        $this->assertSame('high', data_get($codex, 'provider_mapping.value'));

        $this->assertSame('balanced', data_get($gemini, 'atlas_level'));
        $this->assertSame('observed_only_until_gemini_sdk_driver', data_get($gemini, 'provider_mapping.control_status'));
        $this->assertSame('thinkingLevel', data_get($gemini, 'provider_mapping.api_equivalent.gemini_3.control'));
    }

    public function test_infers_deeper_effort_for_heavy_programming_context_without_operator_override(): void
    {
        $contract = app(ComputeEffortPolicy::class)->contract(null, 'codex_cli', [
            'domain' => 'programming',
            'flow' => 'programming.forge',
            'task' => 'refatore arquitetura critica',
        ]);

        $this->assertSame('max', data_get($contract, 'atlas_level'));
        $this->assertSame('atlas_default', data_get($contract, 'source'));
        $this->assertSame('xhigh', data_get($contract, 'provider_mapping.value'));
    }
}
