<?php

declare(strict_types=1);

namespace Tests\Feature\Loop;

use App\Services\Ai\AutonomousEvolution\AtlasLoopMutationAdequacyGateService;
use Illuminate\Support\Facades\Artisan;
use Symfony\Component\Process\Process;
use Tests\TestCase;

final class AtlasLoopMutationAdequacyGateTest extends TestCase
{
    private ?string $workspace = null;

    protected function tearDown(): void
    {
        if ($this->workspace !== null && is_dir($this->workspace)) {
            (new Process(['rm', '-rf', $this->workspace], null, null, null, 30.0))->run();
        }

        parent::tearDown();
    }

    public function test_command_accepts_strong_fixture_and_generates_adversarial_numeric_cases(): void
    {
        $propertyCommand = <<<'CMD'
php -r '$cases=json_decode(getenv("ATLAS_MUTATION_PROPERTY_CASES") ?: "[]", true); $families=array_column(is_array($cases) ? $cases : [], "family"); $ok=in_array("nan", $families, true) && in_array("positive_infinity", $families, true) && in_array("float_overflow", $families, true); exit($ok ? 0 : 1);'
CMD;

        $exit = Artisan::call('atlas:loop:mutation-gate', [
            '--fixture' => 'strong',
            '--property-command' => [$propertyCommand],
            '--json' => true,
            '--strict' => true,
        ]);

        $payload = json_decode(Artisan::output(), true, flags: JSON_THROW_ON_ERROR);

        $this->assertSame(0, $exit, Artisan::output());
        $this->assertSame('atlas.loop.mutation_adequacy_gate.v1', $payload['schema_version']);
        $this->assertSame('mutation_killed', $payload['status']);
        $this->assertTrue($payload['certified']);
        $this->assertSame(1, $payload['mutants_sampled']);
        $this->assertSame(1, $payload['mutants_killed']);
        $this->assertSame('generated_and_runner_passed', data_get($payload, 'property_adversarial_inputs.status'));
        $this->assertContains('nan', data_get($payload, 'property_adversarial_inputs.families'));
        $this->assertContains('float_overflow', data_get($payload, 'property_adversarial_inputs.families'));
    }

    public function test_command_rejects_weak_fixture_when_mutant_survives(): void
    {
        $exit = Artisan::call('atlas:loop:mutation-gate', [
            '--fixture' => 'weak',
            '--json' => true,
            '--strict' => true,
        ]);

        $payload = json_decode(Artisan::output(), true, flags: JSON_THROW_ON_ERROR);

        $this->assertSame(1, $exit);
        $this->assertSame('mutation_survived', $payload['status']);
        $this->assertFalse($payload['certified']);
        $this->assertSame(['mutation_survived'], $payload['blockers']);
        $this->assertSame(1, $payload['mutants_survived']);
    }

    public function test_semantic_certifier_rejects_diff_earned_test_that_does_not_kill_mutation(): void
    {
        config(['atlas.loop.mutation_adequacy_gate.enabled' => true]);
        $this->workspace = $this->weakButDiffEarnedWorkspace();

        $acceptance = [
            'commands' => ['php tests/BarTest.php'],
            'allowed_globs' => ['src/**'],
            'frozen_globs' => ['tests/**'],
            'metric_kind' => 'gate',
            'revert_recheck' => true,
            'timeout_seconds' => 30,
        ];

        $exit = Artisan::call('atlas:loop:certify-implementation', [
            '--workspace' => $this->workspace,
            '--acceptance' => json_encode($acceptance, JSON_THROW_ON_ERROR),
            '--objective' => 'prove weak tests are rejected by mutation adequacy',
            '--allowed-file' => ['src/Bar.php'],
            '--json' => true,
        ]);

        $payload = json_decode(Artisan::output(), true, flags: JSON_THROW_ON_ERROR);

        $this->assertSame(1, $exit, Artisan::output());
        $this->assertFalse($payload['certified']);
        $this->assertTrue(data_get($payload, 'deterministic_gate.certified'), 'old gate should pass so mutation gate proves the extra protection');
        $this->assertSame('mutation_survived', data_get($payload, 'mutation_adequacy_gate.status'));
        $this->assertContains('mutation_adequacy_gate:mutation_survived', $payload['reasons']);
    }

    public function test_schedule_contains_daily_mutation_gate_fixture_proof(): void
    {
        Artisan::call('schedule:list');

        $this->assertStringContainsString('atlas:loop:mutation-gate --fixture=strong --write-receipt --json', Artisan::output());
    }

    // ------------------------------------------------------------------
    // Keystone: refactor-decision-aware sampling (flag-gated, default OFF).
    // These tests call the service DIRECTLY against git-baseline workspaces so
    // the OFF=legacy / ON=decision-aware contracts are pinned byte-for-byte.
    // ------------------------------------------------------------------

    public function test_refactor_contract_kills_decision_mutant_despite_surviving_cosmetic_literal(): void
    {
        $this->workspace = $this->refactorWorkspaceWithCoveredDecisionAndUnassertedLiteral();

        $acceptance = $this->refactorAcceptance(); // complexity_proof=true, metric_kind=minimize

        // OFF (legacy first-mutation-wins): the cosmetic return_string_literal fires first on the
        // relocated, UNASSERTED literal -> it survives -> the behaviour-preserving refactor is FALSELY
        // rejected. This is the exact live-loop certs=0 bug the keystone fixes.
        $off = app(AtlasLoopMutationAdequacyGateService::class)->evaluate(
            $this->workspace,
            $acceptance,
            ['src/Calc.php'],
            ['enabled' => true, 'refactor_decision_aware' => false],
        );

        $this->assertSame('mutation_survived', $off['status'], 'OFF must reproduce the false rejection');
        $this->assertFalse($off['certified']);
        $this->assertSame('return_string_literal', data_get($off, 'mutants.0.operator'));
        $this->assertSame('cosmetic', $off['decisive_operator_family']);

        // ON (decision-aware): cosmetic operators are skipped for refactor contracts, so the covered
        // === comparison is mutated instead -> the sibling test goes RED -> mutant KILLED -> certified.
        $on = app(AtlasLoopMutationAdequacyGateService::class)->evaluate(
            $this->workspace,
            $acceptance,
            ['src/Calc.php'],
            ['enabled' => true, 'refactor_decision_aware' => true],
        );

        $this->assertSame('mutation_killed', $on['status']);
        $this->assertTrue($on['certified']);
        $this->assertSame('strict_equals', data_get($on, 'mutants.0.operator'));
        $this->assertSame('decision', $on['decisive_operator_family']);
        $this->assertTrue(data_get($on, 'mutants.0.killed'));
        $this->assertSame(1, $on['mutants_sampled']);
        $this->assertSame(1, $on['mutants_killed']);
        $this->assertSame(0, $on['mutants_survived']);
    }

    public function test_exhaustive_feature_lane_rejects_an_uncovered_added_decision(): void
    {
        // ACDE lever #4: a FEATURE diff (no complexity_proof => the feature lane) that adds a decision the
        // test never exercises. The legacy strpos feature lane samples <=max_mutants and can miss it; the
        // exhaustive added-DECISION hunt (flag ON) catches the surviving decision and refuses.
        $this->workspace = $this->refactorWorkspaceWithUncoveredDecisionMutant();
        config(['atlas.loop.mutation_adequacy_gate.exhaustive_added_decisions_feature_lane' => true]);

        $on = app(AtlasLoopMutationAdequacyGateService::class)->evaluate(
            $this->workspace,
            $this->featureAcceptance(),
            ['src/Calc.php'],
            ['enabled' => true],
        );

        $this->assertSame('mutation_survived', $on['status']);
        $this->assertFalse($on['certified']);
        $this->assertSame('decision', $on['decisive_operator_family']);
    }

