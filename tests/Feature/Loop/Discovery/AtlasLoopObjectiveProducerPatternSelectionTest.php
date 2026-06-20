<?php

declare(strict_types=1);

namespace Tests\Feature\Loop\Discovery;

use App\Services\Ai\AutonomousEvolution\Discovery\AtlasLoopObjectiveProducer;
use App\Services\Ai\AutonomousEvolution\Pattern\AtlasLoopExecutionContract;
use App\Services\Ai\AutonomousEvolution\Pattern\AtlasLoopPatternCompiler;
use App\Services\Ai\AutonomousEvolution\Pattern\AtlasLoopPatternDecisionDriver;
use App\Services\Ai\AutonomousEvolution\Pattern\AtlasLoopPatternRegistry;
use App\Services\Ai\AutonomousEvolution\Pattern\AtlasLoopPatternSelector;
use App\Services\Ai\AutonomousEvolution\Pattern\AtlasLoopPatternSpec;
use ReflectionMethod;
use Tests\TestCase;

/**
 * LOOP-PATTERN-REGISTRY · Slice 1 — the producer ADVISORY integration.
 *
 * AtlasLoopObjectiveProducer::produce() attaches a selected pattern + a compiled ExecutionContract to the
 * originated objective (top-level 'pattern'/'execution_contract' keys AND payload). The attachment is
 * read-only/advisory: it never reorders or gates origination. The heavy origination path is NOT exercised
 * here on purpose — it can invoke the engine/provider, which a unit test must never do. Instead we drive
 * the producer's OWN advisory method (the exact code produce() runs to build those two keys) through
 * Reflection, with the REAL Selector/Compiler/Registry — proving the selection + compilation end-to-end,
 * deterministically and provider-free. (Same Reflection idiom as AtlasLoopObjectiveProducerEvPickTest.)
 */
final class AtlasLoopObjectiveProducerPatternSelectionTest extends TestCase
{
    /** @param array<string,mixed> $winner @param array<string,mixed> $built @return array{0:?array,1:?array} */
    private function attach(AtlasLoopObjectiveProducer $producer, array $winner, array $built): array
    {
        $m = new ReflectionMethod(AtlasLoopObjectiveProducer::class, 'attachPatternAdvisory');
        $m->setAccessible(true);

        return $m->invoke($producer, $winner, $built);
    }

    /** @param array<string,mixed> $winner @param array<string,mixed> $built @return array<string,mixed> */
    private function descriptor(AtlasLoopObjectiveProducer $producer, array $winner, array $built): array
    {
        $m = new ReflectionMethod(AtlasLoopObjectiveProducer::class, 'patternDescriptor');
        $m->setAccessible(true);

        return $m->invoke($producer, $winner, $built);
    }

    private function producer(): AtlasLoopObjectiveProducer
    {
        // Explicit (real) pattern collaborators — proves the injection seam and keeps it provider-free.
        return new AtlasLoopObjectiveProducer(
            patternRegistry: new AtlasLoopPatternRegistry(),
            patternSelector: new AtlasLoopPatternSelector(),
            patternCompiler: new AtlasLoopPatternCompiler(),
        );
    }

    public function test_a_real_refactor_objective_gets_a_pattern_and_a_complete_contract(): void
    {
        config(['atlas.loop.pattern_advisory_enabled' => true]);

        $winner = ['path' => 'app/Svc/Thing.php', 'verifiable' => true, 'risk' => 0.4, 'cost' => 0.3, '_score' => ['leverage' => 20.0]];
        $built = ['objective' => 'reduce coupling in Thing with a RED-proven refactor', 'target_path' => 'app/Svc/Thing.php', 'shape' => 'refactor'];

        [$pattern, $contract] = $this->attach($this->producer(), $winner, $built);

        // a SELECTABLE pattern was chosen for a refactor objective (never the deprecated cosmetic tombstone)
        $this->assertIsArray($pattern);
        $this->assertSame('ticket_to_pr_ready', $pattern['selected']);
        $this->assertNotSame('legacy_blind_refactor', $pattern['selected']);
        $this->assertArrayHasKey('spec', $pattern);

        // the contract is complete (all 14 fields) and bound to the same pattern + objective
        $this->assertIsArray($contract);
        $this->assertSame([], AtlasLoopExecutionContract::missingFields($contract));
        $this->assertSame('ticket_to_pr_ready', $contract['pattern_id']);
        $this->assertSame($built['objective'], $contract['objective']);
        foreach ([
            'pattern_id', 'pattern_version', 'objective', 'allowed_scope', 'required_inputs', 'expected_outputs',
            'success_gates', 'terminal_states', 'durability_mode', 'sandbox_profile', 'agent_lane_policy',
            'budget', 'rollback_policy', 'memory_writeback_policy',
        ] as $field) {
            $this->assertArrayHasKey($field, $contract);
        }
    }

