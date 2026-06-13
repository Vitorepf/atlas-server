<?php

declare(strict_types=1);

namespace Tests\Unit\Ai\Obra;

use App\Services\Ai\Obra\ProviderObraNodeDelivery;
use App\Services\Ai\RealExecution\AtlasLiveCodeDeliveryService;
use Mockery;
use Tests\TestCase;

final class ProviderObraNodeDeliveryTest extends TestCase
{
    protected function tearDown(): void
    {
        Mockery::close();
        parent::tearDown();
    }

    public function test_blocked_live_delivery_preserves_bounded_autopsy_reason(): void
    {
        $live = Mockery::mock(AtlasLiveCodeDeliveryService::class);
        $live->shouldReceive('deliver')->once()->andReturn([
            'schema_version' => AtlasLiveCodeDeliveryService::SCHEMA,
            'status' => AtlasLiveCodeDeliveryService::STATUS_BLOCKED,
            'blocked_reason' => 'provider_returned_not_ok:rate_limited',
            'provider' => 'hermes_cli',
            'model' => 'gpt-5.5',
            'target_file' => 'app/Services/Ai/AutonomousEvolution/AtlasLoopMorningDigestService.php',
            'file_count' => 1,
            'latency_ms' => 2345,
            'syntax_check' => [
                'ok' => false,
                'tool' => 'php -l',
                'output' => str_repeat('Parse error ', 80),
            ],
            'run_check' => [
                'ok' => false,
                'tool' => 'php_self_test',
                'exit_code' => 1,
                'output' => str_repeat('self-test failed ', 80),
            ],
            'prompt' => 'RAW_PROMPT_SHOULD_NOT_PERSIST',
            'code_preview' => 'SECRET_CODE_PREVIEW_SHOULD_NOT_PERSIST',
            'provider_raw_output' => 'RAW_PROVIDER_OUTPUT_SHOULD_NOT_PERSIST',
        ]);

        $result = (new ProviderObraNodeDelivery($live))->deliver('build morning digest panel', [
            'provider' => 'hermes_cli',
            'target_area' => 'app/Services/Ai/AutonomousEvolution/AtlasLoopMorningDigestService.php',
        ]);

        $this->assertFalse((bool) $result['certified']);
        $this->assertSame('provider_returned_not_ok:rate_limited', $result['reason']);
        $this->assertSame('hermes_cli', $result['provider']);
        $this->assertSame('gpt-5.5', $result['model']);
        $this->assertSame(AtlasLiveCodeDeliveryService::STATUS_BLOCKED, $result['delivery_status']);
        $this->assertSame(1, $result['file_count']);
        $this->assertSame(2345, $result['latency_ms']);
        $this->assertSame(false, data_get($result, 'syntax_check.ok'));
        $this->assertStringContainsString('Parse error', (string) data_get($result, 'syntax_check.output_excerpt'));
        $this->assertStringContainsString('self-test failed', (string) data_get($result, 'run_check.output_excerpt'));
        $this->assertSame('provider_returned_not_ok:rate_limited', data_get($result, 'delivery_diagnostic.blocked_reason'));

        $encoded = json_encode($result, JSON_UNESCAPED_SLASHES);
        $this->assertIsString($encoded);
        $this->assertStringNotContainsString('RAW_PROMPT_SHOULD_NOT_PERSIST', $encoded);
        $this->assertStringNotContainsString('SECRET_CODE_PREVIEW_SHOULD_NOT_PERSIST', $encoded);
        $this->assertStringNotContainsString('RAW_PROVIDER_OUTPUT_SHOULD_NOT_PERSIST', $encoded);
    }

    public function test_obra_delivery_defaults_to_configured_hermes_model_and_timeout(): void
    {
        config()->set('atlas.ai.default_provider', 'hermes_cli');
        config()->set('atlas.ai.timeout_seconds', 300);
        config()->set('atlas.ai.providers.hermes_cli.model', 'gpt-5.5');
        config()->set('atlas.ai.providers.hermes_cli.timeout_seconds', 900);

        $live = Mockery::mock(AtlasLiveCodeDeliveryService::class);
        $live->shouldReceive('deliver')
            ->once()
            ->with(
                'build bridge delivery probe',
                Mockery::on(function (array $options): bool {
                    return ($options['multi_file'] ?? null) === true
                        && ($options['provider'] ?? null) === 'hermes_cli'
                        && ($options['model'] ?? null) === 'gpt-5.5'
                        && ($options['timeout_seconds'] ?? null) === 900
                        && ($options['target_file'] ?? null) === 'app/Services/Ai/AutonomousEvolution/AtlasLoopFunnelService.php';
                })
            )
            ->andReturn([
                'schema_version' => AtlasLiveCodeDeliveryService::SCHEMA,
                'status' => AtlasLiveCodeDeliveryService::STATUS_BLOCKED,
                'blocked_reason' => 'provider_returned_not_ok:timeout',
                'provider' => 'hermes_cli',
                'model' => 'gpt-5.5',
                'target_file' => 'app/Services/Ai/AutonomousEvolution/AtlasLoopFunnelService.php',
                'file_count' => 0,
                'latency_ms' => 900001,
            ]);

        $result = (new ProviderObraNodeDelivery($live))->deliver('build bridge delivery probe', [
            'target_area' => 'app/Services/Ai/AutonomousEvolution/AtlasLoopFunnelService.php',
        ]);

        $this->assertFalse((bool) $result['certified']);
        $this->assertSame('hermes_cli', $result['provider']);
        $this->assertSame('gpt-5.5', $result['model']);
        $this->assertSame(900001, $result['latency_ms']);
    }
}
