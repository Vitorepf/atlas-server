<?php

declare(strict_types=1);

namespace Tests\Unit\Ai\AutonomousEvolution\Discovery;

use App\Services\Ai\AutonomousEvolution\Discovery\AtlasLoopExtractClassObjectiveBuilder;
use Tests\TestCase;

/**
 * Pins the extract-class task contract: a refactor_* task (revert-recheck exempt) carrying BOTH
 * complexity_proof (fires the complexity branch) AND structural_proof (routes to the per-method
 * identity gate), allowing the target + the new class file only, freezing the sibling test.
 */
final class AtlasLoopExtractClassObjectiveBuilderTest extends TestCase
{
    public function test_builds_a_structural_extract_class_contract(): void
    {
        $spec = (new AtlasLoopExtractClassObjectiveBuilder())->build(
            'app/Services/Ai/Aaeos/AtlasAaeosThresholdLadderNormalizer.php',
            'tests/Unit/Ai/Aaeos/AtlasAaeosThresholdLadderNormalizerTest.php',
            'AtlasAaeosThresholdLadderNormalizer::normalize',
            17,
        );

        $payload = $spec['payload'];
        $acc = $payload['acceptance'];

        $this->assertSame('refactor_extract_class', $payload['objective_kind']);
        $this->assertSame('framework', $payload['materializer']);

        // The new class rides in the SAME directory as the target, name = <Target>Support.php.
        $newPath = 'app/Services/Ai/Aaeos/AtlasAaeosThresholdLadderNormalizerSupport.php';
        $this->assertSame($newPath, $payload['extract_class_new_path']);

        // Provider may edit the TARGET and CREATE the new class — nothing else.
        $this->assertSame(
            ['app/Services/Ai/Aaeos/AtlasAaeosThresholdLadderNormalizer.php', $newPath],
            $acc['allowed_globs'],
        );
        // The sibling test is FROZEN (the loop can never edit its own behavior anchor).
        $this->assertContains('tests/Unit/Ai/Aaeos/AtlasAaeosThresholdLadderNormalizerTest.php', $acc['frozen_globs']);

        // BOTH proofs set: complexity (fires the branch) + structural (swaps to the identity gate).
        $this->assertTrue($acc['complexity_proof']);
        $this->assertTrue($acc['structural_proof']);
        $this->assertSame('minimize', $acc['metric_kind']);
        // Behavior-preserving: revert-recheck off (proof is the AST drop, not diff-earned).
        $this->assertFalse($acc['revert_recheck']);

        // Objective names the exact new path + the worst method (so the provider stays in scope).
        $this->assertStringContainsString($newPath, $spec['objective']);
        $this->assertStringContainsString('AtlasAaeosThresholdLadderNormalizer::normalize', $spec['objective']);
        $this->assertNotSame('', $spec['acceptance_hash']);
    }

    public function test_new_class_path_derivation_handles_root_and_nested(): void
    {
        $b = new AtlasLoopExtractClassObjectiveBuilder();
        $this->assertSame('app/Foo/BarSupport.php', $b->newClassPath('app/Foo/Bar.php'));
        $this->assertSame('BazSupport.php', $b->newClassPath('Baz.php'));
    }
}
