<?php

declare(strict_types=1);

namespace Tests\Unit\Ai\SelfConstruction\Quaternity\DialogueToPackets;

use App\Services\Ai\SelfConstruction\Quaternity\DialogueToPackets\AtlasMaestroIntentToPacketShapeProposer;
use App\Services\Ai\SelfConstruction\Quaternity\DialogueToPackets\CortexGroundingSnapshot;
use App\Services\Ai\SelfConstruction\Quaternity\DialogueToPackets\IntentFactBundle;
use App\Services\Ai\SelfConstruction\Quaternity\DialogueToPackets\ProposalRefusal;
use App\Services\Ai\SelfConstruction\Quaternity\DialogueToPackets\ProposedPacketShape;
use PHPUnit\Framework\TestCase;

final class AtlasMaestroIntentToPacketShapeProposerTest extends TestCase
{
    private function cortex(): CortexGroundingSnapshot
    {
        return new CortexGroundingSnapshot(
            symbols: ['App\\Services\\Ai\\Maestro\\MaestroThing'],
            files: ['app/Services/Ai/Maestro/MaestroThing.php'],
            cortexId: 'cortex-run-1',
        );
    }

    private function proposer(): AtlasMaestroIntentToPacketShapeProposer
    {
        return new AtlasMaestroIntentToPacketShapeProposer(static function (): void { /* swallow */ });
    }

    public function test_ungrounded_dialogue_intent_returns_proposal_refusal(): void
    {
        $intent = new IntentFactBundle(
            phrases: ['do something unrelated'],
            timestamps: [1_700_000_000],
            scopeTags: ['not_a_real_scope'],
            verbs: ['do'],
        );

        $result = $this->proposer()->propose($intent, $this->cortex(), 'wave-x');

        $this->assertInstanceOf(ProposalRefusal::class, $result);
    }

    public function test_grounded_intent_emits_packet_shape_with_implementation_and_test_scope(): void
    {
        $intent = new IntentFactBundle(
            phrases: ['evolve the maestro thing'],
            timestamps: [1_700_000_000],
            scopeTags: ['maestro'],
            verbs: ['evolve'],
        );

        $result = $this->proposer()->propose($intent, $this->cortex(), 'wave-x');

        $this->assertInstanceOf(ProposedPacketShape::class, $result);
        $this->assertContains('app/Services/Ai/Maestro/MaestroThing.php', $result->allowedFiles);
        $this->assertContains('tests/Unit/Services/Ai/Maestro/MaestroThingTest.php', $result->allowedFiles);
    }

    public function test_proposed_shape_includes_runnable_acceptance_and_required_evidence_fields(): void
    {
        $intent = new IntentFactBundle(
            phrases: ['evolve the maestro thing'],
            timestamps: [1_700_000_000],
            scopeTags: ['maestro'],
            verbs: ['evolve'],
        );

        $result = $this->proposer()->propose($intent, $this->cortex(), 'wave-x');

        $this->assertInstanceOf(ProposedPacketShape::class, $result);
        $runnableFound = false;
        foreach ($result->acceptanceCriteria as $criterion) {
            if (str_starts_with($criterion, 'runnable:')) {
                $runnableFound = true;
            }
        }
        $this->assertTrue($runnableFound, 'acceptance_criteria must include a runnable acceptance entry');
        $this->assertStringContainsString('tests_or_gates_result', $result->requiredEvidence);
        $this->assertStringContainsString('cortex_id:cortex-run-1', $result->requiredEvidence);
    }
}
