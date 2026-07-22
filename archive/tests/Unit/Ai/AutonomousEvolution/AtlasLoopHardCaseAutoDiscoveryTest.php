<?php

declare(strict_types=1);

namespace Tests\Unit\Ai\AutonomousEvolution;

use App\Services\Ai\AutonomousEvolution\AtlasLoopHardCaseAutoDiscovery;
use App\Services\Ai\AutonomousEvolution\AtlasLoopJudgeDisagreementDiagnostic;
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

    public function test_provider_disagreement_gains_a_typed_disagreement_category(): void
    {
        $cases = $this->discovery()->discover([[
            'id' => 'row-1',
            'bundle_sha256' => 'B1',
            'providers' => [['provider' => 'minimax', 'passed' => true], ['provider' => 'codex', 'passed' => false]],
        ]]);

        $this->assertCount(1, $cases);
        $this->assertArrayHasKey('disagreement_category', $cases[0]);
        $this->assertContains($cases[0]['disagreement_category'], AtlasLoopJudgeDisagreementDiagnostic::CATEGORIES);
        // Same frozen bundle, no capability gap, no prompt lensing ⇒ a genuine semantic split (worth re-grinding).
        $this->assertSame('genuine_semantic_split', $cases[0]['disagreement_category']);
    }

    public function test_two_distinct_bundle_hashes_classify_as_bundle_drift(): void
    {
        $cases = $this->discovery()->discover([[
            'id' => 'row-drift',
            'bundle_sha256' => 'B-row', // row-level fallback, overridden per-judge below
            'providers' => [
                ['provider' => 'minimax', 'passed' => true, 'bundle_sha256' => 'AAA'],
                ['provider' => 'codex', 'passed' => false, 'bundle_sha256' => 'BBB'],
            ],
        ]]);

        $this->assertCount(1, $cases);
        $this->assertSame('provider_disagreement', $cases[0]['trigger_reason']);
        $this->assertSame('bundle_drift', $cases[0]['disagreement_category'], 'two distinct frozen bundles ⇒ a measurement artifact, not a genuine split');
    }

    public function test_non_disagreement_triggers_are_undetermined_and_order_is_byte_identical(): void
    {
        $input = [
            ['id' => 'r-rollback', 'rolled_back' => true],
            ['id' => 'r-disagree', 'bundle_sha256' => 'B1', 'providers' => [['provider' => 'a', 'passed' => true], ['provider' => 'b', 'passed' => false]]],
            ['id' => 'r-flip', 'initial_verdict' => true, 'reprove_verdict' => false],
            ['id' => 'r-clean', 'providers' => [['provider' => 'a', 'passed' => true]]], // agreement ⇒ not a hard case
        ];

        $cases = $this->discovery()->discover($input);

        // The annotation never drops/adds/reorders: same hard cases, same sequence, the clean row still dropped.
        $this->assertSame(
            [
                ['r-rollback', 'provider_rollback'],
                ['r-disagree', 'provider_disagreement'],
                ['r-flip', 'reprove_verdict_flip'],
            ],
            array_map(static fn (array $c): array => [$c['ledger_row_id'], $c['trigger_reason']], $cases),
        );

        $byId = [];
        foreach ($cases as $c) {
            $byId[$c['ledger_row_id']] = $c['disagreement_category'];
        }
        $this->assertSame('undetermined', $byId['r-rollback'], 'a rollback has no judge split to classify');
        $this->assertSame('undetermined', $byId['r-flip'], 'a reprove flip has no judge split to classify');
        $this->assertNotSame('undetermined', $byId['r-disagree'], 'a real disagreement gets a substantive category');
        $this->assertContains($byId['r-disagree'], AtlasLoopJudgeDisagreementDiagnostic::CATEGORIES);
    }

    public function test_each_case_carries_task_seed_readiness_with_required_keys(): void
    {
        $cases = $this->discovery()->discover([[
            'id' => 'row-1',
            'bundle_sha256' => 'B1',
            'providers' => [['provider' => 'minimax', 'passed' => true], ['provider' => 'codex', 'passed' => false]],
        ]]);

        $this->assertCount(1, $cases);
        $tsr = $cases[0]['task_seed_readiness'];
        foreach (['ready', 'route', 'blockers', 'required_evidence_refs'] as $k) {
            $this->assertArrayHasKey($k, $tsr, "task_seed_readiness must have key: {$k}");
        }
    }

    public function test_provider_disagreement_with_frozen_hash_routes_to_regrind_and_ready(): void
    {
        $cases = $this->discovery()->discover([[
            'id' => 'row-1',
            'bundle_sha256' => 'FROZEN-HASH-ABC',
            'providers' => [['provider' => 'minimax', 'passed' => true], ['provider' => 'codex', 'passed' => false]],
        ]]);

        $tsr = $cases[0]['task_seed_readiness'];
        $this->assertTrue($tsr['ready']);
        $this->assertSame('regrind_hard_case', $tsr['route']);
        $this->assertSame([], $tsr['blockers']);
    }

    public function test_bundle_drift_routes_to_verification_repair_and_not_ready(): void
    {
        $cases = $this->discovery()->discover([[
            'id' => 'row-drift',
            'bundle_sha256' => 'B-row',
            'providers' => [
                ['provider' => 'minimax', 'passed' => true, 'bundle_sha256' => 'AAA'],
                ['provider' => 'codex', 'passed' => false, 'bundle_sha256' => 'BBB'],
            ],
        ]]);

        $tsr = $cases[0]['task_seed_readiness'];
        $this->assertFalse($tsr['ready']);
        $this->assertSame('verification_repair', $tsr['route']);
        $this->assertContains('bundle_drift', $tsr['blockers']);
    }

    public function test_missing_frozen_bundle_hash_routes_to_blocked_missing_bundle(): void
    {
        $cases = $this->discovery()->discover([[
            'id' => 'r-rollback',
            'rolled_back' => true,
            // no bundle_sha256 at all
        ]]);

        $tsr = $cases[0]['task_seed_readiness'];
        $this->assertFalse($tsr['ready']);
        $this->assertSame('blocked_missing_bundle', $tsr['route']);
        $this->assertContains('missing_frozen_bundle_hash', $tsr['blockers']);
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
