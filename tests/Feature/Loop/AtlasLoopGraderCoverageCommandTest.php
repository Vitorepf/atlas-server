<?php

declare(strict_types=1);

namespace Tests\Feature\Loop;

use App\Services\Ai\AutonomousEvolution\V3\AtlasLoopV3GraderRegistry;
use Illuminate\Support\Facades\Artisan;
use Tests\TestCase;

/**
 * Proves the V3 grader registry is live at the operator surface: the command lists every claim class with an
 * installed flag, and installed_count + missing_count equals the number of claim classes.
 */
final class AtlasLoopGraderCoverageCommandTest extends TestCase
{
    public function test_grader_coverage_lists_every_claim_class_with_installed_flag(): void
    {
        $exit = Artisan::call('atlas:loop:grader-coverage', ['--json' => true]);
        $decoded = json_decode(trim(Artisan::output()), true);

        $this->assertSame(0, $exit);

        $claimClasses = app(AtlasLoopV3GraderRegistry::class)->claimClasses();
        $this->assertCount(count($claimClasses), $decoded['coverage']);

        $covered = [];
        foreach ($decoded['coverage'] as $record) {
            $this->assertArrayHasKey('installed', $record);
            $this->assertIsBool($record['installed']);
            $covered[$record['claim_class']] = true;
        }
        foreach ($claimClasses as $claimClass) {
            $this->assertArrayHasKey($claimClass, $covered, "claim class {$claimClass} must be listed");
        }

        $this->assertSame(count($claimClasses), $decoded['installed_count'] + $decoded['missing_count']);
    }
}
