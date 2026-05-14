<?php

declare(strict_types=1);

namespace Tests\Feature\Ai\Programming;

use App\Console\Commands\AtlasEngineeringBenchmarkFairCommand;
use App\Services\Ai\FairClaudePolicy;
use Illuminate\Support\Facades\Artisan;
use Tests\TestCase;

class AtlasEngineeringBenchmarkFairCommandModelLockTest extends TestCase
{
    public function test_fair_model_option_accepts_sonnet_alias(): void
    {
        $command = new AtlasEngineeringBenchmarkFairCommand;
        $this->setOptions($command, ['model' => 'sonnet', 'baseline-model' => 'sonnet']);

        $this->assertSame('sonnet', $this->invoke($command, 'fairModelOption'));
        $this->assertSame('sonnet', $this->invoke($command, 'fairBaselineModelOption'));
        $this->assertNull(
            $this->invoke($command, 'validateFairModelLock', [app(FairClaudePolicy::class)]),
            'validateFairModelLock must accept sonnet for both arms.',
        );
    }

    public function test_fair_baseline_model_defaults_to_atlas_model_when_omitted(): void
    {
        $command = new AtlasEngineeringBenchmarkFairCommand;
        $this->setOptions($command, ['model' => 'sonnet']);

        $this->assertSame('sonnet', $this->invoke($command, 'fairModelOption'));
        $this->assertSame('sonnet', $this->invoke($command, 'fairBaselineModelOption'));
    }

    public function test_fair_model_option_defaults_to_opus_when_no_model_option(): void
    {
        $command = new AtlasEngineeringBenchmarkFairCommand;
        $this->setOptions($command, []);

        $this->assertSame('opus', $this->invoke($command, 'fairModelOption'));
        $this->assertSame('opus', $this->invoke($command, 'fairBaselineModelOption'));
        $this->assertNull(
            $this->invoke($command, 'validateFairModelLock', [app(FairClaudePolicy::class)]),
            'validateFairModelLock must accept opus by default.',
        );
    }

    public function test_runbook_rejects_haiku_with_provider_model_not_available(): void
    {
        $exitCode = Artisan::call('atlas:engineering:benchmark:claude-fair', [
            'action' => 'runbook',
            '--model' => 'haiku',
            '--json' => true,
        ]);

        $payload = $this->decode(Artisan::output());

        $this->assertSame(1, $exitCode);
        $this->assertSame(FairClaudePolicy::ERROR_CODE, $payload['error'] ?? null);
        $this->assertSame(
            FairClaudePolicy::MODEL_NOT_AVAILABLE_ERROR,
            data_get($payload, 'details.sub_error'),
        );
        $this->assertSame(
            FairClaudePolicy::MODEL_LOCK_ALLOWLIST,
            data_get($payload, 'details.allowed_aliases'),
        );
    }

    public function test_runbook_rejects_unallowed_baseline_model(): void
    {
        $exitCode = Artisan::call('atlas:engineering:benchmark:claude-fair', [
            'action' => 'runbook',
            '--model' => 'sonnet',
            '--baseline-model' => 'haiku',
            '--json' => true,
        ]);

        $payload = $this->decode(Artisan::output());

        $this->assertSame(1, $exitCode);
        $this->assertSame(FairClaudePolicy::ERROR_CODE, $payload['error'] ?? null);
        $this->assertSame(
            FairClaudePolicy::MODEL_NOT_AVAILABLE_ERROR,
            data_get($payload, 'details.sub_error'),
        );
        $this->assertSame('haiku', data_get($payload, 'details.baseline_model'));
    }

    /**
     * @return array<string,mixed>
     */
    private function decode(string $raw): array
    {
        $decoded = json_decode($raw, true);
        $this->assertIsArray($decoded, 'expected JSON output from --json command');

        return $decoded;
    }

    /**
     * @param  array<string,mixed>  $options
     */
    private function setOptions(AtlasEngineeringBenchmarkFairCommand $command, array $options): void
    {
        $command->setLaravel($this->app);
        $definition = clone $command->getDefinition();
        $arrayInputOptions = [];
        foreach ($options as $name => $value) {
            $arrayInputOptions['--'.$name] = $value;
        }
        $input = new \Symfony\Component\Console\Input\ArrayInput($arrayInputOptions, $definition);
        $reflection = new \ReflectionClass($command);
        $cursor = $reflection;
        while ($cursor !== false && $cursor !== null && ! $cursor->hasProperty('input')) {
            $cursor = $cursor->getParentClass();
        }
        if ($cursor === false || $cursor === null) {
            $this->fail('Unable to locate input property on Command class hierarchy.');
        }
        $property = $cursor->getProperty('input');
        $property->setAccessible(true);
        $property->setValue($command, $input);
    }

    /**
     * @param  array<int,mixed>  $args
     */
    private function invoke(AtlasEngineeringBenchmarkFairCommand $command, string $method, array $args = []): mixed
    {
        $reflection = new \ReflectionClass($command);
        $methodRef = $reflection->getMethod($method);
        $methodRef->setAccessible(true);

        return $methodRef->invokeArgs($command, $args);
    }
}
