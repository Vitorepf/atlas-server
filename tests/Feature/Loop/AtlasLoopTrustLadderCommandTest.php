<?php

declare(strict_types=1);

namespace Tests\Feature\Loop;

use Illuminate\Support\Facades\Artisan;
use Tests\TestCase;

/**
 * Proves the trust ladder is live at the operator surface and emits the deterministic rung verdict: a long
 * spotless record earns autonomous_merge; a strong-but-shorter record is trusted_review (parked); a thin or
 * unproven record is park_only. A missing --input is a usage error.
 */
final class AtlasLoopTrustLadderCommandTest extends TestCase
{
    /** @var list<string> */
    private array $files = [];

    protected function tearDown(): void
    {
        foreach ($this->files as $file) {
            if (is_file($file)) {
                @unlink($file);
            }
        }
        parent::tearDown();
    }

    public function test_requires_input(): void
    {
        $exit = Artisan::call('atlas:loop:trust-ladder', ['--json' => true]);
        $decoded = json_decode(trim(Artisan::output()), true);

        $this->assertNotSame(0, $exit);
        $this->assertSame('usage_error', $decoded['status']);
    }

    public function test_long_spotless_record_is_autonomous(): void
    {
        $decoded = $this->invoke(['successes' => 200, 'failures' => 0]);

        $this->assertSame('atlas.loop.trust_ladder.v1', $decoded['schema']);
        $this->assertSame('autonomous_merge', $decoded['level']);
        $this->assertTrue($decoded['can_auto_merge']);
        $this->assertGreaterThanOrEqual(0.9, $decoded['wilson_lower']);
    }

    public function test_strong_record_is_trusted_but_parked(): void
    {
        $decoded = $this->invoke(['successes' => 30, 'failures' => 2]);

        $this->assertSame('trusted_review', $decoded['level']);
        $this->assertFalse($decoded['can_auto_merge']);
        $this->assertGreaterThanOrEqual(0.7, $decoded['wilson_lower']);
        $this->assertLessThan(0.9, $decoded['wilson_lower']);
    }

    public function test_thin_record_is_park_only(): void
    {
        $decoded = $this->invoke(['successes' => 3, 'failures' => 3]);

        $this->assertSame('park_only', $decoded['level']);
        $this->assertFalse($decoded['can_auto_merge']);
    }

    /**
     * @param  array<string,mixed>  $stats
     * @return array<string,mixed>
     */
    private function invoke(array $stats): array
    {
        $path = tempnam(sys_get_temp_dir(), 'trust_').'.json';
        $this->files[] = $path;
        file_put_contents($path, json_encode($stats));

        $exit = Artisan::call('atlas:loop:trust-ladder', ['--input' => $path, '--json' => true]);
        $this->assertSame(0, $exit);

        return json_decode(trim(Artisan::output()), true);
    }
}
