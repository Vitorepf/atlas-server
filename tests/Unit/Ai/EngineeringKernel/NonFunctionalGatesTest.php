<?php

declare(strict_types=1);

namespace Tests\Unit\Ai\EngineeringKernel;

use App\Services\Ai\EngineeringKernel\Adapters\AtlasDevGateAdapter;
use App\Services\Ai\EngineeringKernel\CertVerdict;
use App\Services\Ai\EngineeringKernel\NonFunctional\ArchitectureRegressionProbe;
use App\Services\Ai\EngineeringKernel\NonFunctional\MigrationSafetyProbe;
use App\Services\Ai\EngineeringKernel\SovereignHonestyFloor;
use App\Services\Ai\EngineeringKernel\TrustLevel;
use PHPUnit\Framework\TestCase;

/**
 * Obra #3 — the non-functional gates. Golden corpus at the floor + unit proofs of the two probes.
 * Each gate WAIVES on N/A (so an honest functional change still promotes) and FAILS CLOSED when its
 * surface is touched but unproven. Wiper-safe: pure floor, zero DB, zero Laravel bootstrap.
 */
final class NonFunctionalGatesTest extends TestCase
{
    private const MIGRATION = 'database/migrations/2026_07_04_000000_touch.php';

    private function floor(): SovereignHonestyFloor
    {
        return new SovereignHonestyFloor(configMutationFloor: 0.0);
    }

    // --- no regression: the four gates never block an honest functional-only change ---

    public function test_an_honest_bundle_still_promotes_with_all_four_gates_live(): void
    {
        $verdict = $this->floor()->certify(AcceptanceBundleFactory::honest(), TrustLevel::Dev);

        self::assertSame(CertVerdict::PROMOTE, $verdict->status, 'blockers: '.implode(',', $verdict->blockers));
        foreach (['performance_budget', 'migration_safety', 'architecture_no_regression', 'property_clean_for_tagged'] as $slot) {
            self::assertSame('pass', $verdict->invariants[$slot]['status'], $slot);
        }
    }

    // --- migration_safety: the direct guard against the table-wiper scar ---

    public function test_touching_a_migration_without_a_safety_probe_is_fail_closed(): void
    {
        $bundle = AcceptanceBundleFactory::honest(['changed_files' => [self::MIGRATION]]);

        $verdict = $this->floor()->certify($bundle, TrustLevel::Dev);

        self::assertSame(CertVerdict::REFUSE, $verdict->status);
        self::assertContains('migration_safety', $verdict->blockers);
        self::assertSame('migration_touched_without_safety_probe', $verdict->invariants['migration_safety']['detail']);
    }

    public function test_a_safe_probed_migration_promotes(): void
    {
        $bundle = AcceptanceBundleFactory::honest([
            'changed_files' => [self::MIGRATION],
            'non_functional' => ['migration_safety' => ['probed' => true, 'safe' => true, 'reasons' => []]],
        ]);

        $verdict = $this->floor()->certify($bundle, TrustLevel::Dev);

        self::assertSame(CertVerdict::PROMOTE, $verdict->status, 'blockers: '.implode(',', $verdict->blockers));
    }

    public function test_an_unsafe_probed_migration_is_refused(): void
    {
        $bundle = AcceptanceBundleFactory::honest([
            'changed_files' => [self::MIGRATION],
            'non_functional' => ['migration_safety' => ['probed' => true, 'safe' => false, 'reasons' => [self::MIGRATION.':data_wipe(truncate()']]],
        ]);

        $verdict = $this->floor()->certify($bundle, TrustLevel::Dev);

        self::assertSame(CertVerdict::REFUSE, $verdict->status);
        self::assertContains('migration_safety', $verdict->blockers);
    }

    public function test_migration_probe_flags_data_wipe_and_irreversible_drop_and_clears_reversible(): void
    {
        // truncate = data wipe, ALWAYS unsafe (no down can restore rows)
        $wipe = MigrationSafetyProbe::probe([self::MIGRATION => '<?php $table->truncate();']);
        self::assertFalse($wipe['safe']);
        self::assertTrue($wipe['applies']);

        // dropColumn with an empty down() = irreversible schema loss = unsafe
        $irreversible = MigrationSafetyProbe::probe([self::MIGRATION => '<?php public function up(){ $table->dropColumn("x"); } public function down(){}']);
        self::assertFalse($irreversible['safe']);

        // dropColumn WITH a reversible down() = safe
        $reversible = MigrationSafetyProbe::probe([self::MIGRATION => '<?php public function up(){ $table->dropColumn("x"); } public function down(){ Schema::table("t", fn($table) => $table->string("x")); }']);
        self::assertTrue($reversible['safe']);

        // a purely additive migration = safe
        $additive = MigrationSafetyProbe::probe([self::MIGRATION => '<?php public function up(){ $table->string("y"); }']);
        self::assertTrue($additive['safe']);

        // a non-migration path is ignored entirely
        $ignored = MigrationSafetyProbe::probe(['app/Foo.php' => '<?php $table->truncate();']);
        self::assertFalse($ignored['applies']);
        self::assertTrue($ignored['safe']);
    }

