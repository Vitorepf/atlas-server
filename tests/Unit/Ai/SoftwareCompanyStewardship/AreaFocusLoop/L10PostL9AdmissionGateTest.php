<?php

declare(strict_types=1);

namespace Tests\Unit\Ai\SoftwareCompanyStewardship\AreaFocusLoop;

use App\Services\Ai\SoftwareCompanyStewardship\AreaFocusLoop\L10PostL9AdmissionGate;
use PHPUnit\Framework\TestCase;

final class L10PostL9AdmissionGateTest extends TestCase
{
    private L10PostL9AdmissionGate $gate;

    protected function setUp(): void
    {
        $this->gate = new L10PostL9AdmissionGate();
    }

    /**
     * Aceite: admit(candidate, l9Certification) returns schema
     * atlas.aaeos.l10.admission.v1 with admitted, allowed_kind,
     * required_predecessor=S145 and blockers.
     */
    public function testGuardrailPreconditionWorkIsAdmittedAfterCertifiedL9(): void
    {
        $result = $this->gate->admit(
            ['level' => 'L10', 'kind' => 'precondition', 'scope' => 'engineering'],
            ['certified' => true],
        );

        $this->assertSame('atlas.aaeos.l10.admission.v1', $result['schema_version']);
        $this->assertTrue($result['admitted']);
        $this->assertSame('precondition', $result['allowed_kind']);
        $this->assertSame('S145', $result['required_predecessor']);
        $this->assertSame([], $result['blockers']);
        $this->assertTrue($result['l9_certified']);
        $this->assertTrue($result['is_guardrail_kind']);
        $this->assertFalse($result['is_capability_claim']);
        $this->assertSame('admitted_l10_guardrail_work', $result['status']);
    }

    /**
     * Aceite: each of the three guardrail kinds (precondition/spec/certification)
     * is admitted, and allowed_kind echoes the candidate's own kind — proving the
     * field is computed, not a canned constant.
     */
    public function testEachGuardrailKindIsAdmittedWithMatchingAllowedKind(): void
    {
        foreach (['precondition', 'spec', 'certification'] as $kind) {
            $result = $this->gate->admit(
                ['level' => 'L10', 'kind' => $kind],
                ['certified' => true],
            );

            $this->assertTrue($result['admitted'], "kind {$kind} must admit");
            $this->assertSame($kind, $result['allowed_kind']);
            $this->assertSame([], $result['blockers']);
        }
    }

    /**
     * Aceite: missing certified L9 blocks. Certification absent => admitted=false,
     * allowed_kind=none, explicit blocker, even for an otherwise-valid guardrail.
     */
    public function testMissingCertifiedL9Blocks(): void
    {
        $result = $this->gate->admit(
            ['level' => 'L10', 'kind' => 'spec'],
            ['certified' => false],
        );

        $this->assertFalse($result['admitted']);
        $this->assertSame('none', $result['allowed_kind']);
        $this->assertSame(['l9_not_certified'], $result['blockers']);
        $this->assertFalse($result['l9_certified']);
        $this->assertSame('blocked_l10_pre_l9', $result['status']);
        $this->assertSame('S145', $result['required_predecessor']);
    }

    /**
     * Aceite: missing certified L9 (key entirely absent) also blocks — fail-closed
     * default, not an admit.
     */
    public function testAbsentL9CertificationKeyBlocks(): void
    {
        $result = $this->gate->admit(
            ['level' => 'L10', 'kind' => 'certification'],
            [],
        );

        $this->assertFalse($result['admitted']);
        $this->assertSame('none', $result['allowed_kind']);
        $this->assertSame(['l9_not_certified'], $result['blockers']);
    }

