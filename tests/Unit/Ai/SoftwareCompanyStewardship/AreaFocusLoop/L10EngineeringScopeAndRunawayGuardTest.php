<?php

declare(strict_types=1);

namespace Tests\Unit\Ai\SoftwareCompanyStewardship\AreaFocusLoop;

use App\Services\Ai\SoftwareCompanyStewardship\AreaFocusLoop\L10EngineeringScopeAndRunawayGuard;
use PHPUnit\Framework\TestCase;

final class L10EngineeringScopeAndRunawayGuardTest extends TestCase
{
    private L10EngineeringScopeAndRunawayGuard $guard;

    protected function setUp(): void
    {
        $this->guard = new L10EngineeringScopeAndRunawayGuard();
    }

    public function testReturnsSchemaVersionAndAllExpectedKeys(): void
    {
        $result = $this->guard->evaluate([
            'scope' => 'software_engineering',
        ]);

        $this->assertSame(
            'atlas.aaeos.l10.engineering_scope_and_runaway_guard.v1',
            $result['schema_version'],
        );
        $this->assertArrayHasKey('in_scope', $result);
        $this->assertArrayHasKey('runaway_risk', $result);
        $this->assertArrayHasKey('sovereignty_violation', $result);
        $this->assertArrayHasKey('rejected_reason', $result);
        $this->assertArrayHasKey('blockers', $result);
    }

    public function testBoundedEngineeringCandidatePasses(): void
    {
        $result = $this->guard->evaluate([
            'candidate_id' => 'deepen-spec-gate',
            'scope' => 'software_engineering',
            'requested_recursion_depth' => 2,
            'proven_convergence_bound' => 4,
            'convergence_bound_proven' => true,
            'operator_sovereignty' => true,
            'sovereignty_receipt_present' => true,
            'ends_authored_by' => 'operator',
        ]);

        $this->assertTrue($result['in_scope']);
        $this->assertFalse($result['runaway_risk']);
        $this->assertFalse($result['sovereignty_violation']);
        $this->assertSame('', $result['rejected_reason']);
        $this->assertSame([], $result['blockers']);
        $this->assertSame('software_engineering', $result['allowed_scope']);
    }

    public function testEngineeringScopeTokenAlsoPasses(): void
    {
        $result = $this->guard->evaluate([
            'domain' => 'forge',
            'kind' => 'engineering_paradigm',
        ]);

        $this->assertTrue($result['in_scope']);
        $this->assertFalse($result['runaway_risk']);
        $this->assertFalse($result['sovereignty_violation']);
        $this->assertSame('', $result['rejected_reason']);
        $this->assertSame([], $result['blockers']);
    }

    // ---- Rule 1: external domain rejects (scope creep) ----

    public function testExternalMarketingDomainRejects(): void
    {
        $result = $this->guard->evaluate([
            'candidate_id' => 'run-ad-campaigns',
            'domain' => 'marketing',
        ]);

        $this->assertFalse($result['in_scope']);
        $this->assertSame('scope_creep', $result['rejected_reason']);
        $this->assertSame(['external_domain:marketing'], $result['blockers']);
        $this->assertFalse($result['runaway_risk']);
        $this->assertFalse($result['sovereignty_violation']);
        $this->assertSame('software_engineering', $result['allowed_scope']);
    }

    public function testExternalFinanceDomainRejects(): void
    {
        $result = $this->guard->evaluate([
            'scope' => 'finance',
        ]);

        $this->assertFalse($result['in_scope']);
        $this->assertSame('scope_creep', $result['rejected_reason']);
        $this->assertSame(['external_domain:finance'], $result['blockers']);
    }

    public function testExternalTradingDomainRejects(): void
    {
        $result = $this->guard->evaluate([
            'target_domain' => 'trading',
        ]);

        $this->assertFalse($result['in_scope']);
        $this->assertSame('scope_creep', $result['rejected_reason']);
        $this->assertSame(['external_domain:trading'], $result['blockers']);
    }

    public function testUnrecognisedForeignDomainFailsClosedAsScopeCreep(): void
    {
        $result = $this->guard->evaluate([
            'domain' => 'logistics',
        ]);

        $this->assertFalse($result['in_scope']);
        $this->assertSame('scope_creep', $result['rejected_reason']);
        $this->assertSame(['out_of_engineering_scope:logistics'], $result['blockers']);
    }

    public function testForeignDomainCannotBeLaunderedBySelfAssertedEngineeringFlag(): void
    {
        // A concrete foreign-domain declaration must fail closed regardless of an
        // auxiliary `software_engineering` flag: the fail-closed scope rule judges
        // the declared domain, never the candidate's claim about itself. Otherwise
        // scope creep is trivially bypassed by attaching software_engineering:true.
        $result = $this->guard->evaluate([
            'domain' => 'logistics',
            'software_engineering' => true,
        ]);

        $this->assertFalse($result['in_scope']);
        $this->assertSame('scope_creep', $result['rejected_reason']);
        $this->assertSame(['out_of_engineering_scope:logistics'], $result['blockers']);
        $this->assertFalse($result['runaway_risk']);
        $this->assertFalse($result['sovereignty_violation']);
    }

