<?php

declare(strict_types=1);

namespace Tests\Feature\Loop;

use App\Services\Ai\AutonomousEvolution\AtlasLoopTaskGrinder;
use ReflectionClass;
use Tests\TestCase;

/**
 * ACDE Tier-1 #1 — cert-reason -> escalation. When the frozen judge passes (or emits no rejected_reasons)
 * but the SEMANTIC implementation cert rejects every proposal, the conductor's attempt-ledger reason must
 * be the cert's OWN specific + STABLE signal instead of the opaque 'no_winner' (which looks identical every
 * round and never trips the ledger's thrashing jump). Escalation-gated => byte-identical when OFF.
 *
 * FLOOR: harvesting cert reasons NEVER weakens the gate — resultHasCertifiedWinner stays false throughout.
 *
 * Pure-method test: tierReason / certificationRejectionReasons / resultHasCertifiedWinner touch only the
 * $result array + config(), so the grinder is built WITHOUT its constructor (mirrors the escalation wiring
 * test) and no ctor dependency is initialized.
 */
final class AtlasLoopCertReasonEscalationTest extends TestCase
{
    private function grinder(): AtlasLoopTaskGrinder
    {
        return (new ReflectionClass(AtlasLoopTaskGrinder::class))->newInstanceWithoutConstructor();
    }

    private function invokePure(string $method, array $result): mixed
    {
        $m = (new ReflectionClass(AtlasLoopTaskGrinder::class))->getMethod($method);
        $m->setAccessible(true);

        return $m->invoke($this->grinder(), $result);
    }

    /** A no-winner round whose frozen judge emitted nothing but whose semantic cert rejected. */
    private function resultWithCertRejections(): array
    {
        return [
            'proposals' => [], // nothing kept => no winner
            'explorations' => [['rejected_reasons' => []]], // frozen judge emitted no rejection
            'semantic_implementation_certification' => [
                'reports' => [
                    ['proposal_hash' => 'a', 'certified' => false, 'level' => 'mutation_adequacy', 'reasons' => ['surviving mutants in refund rounding']],
                ],
            ],
        ];
    }

    public function test_off_is_opaque_no_winner_byte_identical(): void
    {
        config()->set('atlas.loop.conductor_escalation_enabled', false);
        $this->assertSame('no_winner', $this->invokePure('tierReason', $this->resultWithCertRejections()));
    }

    public function test_on_surfaces_the_specific_stable_cert_reason(): void
    {
        config()->set('atlas.loop.conductor_escalation_enabled', true);
        $reason = $this->invokePure('tierReason', $this->resultWithCertRejections());
        $this->assertSame('cert:mutation_adequacy: surviving mutants in refund rounding', $reason);
        // STABLE: identical input => identical reason (a recurring identical failure trips the thrash-jump).
        $this->assertSame($reason, $this->invokePure('tierReason', $this->resultWithCertRejections()));
    }

    public function test_frozen_judge_rejected_reasons_still_win_first(): void
    {
        config()->set('atlas.loop.conductor_escalation_enabled', true);
        $result = $this->resultWithCertRejections();
        $result['explorations'] = [['rejected_reasons' => ['no_passing_candidate']]];
        $this->assertSame('no_passing_candidate', $this->invokePure('tierReason', $result), 'frozen-judge reason precedes the cert reason');
    }

    public function test_certified_winner_short_circuits_to_certified(): void
    {
        config()->set('atlas.loop.conductor_escalation_enabled', true);
        $result = $this->resultWithCertRejections();
        $result['proposals'] = [['proposal_hash' => 'w']]; // a kept proposal => a winner
        $this->assertSame('certified', $this->invokePure('tierReason', $result));
    }

    public function test_harvest_skips_certified_reports_and_is_floor_safe(): void
    {
        $result = [
            'proposals' => [],
            'semantic_implementation_certification' => [
                'reports' => [
                    ['certified' => true, 'level' => 'ok', 'reasons' => []], // certified => contributes nothing
                    ['certified' => false, 'level' => 'cross_file_consumer', 'reasons' => ['consumer Foo broke']],
                    ['certified' => false, 'level' => '', 'reasons' => ['bare reason no level']],
                    ['certified' => false, 'level' => 'gate_error', 'reasons' => []], // level-only
                ],
            ],
        ];
        $this->assertSame([
            'cert:cross_file_consumer: consumer Foo broke',
            'cert:bare reason no level',
            'cert:gate_error',
        ], $this->invokePure('certificationRejectionReasons', $result));

        // FLOOR: harvesting reasons must NOT make the gate believe there is a winner.
        $this->assertFalse($this->invokePure('resultHasCertifiedWinner', $result));
    }
}
