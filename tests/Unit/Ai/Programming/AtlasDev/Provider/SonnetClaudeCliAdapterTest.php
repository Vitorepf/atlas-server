<?php

declare(strict_types=1);

namespace Tests\Unit\Ai\Programming\AtlasDev\Provider;

use App\Services\Ai\Programming\AtlasDev\Provider\ProviderCallResult;
use App\Services\Ai\Programming\AtlasDev\Provider\ProviderLockViolationException;
use App\Services\Ai\Programming\AtlasDev\Provider\SonnetClaudeCliAdapter;
use App\Services\Ai\Programming\AtlasDev\Schemas\ProviderPromptProjection;
use InvalidArgumentException;
use PHPUnit\Framework\TestCase;
use ReflectionClass;

final class SonnetClaudeCliAdapterTest extends TestCase
{
    use AtlasDevProviderFixtures;

    private function makeAdapter(?FakeClaudeCliGateway $gateway = null): array
    {
        $gateway ??= new FakeClaudeCliGateway;
        $adapter = new SonnetClaudeCliAdapter($gateway);

        return [$adapter, $gateway];
    }

    public function test_execute_one_call_returns_provider_call_result_for_valid_inputs(): void
    {
        [$adapter, $gateway] = $this->makeAdapter();
        $projection = $this->buildSendableProjection();

        $gateway->queue($this->gatewayResponse(stdout: "no_patch_needed: true\nreason: target already fixed\n"));

        $result = $adapter->executeOneCall(
            promptProjection: $projection,
            taskContract: $this->taskContractFixture(),
            workspace: '/tmp/atlas-dev-test',
        );

        $this->assertInstanceOf(ProviderCallResult::class, $result);
        $this->assertSame('claude_cli', $result->actualProvider);
        $this->assertSame('sonnet', $result->actualModelFamily);
        $this->assertTrue($result->ok());
        $this->assertSame(hash('sha256', "no_patch_needed: true\nreason: target already fixed\n"), $result->rawResponseHash);
        $this->assertCount(1, $gateway->requests);
        $this->assertSame($projection->runId, $gateway->requests[0]->runId);
        $this->assertFalse($gateway->requests[0]->fallbackAllowed);
    }

    public function test_method_signature_only_accepts_typed_prompt_projection_never_raw_string(): void
    {
        $reflection = new ReflectionClass(SonnetClaudeCliAdapter::class);
        $execute = $reflection->getMethod('executeOneCall');

        $params = $execute->getParameters();
        $this->assertNotEmpty($params, 'executeOneCall must declare at least one parameter.');

        $first = $params[0];
        $firstType = $first->getType();
        $this->assertNotNull($firstType);
        $this->assertSame(
            ProviderPromptProjection::class,
            (string) $firstType,
            'executeOneCall must accept ProviderPromptProjection as the first parameter.',
        );

        // The first parameter (the prompt) MUST be the typed projection.
        // String parameters are only allowed for ancillary transport details
        // (workspace) — never to carry an artisanal prompt.
        foreach ($params as $index => $param) {
            $type = (string) ($param->getType() ?? '');
            if ($index === 0) {
                $this->assertNotSame('string', $type, 'Prompt parameter must not be a raw string.');
            }
            if ($type === 'string') {
                $this->assertSame(
                    'workspace',
                    $param->getName(),
                    'Only the workspace parameter may be a raw string; prompts are always typed.',
                );
            }
        }
    }

    public function test_adapter_rejects_projection_that_failed_quality_checks(): void
    {
        [$adapter] = $this->makeAdapter();
        $projection = $this->buildSendableProjection(
            miniSpec: $this->miniSpecFixture(['acceptance_criteria' => []]),
        );

        $this->assertFalse($projection->isSendable());
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('quality_checks');

        $adapter->executeOneCall(
            promptProjection: $projection,
            taskContract: $this->taskContractFixture(),
            workspace: '/tmp/atlas-dev-test',
        );
    }

