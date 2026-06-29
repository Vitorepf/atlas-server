<?php

declare(strict_types=1);

namespace Tests\Feature\Loop;

use FilesystemIterator;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Config;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use Tests\TestCase;

/**
 * Proves the maestro replenisher feedback renderer is live at the operator surface: with the flag ON and a
 * well-supported mined pattern, it renders a non-empty facts block; with the flag OFF it renders an empty block.
 */
final class AtlasLoopReplenisherFeedbackCommandTest extends TestCase
{
    private string $storage = '';

    protected function setUp(): void
    {
        parent::setUp();
        // Isolate the closed-loop receipt-ledger writes the renderer performs.
        $this->storage = sys_get_temp_dir().'/atlas-replenisher-fb-'.bin2hex(random_bytes(5));
        @mkdir($this->storage, 0o755, true);
        $this->app->useStoragePath($this->storage);
    }

    protected function tearDown(): void
    {
        if (is_dir($this->storage)) {
            $it = new RecursiveIteratorIterator(
                new RecursiveDirectoryIterator($this->storage, FilesystemIterator::SKIP_DOTS),
                RecursiveIteratorIterator::CHILD_FIRST,
            );
            foreach ($it as $f) {
                $f->isDir() ? @rmdir($f->getPathname()) : @unlink($f->getPathname());
            }
            @rmdir($this->storage);
        }
        parent::tearDown();
    }

    private function render(array $facts): array
    {
        $exit = Artisan::call('atlas:loop:replenisher-feedback', [
            '--facts' => (string) json_encode($facts),
            '--json' => true,
        ]);

        return ['exit' => $exit, 'd' => json_decode(trim(Artisan::output()), true)];
    }

    public function test_flag_on_with_supported_pattern_renders_non_empty_block(): void
    {
        Config::set('atlas.maestro.closed_loop.feedback_enabled', true);

        ['exit' => $exit, 'd' => $d] = $this->render([
            'provider' => [
                'claude' => ['total' => 8, 'delivered' => 6, 'insufficient_support' => false, 'delivery_rate' => 0.75],
            ],
        ]);

        $this->assertSame(0, $exit);
        $this->assertSame('atlas.loop.replenisher_feedback.v1', $d['schema']);
        $this->assertFalse($d['empty'], (string) json_encode($d));
        $this->assertNotSame('', $d['facts_block']);
        $this->assertStringContainsString('provider=claude', $d['facts_block']);
    }

    public function test_flag_off_renders_empty_block(): void
    {
        Config::set('atlas.maestro.closed_loop.feedback_enabled', false);

        ['exit' => $exit, 'd' => $d] = $this->render([
            'provider' => ['claude' => ['total' => 8, 'delivered' => 6, 'insufficient_support' => false]],
        ]);

        $this->assertSame(0, $exit);
        $this->assertTrue($d['empty']);
        $this->assertSame('', $d['facts_block']);
    }

    public function test_invalid_facts_is_usage_error(): void
    {
        $exit = Artisan::call('atlas:loop:replenisher-feedback', ['--facts' => 'not json', '--json' => true]);

        $this->assertNotSame(0, $exit);
        $this->assertStringContainsString('usage_error', Artisan::output());
    }
}
