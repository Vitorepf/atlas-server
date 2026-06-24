<?php

declare(strict_types=1);

namespace Tests\Unit\Ai\AutonomousEvolution;

use App\Services\Ai\AutonomousEvolution\Autopoiesis\ScopeOrigination\AtlasLoopScopeOriginationAuditor;
use App\Services\Ai\AutonomousEvolution\Autopoiesis\ScopeOrigination\ScopeOriginationVerdict;
use App\Services\Ai\AutonomousEvolution\Autopoiesis\ScopeOrigination\ScopeProposal;
use Tests\TestCase;

final class AtlasLoopScopeOriginationAuditorTest extends TestCase
{
    public function test_default_config_fail_closed_always_returns_pending_operator(): void
    {
        config(['atlas.autopoiesis.auto_approve_min_coherence' => null]);
        $auditor = new AtlasLoopScopeOriginationAuditor;

        $verdict = $auditor->audit($this->proposal(coherence: 1.0));

        $this->assertSame(ScopeOriginationVerdict::PENDING_OPERATOR, $verdict->verdict);
        $this->assertSame('auto_approve_off', $verdict->reason);
    }

    public function test_forbidden_scope_is_rejected_for_auto_and_manual_approval_paths(): void
    {
        config(['atlas.autopoiesis.auto_approve_min_coherence' => 0.5]);
        $auditor = new AtlasLoopScopeOriginationAuditor;
        $proposal = $this->proposal(
            coherence: 0.99,
            targetPaths: ['app/Services/Ai/AutonomousEvolution/MarketingDomain/Campaign.php'],
        );

        $auto = $auditor->audit($proposal);
        $manual = $auditor->approve($proposal, 'operator_wants_it', '2026-06-24T12:00:00Z');

        $this->assertSame(ScopeOriginationVerdict::REJECTED, $auto->verdict);
        $this->assertSame('scope_violation', $auto->reason);
        $this->assertSame(ScopeOriginationVerdict::REJECTED, $manual->verdict);
        $this->assertSame('scope_violation', $manual->reason);
    }

    public function test_operator_approve_and_reject_record_actor_timestamp_and_reason(): void
    {
        $auditor = new AtlasLoopScopeOriginationAuditor;
        $proposal = $this->proposal(coherence: 0.70);

        $approved = $auditor->approve($proposal, 'operator_reviewed', '2026-06-24T12:01:00Z');
        $rejected = $auditor->reject($proposal, 'not_now', '2026-06-24T12:02:00Z');

        $this->assertSame('operator', $approved->actor);
        $this->assertSame('2026-06-24T12:01:00Z', $approved->timestamp);
        $this->assertSame('operator_reviewed', $approved->reason);
        $this->assertSame('operator', $rejected->actor);
        $this->assertSame('2026-06-24T12:02:00Z', $rejected->timestamp);
        $this->assertSame('not_now', $rejected->reason);
    }

    public function test_auto_approval_records_floor_actor_and_exact_coherence_used(): void
    {
        config(['atlas.autopoiesis.auto_approve_min_coherence' => 0.6]);
        $auditor = new AtlasLoopScopeOriginationAuditor;

        $verdict = $auditor->audit($this->proposal(coherence: 0.75));

        $this->assertSame(ScopeOriginationVerdict::AUTO_APPROVED, $verdict->verdict);
        $this->assertSame('autopoiesis_floor', $verdict->actor);
        $this->assertSame(0.75, $verdict->coherence);
        $this->assertSame('coherence_floor_met', $verdict->reason);
    }

    /**
     * @param  list<string>|null  $targetPaths
     */
    private function proposal(float $coherence, ?array $targetPaths = null): ScopeProposal
    {
        return new ScopeProposal([
            'expected_leverage_signal' => 'coherence:'.number_format($coherence, 6, '.', ''),
            'fact_refs' => [
                ['fact_id' => 'ctx-1', 'source' => 'cortex_meaning', 'snapshot_hash' => 'snap'],
                ['fact_id' => 'loop-1', 'source' => 'loop_telemetry', 'snapshot_hash' => 'snap'],
                ['fact_id' => 'mae-1', 'source' => 'maestro_outcomes', 'snapshot_hash' => 'snap'],
                ['fact_id' => 'op-1', 'source' => 'operator_intent', 'snapshot_hash' => 'snap'],
            ],
            'objective_text' => 'Originate concrete scope for loop/cortex/maestro',
            'target_paths' => $targetPaths ?? [
                'app/Services/Ai/AutonomousEvolution/Loop/Queue.php',
                'app/Services/Ai/AutonomousEvolution/Cortex/Meaning.php',
                'app/Services/Ai/AutonomousEvolution/Maestro/Outcome.php',
            ],
        ]);
    }
}
