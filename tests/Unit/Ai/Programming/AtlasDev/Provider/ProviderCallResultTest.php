<?php

declare(strict_types=1);

namespace Tests\Unit\Ai\Programming\AtlasDev\Provider;

use App\Services\Ai\Programming\AtlasDev\Provider\ProviderCallResult;
use InvalidArgumentException;
use PHPUnit\Framework\TestCase;

final class ProviderCallResultTest extends TestCase
{
    private function happy(): ProviderCallResult
    {
        return ProviderCallResult::fromStdout(
            runId: 'run-1',
            actualProvider: 'claude_cli',
            actualModelFamily: 'sonnet',
            exitStatus: 0,
            stdout: 'hello',
            stderr: '',
            durationMs: 1200,
            tokensIn: 100,
            tokensOut: 50,
            costEstimateUsd: 0.01,
            providerSafe: true,
        );
    }

    public function test_factory_computes_raw_response_hash_from_stdout(): void
    {
        $result = $this->happy();

        $this->assertSame(hash('sha256', 'hello'), $result->rawResponseHash);
        $this->assertTrue($result->ok());
    }

    public function test_ok_is_false_when_exit_status_non_zero(): void
    {
        $result = ProviderCallResult::fromStdout(
            runId: 'run', actualProvider: 'claude_cli', actualModelFamily: 'sonnet',
            exitStatus: 2, stdout: '', stderr: '', durationMs: 0,
        );

        $this->assertFalse($result->ok());
    }

    public function test_ok_is_false_when_provider_safe_false(): void
    {
        $result = ProviderCallResult::fromStdout(
            runId: 'run', actualProvider: 'claude_cli', actualModelFamily: 'sonnet',
            exitStatus: 0, stdout: 'x', stderr: '', durationMs: 1,
            providerSafe: false,
        );

        $this->assertFalse($result->ok());
    }

    public function test_ok_is_false_when_errors_present(): void
    {
        $result = ProviderCallResult::fromStdout(
            runId: 'run', actualProvider: 'claude_cli', actualModelFamily: 'sonnet',
            exitStatus: 0, stdout: 'x', stderr: '', durationMs: 1,
            errors: ['provider_exit_quirk'],
        );

        $this->assertFalse($result->ok());
    }

    public function test_canonical_array_contains_required_fields(): void
    {
        $canonical = $this->happy()->toCanonicalArray();
        $expected = [
            'actual_model_family',
            'actual_provider',
            'cost_estimate_usd',
            'duration_ms',
            'errors',
            'exit_status',
            'ok',
            'provider_safe',
            'raw_response_hash',
            'run_id',
            'schema_version',
            'stderr',
            'stdout',
            'tokens_in',
            'tokens_out',
        ];
        $this->assertSame($expected, array_keys($canonical));
        $this->assertSame('atlas.dev.provider_call_result.v1', $canonical['schema_version']);
    }

    public function test_hash_changes_when_stdout_changes(): void
    {
        $a = $this->happy();
        $b = ProviderCallResult::fromStdout(
            runId: 'run-1', actualProvider: 'claude_cli', actualModelFamily: 'sonnet',
            exitStatus: 0, stdout: 'world', stderr: '', durationMs: 1200,
            tokensIn: 100, tokensOut: 50, costEstimateUsd: 0.01,
        );
        $this->assertNotSame($a->hash(), $b->hash());
    }

    public function test_summary_array_omits_raw_stdout_and_stderr(): void
    {
        $result = ProviderCallResult::fromStdout(
            runId: 'run-1',
            actualProvider: 'claude_cli',
            actualModelFamily: 'sonnet',
            exitStatus: 0,
            stdout: 'raw provider output',
            stderr: 'raw stderr',
            durationMs: 1200,
        );

        $summary = $result->toSummaryArray();

        $this->assertArrayNotHasKey('stdout', $summary);
        $this->assertArrayNotHasKey('stderr', $summary);
        $this->assertSame(strlen('raw provider output'), $summary['stdout_bytes']);
        $this->assertSame(strlen('raw stderr'), $summary['stderr_bytes']);
        $this->assertSame(hash('sha256', 'raw provider output'), $summary['raw_response_hash']);
    }

    public function test_constructor_rejects_negative_duration(): void
    {
        $this->expectException(InvalidArgumentException::class);
        new ProviderCallResult(
            runId: 'run', actualProvider: 'claude_cli', actualModelFamily: 'sonnet',
            exitStatus: 0, stdout: '', stderr: '', durationMs: -1,
            tokensIn: null, tokensOut: null, costEstimateUsd: null,
            rawResponseHash: 'h', providerSafe: true,
        );
    }
}