    // --- architecture_no_regression ---

    public function test_a_reported_architecture_violation_is_refused(): void
    {
        $bundle = AcceptanceBundleFactory::honest([
            'non_functional' => ['architecture_no_regression' => ['violations' => ['App\\Services\\Ai\\EngineeringKernel\\X -> Illuminate\\Database\\Y']]],
        ]);

        $verdict = $this->floor()->certify($bundle, TrustLevel::Dev);

        self::assertSame(CertVerdict::REFUSE, $verdict->status);
        self::assertContains('architecture_no_regression', $verdict->blockers);
    }

    public function test_architecture_probe_flags_kernel_importing_eloquent_and_clears_pure_edges(): void
    {
        $violations = ArchitectureRegressionProbe::violations([
            ['from' => 'App\\Services\\Ai\\EngineeringKernel\\SovereignHonestyFloor', 'to' => 'Illuminate\\Database\\Eloquent\\Model'],
            ['from' => 'App\\Services\\Ai\\EngineeringKernel\\SovereignHonestyFloor', 'to' => 'App\\Services\\Ai\\EngineeringKernel\\CriteriaCanonicalizer'],
        ]);

        self::assertCount(1, $violations);
        self::assertStringContainsString('Illuminate\\Database', $violations[0]);
    }

    // --- performance_budget ---

    public function test_performance_over_budget_is_refused_and_within_budget_promotes(): void
    {
        $over = AcceptanceBundleFactory::honest([
            'non_functional' => ['performance_budget' => ['applies' => true, 'budget' => 100, 'measured' => 250]],
        ]);
        self::assertSame(CertVerdict::REFUSE, $this->floor()->certify($over, TrustLevel::Dev)->status);

        $within = AcceptanceBundleFactory::honest([
            'non_functional' => ['performance_budget' => ['applies' => true, 'budget' => 100, 'measured' => 80]],
        ]);
        self::assertSame(CertVerdict::PROMOTE, $this->floor()->certify($within, TrustLevel::Dev)->status);
    }

    public function test_performance_declared_but_unmeasured_is_fail_closed(): void
    {
        $bundle = AcceptanceBundleFactory::honest([
            'non_functional' => ['performance_budget' => ['applies' => true, 'budget' => 100]],
        ]);

        self::assertSame(CertVerdict::REFUSE, $this->floor()->certify($bundle, TrustLevel::Dev)->status);
    }

    // --- property_clean_for_tagged (sovereignty) ---

    public function test_tagged_sensitive_delivery_must_prove_property_clean(): void
    {
        $unchecked = AcceptanceBundleFactory::honest([
            'non_functional' => ['property_clean_for_tagged' => ['tagged' => true, 'checked' => false]],
        ]);
        self::assertSame(CertVerdict::REFUSE, $this->floor()->certify($unchecked, TrustLevel::Dev)->status);

        $violated = AcceptanceBundleFactory::honest([
            'non_functional' => ['property_clean_for_tagged' => ['tagged' => true, 'checked' => true, 'violations' => ['egress_to_external_host']]],
        ]);
        self::assertSame(CertVerdict::REFUSE, $this->floor()->certify($violated, TrustLevel::Dev)->status);

        $clean = AcceptanceBundleFactory::honest([
            'non_functional' => ['property_clean_for_tagged' => ['tagged' => true, 'checked' => true, 'violations' => []]],
        ]);
        self::assertSame(CertVerdict::PROMOTE, $this->floor()->certify($clean, TrustLevel::Dev)->status);
    }

    // --- adapter integration: the probes are wired end to end ---

    public function test_adapter_probes_an_unsafe_migration_from_raw_sources_and_the_gate_refuses(): void
    {
        $adapter = new AtlasDevGateAdapter($this->floor());
        $bundle = $adapter->bundleFromDevEvidence([
            'changed_files' => [self::MIGRATION],
            'changed_public_symbols' => [],
            'execution' => ['commands' => ['php artisan test'], 'claimed_status' => 'passed', 'tests_run' => 3, 'assertions_executed' => 9, 'selected_tests' => ['t'], 'artifacts' => []],
            'criteria_hash' => 'h', 'frozen_hash' => 'h', 'context_sufficiency' => 88,
            'mutation_report' => ['decision_surface_added' => false],
            'security_scan' => ['ran' => true, 'secret_free' => true, 'critical_sast' => 0, 'critical_cve' => 0],
            'judges' => [['provider_family' => 'anthropic', 'approved' => true], ['provider_family' => 'openai', 'approved' => true]],
            'migration_sources' => [self::MIGRATION => '<?php public function up(){ $table->truncate(); } public function down(){}'],
        ]);

        $verdict = $adapter->certify($bundle, TrustLevel::Dev);

        self::assertSame(CertVerdict::REFUSE, $verdict->status);
        self::assertContains('migration_safety', $verdict->blockers);
    }
}
