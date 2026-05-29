<?php

declare(strict_types=1);

namespace Tests\Unit\Ai\Programming;

use App\Services\Ai\Programming\AtlasForgeCodexCliInvocationDriver;
use App\Services\Ai\Programming\AtlasForgeProviderCommandAllowlistService;
use App\Services\Ai\Programming\AtlasForgeProviderInvocationFailureClassifier;
use App\Services\Ai\Programming\AtlasForgeProviderProcessRunner;
use Tests\TestCase;

/**
 * SEC-003 provider-attributed diff capture in the base CLI driver.
 *
 * The driver must report changed_files as the real before/after delta of the
 * worktree the provider ran in — never raw porcelain (which would launder
 * pre-existing dirty files), and only when the provider was actually called.
 * This is the evidence the provider-proof invariant in
 * AtlasForgeProviderInvocationService::finalizeExecuted depends on:
 *   - external provider call + empty diff  => suspect_no_diff (blocked)
 *   - diff + no provider call              => unattributed_diff (blocked)
 *   - provider call + real diff            => executed
 */
final class AtlasForgeProviderProofDiffCaptureTest extends TestCase
{
    public function test_changed_files_is_the_provider_authored_delta_not_raw_porcelain(): void
    {
        // Pre-existing dirty file (config.php) must NOT be attributed to the
        // provider; only the file the provider newly touched (Forge.php) is.
        $driver = $this->driver(
            providerCalled: true,
            before: ['config.php' => ' M'],
            after: ['config.php' => ' M', 'app/Forge.php' => '??'],
        );

        $result = $driver->invoke(['model' => 'gpt-5.5', 'cwd' => '/tmp/worktree', 'prompt' => 'do it']);

        $this->assertTrue($result['provider_called']);
        $this->assertSame(['app/Forge.php'], $result['changed_files']);
    }

    public function test_real_provider_call_with_no_delta_reports_empty_changed_files(): void
    {
        // Drives the suspect_no_diff classification downstream.
        $driver = $this->driver(
            providerCalled: true,
            before: ['config.php' => ' M'],
            after: ['config.php' => ' M'],
        );

        $result = $driver->invoke(['model' => 'gpt-5.5', 'cwd' => '/tmp/worktree', 'prompt' => 'do it']);

        $this->assertTrue($result['provider_called']);
        $this->assertSame([], $result['changed_files']);
    }

    public function test_no_provider_call_never_attributes_a_diff(): void
    {
        // Even if the worktree gained files, with provider_called=false the
        // driver must not attribute them (prevents laundering a diff as if a
        // provider authored it).
        $driver = $this->driver(
            providerCalled: false,
            before: [],
            after: ['app/Forge.php' => '??'],
        );

        $result = $driver->invoke(['model' => 'gpt-5.5', 'cwd' => '/tmp/worktree', 'prompt' => 'do it']);

        $this->assertFalse($result['provider_called']);
        $this->assertSame([], $result['changed_files']);
    }

    /**
     * @param  array<string,string>  $before
     * @param  array<string,string>  $after
     */
    private function driver(bool $providerCalled, array $before, array $after): AtlasForgeCodexCliInvocationDriver
    {
        $allowlist = new class extends AtlasForgeProviderCommandAllowlistService
        {
            public function evaluate(array $argv, ?string $cwd = null): array
            {
                return ['allowed' => true, 'blockers' => []];
            }
        };

        $runner = new class($providerCalled) extends AtlasForgeProviderProcessRunner
        {
            public function __construct(private readonly bool $providerCalled) {}

            public function run(array $request): array
            {
                return [
                    'status' => self::STATUS_COMPLETED,
                    'provider_called' => $this->providerCalled,
                    'external_provider_call' => $this->providerCalled,
                    'exit_code' => 0,
                    'duration_ms' => 5,
                    'stdout_excerpt' => 'ok',
                    'stderr_excerpt' => '',
                    'stdout_hash' => hash('sha256', 'ok'),
                    'stderr_hash' => hash('sha256', ''),
                ];
            }
        };

        return new class($allowlist, $runner, new AtlasForgeProviderInvocationFailureClassifier(), $before, $after) extends AtlasForgeCodexCliInvocationDriver
        {
            private int $calls = 0;

            /**
             * @param  array<string,string>  $before
             * @param  array<string,string>  $after
             */
            public function __construct(
                AtlasForgeProviderCommandAllowlistService $allowlist,
                AtlasForgeProviderProcessRunner $runner,
                AtlasForgeProviderInvocationFailureClassifier $classifier,
                private readonly array $before,
                private readonly array $after,
            ) {
                parent::__construct($allowlist, $runner, $classifier);
            }

            public function configured(): array
            {
                return ['configured' => true, 'blockers' => []];
            }

            protected function buildArgv(array $request): array
            {
                return ['codex', '--non-interactive'];
            }

            protected function worktreeState(?string $cwd): array
            {
                // First call = before the provider; second = after.
                return $this->calls++ === 0 ? $this->before : $this->after;
            }
        };
    }
}
