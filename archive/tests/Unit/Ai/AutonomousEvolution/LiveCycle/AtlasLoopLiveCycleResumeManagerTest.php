<?php

declare(strict_types=1);

namespace Tests\Unit\Ai\AutonomousEvolution\LiveCycle;

use App\Services\Ai\AutonomousEvolution\LiveCycle\Integration\AtlasLoopLiveCycleResumeManager;
use PHPUnit\Framework\TestCase;

/**
 * Proves the LiveCycle resume manager: after a phase-5 checkpoint, resume() returns from_phase=6 with
 * verified_chain=true and emits exactly one cycle.resume.planned FACT; calling resume() again with no
 * progress between is IDEMPOTENT (fact==null on the 2nd call, same plan); tampering with the persisted
 * last_receipt_hash makes resume refuse (verified_chain=false, refused=true, from_phase=0).
 */
final class AtlasLoopLiveCycleResumeManagerTest extends TestCase
{
    private string $stateDir;

    private AtlasLoopLiveCycleResumeManager $manager;

    protected function setUp(): void
    {
        parent::setUp();
        $this->stateDir = sys_get_temp_dir().'/atlas_resume_'.bin2hex(random_bytes(6));
        mkdir($this->stateDir, 0775, true);
        $this->manager = new AtlasLoopLiveCycleResumeManager($this->stateDir);
    }

    protected function tearDown(): void
    {
        foreach (glob($this->stateDir.'/*') ?: [] as $f) {
            @unlink($f);
        }
        @rmdir($this->stateDir);
        parent::tearDown();
    }

    private function chainHash(string $cycleId, int $lastGoodPhase): string
    {
        return hash('sha256', $cycleId.':'.$lastGoodPhase);
    }

    public function test_resume_after_phase_5_returns_from_phase_6_with_verified_chain_and_one_fact(): void
    {
        $cycle = 'cycle-A';
        $this->manager->checkpoint($cycle, 5, $this->chainHash($cycle, 5));

        $plan = $this->manager->resume($cycle);

        $this->assertSame(6, $plan['from_phase']);
        $this->assertTrue($plan['verified_chain']);
        $this->assertFalse($plan['refused']);
        $this->assertSame(AtlasLoopLiveCycleResumeManager::FACT_NAME, $plan['fact'], 'first resume emits the FACT');
    }

    public function test_idempotent_second_resume_with_no_progress_emits_no_new_fact_and_returns_same_plan(): void
    {
        $cycle = 'cycle-B';
        $this->manager->checkpoint($cycle, 3, $this->chainHash($cycle, 3));

        $a = $this->manager->resume($cycle);
        $b = $this->manager->resume($cycle);

        $this->assertSame(AtlasLoopLiveCycleResumeManager::FACT_NAME, $a['fact']);
        $this->assertNull($b['fact'], 'second call must NOT emit a new FACT');
        $this->assertSame($a['from_phase'], $b['from_phase']);
        $this->assertSame($a['verified_chain'], $b['verified_chain']);
        $this->assertSame($a['refused'], $b['refused']);
    }

    public function test_new_checkpoint_after_resume_re_emits_fact(): void
    {
        $cycle = 'cycle-C';
        $this->manager->checkpoint($cycle, 2, $this->chainHash($cycle, 2));
        $first = $this->manager->resume($cycle);
        $this->assertSame(AtlasLoopLiveCycleResumeManager::FACT_NAME, $first['fact']);

        // Progress: phase 3 completes. New checkpoint clears the emit flag.
        $this->manager->checkpoint($cycle, 3, $this->chainHash($cycle, 3));
        $second = $this->manager->resume($cycle);

        $this->assertSame(AtlasLoopLiveCycleResumeManager::FACT_NAME, $second['fact'], 'fresh checkpoint ⇒ next resume re-emits');
        $this->assertSame(4, $second['from_phase']);
    }

    public function test_tampered_chain_hash_makes_resume_refuse_with_verified_chain_false(): void
    {
        $cycle = 'cycle-D';
        $this->manager->checkpoint($cycle, 4, $this->chainHash($cycle, 4));

        // Mutate the persisted hash directly on disk (simulating an attacker / corruption).
        $path = $this->manager->statePath($cycle);
        $state = json_decode(file_get_contents($path), true);
        $state['last_receipt_hash'] = 'TAMPERED-HASH-DEADBEEF';
        file_put_contents($path, json_encode($state));

        $plan = $this->manager->resume($cycle);

        $this->assertFalse($plan['verified_chain']);
        $this->assertTrue($plan['refused']);
        $this->assertSame(0, $plan['from_phase'], 'refused resume ⇒ from_phase=0');
        $this->assertStringContainsString('does not match', (string) $plan['reason']);
    }

    public function test_no_checkpoint_yet_resume_returns_phase_1_with_verified_chain(): void
    {
        $plan = $this->manager->resume('fresh-cycle');
        $this->assertSame(1, $plan['from_phase']);
        $this->assertTrue($plan['verified_chain']);
        $this->assertFalse($plan['refused']);
    }

    public function test_resume_twice_no_checkpoint_is_idempotent_and_never_reports_tamper(): void
    {
        $cycle = 'never-checkpointed';

        $a = $this->manager->resume($cycle);
        $b = $this->manager->resume($cycle);

        $this->assertSame(AtlasLoopLiveCycleResumeManager::FACT_NAME, $a['fact'], 'first resume emits FACT');
        $this->assertFalse($a['refused']);
        $this->assertTrue($a['verified_chain']);
        $this->assertSame(1, $a['from_phase']);

        $this->assertNull($b['fact'], 'second resume must NOT emit a new FACT (idempotent)');
        $this->assertFalse($b['refused'], 'second resume must NOT report tamper');
        $this->assertTrue($b['verified_chain'], 'second resume must NOT flip verified_chain');
        $this->assertSame($a['from_phase'], $b['from_phase']);
    }
}
