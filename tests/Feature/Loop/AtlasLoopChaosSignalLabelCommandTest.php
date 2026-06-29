<?php

declare(strict_types=1);

namespace Tests\Feature\Loop;

use App\Services\Ai\AutonomousEvolution\Antifragile\AtlasLoopChaosSignalLabeler;
use Illuminate\Support\Facades\Artisan;
use Tests\TestCase;

/**
 * Proves the chaos-signal labeler is live at the operator surface: an event WITH a shape_token mints an
 * attributable, shape-keyed learning label + lesson; an event WITHOUT one refuses to launder a lesson.
 */
final class AtlasLoopChaosSignalLabelCommandTest extends TestCase
{
    private string $input = '';

    protected function setUp(): void
    {
        parent::setUp();
        $this->input = sys_get_temp_dir().'/atlas-chaos-'.bin2hex(random_bytes(5)).'.json';
    }

    protected function tearDown(): void
    {
        @unlink($this->input);
        parent::tearDown();
    }

    private function label(array $event): array
    {
        file_put_contents($this->input, (string) json_encode($event));
        $exit = Artisan::call('atlas:loop:chaos-signal-label', ['--input' => $this->input, '--json' => true]);

        return ['exit' => $exit, 'd' => json_decode(trim(Artisan::output()), true)];
    }

    public function test_event_with_shape_token_is_attributable(): void
    {
        ['exit' => $exit, 'd' => $d] = $this->label([
            'kind' => 'judge_rejected',
            'shape_token' => 'WidgetX',
            'provider' => 'claude',
            'reason' => 'flaky',
        ]);

        $this->assertSame(0, $exit);
        $this->assertSame(AtlasLoopChaosSignalLabeler::SCHEMA_VERSION, $d['schema']);
        $this->assertTrue($d['attributable']);
        $this->assertSame('judge_rejected:widgetx', $d['label']);
        $this->assertSame('WidgetX', $d['shape_token']);
        $this->assertStringContainsString('shape_token=WidgetX', (string) $d['lesson']);
        $this->assertStringContainsString('provider=claude', (string) $d['lesson']);
    }

    public function test_event_without_shape_token_is_unattributable(): void
    {
        ['exit' => $exit, 'd' => $d] = $this->label(['kind' => 'canary_failed']);

        $this->assertSame(0, $exit);
        $this->assertFalse($d['attributable']);
        $this->assertSame('unattributable_canary_failed', $d['label']);
        $this->assertNull($d['shape_token']);
        $this->assertNull($d['lesson']);
    }

    public function test_unknown_kind_normalizes(): void
    {
        ['d' => $d] = $this->label(['kind' => 'meteor_strike', 'shape_token' => 'svc.A']);

        $this->assertTrue($d['attributable']);
        $this->assertSame('unknown_negative_event:svc.a', $d['label']);
    }

    public function test_missing_input_is_usage_error(): void
    {
        $exit = Artisan::call('atlas:loop:chaos-signal-label', ['--json' => true]);

        $this->assertNotSame(0, $exit);
        $this->assertStringContainsString('usage_error', Artisan::output());
    }
}
