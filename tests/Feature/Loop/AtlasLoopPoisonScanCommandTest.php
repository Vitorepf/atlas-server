<?php

declare(strict_types=1);

namespace Tests\Feature\Loop;

use App\Services\Ai\SelfConstruction\TaskQuality\AtlasTaskHiddenPoisonDetector;
use Illuminate\Support\Facades\Artisan;
use Tests\TestCase;

/**
 * Proves the hidden-poison detector is live at the operator surface: a packet whose objective bakes in a
 * permanent-autonomy dependency (and whose allowed_files collide on a canonical symbol) is flagged; a clean
 * packet reports no findings.
 */
final class AtlasLoopPoisonScanCommandTest extends TestCase
{
    private function scan(array $packet): array
    {
        $exit = Artisan::call('atlas:loop:poison-scan', [
            '--packet' => (string) json_encode($packet),
            '--json' => true,
        ]);

        return ['exit' => $exit, 'd' => json_decode(trim(Artisan::output()), true)];
    }

    public function test_poison_packet_is_flagged(): void
    {
        ['exit' => $exit, 'd' => $d] = $this->scan([
            'objective' => 'Implement the feature; operator approval required before each run.',
            'acceptance_criteria' => ['it works'],
            'allowed_files' => ['app/A/Foo.php', 'app/B/Foo.php'], // duplicate canonical symbol Foo.php
        ]);

        $this->assertSame(0, $exit);
        $this->assertSame(AtlasTaskHiddenPoisonDetector::SCHEMA, $d['schema_version']);
        $this->assertFalse($d['clean'], (string) json_encode($d));
        $patterns = array_column($d['found_patterns'], 'pattern_id');
        $this->assertContains(AtlasTaskHiddenPoisonDetector::PATTERN_PERMANENT_AUTONOMY_DEP, $patterns);
        $this->assertContains(AtlasTaskHiddenPoisonDetector::PATTERN_DUPLICATE_CANONICAL_SYMBOL, $patterns);
    }

    public function test_clean_packet_has_no_findings(): void
    {
        ['exit' => $exit, 'd' => $d] = $this->scan([
            'objective' => 'Implement the widget renderer in app/Widget.php and add a passing test.',
            'acceptance_criteria' => ['the widget renders'],
            'allowed_files' => ['app/Widget.php', 'tests/Unit/WidgetTest.php'],
            'required_evidence_kinds' => ['tests_or_gates_result'],
        ]);

        $this->assertSame(0, $exit);
        $this->assertTrue($d['clean'], (string) json_encode($d));
        $this->assertSame([], $d['found_patterns']);
    }

    public function test_empty_packet_is_usage_error(): void
    {
        $exit = Artisan::call('atlas:loop:poison-scan', ['--json' => true]);

        $this->assertNotSame(0, $exit);
        $this->assertStringContainsString('usage_error', Artisan::output());
    }
}
