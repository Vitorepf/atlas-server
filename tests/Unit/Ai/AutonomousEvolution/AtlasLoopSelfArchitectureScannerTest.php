<?php

declare(strict_types=1);

namespace Tests\Unit\Ai\AutonomousEvolution;

use App\Services\Ai\AutonomousEvolution\Consolidation\AtlasLoopSelfArchitectureScanner as ConsolidationScanner;
use App\Services\Ai\AutonomousEvolution\Introspection\AtlasLoopSelfArchitectureScanner;
use InvalidArgumentException;
use Tests\TestCase;

class AtlasLoopSelfArchitectureScannerTest extends TestCase
{
    public function test_two_consecutive_scans_are_byte_identical(): void
    {
        $scanner = new AtlasLoopSelfArchitectureScanner();
        $a = $scanner->scan();
        $b = $scanner->scan();

        self::assertSame(json_encode($a), json_encode($b));
    }

    public function test_output_contains_zero_scalar_score_grade_or_health_field_at_top_level(): void
    {
        $verdict = (new AtlasLoopSelfArchitectureScanner)->scan();

        foreach (array_keys($verdict) as $key) {
            self::assertDoesNotMatchRegularExpression('/(score|grade|health|rating)/i', (string) $key);
        }
    }

    public function test_passing_path_outside_autonomous_evolution_throws(): void
    {
        $scanner = new AtlasLoopSelfArchitectureScanner();
        $this->expectException(InvalidArgumentException::class);
        $scanner->scan(base_path('app/Services/Engineering'));
    }

    public function test_scan_reports_expected_primitive_categories_and_substantial_class_count(): void
    {
        $verdict = (new AtlasLoopSelfArchitectureScanner)->scan();

        self::assertGreaterThan(50, $verdict['class_count'], 'AutonomousEvolution must have substantial class count');
        foreach (['Brain', 'Origination', 'Certification'] as $cat) {
            self::assertArrayHasKey($cat, $verdict['primitives_by_category']);
        }
        self::assertGreaterThan(0, $verdict['file_count']);
        self::assertNotEmpty($verdict['namespace_tree']);
    }

    public function test_output_carries_facts_only_with_no_ranking_field(): void
    {
        $verdict = (new AtlasLoopSelfArchitectureScanner)->scan();

        foreach (['file_count', 'class_count', 'namespace_tree', 'primitives_by_category', 'methods_by_class'] as $key) {
            self::assertArrayHasKey($key, $verdict, "missing expected fact: {$key}");
        }
        self::assertArrayNotHasKey('ranking', $verdict);
        self::assertArrayNotHasKey('top', $verdict);
    }

    // --- Consolidation scanner tests ---

    public function test_consolidation_scanner_emits_deterministic_snapshot_for_fixture(): void
    {
        $dir = sys_get_temp_dir().'/atlas-consol-scan-'.bin2hex(random_bytes(4));
        mkdir($dir, 0755, true);
        file_put_contents($dir.'/Alpha.php', "<?php\n\nnamespace App\\Services\\Ai\\AutonomousEvolution;\n\nuse App\\Services\\Ai\\AutonomousEvolution\\Beta;\n\nclass Alpha {}\n");
        file_put_contents($dir.'/Beta.php', "<?php\n\nnamespace App\\Services\\Ai\\AutonomousEvolution;\n\nclass Beta {}\n");
        file_put_contents($dir.'/AtlasLoopQueueRefiller.php', "<?php\n\nnamespace App\\Services\\Ai\\AutonomousEvolution;\n\nuse App\\Services\\Ai\\AutonomousEvolution\\Alpha;\nuse App\\Services\\Ai\\AutonomousEvolution\\Beta;\n\nclass AtlasLoopQueueRefiller\n{\n    public function run(): void {}\n}\n");

        try {
            $scanner = new ConsolidationScanner;
            $result = $scanner->scan($dir);

            self::assertSame(ConsolidationScanner::SCHEMA, $result['schema_version']);
            self::assertSame(3, $result['fileCount']);
            self::assertSame(15, $result['totalLoc']);
            self::assertSame(8, $result['refillerLoc']);
            self::assertSame(['App\\Services\\Ai\\AutonomousEvolution\\Beta'], $result['perFileEdges']['Alpha.php']);
            self::assertSame([], $result['perFileEdges']['Beta.php']);

            $firstRun = json_encode($result, JSON_UNESCAPED_SLASHES);
            $secondRun = json_encode($scanner->scan($dir), JSON_UNESCAPED_SLASHES);
            self::assertSame($firstRun, $secondRun, 'scan must be deterministic');
        } finally {
            array_map('unlink', glob($dir.'/*') ?: []);
            @rmdir($dir);
        }
    }

    public function test_consolidation_scanner_empty_directory_returns_zero(): void
    {
        $dir = sys_get_temp_dir().'/atlas-consol-empty-'.bin2hex(random_bytes(4));
        mkdir($dir, 0755, true);

        try {
            $result = (new ConsolidationScanner)->scan($dir);
            self::assertSame(0, $result['totalLoc']);
            self::assertSame(0, $result['refillerLoc']);
            self::assertSame(0, $result['fileCount']);
        } finally {
            @rmdir($dir);
        }
    }
}
