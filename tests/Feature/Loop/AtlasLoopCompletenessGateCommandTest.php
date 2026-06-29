<?php

declare(strict_types=1);

namespace Tests\Feature\Loop;

use Illuminate\Support\Facades\Artisan;
use Tests\TestCase;

/**
 * Proves the completeness gate is live at the operator surface: a fully-satisfied checklist is complete; an
 * unmet REQUIRED criterion blocks regardless of coverage; an unmet OPTIONAL one only blocks via the coverage
 * floor (and passes once the floor is lowered).
 */
final class AtlasLoopCompletenessGateCommandTest extends TestCase
{
    private string $input = '';

    protected function setUp(): void
    {
        parent::setUp();
        $this->input = sys_get_temp_dir().'/atlas-completeness-'.bin2hex(random_bytes(5)).'.json';
    }

    protected function tearDown(): void
    {
        @unlink($this->input);
        parent::tearDown();
    }

    private function gate(array $params): array
    {
        $exit = Artisan::call('atlas:loop:completeness-gate', $params + ['--input' => $this->input, '--json' => true]);

        return ['exit' => $exit, 'd' => json_decode(trim(Artisan::output()), true)];
    }

    public function test_all_required_satisfied_is_complete(): void
    {
        file_put_contents($this->input, (string) json_encode([
            ['id' => 'c1', 'satisfied' => true, 'required' => true],
            ['id' => 'c2', 'satisfied' => true, 'required' => true],
        ]));

        ['exit' => $exit, 'd' => $d] = $this->gate([]);

        $this->assertSame(0, $exit);
        $this->assertSame('atlas.loop.completeness_gate.v1', $d['schema']);
        $this->assertTrue($d['complete'], (string) json_encode($d));
        $this->assertEqualsWithDelta(1.0, $d['coverage'], 1e-9);
        $this->assertSame([], $d['required_missing']);
    }

    public function test_required_unmet_blocks(): void
    {
        file_put_contents($this->input, (string) json_encode([
            ['id' => 'c1', 'satisfied' => true, 'required' => true],
            ['id' => 'c2', 'satisfied' => false, 'required' => true],
        ]));

        ['exit' => $exit, 'd' => $d] = $this->gate([]);

        $this->assertSame(0, $exit);
        $this->assertFalse($d['complete']);
        $this->assertContains('c2', $d['required_missing']);
        $this->assertStringContainsString('required_unmet', (string) $d['reason']);
    }

    public function test_optional_unmet_passes_once_coverage_floor_lowered(): void
    {
        file_put_contents($this->input, (string) json_encode([
            ['id' => 'req', 'satisfied' => true, 'required' => true],
            ['id' => 'opt', 'satisfied' => false, 'required' => false],
        ]));

        // default floor 1.0 ⇒ 0.5 coverage blocks even though no REQUIRED is missing
        ['d' => $blocked] = $this->gate([]);
        $this->assertFalse($blocked['complete']);
        $this->assertSame([], $blocked['required_missing']);
        $this->assertContains('opt', $blocked['missing']);

        // floor 0.5 ⇒ exact 0.5 coverage clears, required all met ⇒ complete
        ['d' => $passed] = $this->gate(['--min-coverage' => 0.5]);
        $this->assertTrue($passed['complete'], (string) json_encode($passed));
    }

    public function test_missing_input_is_usage_error(): void
    {
        $exit = Artisan::call('atlas:loop:completeness-gate', ['--json' => true]);

        $this->assertNotSame(0, $exit);
        $this->assertStringContainsString('usage_error', Artisan::output());
    }
}
