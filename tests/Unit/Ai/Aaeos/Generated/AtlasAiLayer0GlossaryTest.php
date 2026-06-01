<?php

declare(strict_types=1);

namespace Tests\Unit\Ai\Aaeos\Generated;

use App\Services\Ai\Aaeos\Generated\AtlasAiLayer0GlossaryService;
use Tests\TestCase;

/**
 * Pins the documented Atlas AI Layer 0 constitution + glossary rules.
 *
 * Pure logic — no database, no RefreshDatabase, no IO.
 *
 * @see docs/engineering-knowledge-base/atlas-ai-layer-0-glossary.md
 */
class AtlasAiLayer0GlossaryTest extends TestCase
{
    private function service(): AtlasAiLayer0GlossaryService
    {
        return new AtlasAiLayer0GlossaryService();
    }

    /**
     * Authority table: identity/terms route to the Layer 0 doc + index;
     * executable contracts route to the Kernel; an unknown subject => null.
     */
    public function test_authority_table_routes_subjects_to_canonical_docs(): void
    {
        $service = $this->service();

        $this->assertStringContainsString(
            'atlas-ai-layer-0-glossary.md',
            (string) $service->authorityFor('Identity and canonical terms'),
        );
        $this->assertSame(
            'atlas-ai-kernel-architecture.md',
            $service->authorityFor('executable contracts envelopes receipts ledger slos'),
        );
        $this->assertNull($service->authorityFor('some subject nobody documented'));
    }

    /**
     * The core Layer 0 guard: "Atlas AI" used AS a "provider" is a forbidden
     * conflation (the doc's "Nao confundir com" for Atlas AI lists provider) and
     * must BLOCK; the same term used as something not on its confusion list is
     * allowed.
     */
    public function test_conflation_guard_blocks_atlas_ai_used_as_provider(): void
    {
        $service = $this->service();

        $blocked = $service->detectConflation('Atlas AI', 'provider');
        $this->assertTrue($blocked['is_conflation']);
        $this->assertSame(AtlasAiLayer0GlossaryService::VERDICT_BLOCK, $blocked['verdict']);

        // "Surface" must not be confused with the "Kernel" per the glossary.
        $surface = $service->detectConflation('Surface', 'kernel');
        $this->assertTrue($surface['is_conflation']);
        $this->assertSame(AtlasAiLayer0GlossaryService::VERDICT_BLOCK, $surface['verdict']);

        // A non-listed usage is not a documented conflation.
        $ok = $service->detectConflation('Atlas AI', 'cognitive core');
        $this->assertFalse($ok['is_conflation']);
        $this->assertSame(AtlasAiLayer0GlossaryService::VERDICT_ALLOW, $ok['verdict']);

        // Unknown term cannot assert a documented conflation.
        $unknown = $service->detectConflation('Totally Made Up Term', 'provider');
        $this->assertFalse($unknown['known_term']);
        $this->assertFalse($unknown['is_conflation']);
    }

    /** classifyTerm returns the canonical meaning + the "do not confuse with" set. */
    public function test_classify_term_returns_canonical_record_and_confusions(): void
    {
        $kernel = $this->service()->classifyTerm('Kernel');

        $this->assertTrue($kernel['known']);
        $this->assertSame('Kernel', $kernel['canonical']);
        $this->assertStringContainsString('envelope', (string) $kernel['meaning']);
        $this->assertContains('product roadmap', $kernel['do_not_confuse_with']);
    }

    /**
     * Legacy terms: CLAUDE.md is a provider/agent projection and is never a
     * primary operational source.
     */
    public function test_legacy_term_claude_md_is_never_a_primary_source(): void
    {
        $service = $this->service();

        $claude = $service->classifyLegacyTerm('CLAUDE.md');
        $this->assertTrue($claude['is_legacy']);
        $this->assertFalse($claude['is_primary_source']);
        $this->assertStringContainsString('projection', (string) $claude['treatment']);

        // A canonical (non-legacy) term is not flagged as legacy.
        $this->assertFalse($service->classifyLegacyTerm('Atlas AI')['is_legacy']);
    }