    public function test_exhaustive_feature_lane_certifies_a_covered_added_decision(): void
    {
        // The sufficiency partner: a covered added decision is killed -> the feature change certifies (the
        // flag tightens the floor, it does not false-reject a genuinely-tested change).
        $this->workspace = $this->refactorWorkspaceWithCoveredDecisionAndUnassertedLiteral();
        config(['atlas.loop.mutation_adequacy_gate.exhaustive_added_decisions_feature_lane' => true]);

        $on = app(AtlasLoopMutationAdequacyGateService::class)->evaluate(
            $this->workspace,
            $this->featureAcceptance(),
            ['src/Calc.php'],
            ['enabled' => true],
        );

        $this->assertSame('mutation_killed', $on['status']);
        $this->assertTrue($on['certified']);
    }

    /**
     * A FEATURE acceptance: the SAME commands as the refactor harness but WITHOUT complexity_proof/minimize,
     * so refactorDecisionAware() is false and the lever's exhaustive flag (not the refactor path) is what
     * routes it through the exhaustive decision hunt.
     *
     * @return array<string,mixed>
     */
    private function featureAcceptance(): array
    {
        return [
            'commands' => ['php tests/CalcTest.php'],
            'allowed_globs' => ['src/**'],
            'frozen_globs' => ['tests/**'],
            'metric_kind' => 'gate',
            'timeout_seconds' => 30,
        ];
    }

    public function test_refactor_contract_still_rejects_when_decision_mutant_survives(): void
    {
        $this->workspace = $this->refactorWorkspaceWithUncoveredDecisionMutant();

        $on = app(AtlasLoopMutationAdequacyGateService::class)->evaluate(
            $this->workspace,
            $this->refactorAcceptance(),
            ['src/Calc.php'],
            ['enabled' => true, 'refactor_decision_aware' => true],
        );

        // The bar is NOT lowered: when the sampled DECISION mutant survives (the test does not cover
        // that comparison), the refactor is still rejected.
        $this->assertSame('mutation_survived', $on['status']);
        $this->assertFalse($on['certified']);
        $this->assertSame(['mutation_survived'], $on['blockers']);
        $this->assertSame('strict_equals', data_get($on, 'mutants.0.operator'));
        $this->assertSame('decision', $on['decisive_operator_family']);
        $this->assertFalse(data_get($on, 'mutants.0.killed'));
        $this->assertSame(1, $on['mutants_survived']);
    }

    public function test_refactor_contract_skips_when_only_an_unasserted_relocated_literal_is_producible(): void
    {
        // REFINED CONTRACT (cosmetic-fallback tier, 2026-06-14): the added lines extract a helper that
        // ONLY returns a string literal the test never asserts. No decision mutant is producible, so the
        // gate falls back to a COSMETIC mutant CONFINED to the added line. That relocated literal SURVIVES
        // (the test never calls label()), and a relocated UNASSERTED literal is NOT evidence of an empty
        // test — this is the original keystone insight. PRE-REFINEMENT this hard-rejected
        // (no_applicable_mutation), falsely killing a behaviour-preserving refactor; now it SKIP-certifies
        // (skipped_cosmetic_survived). The deterministic complexity gate + frozen behaviour test +
        // consumer/refuter gates carry the proof. The true bar is untouched: a SURVIVING DECISION mutant
        // still rejects (see the test above), and the cosmetic never lands on old code (position-confined).
        $this->workspace = $this->refactorWorkspaceWithOnlyRelocatedLiteral();

        $on = app(AtlasLoopMutationAdequacyGateService::class)->evaluate(
            $this->workspace,
            $this->refactorAcceptance(),
            ['src/Calc.php'],
            ['enabled' => true, 'refactor_decision_aware' => true],
        );

        $this->assertSame('skipped_cosmetic_survived', $on['status']);
        $this->assertTrue($on['certified'], 'a relocated unasserted literal is not an empty-test signal');
        $this->assertSame([], $on['blockers']);
        $this->assertSame('cosmetic', $on['decisive_operator_family']);
        $this->assertSame(1, $on['mutants_sampled']);
        $this->assertSame(0, $on['mutants_killed']);
        $this->assertSame(1, $on['mutants_survived']);
        $this->assertSame('return_string_literal', data_get($on, 'mutants.0.operator'));
        $this->assertFalse(data_get($on, 'mutants.0.killed'));
    }

    public function test_refactor_contract_never_mutates_decision_operator_in_old_unchanged_code(): void
    {
        // REGRESSION (adversarial panel, 2026-06-15): the ONLY covered `===` lives in OLD, UNCHANGED
        // classify(); the refactor adds ONLY an unasserted relocated literal, so the diff's added
        // lines contain NO decision operator. The load-bearing invariant — the gate must NEVER reach
        // back into the old classify() `===` to manufacture a kill — is STILL ENFORCED here.
        //
        // REFINED CONTRACT (cosmetic-fallback tier, 2026-06-14): the verdict is no longer the hard
        // no_applicable_mutation reject; with no decision mutant the gate falls back to a COSMETIC
        // mutant CONFINED to the NEW added line (`return 'brand-new-untested-relocated-literal';`).
        // That literal is unasserted, so the cosmetic SURVIVES -> skipped_cosmetic_survived (certified).
        // The anti-false-certify guarantee is unchanged and is the heart of this test: the sampled
        // mutant is a `return_string_literal` on the NEW line that SURVIVED (killed=0) — it did NOT
        // become a `strict_equals` kill on the old covered `===`. mutation_killed must NEVER appear.
        $this->workspace = $this->refactorWorkspaceWithDecisionOnlyInOldCode();

        $on = app(AtlasLoopMutationAdequacyGateService::class)->evaluate(
            $this->workspace,
            $this->refactorAcceptance(),
            ['src/Calc.php'],
            ['enabled' => true, 'refactor_decision_aware' => true],
        );

        // The keystone anti-false-certify assertions: NEVER a kill manufactured from old code.
        $this->assertNotSame('mutation_killed', $on['status'], 'must NOT mutate the covered === in OLD unchanged code');
        $this->assertSame(0, $on['mutants_killed'], 'no kill may come from the old covered === line');
        $this->assertNotSame('decision', $on['decisive_operator_family'], 'the old === must never be the deciding mutant');

        // The refined SKIP outcome (a relocated unasserted literal on the new line, position-confined).
        $this->assertSame('skipped_cosmetic_survived', $on['status']);
        $this->assertTrue($on['certified']);
        $this->assertSame([], $on['blockers']);
        $this->assertSame('cosmetic', $on['decisive_operator_family']);
        $this->assertSame(1, $on['mutants_sampled']);
        $this->assertSame(1, $on['mutants_survived']);
        $this->assertSame('return_string_literal', data_get($on, 'mutants.0.operator'));
        $this->assertFalse(data_get($on, 'mutants.0.killed'));
    }

    public function test_non_refactor_contract_is_byte_identical_when_flag_on(): void
    {
        // SAME fixture as (a), but a NON-refactor contract: metric_kind='gate', no complexity_proof.
        // refactorDecisionAware() is false even with the flag ON, so the legacy first-mutation-wins
        // path runs and the cosmetic literal survives exactly as it did before the keystone.
        $this->workspace = $this->refactorWorkspaceWithCoveredDecisionAndUnassertedLiteral();

        $acceptance = $this->refactorAcceptance();
        $acceptance['metric_kind'] = 'gate';
        unset($acceptance['complexity_proof']);

        $on = app(AtlasLoopMutationAdequacyGateService::class)->evaluate(
            $this->workspace,
            $acceptance,
            ['src/Calc.php'],
            ['enabled' => true, 'refactor_decision_aware' => true],
        );

        $this->assertSame('mutation_survived', $on['status'], 'the flag must ONLY affect refactor contracts');
        $this->assertFalse($on['certified']);
        $this->assertSame('return_string_literal', data_get($on, 'mutants.0.operator'));
        $this->assertSame('cosmetic', $on['decisive_operator_family']);
    }

