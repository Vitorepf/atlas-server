<?php

declare(strict_types=1);

namespace Tests\Unit\Ai\SelfConstruction\StrategyCouncil;

use App\Services\Ai\SelfConstruction\StrategyCouncil\AtlasStrategyCouncilAmbitionBudgetPolicy;
use PHPUnit\Framework\TestCase;

/**
 * Proves AtlasStrategyCouncilAmbitionBudgetPolicy: high leverage + low/med risk + execute_continuous +
 * all deps ⇒ bold; execute_guarded ⇒ standard; propose/observe ⇒ narrow; missing evidence ⇒ hold;
 * budget exceeded ⇒ hold; risk_class above autonomy_mode ⇒ hold.
 */
final class AtlasStrategyCouncilAmbitionBudgetPolicyTest extends TestCase
{
    private function readyFacts(string $mode = 'execute_continuous'): array
    {
        return [
            'leverage_rank' => 'high',
            'risk_class' => 'low',
            'available_budget_units' => 100,
            'required_budget_units' => 10,
            'autonomy_mode' => $mode,
            'dependency_readiness' => ['verification' => true, 'rollback' => true, 'knowledge_sync' => true],
            'evidence_present' => true,
        ];
    }

    public function test_bold_when_high_leverage_low_risk_continuous_and_all_deps_ready(): void
    {
        $r = (new AtlasStrategyCouncilAmbitionBudgetPolicy)->decide($this->readyFacts());
        $this->assertSame(AtlasStrategyCouncilAmbitionBudgetPolicy::LEVEL_BOLD, $r['ambition_level']);
    }

    public function test_standard_when_execute_guarded_only(): void
    {
        $f = $this->readyFacts('execute_guarded');
        $r = (new AtlasStrategyCouncilAmbitionBudgetPolicy)->decide($f);
        $this->assertSame(AtlasStrategyCouncilAmbitionBudgetPolicy::LEVEL_STANDARD, $r['ambition_level']);
    }

    public function test_narrow_when_propose_or_observe_mode(): void
    {
        $f = $this->readyFacts('propose');
        $r = (new AtlasStrategyCouncilAmbitionBudgetPolicy)->decide($f);
        $this->assertSame(AtlasStrategyCouncilAmbitionBudgetPolicy::LEVEL_NARROW, $r['ambition_level']);
    }

    public function test_hold_when_evidence_missing(): void
    {
        $f = $this->readyFacts();
        $f['evidence_present'] = false;
        $r = (new AtlasStrategyCouncilAmbitionBudgetPolicy)->decide($f);
        $this->assertSame(AtlasStrategyCouncilAmbitionBudgetPolicy::LEVEL_HOLD, $r['ambition_level']);
        $this->assertContains('hold:evidence_missing', $r['reasons']);
    }

    public function test_hold_when_budget_exceeded(): void
    {
        $f = $this->readyFacts();
        $f['available_budget_units'] = 5;
        $f['required_budget_units'] = 50;
        $r = (new AtlasStrategyCouncilAmbitionBudgetPolicy)->decide($f);
        $this->assertSame(AtlasStrategyCouncilAmbitionBudgetPolicy::LEVEL_HOLD, $r['ambition_level']);
        $this->assertContains('hold:budget_exceeded', $r['reasons']);
    }

    public function test_hold_when_risk_high_but_mode_only_propose(): void
    {
        $f = $this->readyFacts('propose');
        $f['risk_class'] = 'high';
        $r = (new AtlasStrategyCouncilAmbitionBudgetPolicy)->decide($f);
        $this->assertSame(AtlasStrategyCouncilAmbitionBudgetPolicy::LEVEL_HOLD, $r['ambition_level']);
        $this->assertContains('hold:risk_exceeds_mode', $r['reasons']);
    }

    public function test_hold_when_autonomy_disabled(): void
    {
        $f = $this->readyFacts('disabled');
        $r = (new AtlasStrategyCouncilAmbitionBudgetPolicy)->decide($f);
        $this->assertContains('hold:autonomy_disabled', $r['reasons']);
    }

    public function test_bold_blocked_when_any_dependency_not_ready(): void
    {
        $f = $this->readyFacts();
        $f['dependency_readiness']['knowledge_sync'] = false;
        $r = (new AtlasStrategyCouncilAmbitionBudgetPolicy)->decide($f);
        // Not bold — falls to standard because mode=execute_continuous is still valid.
        $this->assertSame(AtlasStrategyCouncilAmbitionBudgetPolicy::LEVEL_STANDARD, $r['ambition_level']);
    }
}
