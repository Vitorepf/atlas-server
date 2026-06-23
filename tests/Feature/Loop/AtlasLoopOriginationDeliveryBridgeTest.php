<?php

declare(strict_types=1);

namespace Tests\Feature\Loop;

use App\Services\Ai\AutonomousEvolution\AtlasLoopOriginationDeliveryBridge;
use App\Services\Ai\AutonomousEvolution\AtlasLoopWiringMaterialGrader;
use Tests\TestCase;

/**
 * THE DELIVERY BRIDGE turns a loop wiring-claim into a grindable RED packet — but ONLY through the external
 * grader and the factory's RED-check. These pin the fail-closed contract: a grader-rejected claim never
 * reaches the factory; an admitted claim is compiled with the consumer as target, the loop's observable-
 * behaviour atom, and a REQUIRED-USAGE refuter naming the primitive; a not-ready verifier yields no task.
 */
final class AtlasLoopOriginationDeliveryBridgeTest extends TestCase
{
    private function atom(): array
    {
        return ['type' => 'method_return', 'method' => 'rankedForModel', 'expected' => 'orphan_wiring'];
    }

    private function materialClaim(): array
    {
        return [
            'primitive_path' => 'app/Services/Ai/AutonomousEvolution/AtlasLoopWiringMaterialGrader.php',
            'consumer_path' => 'app/Services/Ai/AutonomousEvolution/AtlasLoopAbstainAndAsk.php',
            'behaviour_atom' => $this->atom(),
        ];
    }

    public function test_grader_rejected_claim_never_reaches_the_factory(): void
    {
        $factoryCalls = 0;
        $bridge = new AtlasLoopOriginationDeliveryBridge(
            new AtlasLoopWiringMaterialGrader,
            function () use (&$factoryCalls): array { $factoryCalls++; return ['ready' => true]; },
        );

        // already-wired (AbstainAndAsk into OriginationPipeline) => grader rejects => factory NOT called
        $out = $bridge->buildGrindablePacket([
            'primitive_path' => 'app/Services/Ai/AutonomousEvolution/AtlasLoopAbstainAndAsk.php',
            'consumer_path' => 'app/Services/Ai/AutonomousEvolution/AtlasLoopOriginationPipeline.php',
            'behaviour_atom' => $this->atom(),
        ], 'wire it', base_path());

        $this->assertFalse($out['ready']);
        $this->assertStringContainsString('grader_rejected:primitive_already_wired_into_consumer', (string) $out['reason']);
        $this->assertSame(0, $factoryCalls, 'a rejected claim must never reach the factory');
    }

    public function test_admitted_claim_compiles_with_consumer_target_atom_and_usage_refuter(): void
    {
        $captured = null;
        $bridge = new AtlasLoopOriginationDeliveryBridge(
            new AtlasLoopWiringMaterialGrader,
            function (string $repo, string $intent, array $payload) use (&$captured): array {
                $captured = $payload;

                return ['ready' => true, 'target_relative_path' => $payload['target_relative_path'], 'schema_version' => 'x'];
            },
        );

        $out = $bridge->buildGrindablePacket($this->materialClaim(), 'wire the selector', base_path());

        $this->assertTrue($out['ready'], 'reason='.json_encode($out['reason']));
        $this->assertSame('AtlasLoopWiringMaterialGrader', $out['required_symbol']);
        // factory got: consumer as target, the loop's behaviour atom, and a refuter naming the primitive
        $this->assertSame('app/Services/Ai/AutonomousEvolution/AtlasLoopAbstainAndAsk.php', $captured['target_relative_path']);
        $this->assertSame([$this->atom()], $captured['verification_atoms']);
        $this->assertNotEmpty($captured['verifier_refuter_commands']);
        $this->assertStringContainsString('AtlasLoopWiringMaterialGrader', $captured['verifier_refuter_commands'][0]);
    }

    public function test_not_ready_verifier_yields_no_task_fail_closed(): void
    {
        // The factory blocks (e.g. the RED-check found the atom already green => not new behaviour).
        $bridge = new AtlasLoopOriginationDeliveryBridge(
            new AtlasLoopWiringMaterialGrader,
            fn (): array => ['ready' => false, 'blockers' => [['code' => 'atom_already_green']]],
        );

        $out = $bridge->buildGrindablePacket($this->materialClaim(), 'wire it', base_path());

        $this->assertFalse($out['ready']);
        $this->assertStringContainsString('verifier_not_ready:atom_already_green', (string) $out['reason']);
    }
}