    public function test_refactor_contract_never_false_certifies_via_duplicate_line_in_old_covered_code(): void
    {
        // REGRESSION — HOLE 1, FALSE-CERTIFY via strpos mis-landing (adversarial panel, 2026-06-14):
        // the refactor leaves the COVERED classify() UNCHANGED and adds a NEW, UNTESTED method whose
        // first body line `        if ($n === 0) {` is BYTE-IDENTICAL to the covered line inside the
        // old classify(). The pre-fix per-line path called strpos on the FULL file, landing the `===`
        // mutation on the OLD covered line -> the sibling test killed it -> mutation_killed/certified
        // for code that is actually untested. Fix A position-confines the mutation to the added line's
        // EXACT NEW-file index, so the `===` is flipped only inside the new untested method, where the
        // test never reaches it. The verdict MUST be mutation_survived (or no_applicable_mutation),
        // NEVER mutation_killed/certified=true.
        $this->workspace = $this->refactorWorkspaceWithDuplicateDecisionLineInNewUntestedMethod();

        $on = app(AtlasLoopMutationAdequacyGateService::class)->evaluate(
            $this->workspace,
            $this->refactorAcceptance(),
            ['src/Calc.php'],
            ['enabled' => true, 'refactor_decision_aware' => true],
        );

        $this->assertContains(
            $on['status'],
            ['mutation_survived', 'no_applicable_mutation'],
            'duplicate added line must NEVER be mutated on the old covered copy (false certify)'
        );
        $this->assertFalse($on['certified'], 'an untested new method must never certify');
        $this->assertNotSame('mutation_killed', $on['status']);
        // If a mutant was sampled at all, it must have been the NEW line (so it survived, not killed).
        if ($on['mutants_sampled'] > 0) {
            $this->assertSame(0, $on['mutants_killed'], 'no kill may come from the old covered duplicate line');
            $this->assertSame(1, $on['mutants_survived']);
            $this->assertFalse(data_get($on, 'mutants.0.killed'));
        }
    }

    public function test_refactor_contract_certifies_extract_method_using_gte_and_lt_operators(): void
    {
        // REGRESSION — HOLE 2, FALSE-REJECT via narrow vocabulary (adversarial panel, 2026-06-14):
        // a well-tested extract-method refactor whose relocated COVERED decision uses `>=` (and a
        // sibling fixture using `<`). The pre-fix vocabulary only knew ===,!==,>0,return bool/int,
        // dispatch,insert -> these refactors produced ZERO decision mutants -> no_applicable_mutation
        // -> hard reject (certs=0 for a huge, common class of refactors). Fix B makes >=,<=,<,> real
        // behaviour-changing mutants, so the covered decision is now sampled and KILLED -> certified.

        $this->workspace = $this->refactorWorkspaceWithCoveredGteDecision();
        $gte = app(AtlasLoopMutationAdequacyGateService::class)->evaluate(
            $this->workspace,
            $this->refactorAcceptance(),
            ['src/Calc.php'],
            ['enabled' => true, 'refactor_decision_aware' => true],
        );
        $this->assertSame('mutation_killed', $gte['status'], 'a well-tested >= refactor must certify (was no_applicable_mutation)');
        $this->assertTrue($gte['certified']);
        $this->assertSame('gte_comparison', data_get($gte, 'mutants.0.operator'));
        $this->assertSame('decision', $gte['decisive_operator_family']);
        $this->assertTrue(data_get($gte, 'mutants.0.killed'));
        $this->assertSame(1, $gte['mutants_killed']);

        // Tear the >= workspace down before building the < one (one $this->workspace slot).
        (new Process(['rm', '-rf', $this->workspace], null, null, null, 30.0))->run();

        $this->workspace = $this->refactorWorkspaceWithCoveredLtDecision();
        $lt = app(AtlasLoopMutationAdequacyGateService::class)->evaluate(
            $this->workspace,
            $this->refactorAcceptance(),
            ['src/Calc.php'],
            ['enabled' => true, 'refactor_decision_aware' => true],
        );
        $this->assertSame('mutation_killed', $lt['status'], 'a well-tested < refactor must certify (was no_applicable_mutation)');
        $this->assertTrue($lt['certified']);
        $this->assertSame('lt_comparison', data_get($lt, 'mutants.0.operator'));
        $this->assertSame('decision', $lt['decisive_operator_family']);
        $this->assertTrue(data_get($lt, 'mutants.0.killed'));
        $this->assertSame(1, $lt['mutants_killed']);
    }

    public function test_refactor_contract_certifies_decision_below_typed_docblock_generic(): void
    {
        // REGRESSION — HOLE 2 docblock edge (final adversarial pass, 2026-06-15): the extract-method
        // refactor adds a typed docblock (' * @param array<string,mixed> $ctx') ABOVE the relocated
        // covered `>=`. A docblock CONTINUATION line carries no comment opener, so per-line masking
        // could not see it and the generic's '<'/'>' became a FAKE gt/lt mutant that — sampled first
        // because it has the lower line number — survived (a comment can never be killed) -> the
        // well-tested refactor was FALSELY rejected. firstAddedLineMutation now SKIPS comment/docblock
        // added lines, so the real `>=` is sampled and KILLED -> the refactor certifies.
        $this->workspace = $this->refactorWorkspaceWithTypedDocblockAboveDecision();

        $on = app(AtlasLoopMutationAdequacyGateService::class)->evaluate(
            $this->workspace,
            $this->refactorAcceptance(),
            ['src/Calc.php'],
            ['enabled' => true, 'refactor_decision_aware' => true],
        );

        $this->assertSame('mutation_killed', $on['status'], 'the typed docblock generic must NOT become a fake surviving mutant');
        $this->assertTrue($on['certified']);
        $this->assertSame('gte_comparison', data_get($on, 'mutants.0.operator'), 'must mutate the real >=, not the docblock generic');
        $this->assertSame('decision', $on['decisive_operator_family']);
        $this->assertTrue(data_get($on, 'mutants.0.killed'));
        $this->assertSame(1, $on['mutants_killed']);
    }

    public function test_refactor_contract_certifies_via_killed_cosmetic_when_no_decision_mutant_exists(): void
    {
        // KEYSTONE — cosmetic-fallback KILL (2026-06-14): a match-map extraction whose added lines carry
        // NO decision operator (an array literal + a `?? ` lookup), but DO carry a COVERED string literal
        // the test asserts (classify(0) === 'zero'). No decision mutant is producible, so the gate falls
        // back to a COSMETIC mutant CONFINED to the added line; flipping the relocated 'zero' turns the
        // test RED -> the cosmetic is KILLED -> mutation_killed/certified. This is the exact class the
        // pre-refinement hard no_applicable_mutation FALSELY rejected (the 2 broken framework-refactor
        // certification tests). The kill PROVES the test exercises the new code via the relocated literal.
        // This fixture documents the COSMETIC-fallback path (no decision mutant producible). Pin the base
        // operator set: ACDE QA1's null_coalesce_null would otherwise mutate the fixture's `??` as a decision.
        config(['atlas.loop.extra_mutation_operators_enabled' => false]);
        $this->workspace = $this->refactorWorkspaceWithCoveredRelocatedLiteralNoDecision();

        $on = app(AtlasLoopMutationAdequacyGateService::class)->evaluate(
            $this->workspace,
            $this->refactorAcceptance(),
            ['src/Calc.php'],
            ['enabled' => true, 'refactor_decision_aware' => true],
        );

        $this->assertSame('mutation_killed', $on['status']);
        $this->assertTrue($on['certified']);
        $this->assertSame([], $on['blockers']);
        $this->assertSame('cosmetic', $on['decisive_operator_family']);
        $this->assertSame(1, $on['mutants_sampled']);
        $this->assertSame(1, $on['mutants_killed']);
        $this->assertSame(0, $on['mutants_survived']);
        $this->assertTrue(data_get($on, 'mutants.0.killed'));
    }

