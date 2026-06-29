<?php

declare(strict_types=1);

namespace Tests\Feature\Loop;

use App\Services\Ai\AutonomousEvolution\Autopoiesis\ScopeOrigination\ScopeOriginationVerdict;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Config;
use Tests\TestCase;

/**
 * Proves the scope-origination auditor is live at the operator surface: a forbidden target path is rejected by
 * the scope guard; a clean proposal with auto-approve off is pending; a fully-sourced high-coherence proposal
 * auto-approves once the floor is configured; a missing living-signal source stays pending.
 */
final class AtlasLoopScopeOriginationAuditCommandTest extends TestCase
{
    private string $input = '';

    protected function setUp(): void
    {
        parent::setUp();
        $this->input = sys_get_temp_dir().'/atlas-scope-orig-'.bin2hex(random_bytes(5)).'.json';
    }

    protected function tearDown(): void
    {
        @unlink($this->input);
        parent::tearDown();
    }

    /** @param list<string> $sources */
    private function factRefs(array $sources): array
    {
        return array_map(static fn (string $s, int $i): array => [
            'fact_id' => 'f'.$i,
            'source' => $s,
            'snapshot_hash' => 'h'.$i,
        ], $sources, array_keys($sources));
    }

    private function audit(array $proposal): array
    {
        file_put_contents($this->input, (string) json_encode($proposal));
        $exit = Artisan::call('atlas:loop:scope-origination-audit', ['--input' => $this->input, '--json' => true]);

        return ['exit' => $exit, 'd' => json_decode(trim(Artisan::output()), true)];
    }

    public function test_forbidden_target_path_is_rejected(): void
    {
        ['exit' => $exit, 'd' => $d] = $this->audit([
            'expected_leverage_signal' => 'coherence:0.9',
            'fact_refs' => $this->factRefs(['cortex_meaning', 'loop_telemetry', 'maestro_outcomes', 'operator_intent']),
            'objective_text' => 'Grow forge',
            'target_paths' => ['app/Services/Ai/Forge/Foo.php'], // forbidden marker 'forge'
        ]);

        $this->assertSame(0, $exit);
        $this->assertSame('atlas.loop.scope_origination_audit.v1', $d['schema']);
        $this->assertSame(ScopeOriginationVerdict::REJECTED, $d['verdict'], (string) json_encode($d));
        $this->assertSame('scope_guard', $d['actor']);
        $this->assertSame('scope_violation', $d['reason']);
    }

    public function test_clean_proposal_with_auto_approve_off_is_pending(): void
    {
        Config::set('atlas.autopoiesis.auto_approve_min_coherence', 'OFF');

        ['d' => $d] = $this->audit([
            'expected_leverage_signal' => 'coherence:0.9',
            'fact_refs' => $this->factRefs(['cortex_meaning', 'loop_telemetry', 'maestro_outcomes', 'operator_intent']),
            'objective_text' => 'Evolve the loop',
            'target_paths' => ['app/Services/Ai/AutonomousEvolution/Loop/Foo.php'], // allowed marker 'loop'
        ]);

        $this->assertSame(ScopeOriginationVerdict::PENDING_OPERATOR, $d['verdict'], (string) json_encode($d));
        $this->assertSame('auto_approve_off', $d['reason']);
    }

    public function test_fully_sourced_high_coherence_auto_approves(): void
    {
        Config::set('atlas.autopoiesis.auto_approve_min_coherence', 0.5);

        ['d' => $d] = $this->audit([
            'expected_leverage_signal' => 'coherence:0.9',
            'fact_refs' => $this->factRefs(['cortex_meaning', 'loop_telemetry', 'maestro_outcomes', 'operator_intent']),
            'objective_text' => 'Evolve the loop',
            'target_paths' => ['app/Services/Ai/Cortex/Foo.php'],
        ]);

        $this->assertSame(ScopeOriginationVerdict::AUTO_APPROVED, $d['verdict'], (string) json_encode($d));
        $this->assertEqualsWithDelta(0.9, $d['coherence'], 1e-9);
    }

    public function test_missing_living_signal_source_is_pending(): void
    {
        Config::set('atlas.autopoiesis.auto_approve_min_coherence', 0.5);

        ['d' => $d] = $this->audit([
            'expected_leverage_signal' => 'coherence:0.9',
            'fact_refs' => $this->factRefs(['cortex_meaning', 'loop_telemetry']), // missing maestro_outcomes + operator_intent
            'objective_text' => 'Evolve the loop',
            'target_paths' => ['app/Services/Ai/Cortex/Foo.php'],
        ]);

        $this->assertSame(ScopeOriginationVerdict::PENDING_OPERATOR, $d['verdict'], (string) json_encode($d));
        $this->assertSame('missing_living_signal_source', $d['reason']);
    }

    public function test_missing_input_is_usage_error(): void
    {
        $exit = Artisan::call('atlas:loop:scope-origination-audit', ['--json' => true]);

        $this->assertNotSame(0, $exit);
        $this->assertStringContainsString('usage_error', Artisan::output());
    }
}
