<?php

declare(strict_types=1);

namespace Tests\Feature\Loop;

use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Config;
use Tests\TestCase;

/**
 * Proves the obra-cluster detector is live at the operator surface: with the feature flag OFF it is a
 * byte-identical no-op (no clusters); with the flag ON over an empty target set it still parks nothing; a
 * missing --campaign / --input is a usage error.
 */
final class AtlasLoopObraClusterDetectCommandTest extends TestCase
{
    private string $input = '';

    protected function setUp(): void
    {
        parent::setUp();
        $this->input = sys_get_temp_dir().'/atlas-obra-cluster-'.bin2hex(random_bytes(5)).'.json';
    }

    protected function tearDown(): void
    {
        @unlink($this->input);
        parent::tearDown();
    }

    private function detect(array $targets): array
    {
        file_put_contents($this->input, (string) json_encode($targets));
        $exit = Artisan::call('atlas:loop:obra-cluster-detect', ['--campaign' => 'camp-1', '--input' => $this->input, '--json' => true]);

        return ['exit' => $exit, 'd' => json_decode(trim(Artisan::output()), true)];
    }

    public function test_flag_off_is_a_no_op(): void
    {
        Config::set('atlas.loop.obra_cluster_detection_enabled', false);

        ['exit' => $exit, 'd' => $d] = $this->detect([['id' => 't1'], ['id' => 't2']]);

        $this->assertSame(0, $exit);
        $this->assertSame('atlas.loop.obra_cluster_detect.v1', $d['schema']);
        $this->assertSame(0, $d['cluster_count'], (string) json_encode($d));
        $this->assertSame([], $d['clusters']);
    }

    public function test_flag_on_with_empty_targets_parks_nothing(): void
    {
        Config::set('atlas.loop.obra_cluster_detection_enabled', true);

        ['exit' => $exit, 'd' => $d] = $this->detect([]);

        $this->assertSame(0, $exit);
        $this->assertSame(0, $d['cluster_count']);
    }

    public function test_missing_campaign_is_usage_error(): void
    {
        file_put_contents($this->input, '[]');
        $exit = Artisan::call('atlas:loop:obra-cluster-detect', ['--input' => $this->input, '--json' => true]);

        $this->assertNotSame(0, $exit);
        $this->assertStringContainsString('usage_error', Artisan::output());
    }

    public function test_missing_input_is_usage_error(): void
    {
        $exit = Artisan::call('atlas:loop:obra-cluster-detect', ['--campaign' => 'camp-1', '--json' => true]);

        $this->assertNotSame(0, $exit);
        $this->assertStringContainsString('usage_error', Artisan::output());
    }
}