    public function test_rf2_rf4_per_operator_vector_arms_the_real_denominator_off_is_byte_identical(): void
    {
        config(['atlas.loop.extra_mutation_operators_enabled' => false]);
        $this->workspace = $this->refactorWorkspaceWithCoveredGteDecision();
        $acceptance = $this->refactorAcceptance();
        $gate = app(AtlasLoopMutationAdequacyGateService::class);

        // OFF (default): the legacy single-record certify path => no vector field, mutants_sampled=1.
        config(['atlas.loop.mutation_per_operator_vector_enabled' => false]);
        $off = $gate->evaluate($this->workspace, $acceptance, ['src/Calc.php'], ['enabled' => true, 'refactor_decision_aware' => true]);
        $this->assertSame('mutation_killed', $off['status']);
        $this->assertSame(1, $off['mutants_sampled']);
        $this->assertArrayNotHasKey('operator_kill_vector', $off, 'OFF => no vector field => byte-identical receipt shape');

        // ON: the certify path carries the per-operator kill vector (the real denominator QA2 consumes).
        config(['atlas.loop.mutation_per_operator_vector_enabled' => true]);
        $on = $gate->evaluate($this->workspace, $acceptance, ['src/Calc.php'], ['enabled' => true, 'refactor_decision_aware' => true]);
        $this->assertSame('mutation_killed', $on['status']);
        $this->assertTrue($on['certified']);
        $this->assertArrayHasKey('operator_kill_vector', $on);
        $this->assertGreaterThanOrEqual(1, count($on['operator_kill_vector']));
        $this->assertSame(1.0, (float) $on['operator_kill_vector'][0]['ratio'], 'every sampled decision was killed on the certify path');
        $this->assertSame($on['operator_kill_vector'][0]['sampled'], $on['mutants_sampled']);
    }

    public function test_qa2_off_certifies_despite_an_uncovered_second_decision_byte_identical(): void
    {
        // ACDE QA2 — the HOLE the lever closes. The refactor adds TWO decision lines in ONE file: a COVERED
        // `=== 0` in isZero() (classify exercises it) FIRST, and an UNCOVERED `=== 42` in audit() the test
        // never calls SECOND. The first-only lane probes only the FIRST decision per file: it kills the
        // covered `=== 0` and certifies, never reaching the surviving uncovered branch. Default OFF MUST keep
        // this exact (under-probing) behaviour byte-identical.
        config(['atlas.loop.extra_mutation_operators_enabled' => false]);
        config(['atlas.loop.exhaustive_decision_probing_enabled' => false]);
        $this->workspace = $this->refactorWorkspaceWithCoveredFirstAndUncoveredSecondDecision();

        $off = app(AtlasLoopMutationAdequacyGateService::class)->evaluate(
            $this->workspace,
            $this->refactorAcceptance(),
            ['src/Calc.php'],
            ['enabled' => true, 'refactor_decision_aware' => true],
        );

        $this->assertSame('mutation_killed', $off['status'], 'first-only lane kills the covered === and certifies');
        $this->assertTrue($off['certified'], 'OFF keeps the under-probing certification (the hole QA2 closes)');
        $this->assertSame('strict_equals', data_get($off, 'mutants.0.operator'));
        $this->assertSame('decision', $off['decisive_operator_family']);
    }

    public function test_qa2_armed_rejects_the_uncovered_second_decision(): void
    {
        // ACDE QA2 — ARMED: exhaustive probing samples EVERY added decision line, so audit()'s uncovered
        // `=== 42` is now probed, SURVIVES (the frozen test never calls audit), and hard-rejects the refactor
        // that the first-only lane would have falsely certified. Same fixture, flag flipped: the only delta is
        // the surviving second decision is no longer invisible.
        config(['atlas.loop.extra_mutation_operators_enabled' => false]);
        config(['atlas.loop.exhaustive_decision_probing_enabled' => true]);
        $this->workspace = $this->refactorWorkspaceWithCoveredFirstAndUncoveredSecondDecision();

        $on = app(AtlasLoopMutationAdequacyGateService::class)->evaluate(
            $this->workspace,
            $this->refactorAcceptance(),
            ['src/Calc.php'],
            ['enabled' => true, 'refactor_decision_aware' => true],
        );

        $this->assertSame('mutation_survived', $on['status'], 'the uncovered second decision must now reject');
        $this->assertFalse($on['certified']);
        $this->assertSame(['mutation_survived'], $on['blockers']);
        $this->assertSame('decision', $on['decisive_operator_family']);
        $this->assertSame(1, $on['mutants_survived']);
        $this->assertFalse(data_get($on, 'mutants.0.killed'), 'the deciding mutant is the surviving uncovered branch');
    }

    public function test_rf2_rf4_real_denominator_exceeds_one_with_exhaustive_probing(): void
    {
        // The >1-denominator payoff the adversarial verifier flagged as untested in isolation: with BOTH
        // exhaustive decision probing (QA2) AND the per-operator kill vector (RF2+RF4) armed, a refactor with
        // TWO covered decisions certifies over BOTH kills — mutants_sampled is genuinely 2, not 1.
        config(['atlas.loop.extra_mutation_operators_enabled' => false]);
        config(['atlas.loop.exhaustive_decision_probing_enabled' => true]);
        config(['atlas.loop.mutation_per_operator_vector_enabled' => true]);
        $this->workspace = $this->refactorWorkspaceWithTwoCoveredDecisions();

        $on = app(AtlasLoopMutationAdequacyGateService::class)->evaluate(
            $this->workspace,
            $this->refactorAcceptance(),
            ['src/Calc.php'],
            ['enabled' => true, 'refactor_decision_aware' => true],
        );

        $this->assertSame('mutation_killed', $on['status']);
        $this->assertTrue($on['certified']);
        $this->assertSame(2, $on['mutants_sampled'], 'two covered decisions => the kill denominator is genuinely > 1');
        $this->assertSame(2, $on['mutants_killed']);
        $this->assertArrayHasKey('operator_kill_vector', $on);
        $this->assertSame('strict_equals', $on['operator_kill_vector'][0]['operator']);
        $this->assertSame(2, $on['operator_kill_vector'][0]['sampled']);
        $this->assertSame(2, $on['operator_kill_vector'][0]['killed']);
        $this->assertSame(1.0, (float) $on['operator_kill_vector'][0]['ratio']);
    }

    public function test_refactor_contract_skips_when_no_mutant_is_producible_at_all(): void
    {
        // TIER 3 — no mutant at all (2026-06-14): an extract-method refactor whose added lines contain
        // NEITHER a decision operator NOR a string literal (pure arithmetic delegation + a new helper
        // signature). With nothing to sample the gate SKIP-certifies (skipped_no_refactor_mutant): the
        // deterministic complexity gate + frozen behaviour test + consumer/refuter gates carry the proof.
        // This was a hard no_applicable_mutation reject before the refinement.
        $this->workspace = $this->refactorWorkspaceWithNoProducibleMutant();

        $on = app(AtlasLoopMutationAdequacyGateService::class)->evaluate(
            $this->workspace,
            $this->refactorAcceptance(),
            ['src/Calc.php'],
            ['enabled' => true, 'refactor_decision_aware' => true],
        );

        $this->assertSame('skipped_no_refactor_mutant', $on['status']);
        $this->assertTrue($on['certified']);
        $this->assertSame([], $on['blockers']);
        $this->assertSame('none', $on['decisive_operator_family']);
        $this->assertSame(0, $on['mutants_sampled']);
        $this->assertSame(0, $on['mutants_killed']);
        $this->assertSame(0, $on['mutants_survived']);
    }

