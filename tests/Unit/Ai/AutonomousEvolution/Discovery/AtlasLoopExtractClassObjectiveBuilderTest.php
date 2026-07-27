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
            'app/Services/Ai/Aaeos/AtlasThresholdLadderNormalizer.php',
            'tests/Unit/Ai/Aaeos/AtlasAaeosThresholdLadderNormalizerTest.php',
            'AtlasThresholdLadderNormalizer::normalize',
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
            ['app/Services/Ai/Aaeos/AtlasThresholdLadderNormalizer.php', $newPath],
            $acc['allowed_globs'],
        );
        // The sibling test is FROZEN (the loop can never edit its own behavior anchor).
        $this->assertContains('tests/Unit/Ai/Aaeos/AtlasAaeosThresholdLadderNormalizerTest.php', $acc['frozen_globs']);

        // BOTH proofs set: complexity (fires the branch) + structural (swaps to the identity gate).
        $this->assertTrue($acc['complexity_proof']);
        $this->assertTrue($acc['structural_proof']);
        $this->assertTrue($acc['quality_bar_gate']);
        $this->assertSame(9.0, (float) $acc['quality_bar']);
        $this->assertSame('minimize', $acc['metric_kind']);
        // Behavior-preserving: revert-recheck off (proof is the AST drop, not diff-earned).
        $this->assertFalse($acc['revert_recheck']);

        // Objective names the exact new path + the worst method (so the provider stays in scope).
        $this->assertStringContainsString($newPath, $spec['objective']);
        $this->assertStringContainsString('AtlasThresholdLadderNormalizer::normalize', $spec['objective']);
        $this->assertNotSame('', $spec['acceptance_hash']);
    }

    public function test_new_class_path_derivation_handles_root_and_nested(): void
    {
        $b = new AtlasLoopExtractClassObjectiveBuilder();
        $this->assertSame('app/Foo/BarSupport.php', $b->newClassPath('app/Foo/Bar.php'));
        $this->assertSame('BazSupport.php', $b->newClassPath('Baz.php'));
    }

    public function test_namespace_is_derived_from_psr4_path_and_injected_into_objective(): void
    {
        $b = new AtlasLoopExtractClassObjectiveBuilder();
        // PSR-4 App\ => app/ : the exact namespace removes the #1 extract-class failure (autoload fatal).
        $this->assertSame('App\\Services\\Ai\\Company\\Ventures\\Comprehension', $b->namespaceFromPath('app/Services/Ai/Company/Ventures/Comprehension/WorkspaceReaderSupport.php'));
        $this->assertSame('App', $b->namespaceFromPath('FooSupport.php'));

        $spec = $b->build(
            'app/Services/Ai/Company/Ventures/Comprehension/WorkspaceReader.php',
            'tests/Feature/Ai/Company/Ventures/Comprehension/WorkspaceReaderTest.php',
            'WorkspaceReader::files', 13,
        );
        // The objective must state the EXACT namespace so the new class autoloads.
        $this->assertStringContainsString('namespace App\\Services\\Ai\\Company\\Ventures\\Comprehension', $spec['objective']);
        $this->assertStringContainsString('final class WorkspaceReaderSupport', $spec['objective']);
    }

    public function test_step_one_is_byte_identical_and_higher_steps_spin_out_distinct_classes(): void
    {
        $b = new AtlasLoopExtractClassObjectiveBuilder();
        // Step 1 (default) keeps the historical un-numbered name.
        $this->assertSame('app/Foo/BarSupport.php', $b->newClassPath('app/Foo/Bar.php', 1));
        $this->assertSame('app/Foo/BarSupport.php', $b->newClassPath('app/Foo/Bar.php'));
        // Steps 2/3 spin out DISTINCT class files in the same directory.
        $this->assertSame('app/Foo/BarSupport2.php', $b->newClassPath('app/Foo/Bar.php', 2));
        $this->assertSame('app/Foo/BarSupport3.php', $b->newClassPath('app/Foo/Bar.php', 3));

        // A step-2 build threads the numbered name through path, class, namespace and objective.
        $spec = $b->build(
            'app/Services/Ai/Aaeos/AtlasThresholdLadderNormalizer.php',
            'tests/Unit/Ai/Aaeos/AtlasAaeosThresholdLadderNormalizerTest.php',
            'AtlasThresholdLadderNormalizer::normalize',
            17,
            null,
            2,
        );
        $newPath2 = 'app/Services/Ai/Aaeos/AtlasAaeosThresholdLadderNormalizerSupport2.php';
        $this->assertSame($newPath2, $spec['payload']['extract_class_new_path']);
        $this->assertSame(
            ['app/Services/Ai/Aaeos/AtlasThresholdLadderNormalizer.php', $newPath2],
            $spec['payload']['acceptance']['allowed_globs'],
        );
        $this->assertStringContainsString('final class AtlasAaeosThresholdLadderNormalizerSupport2', $spec['objective']);

        // Distinct steps yield distinct acceptance hashes (so the corpus never dedups two chain links).
        $spec1 = $b->build(
            'app/Services/Ai/Aaeos/AtlasThresholdLadderNormalizer.php',
            'tests/Unit/Ai/Aaeos/AtlasAaeosThresholdLadderNormalizerTest.php',
            'AtlasThresholdLadderNormalizer::normalize',
            17,
            null,
            1,
        );
        $this->assertNotSame($spec1['acceptance_hash'], $spec['acceptance_hash']);
    }

    public function test_next_available_step_picks_lowest_free_and_returns_null_when_exhausted(): void
    {
        $b = new AtlasLoopExtractClassObjectiveBuilder();
        $target = 'app/Foo/Bar.php';

        // Nothing exists yet => step 1.
        $this->assertSame(1, $b->nextAvailableStep($target, static fn (string $rel): bool => false, 3));

        // Support.php taken => step 2.
        $taken = ['app/Foo/BarSupport.php' => true];
        $this->assertSame(2, $b->nextAvailableStep($target, static fn (string $rel): bool => isset($taken[$rel]), 3));

        // Support.php + Support2.php taken => step 3.
        $taken['app/Foo/BarSupport2.php'] = true;
        $this->assertSame(3, $b->nextAvailableStep($target, static fn (string $rel): bool => isset($taken[$rel]), 3));

        // All steps up to budget taken => null (chain exhausted; caller falls through to in-place).
        $taken['app/Foo/BarSupport3.php'] = true;
        $this->assertNull($b->nextAvailableStep($target, static fn (string $rel): bool => isset($taken[$rel]), 3));
    }
}
