<?php

declare(strict_types=1);

namespace Tests\Feature\Loop;

use App\Services\Ai\AutonomousEvolution\Discovery\AtlasLoopPlanHardeningReview;
use Illuminate\Support\Facades\Artisan;
use Tests\TestCase;

/**
 * Proves the plan-hardening review is live at the operator surface: a clean plan IMPLEMENTs, a critical
 * would-block finding forces REPLAN, a majority of high-risk findings forces HARDEN, and ungrounded findings
 * (vague or naming a missing node) are dropped.
 */
final class AtlasLoopPlanHardeningCommandTest extends TestCase
{
    private string $input = '';

    private array $plan = ['nodes' => [['id' => 'n1'], ['id' => 'n2']]];

    protected function setUp(): void
    {
        parent::setUp();
        $this->input = sys_get_temp_dir().'/atlas-plan-harden-'.bin2hex(random_bytes(5)).'.json';
    }

    protected function tearDown(): void
    {
        @unlink($this->input);
        parent::tearDown();
    }

    private function assess(array $findings, int $reviewerCount): array
    {
        file_put_contents($this->input, (string) json_encode([
            'plan' => $this->plan,
            'findings' => $findings,
            'reviewer_count' => $reviewerCount,
        ]));
        $exit = Artisan::call('atlas:loop:plan-hardening', ['--input' => $this->input, '--json' => true]);

        return ['exit' => $exit, 'd' => json_decode(trim(Artisan::output()), true)];
    }

    public function test_clean_plan_implements(): void
    {
        ['exit' => $exit, 'd' => $d] = $this->assess([], 3);

        $this->assertSame(0, $exit);
        $this->assertSame('atlas.loop.plan_hardening.v1', $d['schema']);
        $this->assertSame(AtlasLoopPlanHardeningReview::IMPLEMENT, $d['decision'], (string) json_encode($d));
        $this->assertSame(0, $d['considered']);
    }

    public function test_critical_would_block_forces_replan(): void
    {
        ['d' => $d] = $this->assess([
            ['lens' => 'decomposition', 'severity' => 'critical', 'would_block' => true, 'summary' => 'n2 assumes work no node does'],
        ], 3);

        $this->assertSame(AtlasLoopPlanHardeningReview::REPLAN, $d['decision']);
        $this->assertNotEmpty($d['blocking']);
    }

    public function test_majority_high_risk_forces_harden(): void
    {
        ['d' => $d] = $this->assess([
            ['lens' => 'integration', 'severity' => 'high', 'would_block' => false, 'summary' => 'n2 may break n1'],
            ['lens' => 'verifiability', 'severity' => 'high', 'would_block' => false, 'summary' => 'n1 acceptance is circular'],
        ], 3);

        $this->assertSame(AtlasLoopPlanHardeningReview::HARDEN, $d['decision'], (string) json_encode($d));
        $this->assertSame(2, $d['high_or_critical']);
    }

    public function test_ungrounded_findings_are_dropped(): void
    {
        ['d' => $d] = $this->assess([
            ['lens' => 'decomposition', 'severity' => 'critical', 'would_block' => true, 'summary' => 'x', 'node_id' => 'ghost'], // missing node
            ['lens' => 'scope', 'severity' => 'critical', 'would_block' => true, 'summary' => ''], // vague
        ], 3);

        $this->assertSame(2, $d['dropped']);
        $this->assertSame(0, $d['considered']);
        $this->assertSame(AtlasLoopPlanHardeningReview::IMPLEMENT, $d['decision']); // nothing real survived
    }

    public function test_missing_input_is_usage_error(): void
    {
        $exit = Artisan::call('atlas:loop:plan-hardening', ['--json' => true]);

        $this->assertNotSame(0, $exit);
        $this->assertStringContainsString('usage_error', Artisan::output());
    }
}