    public function testForeignDomainCannotBeLaunderedByScopeKindToken(): void
    {
        // Same fail-closed guarantee for the `scope_kind`/`engineering_scope`
        // affirmative tokens: they may not override an explicit foreign domain.
        $result = $this->guard->evaluate([
            'domain' => 'healthcare',
            'engineering_scope' => 'forge',
        ]);

        $this->assertFalse($result['in_scope']);
        $this->assertSame('scope_creep', $result['rejected_reason']);
        $this->assertSame(['out_of_engineering_scope:healthcare'], $result['blockers']);
    }

    public function testBareEngineeringFlagWithNoDomainStillPasses(): void
    {
        // The affirmative engineering flag remains a valid in-scope signal when
        // NO foreign domain is declared — the fix narrows the flag's reach, it
        // does not remove it (the out-of-scope rule short-circuits on an empty
        // domain).
        $result = $this->guard->evaluate([
            'candidate_id' => 'deepen-loop',
            'software_engineering' => true,
        ]);

        $this->assertTrue($result['in_scope']);
        $this->assertFalse($result['runaway_risk']);
        $this->assertFalse($result['sovereignty_violation']);
        $this->assertSame('', $result['rejected_reason']);
        $this->assertSame([], $result['blockers']);
    }

    // ---- Rule 2: unbounded recursion rejects (runaway) ----

    public function testExplicitUnboundedRecursionRejects(): void
    {
        $result = $this->guard->evaluate([
            'scope' => 'software_engineering',
            'unbounded_recursion' => true,
        ]);

        $this->assertFalse($result['in_scope']);
        $this->assertTrue($result['runaway_risk']);
        $this->assertSame('runaway', $result['rejected_reason']);
        $this->assertSame(['unbounded_recursion'], $result['blockers']);
        $this->assertFalse($result['sovereignty_violation']);
    }

    public function testRecursionWithoutProvenBoundRejects(): void
    {
        $result = $this->guard->evaluate([
            'scope' => 'software_engineering',
            'requested_recursion_depth' => 3,
            // no proven_convergence_bound, no convergence_bound_proven flag
        ]);

        $this->assertFalse($result['in_scope']);
        $this->assertTrue($result['runaway_risk']);
        $this->assertSame('runaway', $result['rejected_reason']);
        $this->assertSame(['unbounded_recursion'], $result['blockers']);
    }

    public function testRecursionDepthAboveProvenBoundRejects(): void
    {
        $result = $this->guard->evaluate([
            'scope' => 'software_engineering',
            'requested_recursion_depth' => 7,
            'proven_convergence_bound' => 5,
        ]);

        $this->assertFalse($result['in_scope']);
        $this->assertTrue($result['runaway_risk']);
        $this->assertSame('runaway', $result['rejected_reason']);
        $this->assertSame(['unbounded_recursion'], $result['blockers']);
    }

    public function testRecursionDepthExactlyAtProvenBoundPasses(): void
    {
        // Boundary: recursion runs exactly up to the proven bound, never beyond.
        $result = $this->guard->evaluate([
            'scope' => 'software_engineering',
            'requested_recursion_depth' => 5,
            'proven_convergence_bound' => 5,
        ]);

        $this->assertTrue($result['in_scope']);
        $this->assertFalse($result['runaway_risk']);
        $this->assertSame('', $result['rejected_reason']);
        $this->assertSame([], $result['blockers']);
    }

    public function testNonFiniteRecursionDepthIsRunawayAndLeaksNoWarning(): void
    {
        // INF/NAN is the canonical unbounded recursion request: it must reject as
        // runaway (fail closed), never be coerced to ~0 and slip through, and it
        // must not emit an int-cast warning (purity / no I/O side channel). Even
        // a (nonsensical) proven bound cannot whitewash a non-finite depth.
        foreach ([INF, NAN] as $poisonDepth) {
            $errors = [];
            set_error_handler(static function (int $errno, string $msg) use (&$errors): bool {
                $errors[] = $msg;

                return true;
            });

            try {
                $result = $this->guard->evaluate([
                    'scope' => 'software_engineering',
                    'requested_recursion_depth' => $poisonDepth,
                    'proven_convergence_bound' => 4,
                    'convergence_bound_proven' => true,
                ]);
            } finally {
                restore_error_handler();
            }

            $this->assertSame([], $errors);
            $this->assertFalse($result['in_scope']);
            $this->assertTrue($result['runaway_risk']);
            $this->assertSame('runaway', $result['rejected_reason']);
            $this->assertSame(['unbounded_recursion'], $result['blockers']);
        }
    }

