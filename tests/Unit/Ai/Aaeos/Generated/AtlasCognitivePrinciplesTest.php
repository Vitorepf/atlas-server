<?php

declare(strict_types=1);

namespace Tests\Unit\Ai\Aaeos\Generated;

use App\Services\Ai\Aaeos\Generated\AtlasCognitivePrinciplesService;
use Tests\TestCase;

/**
 * Pins the documented Cognitive Plane rules: the evidence hierarchy (C15) as a
 * capability gate, the 6-question / 7-verdict external-input filter (C11), and
 * the authority conflict order.
 *
 * @see docs/engineering-knowledge-base/cognitive/principles.md
 */
class AtlasCognitivePrinciplesTest extends TestCase
{
    private function service(): AtlasCognitivePrinciplesService
    {
        return new AtlasCognitivePrinciplesService();
    }

    /**
     * Authority order: thesis (Layer -1) outranks the cognitive principles and
     * anti-patterns, regardless of citation order.
     */
    public function test_authority_resolves_thesis_above_principles_and_anti_patterns(): void
    {
        $r = $this->service()->resolveAuthority([
            AtlasCognitivePrinciplesService::AUTHORITY_ANTI_PATTERNS,
            AtlasCognitivePrinciplesService::AUTHORITY_COGNITIVE_PRINCIPLES,
            AtlasCognitivePrinciplesService::AUTHORITY_THESIS,
        ]);

        $this->assertSame(AtlasCognitivePrinciplesService::AUTHORITY_THESIS, $r['resolved_authority']);
        $this->assertSame(0, $r['rank']);

        // Without the thesis, the 14 hard principles outrank the 22 cognitive ones.
        $r2 = $this->service()->resolveAuthority([
            AtlasCognitivePrinciplesService::AUTHORITY_COGNITIVE_PRINCIPLES,
            AtlasCognitivePrinciplesService::AUTHORITY_ATLAS_HARD_PRINCIPLES,
        ]);
        $this->assertSame(AtlasCognitivePrinciplesService::AUTHORITY_ATLAS_HARD_PRINCIPLES, $r2['resolved_authority']);
    }

    /**
     * C15 evidence gate — consensus may become default only after a contract
     * test; before that it is held back.
     */
    public function test_consensus_is_default_only_after_contract_test(): void
    {
        $before = $this->service()->classifyEvidence([
            'evidence_level' => 'consensus',
            'passed_contract_test' => false,
        ]);
        $this->assertTrue($before['is_capability']);
        $this->assertFalse($before['may_be_default']);
        $this->assertContains('consensus_default_requires_contract_test', $before['block_reasons']);

        $after = $this->service()->classifyEvidence([
            'evidence_level' => 'consensus',
            'passed_contract_test' => true,
        ]);
        $this->assertTrue($after['may_be_default']);
        $this->assertSame([], $after['block_reasons']);
    }

    /**
     * C15 — emerging is opt-in/preview and only becomes default after BOTH
     * AP-99 and adversarial validation loop; one of the two is not enough.
     */
    public function test_emerging_becomes_default_only_with_ap99_and_adversarial(): void
    {
        $partial = $this->service()->classifyEvidence([
            'evidence_level' => 'emerging',
            'has_ap99' => true,
            'has_adversarial_validation' => false,
        ]);
        $this->assertFalse($partial['may_be_default']);
        $this->assertTrue($partial['requires_adversarial_validation']);
        $this->assertContains('emerging_default_requires_ap99_and_adversarial', $partial['block_reasons']);

        $full = $this->service()->classifyEvidence([
            'evidence_level' => 'emerging',
            'has_ap99' => true,
            'has_adversarial_validation' => true,
        ]);
        $this->assertTrue($full['may_be_default']);
    }

    /**
     * C15 — contested may NEVER be default and may NOT promise a gain;
     * speculative is not a capability at all and routes to Curator validation.
     * An undeclared level fails closed to the weakest tier.
     */
    public function test_contested_never_default_and_speculative_is_not_a_capability(): void
    {
        $contested = $this->service()->classifyEvidence([
            'evidence_level' => 'contested',
            'promises_gain' => true,
        ]);
        $this->assertFalse($contested['may_be_default']);
        $this->assertFalse($contested['may_promise_gain']);
        $this->assertContains('contested_may_never_be_default', $contested['block_reasons']);
        $this->assertContains('contested_may_not_promise_gain', $contested['block_reasons']);

        $speculative = $this->service()->classifyEvidence([
            'evidence_level' => 'speculative',
        ]);
        $this->assertFalse($speculative['is_capability']);
        $this->assertTrue($speculative['requires_adversarial_validation']);
        $this->assertContains('speculative_is_not_a_capability', $speculative['block_reasons']);

        // Fail-closed: no declared level => treated as speculative.
        $undeclared = $this->service()->classifyEvidence([]);
        $this->assertFalse($undeclared['recognized']);
        $this->assertSame(AtlasCognitivePrinciplesService::EVIDENCE_SPECULATIVE, $undeclared['evidence_level']);
        $this->assertFalse($undeclared['is_capability']);
        $this->assertContains('evidence_level_undeclared_or_unknown', $undeclared['block_reasons']);
    }

