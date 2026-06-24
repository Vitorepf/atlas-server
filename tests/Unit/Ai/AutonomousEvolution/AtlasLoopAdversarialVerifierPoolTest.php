<?php

declare(strict_types=1);

namespace Tests\Unit\Ai\AutonomousEvolution;

use App\Services\Ai\AutonomousEvolution\AtlasLoopAdversarialVerifierPool;
use App\Services\Ai\AutonomousEvolution\AtlasLoopProviderRouter;
use Tests\TestCase;

final class AtlasLoopAdversarialVerifierPoolTest extends TestCase
{
    public function test_flag_off_review_is_byte_identical_to_primary_verifier_path(): void
    {
        config()->set('atlas.loop.adversarial_verifier_pool_enabled', false);
        $primary = $this->primaryVerdict();
        $proposal = $this->proposal([
            'adversarial_verifier_results' => [
                ['provider' => 'codex', 'lens' => 'correctness', 'passes' => false, 'reason' => 'would_have_dissented'],
            ],
        ]);

        $reviewed = (new AtlasLoopAdversarialVerifierPool)->reviewVerdict($proposal, $primary);

        $this->assertSame($primary, $reviewed);
    }

    public function test_single_dissent_forces_parked_for_review_with_two_passes_and_one_dissent(): void
    {
        config()->set('atlas.loop.adversarial_verifier_pool_enabled', true);
        $pool = new AtlasLoopAdversarialVerifierPool(new AtlasLoopProviderRouter);

        $reviewed = $pool->reviewVerdict($this->proposal(), $this->primaryVerdict(), [
            'providers' => ['codex', 'claude', 'minimax'],
            'verifier_results' => [
                ['provider' => 'codex', 'lens' => 'correctness', 'passes' => true, 'reason' => 'no_hole_found'],
                ['provider' => 'claude', 'lens' => 'security', 'passes' => true, 'reason' => 'no_hole_found'],
                ['provider' => 'minimax', 'lens' => 'maintainability', 'passes' => false, 'reason' => 'found_uncovered_edge'],
            ],
        ]);

        $this->assertSame('parked_for_review', $reviewed['outcome']);
        $this->assertContains('adversarial_verifier_pool_dissent', $reviewed['reasons']);
        $this->assertFalse($reviewed['adversarial_verifier_pool']['passes']);
        $this->assertCount(3, $reviewed['adversarial_verifier_pool']['line_items']);
        $this->assertStringContainsString('found_uncovered_edge', implode(',', $reviewed['adversarial_verifier_pool']['consensus']['dissents']));
    }

    public function test_unanimous_adversarial_pool_keeps_primary_verified_and_records_receipt(): void
    {
        config()->set('atlas.loop.adversarial_verifier_pool_enabled', true);
        $pool = new AtlasLoopAdversarialVerifierPool(new AtlasLoopProviderRouter);

        $reviewed = $pool->reviewVerdict($this->proposal(), $this->primaryVerdict(), [
            'providers' => ['codex', 'claude', 'minimax'],
            'verifier_results' => [
                ['provider' => 'codex', 'lens' => 'correctness', 'passes' => true, 'reason' => 'pass'],
                ['provider' => 'claude', 'lens' => 'security', 'passes' => true, 'reason' => 'pass'],
                ['provider' => 'minimax', 'lens' => 'maintainability', 'passes' => true, 'reason' => 'pass'],
            ],
        ]);

        $this->assertSame('independently_verified', $reviewed['outcome']);
        $this->assertTrue($reviewed['adversarial_verifier_pool']['passes']);
        $this->assertSame('adversarial_pool_unanimous', $reviewed['adversarial_verifier_pool']['reason']);
    }

    public function test_verifier_configurations_are_adversarial_and_provider_diverse(): void
    {
        config()->set('atlas.loop.adversarial_verifier_pool_enabled', true);
        $configs = (new AtlasLoopAdversarialVerifierPool(new AtlasLoopProviderRouter))
            ->verifierConfigurations($this->proposal(), ['providers' => ['codex', 'claude']]);

        $this->assertGreaterThanOrEqual(2, count($configs));
        $this->assertContains('codex', array_column($configs, 'provider'));
        $this->assertContains('claude', array_column($configs, 'provider'));
        $this->assertContains('adversarial_find_the_hole:correctness', array_column($configs, 'prompt_frame'));
    }

    public function test_pool_never_references_the_frozen_judge_mutation_path(): void
    {
        $source = (string) file_get_contents(app_path('Services/Ai/AutonomousEvolution/AtlasLoopAdversarialVerifierPool.php'));

        $this->assertStringNotContainsString('AtlasEvolutionFrozenJudge', $source);
        $this->assertStringNotContainsString('mutate(', $source);
        $this->assertStringNotContainsString('->mutate', $source);
    }

    /**
     * @param  array<string,mixed>  $overrides
     * @return array<string,mixed>
     */
    private function proposal(array $overrides = []): array
    {
        return $overrides + [
            'proposal_hash' => 'proposal-123',
            'provider' => 'codex',
            'mode' => 'deadcode',
            'objective_kind' => 'verification',
            'proposal' => ['diff_text' => "--- a/app/Foo.php\n+++ b/app/Foo.php\n"],
        ];
    }

    /**
     * @return array<string,mixed>
     */
    private function primaryVerdict(): array
    {
        return [
            'schema_version' => 'atlas.loop.proposal_independent_verdict.v1',
            'outcome' => 'independently_verified',
            'reasons' => ['independently_verified'],
            'merged_to_main' => false,
            'checks' => ['acceptance_green' => true, 'revert_red' => true],
        ];
    }
}
