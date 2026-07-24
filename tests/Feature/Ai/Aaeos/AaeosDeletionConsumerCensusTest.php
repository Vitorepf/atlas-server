<?php

declare(strict_types=1);

namespace Tests\Feature\Ai\Aaeos;

use App\Services\Ai\Aaeos\Control\AaeosDeletionConsumerCensus;
use Tests\TestCase;

/**
 * P3a: consumer census lock — no mass delete; every production consumer named.
 */
final class AaeosDeletionConsumerCensusTest extends TestCase
{
    public function test_census_forbids_mass_delete_and_names_all_families(): void
    {
        $report = AaeosDeletionConsumerCensus::report();

        $this->assertSame(AaeosDeletionConsumerCensus::SCHEMA, $report['schema']);
        $this->assertSame('P3a', $report['phase']);
        $this->assertTrue($report['mass_delete_forbidden']);
        $this->assertFalse($report['delete_authorized_in_p3a']);
        $this->assertTrue($report['p3b_requires_master_amendment']);
        $this->assertTrue($report['pipeline_run_executor_retain']);

        foreach (array_keys(AaeosDeletionConsumerCensus::FROZEN_PRODUCTION_CONSUMERS) as $family) {
            $this->assertArrayHasKey($family, $report['families']);
            $this->assertFalse($report['families'][$family]['delete_authorized_in_p3a']);
        }
    }

    public function test_frozen_inventory_matches_live_disk_scan(): void
    {
        $report = AaeosDeletionConsumerCensus::report();

        $this->assertSame([], $report['unknown_production_consumers'], json_encode($report['unknown_production_consumers']));
        $this->assertSame([], $report['missing_owners']);
        $this->assertTrue($report['freeze_matches_live']);

        foreach ($report['families'] as $family => $row) {
            $this->assertSame(
                $row['production_consumers_frozen'],
                $row['production_consumers_live'],
                "family {$family} freeze/live mismatch",
            );
            $this->assertSame([], $row['new_on_disk_not_in_freeze']);
            $this->assertSame([], $row['frozen_missing_on_disk']);
        }
    }

    public function test_pipeline_run_executor_has_named_production_consumers_and_is_retained(): void
    {
        $report = AaeosDeletionConsumerCensus::report();
        $pre = $report['families']['PipelineRunExecutor_family'];

        $this->assertGreaterThanOrEqual(10, $pre['production_consumer_count']);
        $this->assertContains(
            'app/Services/Ai/Programming/AtlasForgeHermesCliInvocationDriver.php',
            $pre['production_consumers_frozen'],
        );
        $this->assertTrue($report['pipeline_run_executor_retain']);
        $this->assertStringContainsString('R103', $pre['retain_reason']);
    }

    public function test_org_state_and_outcome_recorder_still_have_production_readers(): void
    {
        $report = AaeosDeletionConsumerCensus::report();

        $org = $report['families']['AaeosOrgStateProjector'];
        $this->assertContains('app/Console/Commands/AtlasCliCockpitCommand.php', $org['production_consumers_frozen']);
        $this->assertContains('app/Services/Ai/Aaeos/Control/AaeosScorecardProjector.php', $org['production_consumers_frozen']);

        $rec = $report['families']['AaeosCycleOutcomeRecorder'];
        $this->assertContains('app/Console/Commands/AtlasAaeosRunCommand.php', $rec['production_consumers_frozen']);
        $this->assertContains('app/Console/Commands/AtlasAaeosCycleCommand.php', $rec['production_consumers_frozen']);
    }

    public function test_p3b_deleted_families_are_recorded_and_absent_from_live_families(): void
    {
        $report = AaeosDeletionConsumerCensus::report();

        foreach (AaeosDeletionConsumerCensus::DELETED_IN_P3B as $family) {
            $this->assertContains($family, $report['deleted_in_p3b']);
            $this->assertArrayNotHasKey($family, $report['families']);
        }
        $this->assertFileDoesNotExist(base_path('app/Services/Ai/Compat/AaeosHygieneLegacyAliases.php'));
        $this->assertFileDoesNotExist(base_path('app/Console/Commands/AtlasTriHygieneScorecardCommand.php'));
    }

    public function test_owners_still_exist_on_disk_no_silent_delete(): void
    {
        foreach (AaeosDeletionConsumerCensus::OWNERS as $owners) {
            foreach ($owners as $path) {
                $this->assertFileExists(base_path($path), $path);
            }
        }
    }
}
