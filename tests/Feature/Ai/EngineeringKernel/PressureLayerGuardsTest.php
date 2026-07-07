<?php

declare(strict_types=1);

namespace Tests\Feature\Ai\EngineeringKernel;

use App\Services\Ai\AgenticWorkcell\AtlasAgenticWorkcellRuntimeService;
use App\Services\Ai\AtlasDecide\AtlasDecideLiveOutcomeFeedbackService;
use App\Services\Ai\AutonomousEvolution\Discovery\AtlasLoopWiredCallerService;
use App\Services\Ai\AutonomousEvolution\Verify\AtlasLoopComprehensionGroundingGate;
use App\Services\Ai\EngineeringKernel\PressureLayerGuards;
use App\Services\Ai\Obra\AtlasBlastRadiusService;
use Tests\TestCase;

/**
 * GOAL 3 · SLICE 2 — the 3 Cognitive Pressure Layer advisory guards. Each guard reuses a
 * live deterministic signal, BLOCKS a synthetic bad case (gate), and records its verdict
 * into the SAME proof-gated outcome ledger the Decision Core (ADML, Goal 2) weighs. Proves
 * the MECHANISM (bad → blocked; confirmed → proven_real in the ledger); never fabricates.
 */
final class PressureLayerGuardsTest extends TestCase
{
    private string $tmp;

    private AtlasDecideLiveOutcomeFeedbackService $feedback;

    protected function setUp(): void
    {
        parent::setUp();
        $this->tmp = sys_get_temp_dir().'/atlas_pressure_'.uniqid('', true);
        @mkdir($this->tmp, 0775, true);
        $this->feedback = new AtlasDecideLiveOutcomeFeedbackService;
        $this->feedback->setLogPathForTesting($this->tmp.'/live_outcomes.jsonl');
    }

    protected function tearDown(): void
    {
        foreach (glob($this->tmp.'/*') ?: [] as $f) {
            @unlink($f);
        }
        @rmdir($this->tmp);
        parent::tearDown();
    }

    /** @param  null|\Closure(string):?int  $callerCount */
    private function guards(?\Closure $callerCount = null): PressureLayerGuards
    {
        return new PressureLayerGuards(
            new AtlasLoopWiredCallerService,
            new AtlasLoopComprehensionGroundingGate,
            $this->app->make(AtlasBlastRadiusService::class),
            $this->feedback,
            $callerCount,
        );
    }

    // ---------- runtime_verifier (organ-invocation) ----------

    public function test_runtime_verifier_blocks_an_orphan_zero_invocation_organ(): void
    {
        $guards = $this->guards(static fn (string $p): ?int => 0); // measured: 0 production callers
        $verdict = $guards->runtimeVerifier('app/Services/Ai/SomeNewOrphanOrgan.php');

        $this->assertFalse($verdict['pass']);
        $this->assertFalse($verdict['proven_real']);
        $this->assertTrue($guards->gate($verdict)['blocked']);
    }

    public function test_runtime_verifier_passes_and_proves_an_invoked_organ(): void
    {
        $guards = $this->guards(static fn (string $p): ?int => 5);
        $verdict = $guards->runtimeVerifier('app/Services/Ai/EngineeringKernel/FalseClaimInvariant.php');

        $this->assertTrue($verdict['pass']);
        $this->assertTrue($verdict['proven_real']);
        $this->assertFalse($guards->gate($verdict)['blocked']);
    }

    public function test_runtime_verifier_is_fail_open_but_unproven_when_unmeasured(): void
    {
        $guards = $this->guards(static fn (string $p): ?int => null); // unmeasured infra
        $verdict = $guards->runtimeVerifier('anything.php');

        $this->assertTrue($verdict['pass']);          // fail-open: does not block on degraded infra
        $this->assertFalse($verdict['proven_real']);  // but is never counted as proven
    }

    // ---------- context_cartographer (real code-index symbol resolution) ----------

    public function test_context_cartographer_blocks_a_hallucinated_symbol(): void
    {
        $guards = $this->guards();
        $verdict = $guards->contextCartographer(
            'wire the phantom organ',
            ['App\\Services\\Ai\\AtlasNoSuchSymbol_ZZZ_9999'],
            base_path(),
        );

        $this->assertFalse($verdict['pass'], (string) json_encode($verdict));
        $this->assertTrue($guards->gate($verdict)['blocked']);
        $this->assertContains('App\\Services\\Ai\\AtlasNoSuchSymbol_ZZZ_9999', $verdict['evidence']['ungrounded']);
    }