    public function test_objective_kind_is_derived_from_the_originated_shape(): void
    {
        $producer = $this->producer();
        $base = ['_score' => ['leverage' => 15.0], 'verifiable' => true, 'risk' => 0.3, 'cost' => 0.3];

        $this->assertSame('feature', $this->descriptor($producer, $base, ['shape' => 'feature'])['objective_kind']);
        $this->assertSame('refactor', $this->descriptor($producer, $base, ['shape' => 'refactor'])['objective_kind']);
        $this->assertSame('bug', $this->descriptor($producer, $base, ['shape' => 'bug_fix'])['objective_kind']);
        // a real originated objective is never marked cosmetic (proxy-only was PARKed upstream)
        $this->assertFalse($this->descriptor($producer, $base, ['shape' => 'refactor'])['cosmetic']);
    }

    public function test_a_negligible_value_objective_gets_an_honest_rejection_and_no_contract(): void
    {
        // leverage 0 → expected_impact 0 → the selector's anti-cosmetic gate rejects; advisory stays honest.
        $winner = ['path' => 'app/Svc/Nothing.php', 'verifiable' => false, 'risk' => 0.5, 'cost' => 0.5, '_score' => ['leverage' => 0.0]];
        $built = ['objective' => 'tidy whitespace', 'target_path' => 'app/Svc/Nothing.php', 'shape' => 'refactor'];

        [$pattern, $contract] = $this->attach($this->producer(), $winner, $built);

        $this->assertIsArray($pattern);
        $this->assertNull($pattern['selected']);
        $this->assertTrue($pattern['rejected']);
        $this->assertNull($contract, 'a rejected objective must carry no execution contract');
    }

    public function test_the_advisory_is_byte_identical_off_when_the_flag_is_disabled(): void
    {
        config(['atlas.loop.pattern_advisory_enabled' => false]);

        $winner = ['path' => 'app/Svc/Thing.php', 'verifiable' => true, 'risk' => 0.4, 'cost' => 0.3, '_score' => ['leverage' => 20.0]];
        $built = ['objective' => 'reduce coupling', 'target_path' => 'app/Svc/Thing.php', 'shape' => 'refactor'];

        $this->assertSame([null, null], $this->attach($this->producer(), $winner, $built));
    }

    public function test_construction_still_works_without_the_pattern_dependencies(): void
    {
        // The new nullable params must not break existing construction (no AppServiceProvider change).
        $this->assertInstanceOf(AtlasLoopObjectiveProducer::class, new AtlasLoopObjectiveProducer());

        // and the ?? new accessors resolve even when nothing is injected (fail-safe DI backstop)
        $winner = ['path' => 'app/Svc/Thing.php', 'verifiable' => true, 'risk' => 0.4, 'cost' => 0.3, '_score' => ['leverage' => 18.0]];
        $built = ['objective' => 'reduce coupling', 'target_path' => 'app/Svc/Thing.php', 'shape' => 'refactor'];
        config(['atlas.loop.pattern_advisory_enabled' => true]);

        [$pattern, $contract] = $this->attach(new AtlasLoopObjectiveProducer(), $winner, $built);
        $this->assertSame('ticket_to_pr_ready', $pattern['selected']);
        $this->assertIsArray($contract);
    }

    // ───────────────────────────────────────────────────────────────────────────────────────────────
    // LOOP-PATTERN-REGISTRY · A0 — the DECISION DRIVER. driveCandidateDecision() is the EXACT pre-origination
    // code produce() runs when atlas.loop.pattern_driver_enabled is ON. Driving it directly (same idiom as
    // attachPatternAdvisory above) proves the PatternRegistry/Selector CHANGE the chosen candidate BEFORE
    // origination — provider-free, no engine call.
    // ───────────────────────────────────────────────────────────────────────────────────────────────

    private function driverProducer(): AtlasLoopObjectiveProducer
    {
        return new AtlasLoopObjectiveProducer(
            patternRegistry: new AtlasLoopPatternRegistry(),
            patternSelector: new AtlasLoopPatternSelector(),
            patternCompiler: new AtlasLoopPatternCompiler(),
            patternDriver: new AtlasLoopPatternDecisionDriver(),
        );
    }