    public function test_refactor_contract_rejects_surviving_decision_on_a_later_file_despite_killed_cosmetic_first(): void
    {
        // REGRESSION — HOLE 3, FALSE-CERTIFY via multi-file ordering + max_mutants=1 (false-certify probe,
        // 2026-06-14): a two-file refactor where the FIRST diffed file (src/A.php) carries a KILLED
        // cosmetic (a relocated COVERED literal, NO decision op) and the SECOND file (src/B.php) carries a
        // SURVIVING uncovered DECISION branch (a `===` the test never reaches). The pre-fix single-pass
        // loop incremented ONE shared $sampled counter for both families, so the cosmetic kill on A
        // consumed the live max_mutants=1 budget and `break`ed BEFORE B's surviving decision was sampled
        // -> mutation_killed/certified for untested decision code, decided purely by git's file order.
        // The two-pass fix gives DECISION mutants absolute priority across ALL targets, so B's surviving
        // `===` is found in BOTH orderings and the refactor is hard-rejected.
        $svc = app(AtlasLoopMutationAdequacyGateService::class);

        // A FIRST, B SECOND (the order that used to false-certify).
        $this->workspace = $this->refactorTwoFileWorkspaceCosmeticThenSurvivingDecision();
        $aFirst = $svc->evaluate(
            $this->workspace,
            $this->refactorAcceptance(['php tests/Test.php']),
            ['src/A.php', 'src/B.php'],
            ['enabled' => true, 'refactor_decision_aware' => true],
        );
        $this->assertSame('mutation_survived', $aFirst['status'], 'a surviving decision on a later file must reject even when an earlier file has a killed cosmetic');
        $this->assertFalse($aFirst['certified']);
        $this->assertSame(['mutation_survived'], $aFirst['blockers']);
        $this->assertSame('decision', $aFirst['decisive_operator_family']);
        $this->assertSame(0, $aFirst['mutants_killed']);
        $this->assertSame(1, $aFirst['mutants_survived']);
        $this->assertSame('src/B.php', data_get($aFirst, 'mutants.0.file'), 'the deciding mutant must be the surviving decision on B, not the cosmetic on A');

        (new Process(['rm', '-rf', $this->workspace], null, null, null, 30.0))->run();

        // B FIRST, A SECOND — must ALSO reject (order independence).
        $this->workspace = $this->refactorTwoFileWorkspaceCosmeticThenSurvivingDecision();
        $bFirst = $svc->evaluate(
            $this->workspace,
            $this->refactorAcceptance(['php tests/Test.php']),
            ['src/B.php', 'src/A.php'],
            ['enabled' => true, 'refactor_decision_aware' => true],
        );
        $this->assertSame('mutation_survived', $bFirst['status'], 'the verdict must be order-independent');
        $this->assertFalse($bFirst['certified']);
        $this->assertSame('decision', $bFirst['decisive_operator_family']);
    }

    public function test_refactor_contract_rejects_surviving_decision_on_a_later_file_despite_killed_decision_first(): void
    {
        // REGRESSION — HOLE 4 / Fix D, FALSE-CERTIFY via decision-vs-decision multi-file ordering +
        // max_mutants=1 (false-certify probe ROUND 2, 2026-06-14): the HOLE-3 two-pass fix gave DECISION
        // mutants priority over COSMETIC ones, but PASS 1 still `return`ed mutation_killed on the FIRST
        // decision mutant it killed. So a two-file refactor where the FIRST diffed file (src/A.php) has a
        // COVERED (killed) decision and the SECOND file (src/B.php) has an UNCOVERED (surviving) decision
        // certified A-first (the kill consumed the max_mutants=1 budget and returned BEFORE B was probed)
        // but rejected B-first — the verdict depended purely on git's file order, FALSE-certifying the
        // untested decision branch on B. Fix D makes the decision-survivor hunt EXHAUSTIVE over every
        // decision-bearing target (uncapped by max_mutants) and lets a surviving decision OUTRANK any
        // killed one, so B's surviving `=== 42` rejects the refactor in BOTH orderings.
        $svc = app(AtlasLoopMutationAdequacyGateService::class);

        // A FIRST (killed decision), B SECOND (surviving decision) — the order that used to false-certify.
        $this->workspace = $this->refactorTwoFileWorkspaceKilledDecisionThenSurvivingDecision();
        $aFirst = $svc->evaluate(
            $this->workspace,
            $this->refactorAcceptance(['php tests/Test.php']),
            ['src/A.php', 'src/B.php'],
            ['enabled' => true, 'refactor_decision_aware' => true],
        );
        $this->assertSame('mutation_survived', $aFirst['status'], 'a surviving decision on a later file must reject even when an earlier file has a KILLED decision');
        $this->assertFalse($aFirst['certified']);
        $this->assertSame(['mutation_survived'], $aFirst['blockers']);
        $this->assertSame('decision', $aFirst['decisive_operator_family']);
        $this->assertSame(0, $aFirst['mutants_killed'], 'the deciding mutant must be the surviving decision, not the killed one');
        $this->assertSame(1, $aFirst['mutants_survived']);
        $this->assertSame('src/B.php', data_get($aFirst, 'mutants.0.file'), 'the surviving decision on B must decide, not the killed decision on A');

        (new Process(['rm', '-rf', $this->workspace], null, null, null, 30.0))->run();

        // B FIRST (surviving decision), A SECOND (killed decision) — must ALSO reject (order independence).
        $this->workspace = $this->refactorTwoFileWorkspaceKilledDecisionThenSurvivingDecision();
        $bFirst = $svc->evaluate(
            $this->workspace,
            $this->refactorAcceptance(['php tests/Test.php']),
            ['src/B.php', 'src/A.php'],
            ['enabled' => true, 'refactor_decision_aware' => true],
        );
        $this->assertSame('mutation_survived', $bFirst['status'], 'the verdict must be order-independent');
        $this->assertFalse($bFirst['certified']);
        $this->assertSame('decision', $bFirst['decisive_operator_family']);
        $this->assertSame('src/B.php', data_get($bFirst, 'mutants.0.file'));
    }

    /**
     * HOLE 4 / Fix D fixture: a TWO-file behaviour-preserving refactor where BOTH files carry a DECISION
     * mutant, but one is covered and one is not.
     *   - src/A.php relocates the COVERED `> 0` decision into a helper (a KILLED decision mutant), and
     *   - src/B.php adds an UNCOVERED `=== 42` decision branch (audit(), never called by the test).
     * The covered test asserts A->classify(5) and B->name() only. A correct gate must sample B's
     * surviving decision regardless of which file is diffed first, never certifying on A's killed decision.
     */
    private function refactorTwoFileWorkspaceKilledDecisionThenSurvivingDecision(): string
    {
        $dir = sys_get_temp_dir().'/atlas-loop-mutation-twofile-dec-'.bin2hex(random_bytes(5));
        mkdir($dir.'/src', 0o755, true);
        mkdir($dir.'/tests', 0o755, true);

        file_put_contents($dir.'/src/A.php', "<?php\nfinal class A {\n    public function classify(int \$n): string {\n        return \$n > 0 ? 'pos' : 'nonpos';\n    }\n}\n");
        file_put_contents($dir.'/src/B.php', "<?php\nfinal class B {\n    public function name(): string { return 'beta'; }\n}\n");
        file_put_contents($dir.'/tests/Test.php', <<<'PHP'
<?php
require __DIR__.'/../src/A.php';
require __DIR__.'/../src/B.php';
$a = new A(); $b = new B();
if ($a->classify(5) !== 'pos') { fwrite(STDERR, 'A wrong'); exit(1); }
if ($b->name() !== 'beta') { fwrite(STDERR, 'B wrong'); exit(1); }
exit(0);
PHP);

        $this->runProcess(['git', 'init', '-q'], $dir);
        $this->runProcess(['git', 'config', 'user.email', 'atlas-loop@local'], $dir);
        $this->runProcess(['git', 'config', 'user.name', 'Atlas Loop'], $dir);
        $this->runProcess(['git', 'add', '-A'], $dir);
        $this->runProcess(['git', 'commit', '-q', '-m', 'baseline'], $dir);

        // A: relocate the COVERED decision into a helper (killed decision mutant: classify(5) exercises it).
        file_put_contents($dir.'/src/A.php', "<?php\nfinal class A {\n    public function classify(int \$n): string {\n        return \$this->isPos(\$n) ? 'pos' : 'nonpos';\n    }\n    private function isPos(int \$n): bool {\n        return \$n > 0;\n    }\n}\n");
        // B: add an UNCOVERED decision branch (audit() with === 42 the test never calls -> survives).
        file_put_contents($dir.'/src/B.php', "<?php\nfinal class B {\n    public function name(): string { return 'beta'; }\n    public function audit(int \$n): bool {\n        return \$n === 42;\n    }\n}\n");

        return $dir;
    }

