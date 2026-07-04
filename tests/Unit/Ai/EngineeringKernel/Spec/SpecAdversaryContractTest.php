<?php

declare(strict_types=1);

namespace Tests\Unit\Ai\EngineeringKernel\Spec;

use App\Services\Ai\EngineeringKernel\Spec\DivergenceStatus;
use App\Services\Ai\EngineeringKernel\Spec\IntentEnvelope;
use App\Services\Ai\EngineeringKernel\Spec\SpecDraft;
use App\Services\Ai\EngineeringKernel\Spec\SpecProvenance;
use App\Services\Ai\EngineeringKernel\Spec\SpecSourceIndependence;
use App\Services\Ai\EngineeringKernel\Spec\SpecVerdict;
use App\Services\Ai\EngineeringKernel\TrustLevel;
use PHPUnit\Framework\TestCase;

/**
 * Slice 0 — pure contract for the spec-adversary. No behavior yet: enums + DTOs construct and the
 * provenance/lane logic holds. Wiper-safe: zero DB, zero Laravel bootstrap.
 */
final class SpecAdversaryContractTest extends TestCase
{
    public function test_self_composed_unwitnessed_holds_in_autonomous_but_freezes_where_a_human_is_present(): void
    {
        $unwitnessed = SpecSourceIndependence::SelfComposedUnwitnessed;

        // no independent source in the autonomous lane -> may NOT freeze (must HOLD)
        self::assertFalse($unwitnessed->mayFreezeIn(TrustLevel::Autonomos));
        // a human is in the loop in dev/forge -> the human IS the independent source
        self::assertTrue($unwitnessed->mayFreezeIn(TrustLevel::Dev));
        self::assertTrue($unwitnessed->mayFreezeIn(TrustLevel::Forge));

        // witnessed states freeze anywhere
        foreach (TrustLevel::cases() as $lane) {
            self::assertTrue(SpecSourceIndependence::HumanWitnessed->mayFreezeIn($lane));
            self::assertTrue(SpecSourceIndependence::CrossFamilyWitnessed->mayFreezeIn($lane));
        }
    }

    public function test_spec_draft_separates_behavioral_from_backstop_criteria(): void
    {
        $draft = SpecDraft::fromArray([
            'intent_text' => 'adicionar validação de e-mail',
            'acceptance_criteria' => [
                ['id' => 'ac_behavior_add', 'description' => 'rejects invalid email', 'verification' => 'test', 'verification_ref' => 't', 'case_class' => 'happy'],
                ['id' => 'ac_cmd', 'description' => 'command exits 0', 'verification' => 'command', 'verification_ref' => 'c', 'is_backstop' => true],
                ['id' => 'ac_scope', 'description' => 'diff touches only expected_files', 'verification' => 'scope', 'verification_ref' => null, 'is_backstop' => true],
            ],
            'expected_files' => ['app/Foo.php'],
        ]);

        self::assertCount(3, $draft->acceptanceCriteria);
        self::assertCount(1, $draft->behavioralCriteria(), 'backstops must be excluded from coverage');
        self::assertSame('ac_behavior_add', $draft->behavioralCriteria()[0]['id']);
    }

    public function test_intent_envelope_tracks_elicited_answers(): void
    {
        $intent = IntentEnvelope::fromArray([
            'raw_goal' => 'faz o negócio funcionar',
            'recognized_verbs' => ['ajustar'],
            'elicited_answers' => ['qual arquivo?' => 'app/Foo.php', 'em branco?' => '  '],
        ]);

        self::assertTrue($intent->wasElicited('qual arquivo?'));
        self::assertFalse($intent->wasElicited('em branco?'), 'blank answer is not elicited');
        self::assertFalse($intent->wasElicited('nunca perguntado'));
    }

    public function test_spec_verdict_is_fail_closed_and_hold_is_distinct_from_refuse(): void
    {
        $prov = new SpecProvenance('hash-1', DivergenceStatus::NotRequired, SpecSourceIndependence::HumanWitnessed, SpecProvenance::ORACLE_EXECUTIONAL);

        $freeze = SpecVerdict::freeze(['x' => ['status' => 'pass', 'detail' => 'ok']], $prov);
        $hold = SpecVerdict::hold(['spec_source_independence'], [], new SpecProvenance('hash-2', DivergenceStatus::Unavailable, SpecSourceIndependence::SelfComposedUnwitnessed, SpecProvenance::ORACLE_STRUCTURAL_ONLY));
        $refuse = SpecVerdict::refuse(['oracle_adequacy'], [], $prov);

        self::assertTrue($freeze->frozen());
        self::assertSame([], $freeze->gaps);

        self::assertSame(SpecVerdict::HOLD, $hold->status);
        self::assertFalse($hold->frozen());
        self::assertNotSame(SpecVerdict::REFUSE, $hold->status, 'HOLD must be distinct from REFUSE');
        self::assertSame('unavailable', $hold->provenance->divergenceStatus->value);

        self::assertSame(SpecVerdict::REFUSE, $refuse->status);
        self::assertContains('oracle_adequacy', $refuse->gaps);
    }
}