    /**
     * Operational Constitution: all 7 invariants asserted => allow; dropping any
     * single invariant flips the verdict to block and names exactly that
     * violation.
     */
    public function test_constitution_is_fail_closed_over_all_seven_invariants(): void
    {
        $service = $this->service();

        $allTrue = [
            'treats_atlas_as_continuity_not_provider' => true,
            'keeps_identity_in_core_not_surface' => true,
            'context_compiled_provider_safe_traceable' => true,
            'autonomy_increase_has_evidence_gates_rollback' => true,
            'behavior_defined_by_skill_flow_policy_receipt' => true,
            'memory_has_source_scope_validity_privacy_forgetting' => true,
            'legacy_marked_before_guiding_implementation' => true,
        ];

        $compliant = $service->evaluateConstitution($allTrue);
        $this->assertTrue($compliant['compliant']);
        $this->assertSame(AtlasAiLayer0GlossaryService::VERDICT_ALLOW, $compliant['verdict']);
        $this->assertSame([], $compliant['violations']);

        // Drop invariant 1 (continuity not provider) -> exactly one violation.
        $broken = $allTrue;
        $broken['treats_atlas_as_continuity_not_provider'] = false;
        $result = $service->evaluateConstitution($broken);

        $this->assertFalse($result['compliant']);
        $this->assertSame(AtlasAiLayer0GlossaryService::VERDICT_BLOCK, $result['verdict']);
        $this->assertCount(1, $result['violations']);
        $this->assertSame('continuity_not_provider', $result['violations'][0]['id']);
        $this->assertSame(1, $result['violations'][0]['number']);

        // An empty claim violates all seven.
        $this->assertCount(7, $service->evaluateConstitution([])['violations']);
    }

    /**
     * Source-material promotion gate is ordered + fail-closed: it blocks at the
     * FIRST unmet rule, and only an all-true request is allowed.
     */
    public function test_promotion_gate_is_ordered_and_blocks_first_unmet_rule(): void
    {
        $service = $this->service();

        // Skip the very first rule -> it must be the blocker even if later
        // rules are satisfied.
        $result = $service->evaluatePromotion([
            'decision_stable_small' => false,
            'provider_safe' => true,
            'sensitive_content_redacted' => true,
            'source_material_declared' => true,
            'legacy_preserved_with_redirect' => true,
            'index_readme_starthere_updated' => true,
        ]);

        $this->assertFalse($result['allowed']);
        $this->assertSame(AtlasAiLayer0GlossaryService::VERDICT_BLOCK, $result['verdict']);
        $this->assertSame('promote_only_stable_small_decisions', $result['blocking_rule']);
        $this->assertSame([], $result['completed_rules']);

        // All six rules satisfied -> allow.
        $allowed = $service->evaluatePromotion([
            'decision_stable_small' => true,
            'provider_safe' => true,
            'sensitive_content_redacted' => true,
            'source_material_declared' => true,
            'legacy_preserved_with_redirect' => true,
            'index_readme_starthere_updated' => true,
        ]);
        $this->assertTrue($allowed['allowed']);
        $this->assertSame(AtlasAiLayer0GlossaryService::VERDICT_ALLOW, $allowed['verdict']);
        $this->assertNull($allowed['blocking_rule']);
        $this->assertCount(6, $allowed['completed_rules']);
    }

    /** The registry exposes the full Layer 0 contract for callers. */
    public function test_registry_exposes_full_layer0_contract(): void
    {
        $registry = $this->service()->registry();

        $this->assertSame(16, $registry['glossary_term_count']);
        $this->assertCount(7, $registry['constitution']);
        $this->assertCount(5, $registry['authority']);
        $this->assertCount(6, $registry['promotion_rules']);
    }
}