    public function test_driver_disabled_by_default_preserves_advisory_only(): void
    {
        config(['atlas.loop.pattern_driver_enabled' => false]);

        // OFF: produce() must fall back to the legacy advisory path, so the driver self-reports DISABLED.
        $this->assertFalse((bool) config('atlas.loop.pattern_driver_enabled', false), 'the driver must report disabled when the flag is OFF');

        $floorPassers = [
            ['path' => 'app/Svc/Real.php', 'verifiable' => true, '_score' => ['leverage' => 20.0, 'rationale' => 'hub']],
        ];

        $decision = $this->driverProducer()->driveCandidateDecision($floorPassers);

        $this->assertSame(AtlasLoopPatternDecisionDriver::STATE_DISABLED, $decision['state']);
        $this->assertNull($decision['pattern'], 'a disabled driver never selects — the advisory path decides');
        $this->assertNull($decision['selected']);
    }

    /** THE acceptance proof: with the driver ON, the cosmetic top candidate is rejected and #2 is chosen. */
    public function test_driver_on_rejects_cosmetic_top_and_selects_the_runner_up(): void
    {
        config(['atlas.loop.pattern_driver_enabled' => true]);

        $floorPassers = [
            // #1 — highest leverage but flagged cosmetic/proxy. WITHOUT the driver this would be the winner.
            ['path' => 'app/Svc/Cosmetic.php', 'cosmetic' => true, 'verifiable' => false, '_score' => ['leverage' => 40.0, 'rationale' => 'top by order']],
            // #2 — a genuine, verifiable refactor: the candidate the driver must pick instead.
            ['path' => 'app/Svc/Real.php', 'verifiable' => true, '_score' => ['leverage' => 18.0, 'rationale' => 'real refactor']],
        ];

        $decision = $this->driverProducer()->driveCandidateDecision($floorPassers);

        $this->assertSame(AtlasLoopPatternDecisionDriver::STATE_SELECTED, $decision['state']);
        $this->assertSame(1, $decision['selected_index'], 'the driver skips the cosmetic #1 and selects the real #2');
        $this->assertSame('app/Svc/Real.php', $decision['selected']['path']);
        $this->assertInstanceOf(AtlasLoopPatternSpec::class, $decision['pattern']);
        $this->assertSame('ticket_to_pr_ready', $decision['pattern']->id);
        $this->assertSame(1, count($decision['rejections']), 'the rejected cosmetic candidate is receipted');
        $this->assertSame('app/Svc/Cosmetic.php', $decision['rejections'][0]['path']);
    }

    public function test_driver_on_returns_governed_terminal_when_all_candidates_rejected(): void
    {
        config(['atlas.loop.pattern_driver_enabled' => true]);

        $floorPassers = [
            ['path' => 'app/Svc/A.php', 'cosmetic' => true, '_score' => ['leverage' => 40.0, 'rationale' => 'x']],
            ['path' => 'app/Svc/B.php', 'proxy' => true, '_score' => ['leverage' => 30.0, 'rationale' => 'y']],
        ];

        $decision = $this->driverProducer()->driveCandidateDecision($floorPassers);

        // produce() turns any non-SELECTED terminal into a governed null (no objective is invented).
        $this->assertSame(AtlasLoopPatternDecisionDriver::STATE_REJECTED_ALL, $decision['state']);
        $this->assertNull($decision['pattern']);
        $this->assertSame(2, count($decision['rejections']));
    }

    /** Item #6 through the producer's OWN descriptor: NaN/INF/negligible leverage never passes as real work. */
    public function test_driver_on_refuses_nan_inf_and_negligible_impact_candidates(): void
    {
        config(['atlas.loop.pattern_driver_enabled' => true]);

        foreach (['NaN' => NAN, '+INF' => INF, 'negligible' => 0.1] as $label => $leverage) {
            $floorPassers = [
                ['path' => "app/Svc/{$label}.php", 'verifiable' => true, '_score' => ['leverage' => $leverage, 'rationale' => $label]],
            ];

            $decision = $this->driverProducer()->driveCandidateDecision($floorPassers);

            $this->assertSame(
                AtlasLoopPatternDecisionDriver::STATE_REJECTED_ALL,
                $decision['state'],
                "{$label} leverage must not pass as real work"
            );
            $this->assertNull($decision['pattern']);
        }
    }

    // ───────────────────────────────────────────────────────────────────────────────────────────────
    // A — the HONEST candidate value signal that feeds the driver. These prove the veto on signals
    // computed by the REAL classifier from raw (cyclomatic, caller_count, verifiable) tuples — NOT the
    // synthetic ['cosmetic' => true] flags above — which was the gap that made the armed driver theater.
    // ───────────────────────────────────────────────────────────────────────────────────────────────

