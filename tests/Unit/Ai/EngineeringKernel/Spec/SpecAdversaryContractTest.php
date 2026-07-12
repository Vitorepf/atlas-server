<?php

declare(strict_types=1);

namespace Tests\Unit\Ai\EngineeringKernel\Spec;

use App\Services\Ai\EngineeringKernel\Spec\AtlasSpecGateAdapter;
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

    public function test_product_authority_intent_fails_closed_on_missing_metric_provenance_and_stale_world(): void
    {
        $intent = IntentEnvelope::fromArray(['raw_goal' => 'implementar backend', 'problem' => 'p', 'user' => 'u', 'value' => 'v',
            'success_window' => '7d', 'sources' => ['s'], 'constraints' => ['c'], 'hypotheses' => ['h'], 'falsifiers' => ['f'],
            'release_policy' => ['canary'], 'outcome_policy' => ['7d'], 'world_observed_at' => '2020-01-01T00:00:00+00:00']);

        self::assertContains('missing_metric', $intent->productAuthorityGaps());
        self::assertContains('missing_provenance', $intent->productAuthorityGaps());
        self::assertContains('stale_world_model', $intent->productAuthorityGaps());
    }

    public function test_product_authority_detects_contradiction_and_spec_hash_covers_all_nfr_dimensions(): void
    {
        $intent = IntentEnvelope::fromArray(['constraints' => ['never write'], 'non_goals' => ['never write']]);
        self::assertContains('contradictory_constraints', $intent->productAuthorityGaps());

        $base = ['intent_text' => 'x', 'acceptance_criteria' => [['id' => 'a']], 'invariants' => ['i'],
            'non_functional_requirements' => ['n'], 'security' => ['s'], 'accessibility' => ['a'], 'observability' => ['o'],
            'compatibility' => ['c'], 'migration' => ['m'], 'rollback' => ['r'], 'oracles' => ['q'], 'invalidity_conditions' => ['x']];
        $changed = $base;
        $changed['rollback'] = ['different'];
        self::assertNotSame(SpecDraft::fromArray($base)->authorityHash(), SpecDraft::fromArray($changed)->authorityHash());
    }

    public function test_spec_and_intent_authority_hashes_are_stable_under_map_reordering(): void
    {
        $spec = [
            'intent_text' => 'x',
            'acceptance_criteria' => [['id' => 'a', 'description' => 'does x']],
            'invariants' => [['b' => 'two', 'a' => 'one']],
            'non_functional_requirements' => ['n'], 'security' => ['s'], 'accessibility' => ['a'],
            'observability' => ['o'], 'compatibility' => ['c'], 'migration' => ['m'], 'rollback' => ['r'],
            'oracles' => ['q'], 'invalidity_conditions' => ['x'],
        ];
        $reorderedSpec = $spec;
        $reorderedSpec['invariants'] = [['a' => 'one', 'b' => 'two']];
        self::assertSame(SpecDraft::fromArray($spec)->authorityHash(), SpecDraft::fromArray($reorderedSpec)->authorityHash());

        $intent = IntentEnvelope::fromArray(['raw_goal' => 'x', 'release_policy' => ['z' => 2, 'a' => 1]]);
        $reorderedIntent = IntentEnvelope::fromArray(['raw_goal' => 'x', 'release_policy' => ['a' => 1, 'z' => 2]]);
        self::assertSame($intent->productAuthorityHash(), $reorderedIntent->productAuthorityHash());
    }

    public function test_spec_bindings_are_required_by_authority_and_change_the_canonical_hash(): void
    {
        $base = [
            'intent_text' => 'adicionar validação em EmailValidator.php',
            'acceptance_criteria' => [['id' => 'a', 'description' => 'rejects invalid email', 'verification' => 'test']],
            'product_intent_hash' => hash('sha256', 'intent'), 'world_snapshot_hash' => hash('sha256', 'world'),
            'evidence_binding_hash' => hash('sha256', 'evidence'),
            'author_identity' => 'builder-family', 'final_witness_identity' => 'verifier-family',
        ];
        $draft = SpecDraft::fromArray($base);
        self::assertSame([], $draft->bindingGaps());
        $mutated = $base;
        $mutated['world_snapshot_hash'] = hash('sha256', 'changed-world');
        self::assertNotSame($draft->authorityHash(), SpecDraft::fromArray($mutated)->authorityHash());
        self::assertContains('missing_evidence_binding_hash', SpecDraft::fromArray(['intent_text' => 'x'])->bindingGaps());
        $selfReview = $base;
        $selfReview['final_witness_identity'] = 'builder-family';
        self::assertContains('self_review_author_equals_final_witness', SpecDraft::fromArray($selfReview)->bindingGaps());
    }

    public function test_each_spec_authority_dimension_is_individually_detected_when_missing(): void
    {
        $base = [
            'intent_text' => 'adicionar validação em EmailValidator.php',
            'acceptance_criteria' => [['id' => 'a']], 'invariants' => ['i'],
            'non_functional_requirements' => ['n'], 'security' => ['s'], 'accessibility' => ['a'],
            'observability' => ['o'], 'compatibility' => ['c'], 'migration' => ['m'], 'rollback' => ['r'],
            'oracles' => ['q'], 'invalidity_conditions' => ['x'],
            'product_intent_hash' => hash('sha256', 'intent'), 'world_snapshot_hash' => hash('sha256', 'world'),
            'evidence_binding_hash' => hash('sha256', 'evidence'), 'author_identity' => 'builder', 'final_witness_identity' => 'verifier',
        ];
        foreach (['acceptance_criteria', 'invariants', 'non_functional_requirements', 'security', 'accessibility', 'observability', 'compatibility', 'migration', 'rollback', 'oracles', 'invalidity_conditions'] as $field) {
            $mutated = $base;
            $mutated[$field] = [];
            self::assertContains('missing_'.str_replace('non_functional_requirements', 'non_functional_requirements', $field), SpecDraft::fromArray($mutated)->authorityGaps(), $field);
        }
    }

    public function test_product_authority_api_has_no_caller_verdict_parameter(): void
    {
        $parameters = (new \ReflectionMethod(AtlasSpecGateAdapter::class, 'adjudicateProductAuthority'))->getParameters();
        self::assertSame(['draft', 'intent', 'lane'], array_map(static fn (\ReflectionParameter $parameter): string => $parameter->getName(), $parameters));
    }

    public function test_default_court_resolvers_hold_even_when_caller_supplies_favorable_strings(): void
    {
        $decision = (new AtlasSpecGateAdapter)->adjudicateProductAuthority(
            SpecDraft::fromArray(['intent_text' => 'x', 'acceptance_criteria' => [['id' => 'a']]]),
            IntentEnvelope::fromArray(['raw_goal' => 'implementar x', 'problem' => 'p', 'user' => 'u', 'value' => 'v', 'metric' => 'm',
                'success_window' => '7d', 'sources' => ['s'], 'provenance' => ['p'], 'constraints' => ['c'], 'hypotheses' => ['h'],
                'falsifiers' => ['f'], 'release_policy' => ['r'], 'outcome_policy' => ['o'], 'world_observed_at' => now()->toAtomString()]),
            TrustLevel::Dev,
        );
        self::assertSame('hold', $decision['status']);
        self::assertContains('product_truth_resolver_unavailable', $decision['gaps']);
        self::assertContains('world_model_resolver_unavailable', $decision['gaps']);
    }
}
