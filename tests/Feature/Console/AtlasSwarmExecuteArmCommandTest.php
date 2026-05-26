<?php

declare(strict_types=1);

namespace Tests\Feature\Console;

use App\Services\Ai\AtlasDecide\AtlasSwarmProductionResolverService;
use Tests\TestCase;

class AtlasSwarmExecuteArmCommandTest extends TestCase
{
    private function fakeResolver(array $stubOutcome, bool $throw = false): AtlasSwarmProductionResolverService
    {
        // AtlasSwarmProductionResolverService is final — wrap by binding a
        // factory that returns a stub via anonymous override of its public
        // method. Easiest: construct real, but override AiProviderManager
        // with one whose ->get() returns a fake AiProvider that yields the
        // canonical outcome we want. The resolver's resolve() will map it.
        return new class($stubOutcome, $throw) extends AtlasSwarmProductionResolverService
        {
            public function __construct(private array $stub, private bool $throw)
            {
                // skip parent constructor entirely.
            }

            public function resolve(array $arm, array $context): array
            {
                if ($this->throw) {
                    throw new \RuntimeException('synthetic resolver crash');
                }

                return $this->stub;
            }
        };
    }

    public function test_emits_canonical_json_when_arm_resolves_success(): void
    {
        $resolver = $this->fakeResolver([
            'result' => 'success',
            'latency_ms' => 120,
            'quality_score' => 0.85,
            'output' => 'hello',
        ]);
        $this->app->instance(AtlasSwarmProductionResolverService::class, $resolver);

        $arm = json_encode(['arm_id' => 'a1', 'rank' => 1, 'provider' => 'claude_cli', 'model' => 'opus']);
        $ctx = json_encode(['input' => 'ola']);

        ob_start();
        $exit = $this->artisan('atlas:swarm:execute-arm', ['--arm-json' => $arm, '--context-json' => $ctx])->run();
        $stdout = ob_get_clean();

        $this->assertSame(0, $exit);
        $decoded = json_decode($stdout, true);
        $this->assertIsArray($decoded);
        $this->assertSame('success', $decoded['result']);
        $this->assertSame(120, $decoded['latency_ms']);
        $this->assertSame(0.85, $decoded['quality_score']);
    }

    public function test_invalid_arm_json_emits_failure_outcome(): void
    {
        $this->app->instance(AtlasSwarmProductionResolverService::class, $this->fakeResolver(['result' => 'success', 'latency_ms' => 0, 'quality_score' => null, 'output' => 'never']));

        ob_start();
        $exit = $this->artisan('atlas:swarm:execute-arm', ['--arm-json' => 'not json', '--context-json' => '{}'])->run();
        $stdout = ob_get_clean();

        $this->assertSame(0, $exit);
        $decoded = json_decode($stdout, true);
        $this->assertSame('failure', $decoded['result']);
        $this->assertSame('invalid_arm_json', $decoded['output']);
    }

    public function test_resolver_throwing_records_failure_outcome(): void
    {
        $this->app->instance(AtlasSwarmProductionResolverService::class, $this->fakeResolver([], throw: true));

        $arm = json_encode(['arm_id' => 'a1', 'rank' => 1, 'provider' => 'claude_cli', 'model' => 'opus']);

        ob_start();
        $exit = $this->artisan('atlas:swarm:execute-arm', ['--arm-json' => $arm])->run();
        $stdout = ob_get_clean();

        $this->assertSame(0, $exit);
        $decoded = json_decode($stdout, true);
        $this->assertSame('failure', $decoded['result']);
        $this->assertStringContainsString('execute_arm_error', $decoded['output']);
    }
}
