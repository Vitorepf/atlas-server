<?php

declare(strict_types=1);

namespace Tests\Feature\Loop;

use Illuminate\Support\Facades\Artisan;
use Tests\TestCase;

/**
 * Proves the Cortex Council CLI: registered as `atlas:loop:cortex:council`; flag-OFF default path emits a
 * disabled JSON line and exits 0 (byte-identical no-op); flag-ON path runs the triangulator over the
 * registered lenses and emits a valid CouncilReport JSON.
 */
final class AtlasLoopCortexCouncilCommandTest extends TestCase
{
    public function test_artisan_command_atlas_loop_cortex_council_is_registered(): void
    {
        $this->assertArrayHasKey('atlas:loop:cortex:council', Artisan::all());
    }

    public function test_flag_off_emits_disabled_status_and_exits_zero(): void
    {
        config(['atlas.cortex.council.enabled' => false]);

        $exit = Artisan::call('atlas:loop:cortex:council', ['--subject' => 'demo', '--json' => true]);
        $output = trim(Artisan::output());

        $this->assertSame(0, $exit, 'byte-identical no-op exits 0');
        $decoded = json_decode($output, true);
        $this->assertIsArray($decoded);
        $this->assertSame('disabled', $decoded['status']);
    }

    public function test_flag_on_prints_valid_council_report_json(): void
    {
        config(['atlas.cortex.council.enabled' => true]);

        // Provide an inline php source so lenses with source-code path observe it without disk access.
        $source = "<?php\n/**\n * Sample.\n */\nclass Sample {\n    public function go(): void {}\n}\n";
        $exit = Artisan::call('atlas:loop:cortex:council', [
            '--subject' => 'Sample',
            '--source-code' => $source,
            '--json' => true,
        ]);
        $this->assertSame(0, $exit);

        $output = trim(Artisan::output());
        $decoded = json_decode($output, true);
        $this->assertIsArray($decoded, 'output is valid JSON');
        $this->assertSame('Sample', $decoded['subject_id']);
        foreach (['raw_facts_by_lens', 'agreements', 'disagreements', 'participating_lens_ids'] as $key) {
            $this->assertArrayHasKey($key, $decoded);
        }
        // Pétreo: no scoring/verdict key smuggled in.
        foreach (array_keys($decoded) as $key) {
            $this->assertDoesNotMatchRegularExpression('/score|verdict|rank|winner/i', (string) $key);
        }
    }

    public function test_missing_subject_returns_usage_error(): void
    {
        config(['atlas.cortex.council.enabled' => true]);

        $exit = Artisan::call('atlas:loop:cortex:council', ['--json' => true]);
        $this->assertNotSame(0, $exit);
        $decoded = json_decode(trim(Artisan::output()), true);
        $this->assertSame('usage_error', $decoded['status']);
    }
}
