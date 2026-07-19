<?php

namespace Tests\Unit\Ai\Hermes;

use App\Models\AiJob;
use App\Services\Ai\AiProviderResult;
use App\Services\Ai\Hermes\HermesOpenAiResponseAdapter;
use App\Services\Ai\HermesCliProvider;
use App\Services\Ai\Rivals\Adapters\External\InspectEvalsAdapter;
use App\Services\Ai\Rivals\Core\ArmRegistry;
use App\Services\Ai\Rivals\Core\NativeExecutionManifest;
use App\Services\Ai\Rivals\Core\RunPlan;
use Illuminate\Support\Facades\File;
use Mockery;
use Tests\TestCase;

class HermesOpenAiResponseAdapterTest extends TestCase
{
    private string $storage;

    protected function setUp(): void
    {
        parent::setUp();
        $this->storage = sys_get_temp_dir().'/rivals_openai_response_'.uniqid();
        config()->set('atlas_rivals.storage_root', $this->storage);
    }

    protected function tearDown(): void
    {
        File::deleteDirectory($this->storage);
        parent::tearDown();
    }

    public function test_success_uses_governed_runtime_and_aggregates_auditable_unit_proof(): void
    {
        [$plan, $entry] = $this->manifest();
        $provider = Mockery::mock(HermesCliProvider::class);
        $provider->shouldReceive('runStreaming')
            ->twice()
            ->withArgs(function (AiJob $job, string $prompt): bool {
                $this->assertSame('hermes_cli', $job->provider);
                $this->assertSame('kimi-k2.7', $job->model);
                $this->assertTrue((bool) data_get($job->payload, 'hermes.cli_oneshot'));
                $this->assertSame('read', data_get($job->payload, 'tool_permissions.mode'));
                $this->assertSame('rivals_preregistered_single_provider_arm', data_get($job->payload, 'hermes.runtime_router_reason'));
                $this->assertStringContainsString('[user]', $prompt);

                return true;
            })
            ->andReturn($this->providerResult());

        $adapter = new HermesOpenAiResponseAdapter($provider);
        $headers = $this->headers($plan, $entry);
        $body = [
            'model' => 'kimi-k2.7',
            'messages' => [['role' => 'user', 'content' => 'Return 42']],
        ];

        $first = $adapter->complete($body, $headers);
        $second = $adapter->complete($body, $headers);

        $this->assertSame(200, $first['status']);
        $this->assertSame('42', data_get($first, 'body.choices.0.message.content'));
        $this->assertSame(120, data_get($first, 'body.usage.prompt_tokens'));
        $this->assertSame(20, data_get($first, 'body.usage.completion_tokens'));
        $this->assertSame(200, $second['status']);

        $proofPath = $entry['normalization']['scratch_dir'].'/.rivals_atlas_dev_bridge.json';
        $proof = json_decode((string) file_get_contents($proofPath), true);
        $this->assertSame('atlas.rivals2.atlas_dev_bridge_receipt.v2', $proof['schema_version']);
        $this->assertSame('passed', $proof['status']);
        $this->assertTrue($proof['real_provider']);
        $this->assertSame('hermes_cli', $proof['provider']);
        $this->assertSame('kimi-k2.7', $proof['model']);
        $this->assertSame(2, data_get($proof, 'provider_call.provider_calls'));
        $this->assertSame(2, data_get($proof, 'provider_call.successful_responses'));
        $this->assertSame(240, data_get($proof, 'usage.input_tokens'));
        $this->assertSame(40, data_get($proof, 'usage.output_tokens'));
        $this->assertTrue(data_get($proof, 'usage.present'));
        $this->assertTrue(data_get($proof, 'fair_mode.single_provider'));
        $this->assertTrue(data_get($proof, 'fair_mode.decide_disabled'));
        $this->assertTrue(data_get($proof, 'fair_mode.fallback_disabled'));
        $this->assertSame('atlas.hermes.executive_mission.v1', data_get($proof, 'governance.executive_mission_schema'));
        $this->assertSame('atlas.hermes.result_packet.v1', data_get($proof, 'governance.result_packet_schema'));
        $this->assertCount(2, $proof['calls']);
        foreach ($proof['calls'] as $call) {
            $this->assertFileIsReadable($this->storage.'/runs/'.$plan->runId().'/'.$call['stdout_path']);
            $this->assertFileIsReadable($this->storage.'/runs/'.$plan->runId().'/'.$call['stderr_path']);
            $this->assertNotEmpty($call['executive_mission_hash']);
            $this->assertNotEmpty($call['result_packet_hash']);
        }
    }

