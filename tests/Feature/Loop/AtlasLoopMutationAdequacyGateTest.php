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

    public function test_refactor_contract_rejects_when_no_decision_mutant_producible(): void
    {
        $this->workspace = $this->refactorWorkspaceWithOnlyRelocatedLiteral();

        $on = app(AtlasLoopMutationAdequacyGateService::class)->evaluate(
            $this->workspace,
            $this->refactorAcceptance(),
            ['src/Calc.php'],
            ['enabled' => true, 'refactor_decision_aware' => true],
        );

        // Added lines contain ONLY a relocated string literal and no decision operator: with cosmetic
        // operators skipped there is nothing to sample, so the gate fails closed (no free pass).
        $this->assertSame('no_applicable_mutation', $on['status']);
        $this->assertFalse($on['certified']);
        $this->assertSame(['no_applicable_mutation'], $on['blockers']);
        $this->assertSame('none', $on['decisive_operator_family']);
        $this->assertSame(0, $on['mutants_sampled']);
    }

    public function test_refactor_contract_never_mutates_decision_operator_in_old_unchanged_code(): void
    {
        // REGRESSION (adversarial panel, 2026-06-15): the ONLY covered `===` lives in OLD, UNCHANGED
        // classify(); the refactor adds ONLY an unasserted relocated literal, so the diff's added
        // lines contain NO decision operator. A correct decision-aware gate must fail CLOSED
        // (no_applicable_mutation) and must NOT reach back into the old classify() `===` to
        // manufacture a kill — that would FALSELY certify a refactor whose new code is untested.
        $this->workspace = $this->refactorWorkspaceWithDecisionOnlyInOldCode();

        $on = app(AtlasLoopMutationAdequacyGateService::class)->evaluate(
            $this->workspace,
            $this->refactorAcceptance(),
            ['src/Calc.php'],
            ['enabled' => true, 'refactor_decision_aware' => true],
        );

        $this->assertSame('no_applicable_mutation', $on['status'], 'must NOT mutate the covered === in OLD unchanged code');
        $this->assertFalse($on['certified']);
        $this->assertSame(['no_applicable_mutation'], $on['blockers']);
        $this->assertSame(0, $on['mutants_sampled']);
        $this->assertSame('none', $on['decisive_operator_family']);
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

    /**
     * @return array<string,mixed>
     */
    private function refactorAcceptance(): array
    {
        return [
            'commands' => ['php tests/CalcTest.php'],
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
