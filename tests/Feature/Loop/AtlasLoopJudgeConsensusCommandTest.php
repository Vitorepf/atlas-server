<?php

declare(strict_types=1);

namespace Tests\Feature\Loop;

use Illuminate\Support\Facades\Artisan;
use Tests\TestCase;

/**
 * Proves the judge-consensus gate is live at the operator surface: independent passing verdicts covering the
 * required lenses certify consensus; a failing lens or a clone panel (one engine) blocks it with a reason.
 */
final class AtlasLoopJudgeConsensusCommandTest extends TestCase
{
    private string $input = '';

    protected function setUp(): void
    {
        parent::setUp();
        $this->input = sys_get_temp_dir().'/atlas-consensus-'.bin2hex(random_bytes(5)).'.json';
    }

    protected function tearDown(): void
    {
        @unlink($this->input);
        parent::tearDown();
    }

    private function evaluate(array $verdicts, array $quorum): array
    {
        file_put_contents($this->input, (string) json_encode(['verdicts' => $verdicts, 'quorum' => $quorum]));
        $exit = Artisan::call('atlas:loop:judge-consensus', ['--input' => $this->input, '--json' => true]);

        return ['exit' => $exit, 'd' => json_decode(trim(Artisan::output()), true)];
    }

    public function test_independent_passing_panel_reaches_consensus(): void
    {
        ['exit' => $exit, 'd' => $d] = $this->evaluate(
            [
                ['lens' => 'correctness', 'provider' => 'claude', 'passes' => true],
                ['lens' => 'security', 'provider' => 'codex', 'passes' => true],
            ],
            ['policy' => 'unanimous', 'required_lenses' => ['correctness', 'security'], 'min_distinct_providers' => 2],
        );

        $this->assertSame(0, $exit);
        $this->assertSame('atlas.loop.judge_consensus.v1', $d['schema']);
        $this->assertTrue($d['consensus'], (string) json_encode($d));
        $this->assertSame(2, $d['passed']);
        $this->assertSame(2, $d['distinct_providers']);
        $this->assertNull($d['reason']);
    }

    public function test_failing_lens_blocks_unanimous_consensus(): void
    {
        ['d' => $d] = $this->evaluate(
            [
                ['lens' => 'correctness', 'provider' => 'claude', 'passes' => true],
                ['lens' => 'security', 'provider' => 'codex', 'passes' => false, 'reason' => 'sql_injection'],
            ],
            ['policy' => 'unanimous', 'required_lenses' => ['correctness', 'security'], 'min_distinct_providers' => 2],
        );

        $this->assertFalse($d['consensus']);
        $this->assertNotEmpty($d['dissents']);
        $this->assertStringStartsWith('judge_consensus:', (string) $d['reason']);
    }

    public function test_clone_panel_fails_independence(): void
    {
        ['d' => $d] = $this->evaluate(
            [
                ['lens' => 'correctness', 'provider' => 'claude', 'passes' => true],
                ['lens' => 'security', 'provider' => 'claude', 'passes' => true],
            ],
            ['policy' => 'unanimous', 'min_distinct_providers' => 2],
        );

        $this->assertFalse($d['consensus']);
        $this->assertSame(1, $d['distinct_providers']);
        $this->assertStringContainsString('insufficient_independence', (string) $d['reason']);
    }

    public function test_missing_input_is_usage_error(): void
    {
        $exit = Artisan::call('atlas:loop:judge-consensus', ['--json' => true]);

        $this->assertNotSame(0, $exit);
        $this->assertStringContainsString('usage_error', Artisan::output());
    }
}
