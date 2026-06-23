<?php

declare(strict_types=1);

namespace Tests\Feature\Loop;

use App\Services\Ai\AutonomousEvolution\Campaign\AtlasLoopCampaignSupervisor;
use ReflectionClass;
use Tests\TestCase;

/**
 * SLICE 1c — an ORIGINATED leap must be MATERIALIZABLE. The originator returns objective + obligations +
 * target_path, but the materializer requires target_relative_path + target_content + acceptance.commands —
 * so the live origination task died at materialize ("payload requires target_relative_path, target_content
 * and acceptance.commands"). originationGrindPayload() snapshots the real target + its sibling test as the
 * acceptance + a frozen guard. These pin that contract. (The genuine red→green acceptance for NEW behaviour
 * is the follow-up; this proves the leap is now executable + behaviour-guarded.)
 */
final class AtlasLoopOriginationGrindPayloadTest extends TestCase
{
    private function supervisor(): AtlasLoopCampaignSupervisor
    {
        // The payload builder uses only its args + the filesystem (no ctor-injected deps), so an
        // unconstructed instance is sufficient to exercise it in isolation.
        return (new ReflectionClass(AtlasLoopCampaignSupervisor::class))->newInstanceWithoutConstructor();
    }

    /**
     * @param  array<string,mixed>  $result
     * @return array<string,mixed>
     */
    private function payload(array $result): array
    {
        $sup = $this->supervisor();
        $m = (new ReflectionClass($sup))->getMethod('originationGrindPayload');
        $m->setAccessible(true);

        return (array) $m->invoke($sup, $result, base_path());
    }

    private function resolve(string $targetRel): ?string
    {
        $sup = $this->supervisor();
        $m = (new ReflectionClass($sup))->getMethod('resolveSiblingTest');
        $m->setAccessible(true);

        return $m->invoke($sup, $targetRel, base_path());
    }

    public function test_resolves_a_real_sibling_test_anywhere_under_tests(): void
    {
        // A real loop class with a known nested test (tests/Feature/Loop/…).
        $hit = $this->resolve('app/Services/Ai/AutonomousEvolution/AtlasLoopAbstainAndAsk.php');
        $this->assertSame('tests/Feature/Loop/AtlasLoopAbstainAndAskTest.php', $hit);
    }

    public function test_unresolvable_target_returns_null(): void
    {
        $this->assertNull($this->resolve('app/Services/Ai/AutonomousEvolution/NoSuchClassZZZ.php'));
    }

    public function test_payload_is_materializable_for_a_real_target(): void
    {
        $p = $this->payload([
            'objective' => 'wire X into Y',
            'target_path' => 'app/Services/Ai/AutonomousEvolution/AtlasLoopAbstainAndAsk.php',
            'obligations' => [['kind' => 'red_to_green', 'target_symbol' => 'AtlasLoopAbstainAndAsk']],
        ]);

        // the three fields the materializer demands
        $this->assertSame('app/Services/Ai/AutonomousEvolution/AtlasLoopAbstainAndAsk.php', $p['target_relative_path']);
        $this->assertNotEmpty($p['target_content'], 'real file content is snapshotted');
        $this->assertStringContainsString('class AtlasLoopAbstainAndAsk', $p['target_content']);
        $this->assertIsArray($p['acceptance']['commands']);
        $this->assertStringContainsString('phpunit', $p['acceptance']['commands'][0]);
        $this->assertStringContainsString('AtlasLoopAbstainAndAskTest.php', $p['acceptance']['commands'][0]);

        // the test is frozen so the grind cannot weaken it
        $this->assertSame('tests/Feature/Loop/AtlasLoopAbstainAndAskTest.php', $p['frozen_tests'][0]['path']);
        $this->assertNotEmpty($p['frozen_tests'][0]['content']);
        $this->assertSame(['app/Services/Ai/AutonomousEvolution/AtlasLoopAbstainAndAsk.php'], $p['allowed_files']);
        // obligations ride along for the cert/refute floor
        $this->assertTrue($p['_origination']);
        $this->assertNotEmpty($p['obligations']);
    }

    public function test_unresolvable_target_yields_a_lean_payload_not_a_crash(): void
    {
        // No materializer fields when the target can't be resolved — the grind fails soft, never silently green.
        $p = $this->payload(['objective' => 'x', 'target_path' => 'app/Services/Ai/AutonomousEvolution/NoSuchZZZ.php', 'obligations' => []]);
        $this->assertTrue($p['_origination']);
        $this->assertArrayNotHasKey('target_relative_path', $p);
        $this->assertArrayNotHasKey('acceptance', $p);
    }
}
