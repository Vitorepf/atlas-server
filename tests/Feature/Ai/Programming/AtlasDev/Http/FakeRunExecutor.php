<?php

declare(strict_types=1);

namespace Tests\Feature\Ai\Programming\AtlasDev\Http;

use App\Http\Controllers\AtlasDev\Support\RunExecutionResult;
use App\Http\Controllers\AtlasDev\Support\RunExecutor;
use App\Services\Ai\Programming\AtlasDev\Schemas\LightTaskContract;
use App\Services\Ai\Programming\AtlasDev\Schemas\OperationEnvelope;
use App\Services\Ai\Programming\AtlasDev\Schemas\ProviderPromptProjection;

/**
 * Test-only RunExecutor that records each call and returns a canned result.
 * Bound into the container by RunEndpointTest so the HTTP layer is exercised
 * without spawning a real provider process.
 */
final class FakeRunExecutor implements RunExecutor
{
    /** @var list<array{run_id:string, envelope_hash:string, task_contract_hash:string, prompt_hash:string}> */
    public array $calls = [];

    public function __construct(
        private readonly RunExecutionResult $result,
    ) {}

    public function execute(
        OperationEnvelope $envelope,
        LightTaskContract $taskContract,
        ProviderPromptProjection $promptProjection,
        string $runId,
    ): RunExecutionResult {
        $this->calls[] = [
            'run_id' => $runId,
            'envelope_hash' => $envelope->envelopeHash,
            'task_contract_hash' => $taskContract->taskContractHash,
            'prompt_hash' => $promptProjection->promptProjectionHash,
        ];

        return $this->result;
    }

    public static function passing(): self
    {
        return new self(new RunExecutionResult(
            completionState: 'passed',
            scopeGuardStatus: 'passed',
            verificationStatus: 'passed',
            persistedReceiptPaths: [
                'verification_receipt.json' => '/tmp/atlas-dev-fake/verification_receipt.json',
            ],
            providerCallSummary: [
                'provider' => 'claude_cli',
                'model_family' => 'sonnet',
                'provider_calls' => 1,
                'exit_code' => 0,
                'duration_ms' => 1200,
                'tokens_in' => 100,
                'tokens_out' => 50,
                'estimated_cost_usd' => 0.01,
                'error_codes' => [],
            ],
            verificationReceiptHash: str_repeat('a', 64),
            scopeGuardReceiptHash: str_repeat('b', 64),
            diffHash: str_repeat('c', 64),
        ));
    }
}