    public function test_provider_failure_is_named_and_still_persists_both_streams(): void
    {
        [$plan, $entry] = $this->manifest();
        $provider = Mockery::mock(HermesCliProvider::class);
        $provider->shouldReceive('runStreaming')->once()->andReturn(new AiProviderResult(
            ok: false,
            output: '',
            command: ['hermes', '-z', '[prompt:redacted]'],
            exitCode: 1,
            durationMs: 55,
            stdout: 'partial stdout',
            stderr: 'controlled provider failure',
            errorCode: 'provider_unavailable',
            errorMessage: 'controlled provider failure',
            metadata: [],
        ));

        $result = (new HermesOpenAiResponseAdapter($provider))->complete([
            'model' => 'kimi-k2.7',
            'messages' => [['role' => 'user', 'content' => 'probe']],
        ], $this->headers($plan, $entry));

        $this->assertSame(502, $result['status']);
        $this->assertSame('atlas_runtime_failed:provider_unavailable', data_get($result, 'body.error.message'));
        $proof = json_decode((string) file_get_contents(
            $entry['normalization']['scratch_dir'].'/.rivals_atlas_dev_bridge.json',
        ), true);
        $this->assertSame('failed', $proof['status']);
        $this->assertSame('atlas_runtime_failed:provider_unavailable', $proof['failure_reason']);
        $this->assertSame(['provider_unavailable'], data_get($proof, 'provider_call.error_codes'));
        $this->assertStringContainsString(
            'partial stdout',
            (string) file_get_contents($this->storage.'/runs/'.$plan->runId().'/'.$proof['calls'][0]['stdout_path']),
        );
        $this->assertStringContainsString(
            'controlled provider failure',
            (string) file_get_contents($this->storage.'/runs/'.$plan->runId().'/'.$proof['calls'][0]['stderr_path']),
        );
    }

    public function test_request_without_exact_manifest_binding_fails_before_provider_spend(): void
    {
        $provider = Mockery::mock(HermesCliProvider::class);
        $provider->shouldNotReceive('runStreaming');

        $result = (new HermesOpenAiResponseAdapter($provider))->complete([
            'model' => 'kimi-k2.7',
            'messages' => [['role' => 'user', 'content' => 'probe']],
        ], [
            'X-Atlas-Rivals-Run-Id' => 'missing_run',
            'X-Atlas-Rivals-Execution-Id' => 'ne_missing',
        ]);

        $this->assertSame(400, $result['status']);
        $this->assertSame('rivals_execution_binding_invalid', data_get($result, 'body.error.message'));
    }

    /** @return array{RunPlan,array<string,mixed>} */
    private function manifest(): array
    {
        $adapter = new InspectEvalsAdapter;
        $plan = RunPlan::make(
            $adapter->suiteId(),
            ['gsm8k_probe'],
            [(new ArmRegistry)->parse('verboo_kimi_k2_7@atlas_dev', $adapter->suiteId())],
            1,
            ['max_usd' => 0.0, 'max_minutes' => 30],
            42,
        );
        $data = $plan->data;
        $data['environment']['approve_provider_spend'] = true;
        $plan = RunPlan::fromArray($data);
        $plan->persist();
        $manifest = NativeExecutionManifest::fromPlan($plan, $adapter, $adapter->planCommands($plan));
        $manifest->persist();
        $entry = $manifest->entries()[0];
        File::ensureDirectoryExists($entry['normalization']['scratch_dir']);

        return [$plan, $entry];
    }

    /** @return array<string,string> */
    private function headers(RunPlan $plan, array $entry): array
    {
        return [
            'X-Atlas-Rivals-Run-Id' => $plan->runId(),
            'X-Atlas-Rivals-Execution-Id' => $entry['execution_id'],
        ];
    }

    private function providerResult(): AiProviderResult
    {
        return new AiProviderResult(
            ok: true,
            output: '42',
            command: ['hermes', '-z', '[prompt:redacted]'],
            exitCode: 0,
            durationMs: 123,
            stdout: "42\n",
            stderr: '',
            metadata: [
                'hermes_usage' => [
                    'input_tokens' => 120,
                    'output_tokens' => 20,
                    'total_tokens' => 140,
                ],
                'executive_mission' => [
                    'schema_version' => 'atlas.hermes.executive_mission.v1',
                    'mission_hash' => hash('sha256', 'mission'),
                ],
                'hermes_result_packet' => [
                    'schema_version' => 'atlas.hermes.result_packet.v1',
                    'result_hash' => hash('sha256', 'result'),
                ],
                'hermes_runtime' => [
                    'atlas_is_sovereign' => true,
                ],
                'cli_invocation' => [
                    'runtime_contract' => 'atlas.hermes_cli_provider.v1',
                ],
            ],
        );
    }
}
