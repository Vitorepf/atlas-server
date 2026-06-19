<?php

declare(strict_types=1);

namespace Tests\Feature\Loop\Constitution;

use App\Services\Ai\AutonomousEvolution\Constitution\AtlasLoopBatteryGenesis;
use App\Services\Ai\AutonomousEvolution\Constitution\AtlasLoopCertChainClosure;
use App\Services\Ai\AutonomousEvolution\Constitution\AtlasLoopFrozenBattery;
use Illuminate\Support\Facades\File;
use Tests\TestCase;

/**
 * LOOP-OS · Fase 3 · Slice 3/4.5 — the genesis seeds cover the must-catch taxonomy, with ONE blinder per
 * cert-chain closure class DERIVED from the walker (so a future delegate is auto-covered), chain-verifiable
 * and byte-reproducible.
 */
final class AtlasLoopBatteryGenesisTest extends TestCase
{
    /** @var list<string> */
    private array $dirs = [];

    private AtlasLoopBatteryGenesis $genesis;

    private AtlasLoopCertChainClosure $closure;

    protected function setUp(): void
    {
        parent::setUp();
        $this->genesis = new AtlasLoopBatteryGenesis();
        $this->closure = new AtlasLoopCertChainClosure();
    }

    protected function tearDown(): void
    {
        foreach ($this->dirs as $d) {
            File::deleteDirectory($d);
        }
        parent::tearDown();
    }

    private function battery(): AtlasLoopFrozenBattery
    {
        $dir = sys_get_temp_dir().'/atlas-genesis-'.bin2hex(random_bytes(5));
        $this->dirs[] = $dir;

        return new AtlasLoopFrozenBattery($dir);
    }

    public function test_seeds_one_blinder_per_closure_class_plus_the_named_taxonomy(): void
    {
        $battery = $this->battery();
        $this->genesis->seed($battery, $this->closure);
        $cases = $battery->cases();

        $closureCount = count($this->closure->classes());
        $expected = count(AtlasLoopBatteryGenesis::KNOWN_BAD_KINDS) + $closureCount + count(AtlasLoopBatteryGenesis::KNOWN_GOOD_KINDS);
        $this->assertCount($expected, $cases);

        // ONE blinder case per closure class — derived from the walker, never a hand-list.
        $blinderTargets = [];
        foreach ($cases as $c) {
            if (isset($c['mutation_ref']['mutation']) && $c['mutation_ref']['mutation'] === 'force_certify_true') {
                $blinderTargets[] = $c['mutation_ref']['target_class'];
            }
        }
        sort($blinderTargets);
        $this->assertSame($this->closure->classes(), $blinderTargets, 'exactly one blinder per closure delegate');
    }

    public function test_each_named_bad_and_good_kind_is_present_with_the_right_verdict(): void
    {
        $cases = $this->genesis->cases($this->closure);
        $byId = [];
        foreach ($cases as $c) {
            $byId[$c['id']] = $c;
        }
        foreach (array_keys(AtlasLoopBatteryGenesis::KNOWN_BAD_KINDS) as $id) {
            $this->assertSame('REFUTE', $byId['bad-'.$id]['expected_verdict'] ?? null, "known-bad $id present");
        }
        foreach (array_keys(AtlasLoopBatteryGenesis::KNOWN_GOOD_KINDS) as $id) {
            $this->assertSame('CERTIFY', $byId['good-'.$id]['expected_verdict'] ?? null, "known-good $id present");
        }
    }

    public function test_seeded_chain_verifies_and_is_byte_reproducible(): void
    {
        $a = $this->battery();
        $b = $this->battery();
        $rootA = $this->genesis->seed($a, $this->closure);
        $rootB = $this->genesis->seed($b, $this->closure);

        $this->assertMatchesRegularExpression('/^[0-9a-f]{64}$/', $rootA);
        $this->assertSame($rootA, $a->rootHash(), 'the chain verifies after seeding');
        $this->assertSame($rootA, $rootB, 'two fresh seeds yield an identical genesis root (attestable)');
    }

    public function test_seeding_is_idempotent_never_double_appends(): void
    {
        $battery = $this->battery();
        $root1 = $this->genesis->seed($battery, $this->closure);
        $count1 = count($battery->cases());
        $root2 = $this->genesis->seed($battery, $this->closure); // second call is a no-op
        $this->assertSame($root1, $root2);
        $this->assertCount($count1, $battery->cases());
    }
}