    /** Build a packet whose proxy/cosmetic/work_value_class come from the REAL classifier, not by hand. */
    private function packetVia(AtlasLoopObjectiveProducer $p, string $path, int $cx, int $callers, bool $verif, float $leverage): array
    {
        $m = new ReflectionMethod(AtlasLoopObjectiveProducer::class, 'classifyCandidateValue');
        $m->setAccessible(true);
        $signal = $m->invoke($p, $cx, $callers, $verif);

        return ['path' => $path, 'cyclomatic' => $cx, 'caller_count' => $callers, 'verifiable' => $verif, '_score' => ['leverage' => $leverage, 'rationale' => 'r']] + $signal;
    }

    public function test_classifier_stamps_honest_value_from_raw_signals(): void
    {
        $p = $this->driverProducer();
        $m = new ReflectionMethod(AtlasLoopObjectiveProducer::class, 'classifyCandidateValue');
        $m->setAccessible(true);

        // material: behaviour anchor + real complexity + wired ⇒ real value, no proxy reasons.
        $material = $m->invoke($p, 19, 4, true);
        $this->assertFalse($material['proxy']);
        $this->assertFalse($material['cosmetic']);
        $this->assertSame('material_refactor', $material['work_value_class']);
        $this->assertSame([], $material['proxy_reasons']);

        // complex + wired but NO sibling-test anchor ⇒ proxy (the gate can't prove it), auditable reason.
        $noAnchor = $m->invoke($p, 27, 2, false);
        $this->assertTrue($noAnchor['proxy']);
        $this->assertFalse($noAnchor['cosmetic']);
        $this->assertSame('proxy_refactor', $noAnchor['work_value_class']);
        $this->assertContains('no_behavior_anchor', $noAnchor['proxy_reasons']);

        // trivial + orphan ⇒ cosmetic (the unambiguous faxina).
        $cosmetic = $m->invoke($p, 5, 0, false);
        $this->assertTrue($cosmetic['proxy']);
        $this->assertTrue($cosmetic['cosmetic']);
        $this->assertSame('cosmetic', $cosmetic['work_value_class']);
        $this->assertContains('orphan_not_wired', $cosmetic['proxy_reasons']);
        $this->assertContains('low_complexity_not_material', $cosmetic['proxy_reasons']);
    }

    public function test_driver_rejects_real_classifier_proxy_top_and_picks_material(): void
    {
        config(['atlas.loop.pattern_driver_enabled' => true]);
        $p = $this->driverProducer();

        $floorPassers = [
            $this->packetVia($p, 'app/Svc/NoAnchor.php', 27, 2, false, 20.0), // proxy per REAL classifier
            $this->packetVia($p, 'app/Svc/Material.php', 19, 4, true, 12.0),   // material per REAL classifier
        ];
        // guards: the signal is classifier-computed, not hand-flagged.
        $this->assertTrue($floorPassers[0]['proxy']);
        $this->assertFalse($floorPassers[1]['proxy']);

        $decision = $p->driveCandidateDecision($floorPassers);

        $this->assertSame(AtlasLoopPatternDecisionDriver::STATE_SELECTED, $decision['state']);
        $this->assertSame('app/Svc/Material.php', $decision['selected']['path'], 'the classifier-proxy top is skipped; the material runner-up wins');
        $this->assertSame(1, count($decision['rejections']));
        $this->assertSame('app/Svc/NoAnchor.php', $decision['rejections'][0]['path']);
    }

    public function test_driver_rejected_all_when_classifier_marks_every_candidate_proxy(): void
    {
        config(['atlas.loop.pattern_driver_enabled' => true]);
        $p = $this->driverProducer();

        $floorPassers = [
            $this->packetVia($p, 'app/Svc/A.php', 9, 3, false, 20.0), // proxy (no anchor, low complexity)
            $this->packetVia($p, 'app/Svc/B.php', 5, 0, false, 15.0), // cosmetic (trivial orphan)
        ];
        foreach ($floorPassers as $fp) {
            $this->assertTrue($fp['proxy'], 'guard: every candidate is classifier-marked proxy');
        }

        $decision = $p->driveCandidateDecision($floorPassers);

        $this->assertSame(AtlasLoopPatternDecisionDriver::STATE_REJECTED_ALL, $decision['state'], 'all-proxy ⇒ the rédea emits NO objective');
        $this->assertNull($decision['pattern']);
    }
}