    public function testNonFiniteProvenBoundCannotWhitewashRequestedDepth(): void
    {
        // A non-finite "proven bound" is not a valid finite proof: a finite depth
        // requested against it is still unbounded recursion (no usable bound), and
        // no int-cast warning leaks.
        $errors = [];
        set_error_handler(static function (int $errno, string $msg) use (&$errors): bool {
            $errors[] = $msg;

            return true;
        });

        try {
            $result = $this->guard->evaluate([
                'scope' => 'software_engineering',
                'requested_recursion_depth' => 3,
                'proven_convergence_bound' => INF,
            ]);
        } finally {
            restore_error_handler();
        }

        $this->assertSame([], $errors);
        $this->assertFalse($result['in_scope']);
        $this->assertTrue($result['runaway_risk']);
        $this->assertSame('runaway', $result['rejected_reason']);
        $this->assertSame(['unbounded_recursion'], $result['blockers']);
    }

    public function testNumericStringRecursionDepthIsComparedNotIgnored(): void
    {
        // A requested depth supplied as a numeric STRING (the shape a JSON-decoded
        // candidate routinely carries) must be compared against the proven bound,
        // not read as "no recursion requested". A string depth past the bound is a
        // runaway; coercing it to an absent depth would be a fail-open that lets a
        // string depth bypass the runaway guard entirely.
        $aboveBound = $this->guard->evaluate([
            'scope' => 'software_engineering',
            'requested_recursion_depth' => '9',
            'proven_convergence_bound' => 2,
        ]);

        $this->assertFalse($aboveBound['in_scope']);
        $this->assertTrue($aboveBound['runaway_risk']);
        $this->assertSame('runaway', $aboveBound['rejected_reason']);
        $this->assertSame(['unbounded_recursion'], $aboveBound['blockers']);

        // A string depth with no proven bound at all is the canonical runaway.
        $noBound = $this->guard->evaluate([
            'scope' => 'software_engineering',
            'requested_recursion_depth' => '3',
        ]);

        $this->assertFalse($noBound['in_scope']);
        $this->assertTrue($noBound['runaway_risk']);
        $this->assertSame(['unbounded_recursion'], $noBound['blockers']);

        // A string depth within a proven bound still passes (the coercion is
        // faithful, not a blunt reject).
        $withinBound = $this->guard->evaluate([
            'scope' => 'software_engineering',
            'requested_recursion_depth' => '5',
            'proven_convergence_bound' => 5,
        ]);

        $this->assertTrue($withinBound['in_scope']);
        $this->assertFalse($withinBound['runaway_risk']);
        $this->assertSame([], $withinBound['blockers']);
    }

    public function testNonFiniteNumericStringDepthIsRunawayAndLeaksNoWarning(): void
    {
        // A numeric string whose float value is non-finite ("1e400" -> INF) is the
        // same canonical unbounded recursion as a float INF depth: it must reject
        // as runaway (fail closed), even against a proven bound, and emit no
        // int-cast warning (purity / no I/O side channel).
        $errors = [];
        set_error_handler(static function (int $errno, string $msg) use (&$errors): bool {
            $errors[] = $msg;

            return true;
        });

        try {
            $result = $this->guard->evaluate([
                'scope' => 'software_engineering',
                'requested_recursion_depth' => '1e400',
                'proven_convergence_bound' => 4,
                'convergence_bound_proven' => true,
            ]);
        } finally {
            restore_error_handler();
        }

        $this->assertSame([], $errors);
        $this->assertFalse($result['in_scope']);
        $this->assertTrue($result['runaway_risk']);
        $this->assertSame('runaway', $result['rejected_reason']);
        $this->assertSame(['unbounded_recursion'], $result['blockers']);
    }

    public function testUnboundedGenerativeExecutionRejects(): void
    {
        $result = $this->guard->evaluate([
            'scope' => 'software_engineering',
            'executes_generative_paradigm' => true,
            // no proven bound -> generative execution is unbounded -> runaway
        ]);

        $this->assertFalse($result['in_scope']);
        $this->assertTrue($result['runaway_risk']);
        $this->assertSame('runaway', $result['rejected_reason']);
        $this->assertSame(['unbounded_generative_execution'], $result['blockers']);
    }

    public function testGenerativeExecutionWithProvenBoundPasses(): void
    {
        $result = $this->guard->evaluate([
            'scope' => 'software_engineering',
            'executes_generative_paradigm' => true,
            'convergence_bound_proven' => true,
        ]);

        $this->assertTrue($result['in_scope']);
        $this->assertFalse($result['runaway_risk']);
        $this->assertSame([], $result['blockers']);
    }

    // ---- Rule 3: system-chosen ends rejects (value capture / sovereignty) ----

