<?php

declare(strict_types=1);

namespace Tests\Unit\Ai\AutonomousEvolution\SelfModel\Training;

use App\Services\Ai\AutonomousEvolution\SelfModel\Training\AtlasLoopSelfModelTrainingPlanComposer;
use PHPUnit\Framework\TestCase;

/**
 * Proves the training-plan composer: deterministic plan_hash, fail-closed on a corpus without a hash,
 * held-out-fraction clamping to [0.1,0.5], and a source free of any train/spawn/network call.
 */
final class AtlasLoopSelfModelTrainingPlanComposerTest extends TestCase
{
    private function composer(): AtlasLoopSelfModelTrainingPlanComposer
    {
        return new AtlasLoopSelfModelTrainingPlanComposer;
    }

    public function test_plan_hash_is_deterministic_across_runs(): void
    {
        $corpus = ['corpus_hash' => 'corp-abc'];
        $plan1 = $this->composer()->compose($corpus, 'minimax-m3', ['domain' => 'code'], ['epochs' => 3]);
        $plan2 = $this->composer()->compose($corpus, 'minimax-m3', ['domain' => 'code'], ['epochs' => 3]);

        $this->assertSame('atlas.loop.self_model.training_plan.v1', $plan1['schema']);
        $this->assertSame('corp-abc', $plan1['corpus_hash']);
        $this->assertSame('code', $plan1['oracle_domain']);
        $this->assertSame($plan1['plan_hash'], $plan2['plan_hash'], 'identical inputs ⇒ identical plan_hash');
        $this->assertSame(json_encode($plan1), json_encode($plan2));
    }

    public function test_corpus_without_hash_is_blocked(): void
    {
        $result = $this->composer()->compose([], 'minimax-m3', ['domain' => 'code']);

        $this->assertSame('blocked', $result['status']);
        $this->assertSame('corpus_hash_missing', $result['reason']);
    }

    public function test_held_out_fraction_is_clamped(): void
    {
        $high = $this->composer()->compose(['corpus_hash' => 'h'], 'm', ['domain' => 'code'], ['held_out_fraction' => 0.8]);
        $this->assertSame(0.5, $high['held_out_fraction'], '0.8 clamps to 0.5');

        $low = $this->composer()->compose(['corpus_hash' => 'h'], 'm', ['domain' => 'code'], ['held_out_fraction' => 0.01]);
        $this->assertSame(0.1, $low['held_out_fraction'], '0.01 clamps to 0.1');

        $default = $this->composer()->compose(['corpus_hash' => 'h'], 'm', ['domain' => 'code']);
        $this->assertSame(0.2, $default['held_out_fraction'], 'default 0.2 when unset');
    }

    public function test_source_has_no_train_spawn_or_network_call(): void
    {
        $file = (new \ReflectionClass(AtlasLoopSelfModelTrainingPlanComposer::class))->getFileName();
        $source = (string) file_get_contents((string) $file);

        foreach (['Process', 'Http::', 'proc_open', 'shell_exec', 'exec(', '->train('] as $token) {
            $this->assertStringNotContainsString($token, $source, "composer must not contain '{$token}'");
        }
    }
}
