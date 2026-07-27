<?php

declare(strict_types=1);

namespace Tests\Unit\Ai\EngineeringKernel;

use App\Services\Ai\EngineeringKernel\Adapters\AtlasDevGateAdapter;
use App\Services\Ai\EngineeringKernel\CertVerdict;
use App\Services\Ai\EngineeringKernel\SovereignHonestyFloor;
use App\Services\Ai\EngineeringKernel\TrustLevel;
use App\Services\Ai\Programming\AtlasDev\Mutation\MutationScoreVerdict;
use PHPUnit\Framework\TestCase;

/**
 * Slices 3 + 4 — the adapter promotes the REAL AtlasDev machinery (mutation verdict + quality scan)
 * through the sovereign gate. Wiper-safe: maps real tool OUTPUTS, never runs a DB or a scanner binary.
 */
final class AtlasDevGateAdapterTest extends TestCase
{
    private function passingDevEvidence(array $overrides = []): array
    {
        return array_replace([
            'criteria_hash' => 'devhash-1',
            'frozen_hash' => 'devhash-1',
            'changed_files' => ['app/Services/Ai/Foo.php'],
            'changed_public_symbols' => [
                ['symbol' => 'Foo::bar', 'has_criterion' => true, 'has_test' => true],
            ],
            'execution' => [
                'commands' => ['php artisan test tests/Unit/Ai/FooTest.php'],
                'claimed_status' => 'passed',
                'tests_run' => 8,
                'assertions_executed' => 25,
                'selected_tests' => ['tests/Unit/Ai/FooTest.php'],
                'artifacts' => [],
            ],
            'mutation_verdict' => MutationScoreVerdict::pass(0.81, 0.6),
            'mutants_generated' => 17,
            'scan_result' => ['status' => 'passed', 'tools' => [['slug' => 'gitleaks', 'category' => 'security', 'status' => 'passed']], 'findings' => []],
            'judges' => [
                ['name' => 'a', 'provider_family' => 'anthropic', 'approved' => true],
                ['name' => 'b', 'provider_family' => 'openai', 'approved' => true],
            ],
            'context_sufficiency' => 88,
        ], $overrides);
    }

    // --- Slice 3: the current Dev green path continues to certify, now via the sovereign gate ---

    public function test_dev_passing_delivery_certifies_via_the_sovereign_gate(): void
    {
        $adapter = new AtlasDevGateAdapter(new SovereignHonestyFloor(configMutationFloor: 0.0));

        $verdict = $adapter->certifyDevDelivery($this->passingDevEvidence(), TrustLevel::Dev);

        self::assertSame(CertVerdict::PROMOTE, $verdict->status, 'blockers: '.implode(',', $verdict->blockers));
        self::assertSame('observe', $verdict->invariants['verification_court_migration']['status']);
        self::assertSame('legacy_bundle_without_quality_foundry_court_facts', $verdict->invariants['verification_court_migration']['detail']);
    }

    public function test_mutation_report_from_verdict_maps_msi_and_no_op(): void
    {
        $passing = AtlasDevGateAdapter::mutationReportFromVerdict(MutationScoreVerdict::pass(0.8, 0.6), 17);
        self::assertSame(0.8, $passing['kill_ratio']);
        self::assertSame(17, $passing['mutants_generated']);
        self::assertTrue($passing['decision_surface_added']);

        $noOp = AtlasDevGateAdapter::mutationReportFromVerdict(MutationScoreVerdict::noOp('no decision surface', 0.6), 0);
        self::assertFalse($noOp['decision_surface_added']);
    }

    // --- Slice 4: security is a hard cut, mapping the REAL scan shape ---