    public function testSystemChosenEndsRejects(): void
    {
        $result = $this->guard->evaluate([
            'scope' => 'software_engineering',
            'system_chosen_ends' => true,
        ]);

        $this->assertFalse($result['in_scope']);
        $this->assertTrue($result['sovereignty_violation']);
        $this->assertSame('value_capture', $result['rejected_reason']);
        $this->assertSame(['system_chosen_ends'], $result['blockers']);
        $this->assertFalse($result['runaway_risk']);
    }

    public function testEndsAuthoredBySystemRejects(): void
    {
        $result = $this->guard->evaluate([
            'scope' => 'software_engineering',
            'ends_authored_by' => 'atlas',
        ]);

        $this->assertFalse($result['in_scope']);
        $this->assertTrue($result['sovereignty_violation']);
        $this->assertSame('value_capture', $result['rejected_reason']);
        $this->assertSame(['system_chosen_ends'], $result['blockers']);
    }

    public function testBypassSovereigntyRejects(): void
    {
        $result = $this->guard->evaluate([
            'scope' => 'software_engineering',
            'bypass_sovereignty' => true,
        ]);

        $this->assertFalse($result['in_scope']);
        $this->assertTrue($result['sovereignty_violation']);
        $this->assertSame('value_capture', $result['rejected_reason']);
        $this->assertSame(['sovereignty_bypass'], $result['blockers']);
    }

    public function testExplicitlyAbsentSovereigntyReceiptRejects(): void
    {
        $result = $this->guard->evaluate([
            'scope' => 'software_engineering',
            'operator_sovereignty' => false,
        ]);

        $this->assertFalse($result['in_scope']);
        $this->assertTrue($result['sovereignty_violation']);
        $this->assertSame('value_capture', $result['rejected_reason']);
        $this->assertSame(['sovereignty_bypass'], $result['blockers']);
    }

    // ---- DoD: blocks runaway, scope creep and value capture together ----

    public function testAllThreeViolationFamiliesAccumulateSortedBlockers(): void
    {
        $result = $this->guard->evaluate([
            'domain' => 'trading',
            'unbounded_recursion' => true,
            'system_chosen_ends' => true,
        ]);

        $this->assertFalse($result['in_scope']);
        $this->assertTrue($result['runaway_risk']);
        $this->assertTrue($result['sovereignty_violation']);
        $this->assertSame(
            ['external_domain:trading', 'system_chosen_ends', 'unbounded_recursion'],
            $result['blockers'],
        );
        // The first matched ordered rule (scope creep) names the reason.
        $this->assertSame('scope_creep', $result['rejected_reason']);
    }

    public function testRunawayPrecedesValueCaptureWhenScopeClean(): void
    {
        $result = $this->guard->evaluate([
            'scope' => 'software_engineering',
            'requested_recursion_depth' => 9,
            'proven_convergence_bound' => 2,
            'self_authorized' => true,
        ]);

        $this->assertFalse($result['in_scope']);
        $this->assertTrue($result['runaway_risk']);
        $this->assertTrue($result['sovereignty_violation']);
        $this->assertSame('runaway', $result['rejected_reason']);
        $this->assertSame(['sovereignty_bypass', 'unbounded_recursion'], $result['blockers']);
    }

    public function testBlockersIsListOfStrings(): void
    {
        $result = $this->guard->evaluate([
            'domain' => 'cyber',
            'system_chosen_ends' => true,
        ]);

        $this->assertSame(array_values($result['blockers']), $result['blockers']);
        foreach ($result['blockers'] as $blocker) {
            $this->assertIsString($blocker);
        }
        $this->assertContains('external_domain:cyber', $result['blockers']);
        $this->assertContains('system_chosen_ends', $result['blockers']);
    }

    public function testRunawayRiskAndSovereigntyViolationAreBooleans(): void
    {
        $result = $this->guard->evaluate(['scope' => 'software_engineering']);

        $this->assertIsBool($result['runaway_risk']);
        $this->assertIsBool($result['sovereignty_violation']);
        $this->assertIsBool($result['in_scope']);
    }

    public function testIdenticalInputIsDeterministic(): void
    {
        $candidate = [
            'domain' => 'marketing',
            'unbounded_recursion' => true,
            'system_chosen_ends' => true,
        ];

        $first = $this->guard->evaluate($candidate);
        $second = $this->guard->evaluate($candidate);

        $this->assertSame($first, $second);
    }

    public function testAllowedScopeIsAlwaysSoftwareEngineering(): void
    {
        $passing = $this->guard->evaluate(['scope' => 'software_engineering']);
        $rejecting = $this->guard->evaluate(['domain' => 'cyber']);

        $this->assertSame('software_engineering', $passing['allowed_scope']);
        $this->assertSame('software_engineering', $rejecting['allowed_scope']);
    }
}
