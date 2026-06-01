<?php

declare(strict_types=1);

namespace Tests\Unit\Ai\Aaeos\Generated;

use App\Services\Ai\Aaeos\Generated\AtlasSkillPackCanonicalService;
use Tests\TestCase;

/**
 * Pins the executable governance rules from the Skill Pack Canonical doc: the
 * atlas.skill_pack.v1 schema (closed namespace + sovereignty sets, skill_id shape
 * agreeing with namespace, v<int> version) and the user -> core promotion gate
 * stated verbatim in the doc — 30 consecutive failure-free uses, 0 incidents,
 * sovereignty NOT in {sensitive,secret,cyber}, and Architect review. Pure, no DB.
 *
 * @see docs/engineering-knowledge-base/atlas-skill-pack-canonical.md
 */
class AtlasSkillPackCanonicalTest extends TestCase
{
    private function service(): AtlasSkillPackCanonicalService
    {
        return new AtlasSkillPackCanonicalService;
    }

    /**
     * The doc's worked example: atlas.skill.user.expense_categorizer with 45 uses,
     * 0 failures, ok_to_share, Architect approved -> promotes to
     * atlas.skill.core.expense_categorizer.
     *
     * @return array<string,mixed>
     */
    private function workedExample(): array
    {
        return [
            'schema' => 'atlas.skill_pack.v1',
            'skill_id' => 'atlas.skill.user.expense_categorizer',
            'version' => 'v1',
            'namespace' => 'user',
            'human_name' => 'Expense Categorizer',
            'purpose' => 'Categorize expenses.',
            'code_path' => 'app/Services/Ai/AiSkillStore.php',
            'sovereignty_class' => 'ok_to_share',
            'uses_count' => 45,
            'failure_count' => 0,
            'incident_count' => 0,
            'architect_approved' => true,
        ];
    }

    public function test_worked_example_is_promoted_to_core_with_projected_id(): void
    {
        $decision = $this->service()->evaluatePromotion($this->workedExample());

        $this->assertSame(AtlasSkillPackCanonicalService::VERDICT_PROMOTE_CORE, $decision['verdict']);
        $this->assertSame([], $decision['blocking_reasons']);
        $this->assertTrue($decision['eligible']);
        // Doc example: user.expense_categorizer -> core.expense_categorizer.
        $this->assertSame('atlas.skill.core.expense_categorizer', $decision['promoted_skill_id']);
        $this->assertSame('core', $decision['promoted_namespace']);
    }

    public function test_schema_is_valid_for_worked_example(): void
    {
        $check = $this->service()->validateSchema($this->workedExample());

        $this->assertTrue($check['schema_valid']);
        $this->assertSame([], $check['errors']);
    }

    public function test_29_uses_holds_in_user_namespace_not_promoted(): void
    {
        $skill = $this->workedExample();
        $skill['uses_count'] = 29; // one short of the 30-use threshold

        $decision = $this->service()->evaluatePromotion($skill);

        $this->assertSame(AtlasSkillPackCanonicalService::VERDICT_HOLD_USER, $decision['verdict']);
        $this->assertNull($decision['promoted_skill_id']);
        $this->assertFalse($decision['gate']['consecutive_uses']['ok']);
        // Exactly at 30 it flips to promote.
        $skill['uses_count'] = 30;
        $this->assertSame(
            AtlasSkillPackCanonicalService::VERDICT_PROMOTE_CORE,
            $this->service()->evaluatePromotion($skill)['verdict'],
        );
    }

    public function test_single_failure_breaks_streak_and_blocks_promotion(): void
    {
        $skill = $this->workedExample();
        $skill['failure_count'] = 1; // breaks "consecutive sem failure"

        $decision = $this->service()->evaluatePromotion($skill);

        $this->assertNotSame(AtlasSkillPackCanonicalService::VERDICT_PROMOTE_CORE, $decision['verdict']);
        $this->assertFalse($decision['gate']['no_failures']['ok']);
        $this->assertContains('streak broken: failure_count must be 0; got 1', $decision['blocking_reasons']);
    }

    public function test_secret_sovereignty_never_promotes_even_when_everything_else_passes(): void
    {
        $skill = $this->workedExample();
        $skill['sovereignty_class'] = 'secret'; // sensitive/secret/cyber NUNCA promove

        $decision = $this->service()->evaluatePromotion($skill);

        $this->assertSame(AtlasSkillPackCanonicalService::VERDICT_REFINE, $decision['verdict']);
        $this->assertTrue($decision['gate']['sovereignty_promotable']['locked']);
        $this->assertContains(
            "sovereignty_class 'secret' NEVER promotes to core (must be ok_to_share)",
            $decision['blocking_reasons'],
        );
    }

    public function test_architect_rejection_blocks_promotion_when_threshold_met(): void
    {
        $skill = $this->workedExample();
        $skill['architect_approved'] = false; // threshold met but no Architect review

        $decision = $this->service()->evaluatePromotion($skill);

        $this->assertSame(AtlasSkillPackCanonicalService::VERDICT_REFINE, $decision['verdict']);
        $this->assertContains('Architect review approval not registered', $decision['blocking_reasons']);
    }

    public function test_schema_rejects_namespace_mismatch_and_bad_version(): void
    {
        $bad = $this->workedExample();
        $bad['skill_id'] = 'atlas.skill.core.expense_categorizer'; // id says core
        $bad['namespace'] = 'user'; // but namespace says user -> mismatch
        $bad['version'] = '1'; // must be v<int>

        $check = $this->service()->validateSchema($bad);

        $this->assertFalse($check['schema_valid']);
        $this->assertArrayHasKey('skill_id', $check['errors']);
        $this->assertArrayHasKey('version', $check['errors']);
    }
}