    /**
     * Aceite: runtime_execution candidate blocks. A generative-runtime-execution
     * candidate is never admitted, even with a certified L9 and engineering scope.
     */
    public function testRuntimeExecutionCandidateBlocks(): void
    {
        $result = $this->gate->admit(
            ['level' => 'L10', 'kind' => 'runtime_execution', 'scope' => 'engineering'],
            ['certified' => true],
        );

        $this->assertFalse($result['admitted']);
        $this->assertSame('none', $result['allowed_kind']);
        $this->assertSame(['runtime_execution_not_admissible'], $result['blockers']);
        $this->assertTrue($result['is_capability_claim']);
        $this->assertFalse($result['is_guardrail_kind']);
        $this->assertSame('blocked_capability_claim', $result['status']);
    }

    /**
     * Aceite (generalisation): other capability-claim kinds — generative and
     * recursion execution — are likewise blocked with specific reasons. Inputs the
     * happy-path test never sees, so canned output cannot pass this.
     */
    public function testGenerativeAndRecursionExecutionClaimsBlock(): void
    {
        $generative = $this->gate->admit(
            ['level' => 'L10', 'kind' => 'generative_execution'],
            ['certified' => true],
        );
        $this->assertFalse($generative['admitted']);
        $this->assertSame('none', $generative['allowed_kind']);
        $this->assertSame(['generative_execution_not_admissible'], $generative['blockers']);

        $recursion = $this->gate->admit(
            ['level' => 'L10', 'kind' => 'recursion_execution'],
            ['certified' => true],
        );
        $this->assertFalse($recursion['admitted']);
        $this->assertSame(['recursion_execution_not_admissible'], $recursion['blockers']);
    }

    /**
     * Aceite (normalisation): a capability claim written as "Runtime Execution"
     * normalises to runtime_execution and is still blocked — proves the kind is
     * computed by rule, not matched against the exact test literal.
     */
    public function testCapabilityClaimIsNormalisedBeforeBlocking(): void
    {
        $result = $this->gate->admit(
            ['level' => 'l10', 'kind' => 'Runtime Execution'],
            ['certified' => true],
        );

        $this->assertSame('runtime_execution', $result['kind']);
        $this->assertFalse($result['admitted']);
        $this->assertSame(['runtime_execution_not_admissible'], $result['blockers']);
    }

    /**
     * Aceite: non-engineering scope blocks. An explicit external-domain scope is
     * rejected before the kind is even considered.
     */
    public function testNonEngineeringScopeBlocks(): void
    {
        $result = $this->gate->admit(
            ['level' => 'L10', 'kind' => 'precondition', 'scope' => 'marketing'],
            ['certified' => true],
        );

        $this->assertFalse($result['admitted']);
        $this->assertSame('none', $result['allowed_kind']);
        $this->assertSame(['non_engineering_scope'], $result['blockers']);
        $this->assertFalse($result['scope_in_engineering']);
        $this->assertSame('blocked_non_engineering_scope', $result['status']);
    }

    /**
     * Aceite (generalisation): a domain-generator scope — scope-creep into making
     * other domains — is likewise rejected.
     */
    public function testDomainGeneratorScopeBlocks(): void
    {
        $result = $this->gate->admit(
            ['level' => 'L10', 'kind' => 'spec', 'scope' => 'domain_generator'],
            ['certified' => true],
        );

        $this->assertFalse($result['admitted']);
        $this->assertSame(['non_engineering_scope'], $result['blockers']);
        $this->assertFalse($result['scope_in_engineering']);
    }

    /**
     * DoD: "L10 backlog starts with guardrails, not capability claims." A
     * guardrail kind admits while a capability claim is refused under the SAME
     * certified-L9 / engineering-scope conditions — the only difference is kind.
     */
    public function testGuardrailIsAdmittedButCapabilityClaimIsRefused(): void
    {
        $certification = ['certified' => true];

        $guardrail = $this->gate->admit(['level' => 'L10', 'kind' => 'certification'], $certification);
        $capability = $this->gate->admit(['level' => 'L10', 'kind' => 'capability_claim'], $certification);

        $this->assertTrue($guardrail['admitted']);
        $this->assertTrue(in_array($guardrail['allowed_kind'], $this->gate->guardrailKinds(), true));

        $this->assertFalse($capability['admitted']);
        $this->assertSame('none', $capability['allowed_kind']);
        $this->assertTrue($capability['is_capability_claim']);
        $this->assertSame(['capability_claim_not_admissible'], $capability['blockers']);
    }

