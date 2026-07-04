<?php

declare(strict_types=1);

namespace Tests\Unit\Ai\Brain2;

use App\Services\Ai\SelfConstruction\ExternalBrain\AtlasExternalBrainProviderPoolOutcomeAttributor;
use Tests\TestCase;

/**
 * Proves AtlasExternalBrainProviderPoolOutcomeAttributor::groupKey() cannot collide
 * when fields contain the old pipe delimiter.
 */
final class AtlasExternalBrainProviderPoolOutcomeAttributorHardeningTest extends TestCase
{
    private AtlasExternalBrainProviderPoolOutcomeAttributor $attributor;

    protected function setUp(): void
    {
        parent::setUp();
        $this->attributor = new AtlasExternalBrainProviderPoolOutcomeAttributor();
    }

    private function groupKey(array $row): string
    {
        $reflection = new \ReflectionClass($this->attributor);
        $method = $reflection->getMethod('groupKey');
        return $method->invoke($this->attributor, $row);
    }

    public function test_pipe_in_field_does_not_collide(): void
    {
        // Two different tuples that would collide with pipe delimiter:
        // "a|b|c|d|e|f" could come from provider="a|b", model="c", ... OR provider="a", model="b|c", ...
        $key1 = $this->groupKey([
            'provider_id' => 'a|b',
            'model_id' => 'c',
            'task_family' => 'd',
            'complexity_tier' => 'e',
            'role' => 'f',
            'prompt_scaffold' => 'g',
        ]);

        $key2 = $this->groupKey([
            'provider_id' => 'a',
            'model_id' => 'b|c',
            'task_family' => 'd',
            'complexity_tier' => 'e',
            'role' => 'f',
            'prompt_scaffold' => 'g',
        ]);

        $this->assertNotSame($key1, $key2, 'Keys must differ when pipe is in different fields');
    }

    public function test_identical_tuples_produce_same_key(): void
    {
        $key1 = $this->groupKey([
            'provider_id' => 'openai',
            'model_id' => 'gpt-4',
            'task_family' => 'code',
            'complexity_tier' => 'high',
            'role' => 'worker',
            'prompt_scaffold' => 'default',
        ]);

        $key2 = $this->groupKey([
            'provider_id' => 'openai',
            'model_id' => 'gpt-4',
            'task_family' => 'code',
            'complexity_tier' => 'high',
            'role' => 'worker',
            'prompt_scaffold' => 'default',
        ]);

        $this->assertSame($key1, $key2);
    }

    public function test_different_tuples_produce_different_keys(): void
    {
        $key1 = $this->groupKey([
            'provider_id' => 'openai',
            'model_id' => 'gpt-4',
            'task_family' => 'code',
            'complexity_tier' => 'high',
            'role' => 'worker',
            'prompt_scaffold' => 'default',
        ]);

        $key2 = $this->groupKey([
            'provider_id' => 'anthropic',
            'model_id' => 'claude',
            'task_family' => 'code',
            'complexity_tier' => 'high',
            'role' => 'worker',
            'prompt_scaffold' => 'default',
        ]);

        $this->assertNotSame($key1, $key2);
    }
}