    /**
     * C15 cross-cutting — ANY level that promises a "+X%" gain without an AP-99
     * is blocked, even a consensus capability.
     */
    public function test_promised_gain_without_ap99_is_blocked_at_any_level(): void
    {
        $c = $this->service()->classifyEvidence([
            'evidence_level' => 'consensus',
            'passed_contract_test' => true,
            'promises_gain' => true,
            'has_ap99' => false,
        ]);
        $this->assertFalse($c['may_promise_gain']);
        $this->assertContains('promised_gain_without_ap99', $c['block_reasons']);

        // With AP-99, the same consensus capability may state a gain.
        $ok = $this->service()->classifyEvidence([
            'evidence_level' => 'consensus',
            'passed_contract_test' => true,
            'promises_gain' => true,
            'has_ap99' => true,
        ]);
        $this->assertTrue($ok['may_promise_gain']);
    }

    /**
     * External-input filter — a "build your own gamified app" suggestion is a
     * parallel product that competes with the single Atlas channel: rejected.
     */
    public function test_parallel_product_is_rejected_as_competing_with_channel(): void
    {
        $r = $this->service()->filterExternalInput([
            'multiplies_operator_output' => false,
            'classification' => 'parallel_product',
        ]);

        $this->assertSame(
            AtlasCognitivePrinciplesService::VERDICT_REJECTED_COMPETE_WITH_ATLAS,
            $r['verdict'],
        );
        $this->assertFalse($r['accepted']);
        $this->assertContains('q2_parallel_product_competes_with_channel', $r['reasons']);
    }

    /**
     * External-input filter — a principle violation is an unconditional
     * rejection that wins over every other answer (e.g. "own model for
     * everything").
     */
    public function test_principle_violation_is_unconditional_rejection(): void
    {
        $r = $this->service()->filterExternalInput([
            'multiplies_operator_output' => true,
            'classification' => 'capability',
            'violates_principle' => true,
            'gain_is_proven' => true,
        ]);

        $this->assertSame(
            AtlasCognitivePrinciplesService::VERDICT_REJECTED_VIOLATES_PRINCIPLE,
            $r['verdict'],
        );
        $this->assertFalse($r['accepted']);
    }

    /**
     * External-input filter — an admissible capability whose gain is unproven
     * routes to adversarial validation validation (Q5); the same capability with a proven gain
     * is accepted to the Core.
     */
    public function test_unproven_capability_routes_to_adversarial_then_accepts_when_proven(): void
    {
        $unproven = $this->service()->filterExternalInput([
            'multiplies_operator_output' => true,
            'classification' => 'capability',
            'gain_is_proven' => false,
        ]);
        $this->assertSame(
            AtlasCognitivePrinciplesService::VERDICT_REQUIRES_ADVERSARIAL_VALIDATION,
            $unproven['verdict'],
        );
        $this->assertFalse($unproven['accepted']);

        $proven = $this->service()->filterExternalInput([
            'multiplies_operator_output' => true,
            'classification' => 'capability',
            'gain_is_proven' => true,
        ]);
        $this->assertSame(
            AtlasCognitivePrinciplesService::VERDICT_ACCEPTED_AS_CAPABILITY,
            $proven['verdict'],
        );
        $this->assertTrue($proven['accepted']);
    }

    /**
     * External-input filter — a human tutor is absorbed as a source/event, and
     * a partially-accepted contribution (paid course) is filtered_partial. Both
     * verdicts are inside the closed set of 7.
     */
    public function test_source_and_partial_acceptance_verdicts(): void
    {
        $source = $this->service()->filterExternalInput([
            'multiplies_operator_output' => true,
            'classification' => 'source',
            'absorbable_as_source' => true,
        ]);
        $this->assertSame(
            AtlasCognitivePrinciplesService::VERDICT_ACCEPTED_AS_SOURCE,
            $source['verdict'],
        );
        $this->assertTrue($source['accepted']);

        $partial = $this->service()->filterExternalInput([
            'multiplies_operator_output' => true,
            'classification' => 'source',
            'absorbable_as_source' => true,
            'partially_accepted' => true,
        ]);
        $this->assertSame(
            AtlasCognitivePrinciplesService::VERDICT_FILTERED_PARTIAL,
            $partial['verdict'],
        );

        foreach ([$source['verdict'], $partial['verdict']] as $v) {
            $this->assertContains($v, AtlasCognitivePrinciplesService::EXTERNAL_INPUT_VERDICTS);
        }
    }
}
