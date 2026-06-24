<?php

declare(strict_types=1);

namespace Tests\Unit\Ai\AutonomousEvolution;

use App\Services\Ai\AutonomousEvolution\Discovery\AtlasLoopScopeComprehensionModel;
use App\Services\Ai\AutonomousEvolution\Feedback\AtlasLoopGiveBackPatternMiner;
use App\Services\Ai\AutonomousEvolution\Feedback\AtlasLoopGiveBackToReplenisherFeedback;
use App\Services\Ai\SelfConstruction\AgentControlPlaneTaskQueueOrchestrator;
use App\Services\Ai\SelfConstruction\AtlasTaskBrainReplenisher;
use App\Services\Ai\SelfConstruction\AtlasTaskPacketQualityInspector;
use Tests\TestCase;

/**
 * Proves the give_back→replenisher feedback wire: flag OFF ⇒ the Replenisher's round context is byte-identical
 * (no give_back_facts key); flag ON ⇒ the context gains exactly the give_back_facts key carrying the miner
 * output, with every pre-existing key unchanged in shape (additive — empty key diff).
 */
final class AtlasLoopGiveBackToReplenisherFeedbackTest extends TestCase
{
    /** A miner over a fixed in-memory reader — deterministic FACTs, no disk/DB. */
    private function miner(): AtlasLoopGiveBackPatternMiner
    {
        $reader = new class
        {
            /** @return list<array<string,mixed>> */
            public function recentOutcomes(int $limit): array
            {
                return [
                    ['packet_class' => 'Foo', 'outcome' => 'give_back', 'reason' => 'forbidden_self_target', 'worker' => 'claude-1'],
                    ['packet_class' => 'Foo', 'outcome' => 'success', 'reason' => '', 'worker' => 'claude-1'],
                    ['packet_class' => 'Bar', 'outcome' => 'give_back', 'reason' => 'unsatisfiable', 'worker' => 'codex-1'],
                ];
            }
        };

        return new AtlasLoopGiveBackPatternMiner($reader);
    }

    private function replenisher(): AtlasTaskBrainReplenisher
    {
        return new AtlasTaskBrainReplenisher(
            app(AgentControlPlaneTaskQueueOrchestrator::class),
            new AtlasTaskPacketQualityInspector,
            null,
            null,
            null,
            new AtlasLoopGiveBackToReplenisherFeedback($this->miner()),
        );
    }

    /** An empty comprehension model ⇒ zero candidates ⇒ the orchestrator is never touched (no disk). */
    private function emptyModel(): AtlasLoopScopeComprehensionModel
    {
        return new AtlasLoopScopeComprehensionModel([], [], [], [], [], [], [], 'snap-test');
    }

    /** @return array<string,mixed> */
    private function context(): array
    {
        return $this->replenisher()->replenishFromModel($this->emptyModel(), '/scope', 1, 1, 0, new AtlasTaskPacketQualityInspector, false);
    }

    public function test_flag_off_context_is_byte_identical_no_give_back_facts(): void
    {
        config(['atlas.loop.feedback.replenisher_enabled' => false]);

        $context = $this->context();

        $this->assertArrayNotHasKey('give_back_facts', $context, 'flag OFF adds no key');
        // The HEAD key set — none of these is the feedback key.
        $this->assertSame(
            ['schema', 'scope_root', 'status', 'claimable_before', 'claimable_after', 'enqueued', 'enqueued_count', 'skipped_existing', 'skipped_deficient', 'candidates_considered'],
            array_keys($context),
        );
    }

    public function test_flag_on_adds_only_give_back_facts_existing_keys_unchanged(): void
    {
        config(['atlas.loop.feedback.replenisher_enabled' => false]);
        $off = $this->context();

        config(['atlas.loop.feedback.replenisher_enabled' => true]);
        $on = $this->context();

        $this->assertArrayHasKey('give_back_facts', $on);
        $this->assertSame($this->miner()->mine(), $on['give_back_facts'], 'carries the miner output verbatim');

        // Additive: the key diff (ON minus the added key) vs OFF is empty, and the shared keys are identical.
        unset($on['give_back_facts']);
        $this->assertSame($off, $on, 'every pre-existing key is unchanged in shape — byte-identical');
    }
}
