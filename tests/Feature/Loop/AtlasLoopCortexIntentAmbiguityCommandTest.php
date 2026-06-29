<?php

declare(strict_types=1);

namespace Tests\Feature\Loop;

use Illuminate\Support\Facades\Artisan;
use Tests\TestCase;

/**
 * Proves the Cortex intent-meaning ambiguity detector is live at the operator surface: one distinct grounded
 * site (even if repeated) is unique and proceeds; two distinct sites are ambiguous; zero sites require
 * clarification.
 */
final class AtlasLoopCortexIntentAmbiguityCommandTest extends TestCase
{
    private string $input = '';

    protected function setUp(): void
    {
        parent::setUp();
        $this->input = sys_get_temp_dir().'/atlas-intent-ambig-'.bin2hex(random_bytes(5)).'.json';
    }

    protected function tearDown(): void
    {
        @unlink($this->input);
        parent::tearDown();
    }

    private function detect(array $facts): array
    {
        file_put_contents($this->input, (string) json_encode($facts));
        $exit = Artisan::call('atlas:loop:cortex-intent-ambiguity', ['--input' => $this->input, '--json' => true]);

        return ['exit' => $exit, 'd' => json_decode(trim(Artisan::output()), true)];
    }

    public function test_single_site_repeated_is_unique(): void
    {
        ['exit' => $exit, 'd' => $d] = $this->detect([
            ['symbol' => 'Foo', 'file' => 'a.php'],
            ['symbol' => 'Foo', 'file' => 'a.php'], // dedup ⇒ still one site
        ]);

        $this->assertSame(0, $exit);
        $this->assertSame('atlas.loop.cortex_intent_ambiguity.v1', $d['schema']);
        $this->assertFalse($d['ambiguous'], (string) json_encode($d));
        $this->assertSame(1, $d['site_count']);
        $this->assertFalse($d['clarification_required']);
        $this->assertSame('unique_grounded_site', $d['reason']);
    }

    public function test_two_distinct_sites_are_ambiguous(): void
    {
        ['d' => $d] = $this->detect([
            ['symbol' => 'Foo', 'file' => 'a.php'],
            ['symbol' => 'Bar', 'file' => 'b.php'],
        ]);

        $this->assertTrue($d['ambiguous']);
        $this->assertSame(2, $d['site_count']);
        $this->assertTrue($d['clarification_required']);
        $this->assertSame('multiple_grounded_sites', $d['reason']);
    }

    public function test_zero_sites_requires_clarification(): void
    {
        ['d' => $d] = $this->detect([]);

        $this->assertFalse($d['ambiguous']);
        $this->assertSame(0, $d['site_count']);
        $this->assertTrue($d['clarification_required']);
        $this->assertSame('no_grounded_site', $d['reason']);
    }

    public function test_missing_input_is_usage_error(): void
    {
        $exit = Artisan::call('atlas:loop:cortex-intent-ambiguity', ['--json' => true]);

        $this->assertNotSame(0, $exit);
        $this->assertStringContainsString('usage_error', Artisan::output());
    }
}
