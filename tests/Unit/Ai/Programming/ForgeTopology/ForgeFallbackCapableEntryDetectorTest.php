<?php

declare(strict_types=1);

namespace Tests\Unit\Ai\Programming\ForgeTopology;

use App\Services\Ai\Programming\ForgeTopology\ForgeFallbackCapableEntryDetector;
use PHPUnit\Framework\TestCase;

final class ForgeFallbackCapableEntryDetectorTest extends TestCase
{
    private ForgeFallbackCapableEntryDetector $detector;

    protected function setUp(): void
    {
        $this->detector = new ForgeFallbackCapableEntryDetector();
    }

    public function testMixedChainKeepsOnlyStrictlyCapableRole(): void
    {
        $result = $this->detector->detect([
            ['capable' => true, 'role' => 'critical_reviewer'],
            ['capable' => false, 'role' => 'repair_agent'],
        ]);

        $this->assertSame('atlas.aaeos.forge_fallback_capable.v1', $result['schema_version']);
        $this->assertTrue($result['has_capable_entry']);
        $this->assertSame(['critical_reviewer'], $result['capable_roles']);
        $this->assertSame(1, $result['incapable_count']);
        $this->assertNull($result['defect']);
    }

    public function testAllIncapableEntriesReportDefect(): void
    {
        $result = $this->detector->detect([
            ['capable' => false, 'role' => 'repair_agent'],
            ['capable' => false, 'role' => 'context_scout'],
        ]);

        $this->assertFalse($result['has_capable_entry']);
        $this->assertSame([], $result['capable_roles']);
        $this->assertSame(2, $result['incapable_count']);
        $this->assertSame('no_capable_fallback', $result['defect']);
    }

    public function testEmptyChainReportsDefectWithZeroIncapableCount(): void
    {
        $result = $this->detector->detect([]);

        $this->assertFalse($result['has_capable_entry']);
        $this->assertSame([], $result['capable_roles']);
        $this->assertSame(0, $result['incapable_count']);
        $this->assertSame('no_capable_fallback', $result['defect']);
    }

    public function testIntegerOneIsNotStrictlyCapable(): void
    {
        $result = $this->detector->detect([
            ['capable' => 1, 'role' => 'critical_reviewer'],
        ]);

        $this->assertFalse($result['has_capable_entry']);
        $this->assertSame([], $result['capable_roles']);
        $this->assertSame(1, $result['incapable_count']);
        $this->assertSame('no_capable_fallback', $result['defect']);
    }

    public function testTwoCapableEntriesPreserveInputOrder(): void
    {
        $result = $this->detector->detect([
            ['capable' => true, 'role' => 'critical_reviewer'],
            ['capable' => true, 'role' => 'context_scout'],
        ]);

        $this->assertTrue($result['has_capable_entry']);
        $this->assertSame(['critical_reviewer', 'context_scout'], $result['capable_roles']);
        $this->assertSame(0, $result['incapable_count']);
        $this->assertNull($result['defect']);
    }

    public function testStringTrueIsNotStrictlyCapable(): void
    {
        $result = $this->detector->detect([
            ['capable' => 'true', 'role' => 'critical_reviewer'],
        ]);

        $this->assertFalse($result['has_capable_entry']);
        $this->assertSame([], $result['capable_roles']);
        $this->assertSame(1, $result['incapable_count']);
        $this->assertSame('no_capable_fallback', $result['defect']);
    }
}
