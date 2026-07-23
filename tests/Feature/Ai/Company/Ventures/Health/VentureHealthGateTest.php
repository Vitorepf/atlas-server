<?php

namespace Tests\Feature\Ai\Company\Ventures\Health;

use App\Services\Ai\Company\Ventures\Health\HealthSignalState;
use App\Services\Ai\Company\Ventures\Health\VentureHealthGate;
use Tests\TestCase;

class VentureHealthGateTest extends TestCase
{
    private function allGreen(): array
    {
        return [
            'solvency' => 'green',
            'churn' => 'green',
            'concentration' => 'green',
            'legality' => 'green',
            'deliverability' => 'green',
        ];
    }

    public function test_all_green_is_healthy_and_eligible(): void
    {
        $r = (new VentureHealthGate)->evaluate($this->allGreen());
        $this->assertSame(VentureHealthGate::VERDICT_HEALTHY, $r['verdict']);
        $this->assertTrue($r['succeeded_eligible']);
    }

    public function test_any_red_blocks_and_names_it(): void
    {
        $s = $this->allGreen();
        $s['legality'] = 'red';
        $r = (new VentureHealthGate)->evaluate($s);
        $this->assertSame(VentureHealthGate::VERDICT_BLOCKED_RED, $r['verdict']);
        $this->assertFalse($r['succeeded_eligible']);
        $this->assertContains('legality', $r['red']);
    }

    public function test_unknown_blocks_distinct_from_red(): void
    {
        $s = $this->allGreen();
        $s['churn'] = 'unknown';
        $r = (new VentureHealthGate)->evaluate($s);
        $this->assertSame(VentureHealthGate::VERDICT_BLOCKED_UNKNOWN, $r['verdict']);
        $this->assertFalse($r['succeeded_eligible']);
        $this->assertContains('churn', $r['unknown']);
        $this->assertSame([], $r['red']);
    }

    public function test_missing_signal_is_failclosed_unknown(): void
    {
        $r = (new VentureHealthGate)->evaluate(['solvency' => 'green']); // 4 signals missing
        $this->assertSame(VentureHealthGate::VERDICT_BLOCKED_UNKNOWN, $r['verdict']);
        $this->assertContains('legality', $r['unknown']);
        $this->assertFalse($r['succeeded_eligible']);
    }

    public function test_red_takes_precedence_over_unknown(): void
    {
        $s = $this->allGreen();
        $s['churn'] = 'unknown';
        $s['solvency'] = 'red';
        $r = (new VentureHealthGate)->evaluate($s);
        $this->assertSame(VentureHealthGate::VERDICT_BLOCKED_RED, $r['verdict']);
    }

    public function test_downgrade_only_never_upgrades(): void
    {
        $gate = new VentureHealthGate;
        $this->assertSame(HealthSignalState::RED, $gate->downgradeOnly(HealthSignalState::GREEN, HealthSignalState::RED));
        $this->assertSame(HealthSignalState::RED, $gate->downgradeOnly(HealthSignalState::RED, HealthSignalState::GREEN), 'never silently upgrades');
        $this->assertSame(HealthSignalState::UNKNOWN, $gate->downgradeOnly(HealthSignalState::GREEN, HealthSignalState::UNKNOWN));
    }
}
