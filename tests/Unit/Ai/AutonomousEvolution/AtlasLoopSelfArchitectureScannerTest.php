<?php

declare(strict_types=1);

namespace Tests\Unit\Ai\AutonomousEvolution;

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
}