    public function test_context_cartographer_passes_a_symbol_that_resolves(): void
    {
        $guards = $this->guards();
        $verdict = $guards->contextCartographer(
            'reuse the pressure guards',
            ['App\\Services\\Ai\\EngineeringKernel\\PressureLayerGuards'],
            base_path(),
        );

        $this->assertTrue($verdict['pass'], (string) json_encode($verdict));
        $this->assertFalse($guards->gate($verdict)['blocked']);
    }

    // ---------- boundary_wiring_guard (real deterministic blast radius) ----------

    public function test_boundary_wiring_guard_blocks_an_edit_outside_the_radius(): void
    {
        $guards = $this->guards();
        $verdict = $guards->boundaryWiringGuard(
            ['app/Services/Ai/EngineeringKernel/FalseClaimInvariant.php'],
            [
                'app/Services/Ai/EngineeringKernel/FalseClaimInvariant.php',
                'app/Services/Ai/AtlasUnrelatedWiperTarget_ZZZ.php',
            ],
            base_path(),
        );

        $this->assertFalse($verdict['pass'], (string) json_encode($verdict));
        $this->assertContains('app/Services/Ai/AtlasUnrelatedWiperTarget_ZZZ.php', $verdict['evidence']['outside']);
        $this->assertTrue($guards->gate($verdict)['blocked']);
    }

    public function test_boundary_wiring_guard_passes_a_diff_within_the_radius(): void
    {
        $guards = $this->guards();
        $verdict = $guards->boundaryWiringGuard(
            ['app/Services/Ai/EngineeringKernel/FalseClaimInvariant.php'],
            ['app/Services/Ai/EngineeringKernel/FalseClaimInvariant.php'],
            base_path(),
        );

        $this->assertTrue($verdict['pass'], (string) json_encode($verdict));
        $this->assertFalse($guards->gate($verdict)['blocked']);
    }

    // ---------- outcome: the verdict enters the ledger the Decision Core weighs ----------

    public function test_verdict_records_into_the_proven_gated_ledger_the_decision_core_weighs(): void
    {
        // hermes_cli produced a proven-real, invoked organ; claude_cli produced an orphan (failure).
        $provenGuards = $this->guards(static fn (string $p): ?int => 3);
        $provenGuards->recordVerdict(
            $provenGuards->runtimeVerifier('app/Services/Ai/EngineeringKernel/FalseClaimInvariant.php'),
            'programming',
            'hermes_cli',
        );

        $orphanGuards = $this->guards(static fn (string $p): ?int => 0);
        $orphanGuards->recordVerdict(
            $orphanGuards->runtimeVerifier('app/Services/Ai/SomeNewOrphanOrgan.php'),
            'programming',
            'claude_cli',
        );

        $stats = $this->feedback->routeStats('programming', PressureLayerGuards::RUNTIME_VERIFIER);
        $byProvider = [];
        foreach ($stats['providers'] as $p) {
            $byProvider[$p['provider']] = $p;
        }

        // The Decision Core can now weigh which runtime produces guard-clean, proven work.
        $this->assertSame(1, $byProvider['hermes_cli']['proven_success']);
        $this->assertSame(1.0, $byProvider['hermes_cli']['proven_success_rate']);
        $this->assertSame(0, $byProvider['claude_cli']['proven_success']);
        $this->assertSame(1, $byProvider['claude_cli']['failure']);
    }

    // ---------- roster registration: the guards ARE advisory roles in the AAWR output ----------

    public function test_guards_are_advisory_roles_in_the_aawr_roster(): void
    {
        $aawr = $this->app->make(AtlasAgenticWorkcellRuntimeService::class);
        $design = $aawr->design(['objective' => 'ship a small reversible slice', 'domain' => 'engineering']);

        $this->assertArrayHasKey('pressure_layer_guards', $design);
        $ids = array_column($design['pressure_layer_guards'], 'role_id');
        $this->assertContains(PressureLayerGuards::RUNTIME_VERIFIER, $ids);
        $this->assertContains(PressureLayerGuards::CONTEXT_CARTOGRAPHER, $ids);
        $this->assertContains(PressureLayerGuards::BOUNDARY_WIRING_GUARD, $ids);

        foreach ($design['pressure_layer_guards'] as $guard) {
            $this->assertTrue($guard['advisory']);
            $this->assertTrue($guard['read_only']);
        }

        // Advisory guards must NOT leak into the counted execution roster.
        $executionRoleIds = array_column($design['role_roster'], 'role_id');
        $this->assertNotContains(PressureLayerGuards::RUNTIME_VERIFIER, $executionRoleIds);
    }
}
