<?php

declare(strict_types=1);

namespace Tests\Unit\Ai\AutonomousEvolution;

use App\Services\Ai\AutonomousEvolution\AtlasLoopHardCaseAutoDiscovery;
use App\Services\Ai\AutonomousEvolution\AtlasLoopMasterSwitch;
use PHPUnit\Framework\TestCase;

/**
 * Proves hard-case auto-discovery: it emits ONLY from real ledger rows (empty ledger ⇒ empty, no synthesis),
 * carries the typed trigger + evidence per case, refuses entirely when the anti-farm gate returns false, and
 * is master-switch gated.
 */
final class AtlasLoopHardCaseAutoDiscoveryTest extends TestCase
{
    private string $tmpEnv;

    protected function setUp(): void
    {
        parent::setUp();
        // Arm the §0 master switch ON via the documented test seam (never the real .env).
        $this->tmpEnv = sys_get_temp_dir().'/atlas-hardcase-master-'.bin2hex(random_bytes(6)).'.env';
        file_put_contents($this->tmpEnv, "ATLAS_LOOP_MASTER_ENABLED=true\n");
        AtlasLoopMasterSwitch::$envPathOverride = $this->tmpEnv;
    }

    protected function tearDown(): void
    {
        AtlasLoopMasterSwitch::$envPathOverride = null;
        @unlink($this->tmpEnv);
        parent::tearDown();
    }

    private function discovery(?\Closure $antiFarmGate = null): AtlasLoopHardCaseAutoDiscovery
    {
        return new AtlasLoopHardCaseAutoDiscovery($antiFarmGate);
    }

    public function test_empty_ledger_emits_nothing_no_synthesis(): void
    {
        $this->assertSame([], $this->discovery()->discover([]));
    }

    public function test_provider_disagreement_is_surfaced_with_evidence(): void
    {
        $cases = $this->discovery()->discover([[
            'id' => 'row-1',
            'bundle_sha256' => 'B1',
            'providers' => [['provider' => 'minimax', 'passed' => true], ['provider' => 'codex', 'passed' => false]],
        ]]);

        $this->assertCount(1, $cases);
        $this->assertSame('row-1', $cases[0]['ledger_row_id']);
        $this->assertSame('provider_disagreement', $cases[0]['trigger_reason']);
        $this->assertSame(['codex', 'minimax'], $cases[0]['provider_set']);
        $this->assertSame('B1', $cases[0]['frozen_bundle_hash']);
        $this->assertContains($cases[0]['trigger_reason'], AtlasLoopHardCaseAutoDiscovery::TRIGGERS);
    }

    public function test_rollback_and_reprove_flip_triggers(): void
    {
        $cases = $this->discovery()->discover([
            ['id' => 'r-rollback', 'rolled_back' => true],
            ['id' => 'r-flip', 'initial_verdict' => true, 'reprove_verdict' => false],
            ['id' => 'r-clean', 'providers' => [['provider' => 'minimax', 'passed' => true]]], // agreement ⇒ not a hard case
        ]);

        $byId = [];
        foreach ($cases as $c) {
            $byId[$c['ledger_row_id']] = $c['trigger_reason'];
        }
        $this->assertSame('provider_rollback', $byId['r-rollback']);
        $this->assertSame('reprove_verdict_flip', $byId['r-flip']);
        $this->assertArrayNotHasKey('r-clean', $byId, 'a clean row is never a hard case');
    }

    public function test_anti_farm_gate_false_emits_nothing(): void
    {
        $discovery = $this->discovery(static fn (): bool => false);

        $cases = $discovery->discover([[
            'id' => 'row-1',
            'providers' => [['provider' => 'a', 'passed' => true], ['provider' => 'b', 'passed' => false]],
        ]]);

        $this->assertSame([], $cases, 'anti-farm floor false ⇒ cannot manufacture cases');
    }

    public function test_master_off_emits_nothing(): void
    {
        file_put_contents($this->tmpEnv, "ATLAS_LOOP_MASTER_ENABLED=false\n");

        $cases = $this->discovery()->discover([[
            'id' => 'row-1',
            'providers' => [['provider' => 'a', 'passed' => true], ['provider' => 'b', 'passed' => false]],
        ]]);

        $this->assertSame([], $cases, 'master OFF ⇒ empty, no mining');
    }
}