    /**
     * HOLE 3 fixture: a TWO-file behaviour-preserving refactor.
     *   - src/A.php relocates a COVERED literal into a helper (a KILLED cosmetic, no decision op), and
     *   - src/B.php adds an UNCOVERED `===` decision branch (audit(), never called by the test).
     * The covered test asserts A->name() and B->classify(5) only. A correct decision-priority gate must
     * sample B's surviving decision regardless of which file is diffed first.
     */
    private function refactorTwoFileWorkspaceCosmeticThenSurvivingDecision(): string
    {
        $dir = sys_get_temp_dir().'/atlas-loop-mutation-twofile-'.bin2hex(random_bytes(5));
        mkdir($dir.'/src', 0o755, true);
        mkdir($dir.'/tests', 0o755, true);

        file_put_contents($dir.'/src/A.php', "<?php\nfinal class A {\n    public function name(): string { return 'alpha'; }\n}\n");
        file_put_contents($dir.'/src/B.php', "<?php\nfinal class B {\n    public function classify(int \$n): string {\n        return \$n > 0 ? 'pos' : 'nonpos';\n    }\n}\n");
        file_put_contents($dir.'/tests/Test.php', <<<'PHP'
<?php
require __DIR__.'/../src/A.php';
require __DIR__.'/../src/B.php';
$a = new A(); $b = new B();
if ($a->name() !== 'alpha') { fwrite(STDERR, 'A wrong'); exit(1); }
if ($b->classify(5) !== 'pos') { fwrite(STDERR, 'B wrong'); exit(1); }
exit(0);
PHP);

        $this->runProcess(['git', 'init', '-q'], $dir);
        $this->runProcess(['git', 'config', 'user.email', 'atlas-loop@local'], $dir);
        $this->runProcess(['git', 'config', 'user.name', 'Atlas Loop'], $dir);
        $this->runProcess(['git', 'add', '-A'], $dir);
        $this->runProcess(['git', 'commit', '-q', '-m', 'baseline'], $dir);

        // A: relocate the covered literal (killed cosmetic, no decision op).
        file_put_contents($dir.'/src/A.php', "<?php\nfinal class A {\n    public function name(): string { return \$this->build(); }\n    private function build(): string { return 'alpha'; }\n}\n");
        // B: add an UNCOVERED decision branch (audit() with === 42 the test never calls).
        file_put_contents($dir.'/src/B.php', "<?php\nfinal class B {\n    public function classify(int \$n): string {\n        return \$n > 0 ? 'pos' : 'nonpos';\n    }\n    public function audit(int \$n): bool {\n        return \$n === 42;\n    }\n}\n");

        return $dir;
    }

    /**
     * @param  list<string>|null  $commands
     * @return array<string,mixed>
     */
    private function refactorAcceptance(?array $commands = null): array
    {
        return [
            'commands' => $commands ?? ['php tests/CalcTest.php'],
            'allowed_globs' => ['src/**'],
            'frozen_globs' => ['tests/**'],
            'metric_kind' => 'minimize',
            'complexity_proof' => true,
            'timeout_seconds' => 30,
        ];
    }

    /**
     * (a)/(d) fixture: a behaviour-preserving refactor whose ADDED lines contain BOTH
     *   - an unasserted relocated `return '...';` in label() (the test never calls it), and
     *   - a covered `===` inside isZero() that classify(0)/classify(5) exercise.
     * OFF mutates the surviving cosmetic literal; ON mutates the killed === comparison.
     */
    private function refactorWorkspaceWithCoveredDecisionAndUnassertedLiteral(): string
    {
        $baseline = <<<'PHP'
<?php
final class Calc {
    public function classify(int $n): string {
        if ($n === 0) {
            return 'zero';
        }
        return 'nonzero';
    }
}
PHP;
        $test = <<<'PHP'
<?php
require __DIR__.'/../src/Calc.php';
$c = new Calc();
if ($c->classify(0) !== 'zero') { fwrite(STDERR, 'classify(0) wrong'); exit(1); }
if ($c->classify(5) !== 'nonzero') { fwrite(STDERR, 'classify(5) wrong'); exit(1); }
exit(0);
PHP;
        $refactor = <<<'PHP'
<?php
final class Calc {
    public function classify(int $n): string {
        return $this->isZero($n) ? 'zero' : 'nonzero';
    }
    private function isZero(int $n): bool {
        return $n === 0;
    }
    public function label(): string {
        return 'unused-relocated-literal';
    }
}
PHP;

        return $this->refactorWorkspace($baseline, $test, $refactor);
    }

    /**
     * (b) fixture: the only DECISION operator in the added lines is a `===` inside audit(),
     * a method the sibling test never calls. classify() (covered) has no `===`, so flipping
     * the audit() comparison changes nothing the test sees: the decision mutant SURVIVES.
     * There is no relocated string literal, so the === is the mutant that gets sampled.
     */
    private function refactorWorkspaceWithUncoveredDecisionMutant(): string
    {
        $baseline = <<<'PHP'
<?php
final class Calc {
    public function classify(int $n): string {
        return $n > 0 ? 'pos' : 'nonpos';
    }
}
PHP;
        $test = <<<'PHP'
<?php
require __DIR__.'/../src/Calc.php';
$c = new Calc();
if ($c->classify(5) !== 'pos') { fwrite(STDERR, 'classify(5) wrong'); exit(1); }
exit(0);
PHP;
        $refactor = <<<'PHP'
<?php
final class Calc {
    public function classify(int $n): string {
        return $n > 0 ? 'pos' : 'nonpos';
    }
    public function audit(int $n): bool {
        return $n === 42;
    }
}
PHP;

        return $this->refactorWorkspace($baseline, $test, $refactor);
    }

    /**
     * QA2 fixture: ONE file with TWO added decision lines — a COVERED `=== 0` in isZero() (classify
     * exercises it) declared FIRST, and an UNCOVERED `=== 42` in audit() the test never calls SECOND.
     * The first-only lane probes only isZero's `===` (killed) and certifies; exhaustive probing also
     * probes audit's `=== 42`, which survives and rejects.
     */
    private function refactorWorkspaceWithCoveredFirstAndUncoveredSecondDecision(): string
    {
        $baseline = <<<'PHP'
<?php
final class Calc {
    public function classify(int $n): string {
        if ($n === 0) {
            return 'zero';
        }
        return 'nonzero';
    }
}
PHP;
        $test = <<<'PHP'
<?php
require __DIR__.'/../src/Calc.php';
$c = new Calc();
if ($c->classify(0) !== 'zero') { fwrite(STDERR, 'classify(0) wrong'); exit(1); }
if ($c->classify(5) !== 'nonzero') { fwrite(STDERR, 'classify(5) wrong'); exit(1); }
exit(0);
PHP;
        $refactor = <<<'PHP'
<?php
final class Calc {
    public function classify(int $n): string {
        return $this->isZero($n) ? 'zero' : 'nonzero';
    }
    private function isZero(int $n): bool {
        return $n === 0;
    }
    public function audit(int $n): bool {
        return $n === 42;
    }
}
PHP;

        return $this->refactorWorkspace($baseline, $test, $refactor);
    }

    /**
     * RF2+RF4 / QA2 fixture: ONE file with TWO COVERED added decisions — `=== 0` in isZero() (classify(0)
     * exercises it) and `=== 1` in isOne() (classify(1) exercises it). With exhaustive probing + the per-
     * operator vector armed, BOTH are killed, so the certify denominator is genuinely 2 (not 1).
     */
    private function refactorWorkspaceWithTwoCoveredDecisions(): string
    {
        $baseline = <<<'PHP'
<?php
final class Calc {
    public function classify(int $n): string {
        if ($n === 0) {
            return 'zero';
        }
        if ($n === 1) {
            return 'one';
        }
        return 'many';
    }
}
PHP;
        $test = <<<'PHP'
<?php
require __DIR__.'/../src/Calc.php';
$c = new Calc();
if ($c->classify(0) !== 'zero') { fwrite(STDERR, 'classify(0) wrong'); exit(1); }
if ($c->classify(1) !== 'one') { fwrite(STDERR, 'classify(1) wrong'); exit(1); }
if ($c->classify(5) !== 'many') { fwrite(STDERR, 'classify(5) wrong'); exit(1); }
exit(0);
PHP;
        $refactor = <<<'PHP'
<?php
final class Calc {
    public function classify(int $n): string {
        if ($this->isZero($n)) {
            return 'zero';
        }
        if ($this->isOne($n)) {
            return 'one';
        }
        return 'many';
    }
    private function isZero(int $n): bool {
        return $n === 0;
    }
    private function isOne(int $n): bool {
        return $n === 1;
    }
}
PHP;

        return $this->refactorWorkspace($baseline, $test, $refactor);
    }

