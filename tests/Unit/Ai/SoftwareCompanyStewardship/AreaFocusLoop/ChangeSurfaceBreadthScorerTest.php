<?php

declare(strict_types=1);

namespace Tests\Unit\Ai\SoftwareCompanyStewardship\AreaFocusLoop;

use App\Services\Ai\SoftwareCompanyStewardship\AreaFocusLoop\ChangeSurfaceBreadthScorer;
use App\Services\Ai\SoftwareCompanyStewardship\AreaFocusLoop\FindingSlicePlannerService;
use PHPUnit\Framework\TestCase;

final class ChangeSurfaceBreadthScorerTest extends TestCase
{
    private ChangeSurfaceBreadthScorer $scorer;

    protected function setUp(): void
    {
        $this->scorer = new ChangeSurfaceBreadthScorer();
    }

    public function testProductionFileWithPairedTestIsNarrow(): void
    {
        $result = $this->scorer->score([
            'app/Services/Ai/X.php',
            'tests/Unit/Ai/XTest.php',
        ]);

        $this->assertSame(ChangeSurfaceBreadthScorer::SCHEMA_VERSION, $result['schema_version']);
        $this->assertSame(2, $result['file_count']);
        $this->assertSame(1, $result['layer_count']);
        $this->assertSame(1, $result['directory_count']);
        $this->assertSame('narrow', $result['breadth_band']);
        $this->assertTrue($result['bounded']);
        $this->assertSame(['within_breadth_budget'], $result['reasons']);
    }

    public function testThreeProductionLayersAreBroad(): void
    {
        $result = $this->scorer->score([
            'app/Services/Ai/A.php',
            'app/Console/B.php',
            'app/Models/C.php',
        ]);

        $this->assertSame('broad', $result['breadth_band']);
        $this->assertSame(3, $result['layer_count']);
        $this->assertFalse($result['bounded']);
        $this->assertTrue(in_array('multiple_layers', $result['reasons'], true));
    }

    public function testMoreThanCanonicalMaxFilesInSingleLayerIsBroad(): void
    {
        $files = [];
        for ($i = 1; $i <= FindingSlicePlannerService::MAX_FILES_PER_SLICE + 1; $i++) {
            $files[] = 'app/Services/Ai/File'.$i.'.php';
        }

        $result = $this->scorer->score($files);

        $this->assertSame(FindingSlicePlannerService::MAX_FILES_PER_SLICE + 1, $result['file_count']);
        $this->assertSame(1, $result['layer_count']);
        $this->assertSame(1, $result['directory_count']);
        $this->assertSame('broad', $result['breadth_band']);
        $this->assertTrue(in_array('too_many_files', $result['reasons'], true));
    }

    public function testDirectorySpreadCanMakeSmallSliceBroad(): void
    {
        $result = $this->scorer->score([
            'app/One/A.php',
            'app/Two/B.php',
            'app/Three/C.php',
        ]);

        $this->assertSame('broad', $result['breadth_band']);
        $this->assertSame(3, $result['directory_count']);
        $this->assertTrue(in_array('directory_spread', $result['reasons'], true));
    }

    public function testEmptyAllowedFilesFailClosed(): void
    {
        $result = $this->scorer->score([]);

        $this->assertSame('broad', $result['breadth_band']);
        $this->assertFalse($result['bounded']);
        $this->assertSame(['no_allowed_files'], $result['reasons']);
        $this->assertSame(0, $result['file_count']);
        $this->assertSame(0, $result['layer_count']);
        $this->assertSame(0, $result['directory_count']);
        $this->assertSame(0.0, $result['breadth_score']);
    }

    public function testBreadthScoreIncreasesWithBroaderSurface(): void
    {
        $narrow = $this->scorer->score(['app/Services/Ai/X.php']);
        $broad = $this->scorer->score([
            'app/Services/Ai/A.php',
            'app/Console/B.php',
            'app/Models/C.php',
            'app/Http/D.php',
            'app/View/E.php',
        ]);

        $this->assertLessThan($broad['breadth_score'], $narrow['breadth_score']);
    }

    public function testNormalizesBackslashesLeadingSlashAndDuplicates(): void
    {
        $result = $this->scorer->score([
            '/app/Services/Ai/X.php',
            '\\app\\Services\\Ai\\X.php',
            ' tests/Unit/Ai/XTest.php ',
        ]);

        $this->assertSame(2, $result['file_count']);
        $this->assertSame(1, $result['layer_count']);
    }
}