    public function test_adapter_blocks_fallback_allowed_true_on_task_contract(): void
    {
        [$adapter] = $this->makeAdapter();
        $projection = $this->buildSendableProjection();

        $this->expectException(ProviderLockViolationException::class);
        $this->expectExceptionMessage('fallback_allowed=true');

        $adapter->executeOneCall(
            promptProjection: $projection,
            taskContract: $this->taskContractFixture([
                'provider_lock' => [
                    'provider' => 'claude_cli',
                    'model_family' => 'sonnet',
                    'fallback_allowed' => true,
                ],
            ]),
            workspace: '/tmp/atlas-dev-test',
        );
    }

    public function test_adapter_blocks_when_task_contract_has_different_provider(): void
    {
        [$adapter] = $this->makeAdapter();
        $projection = $this->buildSendableProjection();

        $this->expectException(ProviderLockViolationException::class);
        $this->expectExceptionMessage('codex_cli');

        $adapter->executeOneCall(
            promptProjection: $projection,
            taskContract: $this->taskContractFixture([
                'provider_lock' => [
                    'provider' => 'codex_cli',
                    'model_family' => 'sonnet',
                    'fallback_allowed' => false,
                ],
            ]),
            workspace: '/tmp/atlas-dev-test',
        );
    }

    public function test_adapter_raises_when_gateway_reports_different_provider(): void
    {
        [$adapter, $gateway] = $this->makeAdapter();
        $projection = $this->buildSendableProjection();
        $gateway->queue($this->gatewayResponse(
            stdout: "no_patch_needed: true\nreason: ok\n",
            actualProvider: 'codex_cli',
        ));

        $this->expectException(ProviderLockViolationException::class);
        $this->expectExceptionMessage('provider=codex_cli');

        $adapter->executeOneCall(
            promptProjection: $projection,
            taskContract: $this->taskContractFixture(),
            workspace: '/tmp/atlas-dev-test',
        );
    }

    public function test_adapter_raises_when_gateway_reports_different_model_family(): void
    {
        [$adapter, $gateway] = $this->makeAdapter();
        $projection = $this->buildSendableProjection();
        $gateway->queue($this->gatewayResponse(
            stdout: "no_patch_needed: true\nreason: ok\n",
            actualModelFamily: 'haiku',
        ));

        $this->expectException(ProviderLockViolationException::class);
        $this->expectExceptionMessage('model_family=haiku');

        $adapter->executeOneCall(
            promptProjection: $projection,
            taskContract: $this->taskContractFixture(),
            workspace: '/tmp/atlas-dev-test',
        );
    }

    public function test_adapter_surfaces_provider_exit_status_in_errors(): void
    {
        [$adapter, $gateway] = $this->makeAdapter();
        $projection = $this->buildSendableProjection();
        $gateway->queue($this->gatewayResponse(stdout: '', stderr: 'fatal', exitCode: 2));

        $result = $adapter->executeOneCall(
            promptProjection: $projection,
            taskContract: $this->taskContractFixture(),
            workspace: '/tmp/atlas-dev-test',
        );

        $this->assertSame(2, $result->exitStatus);
        $this->assertFalse($result->ok());
        $this->assertContains('provider_exit_2', $result->errors);
    }

    public function test_adapter_flags_empty_stdout_with_zero_exit(): void
    {
        [$adapter, $gateway] = $this->makeAdapter();
        $projection = $this->buildSendableProjection();
        $gateway->queue($this->gatewayResponse(stdout: '', exitCode: 0));

        $result = $adapter->executeOneCall(
            promptProjection: $projection,
            taskContract: $this->taskContractFixture(),
            workspace: '/tmp/atlas-dev-test',
        );

        $this->assertFalse($result->ok());
        $this->assertContains('empty_stdout_with_zero_exit', $result->errors);
    }

    public function test_adapter_rejects_empty_workspace(): void
    {
        [$adapter] = $this->makeAdapter();
        $projection = $this->buildSendableProjection();

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('workspace');

        $adapter->executeOneCall(
            promptProjection: $projection,
            taskContract: $this->taskContractFixture(),
            workspace: '   ',
        );
    }
}
