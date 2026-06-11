<?php

namespace Tests\Feature\Ai;

use App\Models\AiJob;
use App\Models\AiTrace;
use App\Services\Ai\AiGatewayService;
use App\Services\Ai\AiWorker;
use Tests\TestCase;

/**
 * G7 — ponte síncrona texto→resultado: UMA chamada HTTP percorre o pipeline de
 * criação existente + a execução do worker existente e devolve o resultado.
 * Aqui os dois trilhos são mockados nas COSTURAS reais (gateway + worker) — o
 * que se prova é o transporte síncrono e os fallbacks honestos.
 */
class AtlasChatSyncBridgeTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        config()->set('atlas.token', 'test-token-with-enough-length-123');
    }

    /**
     * @return array<string,string>
     */
    private function authHeaders(): array
    {
        return ['X-Atlas-Token' => 'test-token-with-enough-length-123'];
    }

    public function test_disabled_by_default_returns_503(): void
    {
        config(['atlas.ai.sync_bridge.enabled' => false]);

        $response = $this->postJson('/ai/interactions/sync', ['input' => 'qual o status do atlas?'], $this->authHeaders());

        $response->assertStatus(503);
    }

    public function test_single_call_returns_result_text_when_worker_completes_inline(): void
    {
        config(['atlas.ai.sync_bridge.enabled' => true]);

        $trace = (new AiTrace)->forceFill(['id' => 'trace-sync-demo']);
        $job = (new AiJob)->forceFill([
            'id' => 'job-sync-demo',
            'status' => 'succeeded',
            'provider' => 'probe_cli',
            'result_text' => 'resposta completa da ponte síncrona',
            'error_code' => null,
            'error_message' => null,
        ]);

        $gateway = $this->createMock(AiGatewayService::class);
        $gateway->expects($this->once())->method('enqueueInteraction')
            ->with('qual o status do atlas?', $this->callback(fn (array $options): bool => ($options['surface'] ?? null) === 'sync_bridge'))
            ->willReturn($trace);
        $this->app->instance(AiGatewayService::class, $gateway);

        $worker = $this->createMock(AiWorker::class);
        $worker->expects($this->once())->method('runNextForTrace')
            ->with('trace-sync-demo')
            ->willReturn($job);
        $this->app->instance(AiWorker::class, $worker);

        $response = $this->postJson('/ai/interactions/sync', ['input' => 'qual o status do atlas?'], $this->authHeaders());

        $response->assertOk();
        $this->assertSame('succeeded', $response->json('status'));
        $this->assertSame('trace-sync-demo', $response->json('trace_id'));
        $this->assertSame('resposta completa da ponte síncrona', $response->json('result_text'));
    }

    public function test_falls_back_to_async_envelope_when_no_job_is_picked_inline(): void
    {
        config(['atlas.ai.sync_bridge.enabled' => true]);

        $gateway = $this->createMock(AiGatewayService::class);
        $gateway->method('enqueueInteraction')->willReturn((new AiTrace)->forceFill(['id' => 'trace-async-fb']));
        $this->app->instance(AiGatewayService::class, $gateway);

        $worker = $this->createMock(AiWorker::class);
        $worker->method('runNextForTrace')->willReturn(null);
        $this->app->instance(AiWorker::class, $worker);

        $response = $this->postJson('/ai/interactions/sync', ['input' => 'pergunta que cai no async'], $this->authHeaders());

        $response->assertStatus(202);
        $this->assertSame('pending_async', $response->json('status'));
        $this->assertSame('GET /ai/interactions/trace-async-fb', $response->json('fallback'));
    }

    public function test_inline_execution_error_returns_honest_envelope_with_async_fallback(): void
    {
        config(['atlas.ai.sync_bridge.enabled' => true]);

        $gateway = $this->createMock(AiGatewayService::class);
        $gateway->method('enqueueInteraction')->willReturn((new AiTrace)->forceFill(['id' => 'trace-err']));
        $this->app->instance(AiGatewayService::class, $gateway);

        $worker = $this->createMock(AiWorker::class);
        $worker->method('runNextForTrace')->willThrowException(new \RuntimeException('provider morreu inline'));
        $this->app->instance(AiWorker::class, $worker);

        $response = $this->postJson('/ai/interactions/sync', ['input' => 'pergunta que explode'], $this->authHeaders());

        $response->assertOk();
        $this->assertSame('execution_error', $response->json('status'));
        $this->assertSame('trace-err', $response->json('trace_id'));
        $this->assertStringContainsString('provider morreu', (string) $response->json('error'));
    }
}
