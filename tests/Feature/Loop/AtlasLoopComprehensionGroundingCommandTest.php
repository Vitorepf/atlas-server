<?php

declare(strict_types=1);

namespace Tests\Feature\Loop;

use Illuminate\Support\Facades\Artisan;
use Tests\TestCase;

/**
 * Proves the comprehension grounding gate is live at the operator surface and emits deterministic facts: an
 * objective citing a real class is grounded; one citing a phantom symbol is ungrounded with the refuted
 * citation surfaced. A missing --input is a usage error.
 */
final class AtlasLoopComprehensionGroundingCommandTest extends TestCase
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
        $exit = Artisan::call('atlas:loop:comprehension-grounding-gate', ['--objective' => 'x', '--json' => true]);
        $decoded = json_decode(trim(Artisan::output()), true);

        $this->assertNotSame(0, $exit);
        $this->assertSame('usage_error', $decoded['status']);
    }

    public function test_real_citation_is_grounded(): void
    {
        $decoded = $this->invoke('arm the grounding gate', [
            'App\\Services\\Ai\\AutonomousEvolution\\Verify\\AtlasLoopComprehensionGroundingGate',
        ]);

        $this->assertSame('atlas.loop.comprehension.grounding.v1', $decoded['schema']);
        $this->assertTrue($decoded['grounded']);
        $this->assertSame(1, $decoded['citation_count']);
        $this->assertNotEmpty($decoded['resolved']);
    }

    public function test_phantom_citation_is_ungrounded(): void
    {
        $phantom = 'App\\Phantom\\AtlasNonexistentHallucinatedSymbolXyz987';
        $decoded = $this->invoke('arm a hallucinated objective', [$phantom]);

        $this->assertFalse($decoded['grounded']);
        $this->assertContains($phantom, $decoded['ungrounded']);
    }

    /**
     * @param  list<string>  $symbols
     * @return array<string,mixed>
     */
    private function invoke(string $objective, array $symbols): array
    {
        $path = tempnam(sys_get_temp_dir(), 'grounding_').'.json';
        $this->files[] = $path;
        file_put_contents($path, json_encode($symbols));

        $exit = Artisan::call('atlas:loop:comprehension-grounding-gate', [
            '--objective' => $objective,
            '--input' => $path,
            '--json' => true,
        ]);
        $this->assertSame(0, $exit);

        return json_decode(trim(Artisan::output()), true);
    }
}