    /**
     * Fail-closed: an L10 candidate with an unrecognised kind is rejected, not
     * admitted — an unknown kind is never silently treated as a guardrail.
     */
    public function testUnknownL10KindFailsClosed(): void
    {
        $result = $this->gate->admit(
            ['level' => 'L10', 'kind' => 'mystery_work'],
            ['certified' => true],
        );

        $this->assertFalse($result['admitted']);
        $this->assertSame('none', $result['allowed_kind']);
        $this->assertSame(['unknown_l10_kind'], $result['blockers']);
        $this->assertFalse($result['is_guardrail_kind']);
        $this->assertFalse($result['is_capability_claim']);
        $this->assertSame('blocked_unknown_l10_kind', $result['status']);
    }

    /**
     * A non-L10 candidate is not this gate's concern: it passes through with no
     * blockers and allowed_kind=none (nothing L10 is admitted), never claiming an
     * L10 admission.
     */
    public function testNonL10CandidatePassesThroughUngated(): void
    {
        $result = $this->gate->admit(
            ['level' => 'L9', 'kind' => 'precondition'],
            ['certified' => true],
        );

        $this->assertTrue($result['admitted']);
        $this->assertFalse($result['is_l10_candidate']);
        $this->assertSame('none', $result['allowed_kind']);
        $this->assertSame([], $result['blockers']);
        $this->assertSame('not_l10_candidate', $result['status']);
    }

    /**
     * Combined-id parsing: "L10-spec" yields level L10 + kind spec and admits,
     * with the candidate id echoed — no separate level/kind keys required.
     */
    public function testCombinedIdIsParsedIntoLevelAndKind(): void
    {
        $result = $this->gate->admit(
            ['id' => 'L10-spec'],
            ['l9_certified' => true],
        );

        $this->assertSame('L10', $result['level']);
        $this->assertSame('spec', $result['kind']);
        $this->assertSame('L10-spec', $result['candidate']);
        $this->assertTrue($result['admitted']);
        $this->assertSame('spec', $result['allowed_kind']);
    }

    /**
     * Ordering: L9-not-certified is reported before scope, before kind. A
     * candidate that is simultaneously uncertified-L9, non-engineering and a
     * capability claim reports exactly the first failing rule.
     */
    public function testEarliestFailingRuleIsReportedFirst(): void
    {
        $result = $this->gate->admit(
            ['level' => 'L10', 'kind' => 'runtime_execution', 'scope' => 'finance'],
            ['certified' => false],
        );

        $this->assertFalse($result['admitted']);
        $this->assertSame(['l9_not_certified'], $result['blockers']);
        $this->assertSame('blocked_l10_pre_l9', $result['status']);
    }

    /**
     * Determinism / purity: identical inputs always yield an identical envelope.
     */
    public function testIdenticalInputIsDeterministic(): void
    {
        $candidate = ['level' => 'L10', 'kind' => 'precondition', 'scope' => 'engineering'];
        $certification = ['certified' => true];

        $first = $this->gate->admit($candidate, $certification);
        $second = $this->gate->admit($candidate, $certification);

        $this->assertSame($first, $second);
    }

    /**
     * The blockers field is always a sequential (0-indexed) list of strings, never
     * a string-keyed map — honouring the list<string> contract on every path.
     */
    public function testBlockersIsAlwaysAListOfStrings(): void
    {
        $blocked = $this->gate->admit(['level' => 'L10', 'kind' => 'runtime_execution'], ['certified' => true]);
        $admitted = $this->gate->admit(['level' => 'L10', 'kind' => 'spec'], ['certified' => true]);

        foreach ([$blocked['blockers'], $admitted['blockers']] as $blockers) {
            $this->assertSame(array_values($blockers), $blockers);
            foreach ($blockers as $blocker) {
                $this->assertIsString($blocker);
            }
        }
    }
}