    /**
     * (c) fixture: the added lines extract a helper that ONLY returns a string literal.
     * No `===`, no boolean/integer return, no `> 0`, no dispatch/insert: with cosmetic
     * operators skipped, no decision mutant is producible at all.
     */
    private function refactorWorkspaceWithOnlyRelocatedLiteral(): string
    {
        $baseline = <<<'PHP'
<?php
final class Calc {
    public function describe(): string {
        return 'plain';
    }
}
PHP;
        $test = <<<'PHP'
<?php
require __DIR__.'/../src/Calc.php';
$c = new Calc();
if ($c->describe() !== 'plain') { fwrite(STDERR, 'describe() wrong'); exit(1); }
exit(0);
PHP;
        $refactor = <<<'PHP'
<?php
final class Calc {
    public function describe(): string {
        return 'plain';
    }
    public function label(): string {
        return 'unused-relocated-literal';
    }
}
PHP;

        return $this->refactorWorkspace($baseline, $test, $refactor);
    }

    /**
     * REGRESSION fixture (closes the full-file-fallback hole): baseline classify() has a COVERED
     * `===`; the refactor leaves classify() UNCHANGED and adds ONLY freshUntestedFeature() returning
     * an unasserted literal. The diff's added lines contain no decision operator, so a correct
     * decision-aware gate must fail closed (no_applicable_mutation) and never reach into the old
     * classify() `===` to manufacture a kill.
     */
    private function refactorWorkspaceWithDecisionOnlyInOldCode(): string
    {
        $baseline = <<<'PHP'
<?php
final class Calc {
    public function classify(int $n): string {
        if ($n === 0) {
            return 'zero';
        }
        return 'nonzero';
    }
}
PHP;
        $test = <<<'PHP'
<?php
require __DIR__.'/../src/Calc.php';
$c = new Calc();
if ($c->classify(0) !== 'zero') { fwrite(STDERR, 'classify(0) wrong'); exit(1); }
if ($c->classify(5) !== 'nonzero') { fwrite(STDERR, 'classify(5) wrong'); exit(1); }
exit(0);
PHP;
        $refactor = <<<'PHP'
<?php
final class Calc {
    public function classify(int $n): string {
        if ($n === 0) {
            return 'zero';
        }
        return 'nonzero';
    }
    public function freshUntestedFeature(): string {
        return 'brand-new-untested-relocated-literal';
    }
}
PHP;

        return $this->refactorWorkspace($baseline, $test, $refactor);
    }

    /**
     * REGRESSION fixture for HOLE 1 (false-certify via strpos mis-landing): classify() is COVERED and
     * left UNCHANGED; the refactor adds a NEW, UNTESTED method whose first body line is BYTE-IDENTICAL
     * to the covered `        if ($n === 0) {` line inside classify(). A strpos-based mutation would
     * land the `===` flip on the OLD covered copy and get a false kill; a position-confined mutation
     * flips the `===` only in the new untested method, where it must SURVIVE.
     */
    private function refactorWorkspaceWithDuplicateDecisionLineInNewUntestedMethod(): string
    {
        $baseline = <<<'PHP'
<?php
final class Calc {
    public function classify(int $n): string {
        if ($n === 0) {
            return 'zero';
        }
        return 'nonzero';
    }
}
PHP;
        $test = <<<'PHP'
<?php
require __DIR__.'/../src/Calc.php';
$c = new Calc();
if ($c->classify(0) !== 'zero') { fwrite(STDERR, 'classify(0) wrong'); exit(1); }
if ($c->classify(5) !== 'nonzero') { fwrite(STDERR, 'classify(5) wrong'); exit(1); }
exit(0);
PHP;
        $refactor = <<<'PHP'
<?php
final class Calc {
    public function classify(int $n): string {
        if ($n === 0) {
            return 'zero';
        }
        return 'nonzero';
    }
    public function freshUntested(int $n): string {
        if ($n === 0) {
            return 'new-untested-zero';
        }
        return 'new-untested-other';
    }
}
PHP;

        return $this->refactorWorkspace($baseline, $test, $refactor);
    }

    /**
     * REGRESSION fixture for HOLE 2 (false-reject via narrow vocabulary): a well-tested extract-method
     * refactor whose relocated COVERED decision uses `>=`. classify(10)=big and classify(9)=small both
     * exercise the relocated `return $n >= 10;`, so flipping `>=` -> `<` flips classify(10) to small and
     * the sibling test goes RED -> the decision mutant is KILLED -> the refactor certifies.
     */
    private function refactorWorkspaceWithCoveredGteDecision(): string
    {
        $baseline = <<<'PHP'
<?php
final class Calc {
    public function classify(int $n): string {
        if ($n >= 10) {
            return 'big';
        }
        return 'small';
    }
}
PHP;
        $test = <<<'PHP'
<?php
require __DIR__.'/../src/Calc.php';
$c = new Calc();
if ($c->classify(10) !== 'big') { fwrite(STDERR, 'classify(10) wrong'); exit(1); }
if ($c->classify(9) !== 'small') { fwrite(STDERR, 'classify(9) wrong'); exit(1); }
exit(0);
PHP;
        $refactor = <<<'PHP'
<?php
final class Calc {
    public function classify(int $n): string {
        return $this->isBig($n) ? 'big' : 'small';
    }
    private function isBig(int $n): bool {
        return $n >= 10;
    }
}
PHP;

        return $this->refactorWorkspace($baseline, $test, $refactor);
    }

    /**
     * REGRESSION fixture for the docblock edge: the extracted helper carries a typed @param docblock
     * whose generic (array<string,mixed>) contains '<'/'>' on a comment-continuation line ABOVE the
     * relocated covered `>=`. The gate must skip the docblock line and mutate the real `>=`.
     */
    private function refactorWorkspaceWithTypedDocblockAboveDecision(): string
    {
        $baseline = <<<'PHP'
<?php
final class Calc {
    public function classify(int $n): string {
        if ($n >= 10) {
            return 'big';
        }
        return 'small';
    }
}
PHP;
        $test = <<<'PHP'
<?php
require __DIR__.'/../src/Calc.php';
$c = new Calc();
if ($c->classify(10) !== 'big') { fwrite(STDERR, 'classify(10) wrong'); exit(1); }
if ($c->classify(9) !== 'small') { fwrite(STDERR, 'classify(9) wrong'); exit(1); }
exit(0);
PHP;
        $refactor = <<<'PHP'
<?php
final class Calc {
    public function classify(int $n): string {
        return $this->isBig($n) ? 'big' : 'small';
    }
    /**
     * @param array<string,mixed> $ctx
     */
    private function isBig(int $n, array $ctx = []): bool {
        return $n >= 10;
    }
}
PHP;

        return $this->refactorWorkspace($baseline, $test, $refactor);
    }

