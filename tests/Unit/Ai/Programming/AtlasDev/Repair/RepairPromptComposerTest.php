<?php

declare(strict_types=1);

namespace Tests\Unit\Ai\Programming\AtlasDev\Repair;

use App\Services\Ai\Programming\AtlasDev\Repair\RepairPromptComposer;
use InvalidArgumentException;
use PHPUnit\Framework\TestCase;

final class RepairPromptComposerTest extends TestCase
{
    public function test_compose_preserves_allowed_and_forbidden_files(): void
    {
        $composer = new RepairPromptComposer();
        $contract = RepairFixtureFactory::lightTaskContract(
            allowedFiles: ['app/Foo.php', 'tests/Unit/FooTest.php'],
            forbiddenFiles: ['.env', 'config/secrets.php'],
        );
        $prompt = RepairFixtureFactory::providerPromptProjection(
            taskContractHash: $contract->taskContractHash,
            allowedFiles: ['app/Foo.php', 'tests/Unit/FooTest.php'],
            forbiddenFiles: ['.env', 'config/secrets.php'],
        );
        $capsule = RepairFixtureFactory::failureCapsule(
            taskContractHash: $contract->taskContractHash,
        );

        $repair = $composer->compose($prompt, $capsule, $contract, attemptIndex: 1, maxAttempts: 2);

        $this->assertSame($prompt->sections->allowedFiles, $repair->sections->allowedFiles);
        $this->assertSame($prompt->sections->forbiddenFiles, $repair->sections->forbiddenFiles);
    }

    public function test_compose_appends_repair_capsule_and_stop_conditions(): void
    {
        $composer = new RepairPromptComposer();
        $contract = RepairFixtureFactory::lightTaskContract();
        $prompt = RepairFixtureFactory::providerPromptProjection(taskContractHash: $contract->taskContractHash);
        $capsule = RepairFixtureFactory::failureCapsule(taskContractHash: $contract->taskContractHash);

        $repair = $composer->compose($prompt, $capsule, $contract, attemptIndex: 1, maxAttempts: 2);

        $this->assertStringContainsString('# Repair Capsule', $repair->renderedPromptText);
        $this->assertStringContainsString($capsule->failureSignature, $repair->renderedPromptText);
        $this->assertStringContainsString('stop_if_same_failure_signature_repeats', $repair->renderedPromptText);
        $this->assertContains('stop_if_same_failure_signature_repeats', $repair->sections->stopConditions);
        $this->assertContains('stop_if_diff_grows_beyond_previous_attempt', $repair->sections->stopConditions);
        $this->assertContains('stop_if_max_repair_attempts_reached', $repair->sections->stopConditions);
    }

    public function test_compose_hash_differs_from_original(): void
    {
        $composer = new RepairPromptComposer();
        $contract = RepairFixtureFactory::lightTaskContract();
        $prompt = RepairFixtureFactory::providerPromptProjection(taskContractHash: $contract->taskContractHash);
        $capsule = RepairFixtureFactory::failureCapsule(taskContractHash: $contract->taskContractHash);

        $repair = $composer->compose($prompt, $capsule, $contract, attemptIndex: 1, maxAttempts: 2);

        $this->assertNotSame($prompt->promptProjectionHash, $repair->promptProjectionHash);
        $this->assertNotSame($prompt->renderedPromptHash, $repair->renderedPromptHash);
        $this->assertSame($prompt->runId, $repair->runId);
        $this->assertSame(
            $capsule->capsuleHash,
            $repair->upstreamHashes['previous_failure_capsule_hash'],
        );
        $this->assertSame('1', $repair->upstreamHashes['repair_attempt_index']);
    }

    public function test_compose_includes_provider_lock_for_audit(): void
    {
        $composer = new RepairPromptComposer();
        $contract = RepairFixtureFactory::lightTaskContract();
        $prompt = RepairFixtureFactory::providerPromptProjection(taskContractHash: $contract->taskContractHash);
        $capsule = RepairFixtureFactory::failureCapsule(taskContractHash: $contract->taskContractHash);

        $repair = $composer->compose($prompt, $capsule, $contract, attemptIndex: 1, maxAttempts: 2);

        $this->assertStringContainsString('claude_cli/sonnet', $repair->renderedPromptText);
        $this->assertStringContainsString('fallback_allowed=false', $repair->renderedPromptText);
    }

    public function test_run_id_mismatch_rejects(): void
    {
        $this->expectException(InvalidArgumentException::class);

        $composer = new RepairPromptComposer();
        $contract = RepairFixtureFactory::lightTaskContract(runId: 'a');
        $prompt = RepairFixtureFactory::providerPromptProjection(runId: 'a', taskContractHash: $contract->taskContractHash);
        // capsule has run_id = 'b' (mismatch)
        $capsule = RepairFixtureFactory::failureCapsule(runId: 'b', taskContractHash: $contract->taskContractHash);

        $composer->compose($prompt, $capsule, $contract, attemptIndex: 1, maxAttempts: 2);
    }

    public function test_attempt_index_lower_than_one_is_rejected(): void
    {
        $this->expectException(InvalidArgumentException::class);

        $composer = new RepairPromptComposer();
        $contract = RepairFixtureFactory::lightTaskContract();
        $prompt = RepairFixtureFactory::providerPromptProjection(taskContractHash: $contract->taskContractHash);
        $capsule = RepairFixtureFactory::failureCapsule(taskContractHash: $contract->taskContractHash);

        $composer->compose($prompt, $capsule, $contract, attemptIndex: 0, maxAttempts: 2);
    }
}
