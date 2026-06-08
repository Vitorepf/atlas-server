<?php

declare(strict_types=1);

namespace Tests\Unit\Ai\AtlasDecide;

use App\Models\AiJob;
use App\Services\Ai\AiProvider;
use App\Services\Ai\AiProviderHealthCheck;
use App\Services\Ai\AiProviderManager;
use App\Services\Ai\AiProviderResult;
use App\Services\Ai\AtlasDecide\AtlasSwarmProductionResolverService;
use App\Services\Ai\Caching\AtlasProviderCostSentinel;
use Mockery;
use Tests\TestCase;

class AtlasSwarmProductionResolverCostSentinelTest extends TestCase
{
    /** @var list<string> */
    private array $tmp = [];

    protected function tearDown(): void
    {
        foreach ($this->tmp as $p) {
            @unlink($p);
        }
        Mockery::close();
        parent::tearDown();
    }

    private function fakeManager(): AiProviderManager
    {
        $provider = new class implements AiProvider
        {
            public function key(): string
            {
                return 'fake';
            }

            public function run(AiJob $job, string $prompt): AiProviderResult
            {
                return new AiProviderResult(true, 'real output', [], 0, 5, 'real output', '');
            }

            public function runStreaming(AiJob $job, string $prompt, ?callable $onEvent = null): AiProviderResult
            {
                return $this->run($job, $prompt);
            }

            public function health(): AiProviderHealthCheck
            {
                return new AiProviderHealthCheck(true, 'ok', null);
            }
        };

        $mgr = Mockery::mock(AiProviderManager::class);
        $mgr->shouldReceive('get')->andReturn($provider);

        return $mgr;
    }

    public function test_records_cost_telemetry_and_does_not_block_in_observe(): void
    {
        config(['atlas.ai.cost_sentinel.hard_gate_units' => 0.0]); // observe: no ceiling
        $log = sys_get_temp_dir().'/atlas-cost-tel-'.bin2hex(random_bytes(4)).'.jsonl';
        $this->tmp[] = $log;

        $resolver = new AtlasSwarmProductionResolverService($this->fakeManager());
        $resolver->setCostSentinel(app(AtlasProviderCostSentinel::class), $log);

        $out = $resolver->resolve(['provider' => 'fake'], ['input' => 'a real prompt to assess']);

        $this->assertSame('success', $out['result']); // not blocked (observe)
        $this->assertFileExists($log);
        $this->assertStringContainsString('pre_cost_units', (string) file_get_contents($log));
    }

    public function test_refuses_when_operator_sets_a_hard_ceiling_that_is_exceeded(): void
    {
        config(['atlas.ai.cost_sentinel.hard_gate_units' => 0.0001]); // tiny ceiling: enforce
        $resolver = new AtlasSwarmProductionResolverService($this->fakeManager());
        $resolver->setCostSentinel(app(AtlasProviderCostSentinel::class), null);

        $out = $resolver->resolve(['provider' => 'fake'], ['input' => 'a real prompt to assess']);

        $this->assertSame('failure', $out['result']);
        $this->assertSame('cost_ceiling_exceeded', $out['output']);
    }

    public function test_default_unwired_sentinel_is_inert(): void
    {
        $resolver = new AtlasSwarmProductionResolverService($this->fakeManager());

        $out = $resolver->resolve(['provider' => 'fake'], ['input' => 'prompt']);

        $this->assertSame('success', $out['result']);
    }
}
