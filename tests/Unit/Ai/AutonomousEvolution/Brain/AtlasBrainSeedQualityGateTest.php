<?php

declare(strict_types=1);

namespace Tests\Unit\Ai\AutonomousEvolution\Brain;

use App\Services\Ai\AutonomousEvolution\Brain\AtlasBrainSeedQualityGate;
use Tests\TestCase;

/**
 * The SEED boundary promotes the three advisory excellence flags (vague_objective, acceptance_not_runnable,
 * blind_orphan_wiring_proxy) to BLOCKING — without touching the universal inspector. Proves a vague/proxy
 * packet is refused and a clean concrete packet is admitted.
 */
final class AtlasBrainSeedQualityGateTest extends TestCase
{
    /** A packet a cold worker can implement + prove: concrete objective, real file, runnable acceptance, evidence. */
    private function cleanPacket(): array
    {
        return [
            'objective' => 'Extract the status-transition policy cluster from App\\Services\\Ai\\Foo into FooStatusPolicy.php',
            'allowed_files' => ['app/Services/Ai/Foo/FooStatusPolicy.php'],
            'scope_in' => ['app/Services/Ai/Foo'],
            'acceptance_criteria' => ['php artisan test --filter=FooStatusPolicy runs green'],
            'required_evidence' => ['tests_or_gates_result'],
        ];
    }

    public function test_admits_a_clean_concrete_packet(): void
    {
        $result = (new AtlasBrainSeedQualityGate)->evaluate($this->cleanPacket());

        self::assertTrue($result['admit'], 'a concrete, runnable, grounded packet must be admitted');
        self::assertSame([], $result['blocking']);
    }

    public function test_blocks_a_vague_objective(): void
    {
        $packet = $this->cleanPacket();
        // Short + no concrete reference token => vague_objective (advisory in the inspector, fatal here).
        $packet['objective'] = 'make it better';

        $result = (new AtlasBrainSeedQualityGate)->evaluate($packet);

        self::assertFalse($result['admit']);
        self::assertContains('vague_objective', $result['blocking']);
    }

    public function test_blocks_a_non_runnable_acceptance(): void
    {
        $packet = $this->cleanPacket();
        $packet['acceptance_criteria'] = ['the code looks cleaner and reads nicely'];

        $result = (new AtlasBrainSeedQualityGate)->evaluate($packet);

        self::assertFalse($result['admit']);
        self::assertContains('acceptance_not_runnable', $result['blocking']);
    }

    public function test_blocks_a_blind_orphan_wiring_proxy(): void
    {
        $packet = $this->cleanPacket();
        $packet['objective'] = 'This class is a confirmed orphan with zero production callers; wire it into the live flow of App\\Services\\Ai\\Foo';

        $result = (new AtlasBrainSeedQualityGate)->evaluate($packet);

        self::assertFalse($result['admit']);
        self::assertContains('blind_orphan_wiring_proxy', $result['blocking']);
    }

    public function test_propagates_universal_blocking_deficiencies(): void
    {
        // empty_allowed_files is a UNIVERSAL blocking deficiency — the gate must surface it too, not just the
        // three advisory promotions.
        $packet = $this->cleanPacket();
        $packet['allowed_files'] = [];

        $result = (new AtlasBrainSeedQualityGate)->evaluate($packet);

        self::assertFalse($result['admit']);
        self::assertContains('empty_allowed_files', $result['blocking']);
    }

    public function test_fatal_advisory_constant_is_the_three_promoted_flags(): void
    {
        self::assertSame(
            ['vague_objective', 'acceptance_not_runnable', 'blind_orphan_wiring_proxy'],
            AtlasBrainSeedQualityGate::BRAIN_FATAL_ADVISORY,
        );
    }
}
