<?php

declare(strict_types=1);

namespace Tests\Feature\Loop;

use App\Services\Ai\AutonomousEvolution\Autopoiesis\ScopeOrigination\ScopeOriginationVerdict;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Config;
use Tests\TestCase;

/**
 * Proves the scope-origination auditor is live at the operator surface: a deficient proposal targeting a
 * forbidden scope is REJECTED (negative); a sound, fully-sourced, high-coherence proposal in an allowed scope
 * is auto_approved (positive) once the operator floor is configured.
 */
final class AtlasLoopScopeAuditCommandTest extends TestCase
{
    /** @param list<string> $sources */
    private function factRefs(array $sources): array
    {
        return array_map(static fn (string $s, int $i): array => ['fact_id' => 'f'.$i, 'source' => $s, 'snapshot_hash' => 'h'.$i], $sources, array_keys($sources));
    }

    private function audit(array $proposal): array
    {
        $exit = Artisan::call('atlas:loop:scope-audit', [
            '--proposal' => (string) json_encode($proposal),
            '--json' => true,
        ]);

        return ['exit' => $exit, 'd' => json_decode(trim(Artisan::output()), true)];
    }

    public function test_forbidden_scope_proposal_is_rejected(): void
    {
        ['exit' => $exit, 'd' => $d] = $this->audit([
            'expected_leverage_signal' => 'coherence:0.9',
            'fact_refs' => $this->factRefs(['cortex_meaning', 'loop_telemetry', 'maestro_outcomes', 'operator_intent']),
            'objective_text' => 'grow forge',
            'target_paths' => ['app/Services/Ai/Forge/Foo.php'], // forbidden scope marker
        ]);

        $this->assertSame(0, $exit);
        $this->assertSame('atlas.loop.scope_audit.v1', $d['schema']);
        $this->assertSame(ScopeOriginationVerdict::REJECTED, $d['verdict'], (string) json_encode($d));
        $this->assertSame('scope_violation', $d['reason']);
    }

    public function test_sound_proposal_auto_approves(): void
    {
        Config::set('atlas.autopoiesis.auto_approve_min_coherence', 0.5);

        ['exit' => $exit, 'd' => $d] = $this->audit([
            'expected_leverage_signal' => 'coherence:0.9',
            'fact_refs' => $this->factRefs(['cortex_meaning', 'loop_telemetry', 'maestro_outcomes', 'operator_intent']),
            'objective_text' => 'evolve the loop',
            'target_paths' => ['app/Services/Ai/Cortex/Foo.php'], // allowed scope marker 'cortex'
        ]);

        $this->assertSame(0, $exit);
        $this->assertSame(ScopeOriginationVerdict::AUTO_APPROVED, $d['verdict'], (string) json_encode($d));
        $this->assertNotSame(ScopeOriginationVerdict::REJECTED, $d['verdict']);
    }

    public function test_missing_proposal_is_usage_error(): void
    {
        $exit = Artisan::call('atlas:loop:scope-audit', ['--json' => true]);

        $this->assertNotSame(0, $exit);
        $this->assertStringContainsString('usage_error', Artisan::output());
    }
}