    /**
     * REGRESSION fixture for HOLE 2 (the `<` arm): the relocated COVERED decision uses `<`. The mutant
     * `<` -> `>=` flips classify(-1) from neg to nonneg, the sibling test goes RED, the decision mutant
     * is KILLED -> the refactor certifies. Pins that `<` is NOT mis-eaten by the `<=` mutator.
     */
    private function refactorWorkspaceWithCoveredLtDecision(): string
    {
        $baseline = <<<'PHP'
<?php
final class Calc {
    public function classify(int $n): string {
        if ($n < 0) {
            return 'neg';
        }
        return 'nonneg';
    }
}
PHP;
        $test = <<<'PHP'
<?php
require __DIR__.'/../src/Calc.php';
$c = new Calc();
if ($c->classify(-1) !== 'neg') { fwrite(STDERR, 'classify(-1) wrong'); exit(1); }
if ($c->classify(0) !== 'nonneg') { fwrite(STDERR, 'classify(0) wrong'); exit(1); }
exit(0);
PHP;
        $refactor = <<<'PHP'
<?php
final class Calc {
    public function classify(int $n): string {
        return $this->isNeg($n) ? 'neg' : 'nonneg';
    }
    private function isNeg(int $n): bool {
        return $n < 0;
    }
}
PHP;

        return $this->refactorWorkspace($baseline, $test, $refactor);
    }

    /**
     * KEYSTONE fixture (cosmetic-fallback KILL): a match-map extraction whose added lines have NO
     * decision operator (an array literal + a `?? ` lookup), but DO carry a COVERED string literal
     * (`'zero'`) that classify(0) asserts. No decision mutant is producible; the cosmetic fallback
     * flips the relocated 'zero' -> the test goes RED -> KILLED -> certified. Mirrors the framework
     * refactor certification fixtures (match-map / heavy extraction) the refinement unblocks.
     */
    private function refactorWorkspaceWithCoveredRelocatedLiteralNoDecision(): string
    {
        $baseline = <<<'PHP'
<?php
final class Calc {
    public function classify(int $n): string {
        if ($n === 0) { return 'zero'; }
        return 'other';
    }
}
PHP;
        $test = <<<'PHP'
<?php
require __DIR__.'/../src/Calc.php';
$c = new Calc();
if ($c->classify(0) !== 'zero') { fwrite(STDERR, 'classify(0) wrong'); exit(1); }
if ($c->classify(5) !== 'other') { fwrite(STDERR, 'classify(5) wrong'); exit(1); }
exit(0);
PHP;
        $refactor = <<<'PHP'
<?php
final class Calc {
    public function classify(int $n): string {
        $map = [0 => 'zero'];
        return $map[$n] ?? 'other';
    }
}
PHP;

        return $this->refactorWorkspace($baseline, $test, $refactor);
    }

    /**
     * TIER 3 fixture (no mutant at all): an extract-method refactor whose added lines contain NEITHER a
     * decision operator NOR a string literal — pure arithmetic delegation plus a new helper signature.
     * Nothing is producible, so the gate SKIP-certifies (skipped_no_refactor_mutant) and the
     * deterministic complexity gate + frozen behaviour test carry the proof.
     */
    private function refactorWorkspaceWithNoProducibleMutant(): string
    {
        $baseline = <<<'PHP'
<?php
final class Calc {
    public function total(int $a, int $b): int {
        return $a + $b;
    }
}
PHP;
        $test = <<<'PHP'
<?php
require __DIR__.'/../src/Calc.php';
$c = new Calc();
if ($c->total(2, 3) !== 5) { fwrite(STDERR, 'total wrong'); exit(1); }
exit(0);
PHP;
        $refactor = <<<'PHP'
<?php
final class Calc {
    public function total(int $a, int $b): int {
        return $this->sum($a, $b);
    }
    private function sum(int $a, int $b): int {
        return $a + $b;
    }
}
PHP;

        return $this->refactorWorkspace($baseline, $test, $refactor);
    }

    /**
     * Build a git repo: commit the BASELINE source+test, then overwrite the source with the
     * REFACTORED version (uncommitted) so the gate's diff added-lines are exactly the refactor.
     */
    private function refactorWorkspace(string $baselineSrc, string $test, string $refactorSrc): string
    {
        $dir = sys_get_temp_dir().'/atlas-loop-mutation-refactor-'.bin2hex(random_bytes(5));
        mkdir($dir.'/src', 0o755, true);
        mkdir($dir.'/tests', 0o755, true);
        file_put_contents($dir.'/src/Calc.php', $baselineSrc);
        file_put_contents($dir.'/tests/CalcTest.php', $test);
        $this->runProcess(['git', 'init', '-q'], $dir);
        $this->runProcess(['git', 'config', 'user.email', 'atlas-loop@local'], $dir);
        $this->runProcess(['git', 'config', 'user.name', 'Atlas Loop'], $dir);
        $this->runProcess(['git', 'add', '-A'], $dir);
        $this->runProcess(['git', 'commit', '-q', '-m', 'baseline'], $dir);
        file_put_contents($dir.'/src/Calc.php', $refactorSrc);

        return $dir;
    }

    private function weakButDiffEarnedWorkspace(): string
    {
        $dir = sys_get_temp_dir().'/atlas-loop-mutation-certifier-'.bin2hex(random_bytes(5));
        mkdir($dir.'/src', 0o755, true);
        mkdir($dir.'/tests', 0o755, true);
        file_put_contents($dir.'/src/Bar.php', "<?php\nfinal class Bar { public function value(): string { return 'red'; } }\n");
        file_put_contents($dir.'/tests/BarTest.php', <<<'PHP'
<?php
require __DIR__.'/../src/Bar.php';
$bar = new Bar();
if ($bar->value() === 'red') {
    fwrite(STDERR, 'still red');
    exit(1);
}
PHP);
        $this->runProcess(['git', 'init', '-q'], $dir);
        $this->runProcess(['git', 'config', 'user.email', 'atlas-loop@local'], $dir);
        $this->runProcess(['git', 'config', 'user.name', 'Atlas Loop'], $dir);
        $this->runProcess(['git', 'add', '-A'], $dir);
        $this->runProcess(['git', 'commit', '-q', '-m', 'baseline'], $dir);
        file_put_contents($dir.'/src/Bar.php', "<?php\nfinal class Bar { public function value(): string { return 'green'; } }\n");

        return $dir;
    }

    public function test_overfit_probe_detects_arg_count_short_circuit(): void
    {
        // The exact observed weak-model gaming on a thin acceptance.
        $added = ['src/Calc.php' => "    public function add(int \$a, int \$b = 0): int\n    {\n        if (func_num_args() === 1) {\n            return 5;\n        }\n        return \$a + \$b;\n    }"];
        $reason = app(AtlasLoopMutationAdequacyGateService::class)->detectOverfitShortCircuit($added);
        $this->assertSame('overfit_constant_return:arg_count_short_circuit', $reason);
    }

    public function test_overfit_probe_detects_hardcoded_input_special_case(): void
    {
        $added = ['src/Calc.php' => "        if (\$a === 2) return 5;\n        return \$a + \$b;"];
        $this->assertSame(
            'overfit_constant_return:literal_input_special_case',
            app(AtlasLoopMutationAdequacyGateService::class)->detectOverfitShortCircuit($added),
        );
    }

    public function test_overfit_probe_does_not_false_reject_honest_general_code(): void
    {
        $gate = app(AtlasLoopMutationAdequacyGateService::class);
        // a clean general implementation
        $this->assertNull($gate->detectOverfitShortCircuit(['src/Calc.php' => '        return $a + $b;']));
        // legitimate func_get_args() usage that returns a real expression, not a bare literal
        $this->assertNull($gate->detectOverfitShortCircuit(['src/X.php' => "        \$args = func_get_args();\n        return array_sum(\$args);"]));
        // a literal-compared guard that returns a VARIABLE (not a hardcoded answer) is fine
        $this->assertNull($gate->detectOverfitShortCircuit(['src/X.php' => "        if (\$mode === 1) return \$cached;\n        return compute(\$mode);"]));
        // empty diff
        $this->assertNull($gate->detectOverfitShortCircuit([]));
    }

    /**
     * @param  list<string>  $argv
     */
    private function runProcess(array $argv, string $cwd): void
    {
        $process = new Process($argv, $cwd, null, null, 30.0);
        $process->run();
        $this->assertTrue($process->isSuccessful(), $process->getErrorOutput() ?: $process->getOutput());
    }
}