    public function test_dev_delivery_carrying_a_planted_secret_is_refused(): void
    {
        $adapter = new AtlasDevGateAdapter(new SovereignHonestyFloor(configMutationFloor: 0.0));

        $evidence = $this->passingDevEvidence([
            'scan_result' => [
                'status' => 'failed',
                'findings' => [[
                    'tool' => 'gitleaks',
                    'category' => 'security',
                    'severity' => 'high',
                    'rule_id' => 'generic-api-key-secret',
                    'blocks_resolved' => true,
                ]],
            ],
        ]);

        $verdict = $adapter->certifyDevDelivery($evidence, TrustLevel::Dev);

        self::assertSame(CertVerdict::REFUSE, $verdict->status);
        self::assertContains('security_free', $verdict->blockers);
    }

    public function test_security_from_scan_maps_the_real_scan_shape(): void
    {
        // blocked (AWIS gate refused) => did not run => fail-closed
        $blocked = AtlasDevGateAdapter::securityFromScan(['status' => 'blocked', 'findings' => []]);
        self::assertFalse($blocked['ran']);

        // clean pass
        $clean = AtlasDevGateAdapter::securityFromScan(['status' => 'passed', 'tools' => [['slug' => 'gitleaks', 'category' => 'security', 'status' => 'passed']], 'findings' => []]);
        self::assertSame(['ran' => true, 'secret_free' => true, 'critical_sast' => 0, 'critical_cve' => 0], $clean);

        // every security tool missing from PATH => all 'skipped'. The service does NOT
        // count skipped when it computes the aggregate, so this reports 'passed' — the
        // shape that made the whole invariant vacuous. It must read as: did not run.
        $allSkipped = AtlasDevGateAdapter::securityFromScan([
            'status' => 'passed',
            'tools' => [
                ['slug' => 'gitleaks', 'category' => 'security', 'status' => 'skipped', 'reason' => 'tool_missing'],
                ['slug' => 'semgrep', 'category' => 'security', 'status' => 'skipped', 'reason' => 'tool_missing'],
            ],
            'findings' => [],
        ]);
        self::assertFalse($allSkipped['ran'], 'an all-skipped security scan must not certify as run');

        // a quality tool running proves nothing about security
        $qualityOnly = AtlasDevGateAdapter::securityFromScan([
            'status' => 'passed',
            'tools' => [['slug' => 'phpstan', 'category' => 'quality', 'status' => 'passed']],
            'findings' => [],
        ]);
        self::assertFalse($qualityOnly['ran']);

        // gitleaks secret => not secret_free
        $secret = AtlasDevGateAdapter::securityFromScan([
            'status' => 'failed',
            'tools' => [['slug' => 'gitleaks', 'category' => 'security', 'status' => 'failed']],
            'findings' => [['tool' => 'gitleaks', 'category' => 'security', 'blocks_resolved' => true]],
        ]);
        self::assertFalse($secret['secret_free']);

        // semgrep => sast; osv_scanner => cve
        $sast = AtlasDevGateAdapter::securityFromScan([
            'status' => 'failed',
            'tools' => [['slug' => 'semgrep', 'category' => 'security', 'status' => 'failed']],
            'findings' => [['tool' => 'semgrep', 'category' => 'security', 'blocks_resolved' => true]],
        ]);
        self::assertSame(1, $sast['critical_sast']);

        $cve = AtlasDevGateAdapter::securityFromScan([
            'status' => 'failed',
            'tools' => [['slug' => 'osv_scanner', 'category' => 'security', 'status' => 'failed']],
            'findings' => [['tool' => 'osv_scanner', 'category' => 'security', 'blocks_resolved' => true]],
        ]);
        self::assertSame(1, $cve['critical_cve']);

        // a non-blocking or non-security finding never trips the cut
        $noise = AtlasDevGateAdapter::securityFromScan([
            'status' => 'failed',
            'tools' => [['slug' => 'gitleaks', 'category' => 'security', 'status' => 'passed'], ['slug' => 'phpstan', 'category' => 'quality', 'status' => 'failed']],
            'findings' => [['tool' => 'phpstan', 'category' => 'quality', 'blocks_resolved' => true]],
        ]);
        self::assertTrue($noise['secret_free']);
        self::assertSame(0, $noise['critical_sast']);
    }
}
