<?php

declare(strict_types=1);

namespace Tests\Feature\Ai\Aaeos;

use App\Services\Ai\Aaeos\Control\AaeosDeletionConsumerCensus;
use Tests\TestCase;

/**
 * P3b: TriHygiene + HygieneLegacyAliases removed; PipelineRunExecutor retained.
 */
final class AaeosP3bDeletionColdNegativeTest extends TestCase
{
    public function test_trihygiene_classes_and_command_are_absent(): void
    {
        $this->assertFileDoesNotExist(base_path('app/Services/Ai/Aaeos/Control/AaeosTriHygieneScorecardProjector.php'));
        $this->assertFileDoesNotExist(base_path('app/Console/Commands/AtlasTriHygieneScorecardCommand.php'));
        $this->assertFalse(class_exists(\App\Services\Ai\Aaeos\Control\AaeosTriHygieneScorecardProjector::class, false));
        $this->assertFalse(class_exists(\App\Console\Commands\AtlasTriHygieneScorecardCommand::class, false));

        $exit = \Illuminate\Support\Facades\Artisan::call('list', ['--raw' => true]);
        $this->assertSame(0, $exit);
        $out = \Illuminate\Support\Facades\Artisan::output();
        $this->assertStringNotContainsString('atlas:tri-hygiene:scorecard', $out);
    }

    public function test_hygiene_legacy_aliases_file_and_composer_entry_absent(): void
    {
        $this->assertFileDoesNotExist(base_path('app/Services/Ai/Compat/AaeosHygieneLegacyAliases.php'));
        $composer = (string) file_get_contents(base_path('composer.json'));
        $this->assertStringNotContainsString('AaeosHygieneLegacyAliases.php', $composer);
        $this->assertFalse(class_exists(\App\Services\Ai\Compat\AaeosHygieneLegacyAliases::class, false));
    }

    public function test_pipeline_run_executor_and_org_outcome_retained(): void
    {
        $this->assertFileExists(base_path('app/Http/Controllers/AtlasDev/Support/PipelineRunExecutor.php'));
        $this->assertFileExists(base_path('app/Services/Ai/Aaeos/Control/AaeosOrgStateProjector.php'));
        $this->assertFileExists(base_path('app/Services/Ai/Aaeos/Control/AaeosCycleOutcomeRecorder.php'));
        $this->assertFileExists(base_path('app/Services/Ai/Aaeos/Control/AaeosScorecardProjector.php'));
    }

    public function test_census_reports_p3b_deletes_and_freeze_still_matches(): void
    {
        $report = AaeosDeletionConsumerCensus::report();
        $this->assertContains('AaeosTriHygieneScorecardProjector', $report['deleted_in_p3b'] ?? []);
        $this->assertContains('AaeosHygieneLegacyAliases', $report['deleted_in_p3b'] ?? []);
        $this->assertArrayNotHasKey('AaeosTriHygieneScorecardProjector', $report['families']);
        $this->assertTrue($report['freeze_matches_live']);
        $this->assertTrue($report['pipeline_run_executor_retain']);
        $this->assertGreaterThanOrEqual(10, $report['families']['PipelineRunExecutor_family']['production_consumer_count']);
    }
}
