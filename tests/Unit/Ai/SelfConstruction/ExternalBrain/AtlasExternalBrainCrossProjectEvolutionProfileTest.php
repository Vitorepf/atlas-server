<?php

declare(strict_types=1);

namespace Tests\Unit\Ai\SelfConstruction\ExternalBrain;

use App\Services\Ai\SelfConstruction\ExternalBrain\AtlasExternalBrainCrossProjectEvolutionProfile;
use PHPUnit\Framework\TestCase;

/**
 * Proves AtlasExternalBrainCrossProjectEvolutionProfile:
 *  - forAtlas() and forProject() emit the same schema but distinct autonomy/evidence/target policies.
 *  - forProject() defaults to readonly_planning unless source_of_truth_docs AND allowed_targets are set.
 *  - toArray() includes the canonical schema key.
 */
final class AtlasExternalBrainCrossProjectEvolutionProfileTest extends TestCase
{
    // --- forAtlas() -----------------------------------------------------------

    public function test_atlas_profile_has_full_autonomous_level(): void
    {
        $p = AtlasExternalBrainCrossProjectEvolutionProfile::forAtlas();

        $this->assertSame(AtlasExternalBrainCrossProjectEvolutionProfile::AUTONOMY_FULL_AUTONOMOUS, $p->autonomyLevel());
        $this->assertFalse($p->isReadonlyPlanning());
        $this->assertTrue($p->isCanonicalAtlas());
    }

    public function test_atlas_profile_has_multiple_task_lanes(): void
    {
        $p = AtlasExternalBrainCrossProjectEvolutionProfile::forAtlas();

        $this->assertContains('feature', $p->taskLanes());
        $this->assertContains('bug-fix', $p->taskLanes());
        $this->assertContains('refactor', $p->taskLanes());
        $this->assertGreaterThan(1, count($p->taskLanes()));
    }

    public function test_atlas_profile_has_source_of_truth_docs_and_allowed_targets(): void
    {
        $p = AtlasExternalBrainCrossProjectEvolutionProfile::forAtlas();

        $this->assertNotEmpty($p->sourceOfTruthDocs());
        $this->assertNotEmpty($p->allowedTargets());
    }

    public function test_atlas_profile_evidence_contract_includes_implementation_notes(): void
    {
        $p = AtlasExternalBrainCrossProjectEvolutionProfile::forAtlas();

        $this->assertContains('tests_or_gates_result', $p->requiredEvidenceFields());
        $this->assertContains('implementation_notes', $p->requiredEvidenceFields());
    }

    // --- forProject() defaults ------------------------------------------------

    public function test_project_profile_defaults_to_readonly_planning(): void
    {
        $p = AtlasExternalBrainCrossProjectEvolutionProfile::forProject('my-service');

        $this->assertSame(AtlasExternalBrainCrossProjectEvolutionProfile::AUTONOMY_READONLY_PLANNING, $p->autonomyLevel());
        $this->assertTrue($p->isReadonlyPlanning());
        $this->assertFalse($p->isCanonicalAtlas());
    }

    public function test_project_profile_autonomy_stays_readonly_when_only_source_docs_set(): void
    {
        $p = AtlasExternalBrainCrossProjectEvolutionProfile::forProject('my-service', [
            'autonomy_level' => AtlasExternalBrainCrossProjectEvolutionProfile::AUTONOMY_FULL_AUTONOMOUS,
            'source_of_truth_docs' => ['docs/'],
            // allowed_targets NOT provided
        ]);

        $this->assertSame(AtlasExternalBrainCrossProjectEvolutionProfile::AUTONOMY_READONLY_PLANNING, $p->autonomyLevel());
    }

    public function test_project_profile_autonomy_stays_readonly_when_only_allowed_targets_set(): void
    {
        $p = AtlasExternalBrainCrossProjectEvolutionProfile::forProject('my-service', [
            'autonomy_level' => AtlasExternalBrainCrossProjectEvolutionProfile::AUTONOMY_FULL_AUTONOMOUS,
            'allowed_targets' => ['src/'],
            // source_of_truth_docs NOT provided
        ]);

        $this->assertSame(AtlasExternalBrainCrossProjectEvolutionProfile::AUTONOMY_READONLY_PLANNING, $p->autonomyLevel());
    }

    public function test_project_profile_unlocks_autonomy_when_both_source_docs_and_targets_provided(): void
    {
        $p = AtlasExternalBrainCrossProjectEvolutionProfile::forProject('my-service', [
            'autonomy_level' => AtlasExternalBrainCrossProjectEvolutionProfile::AUTONOMY_SUPERVISED,
            'source_of_truth_docs' => ['docs/canonical.md'],
            'allowed_targets' => ['src/'],
        ]);

        $this->assertSame(AtlasExternalBrainCrossProjectEvolutionProfile::AUTONOMY_SUPERVISED, $p->autonomyLevel());
        $this->assertFalse($p->isReadonlyPlanning());
    }

    // --- Atlas vs non-Atlas distinctions (same schema, distinct policies) -----

    public function test_atlas_and_project_profiles_share_schema_but_differ_on_autonomy(): void
    {
        $atlas = AtlasExternalBrainCrossProjectEvolutionProfile::forAtlas();
        $other = AtlasExternalBrainCrossProjectEvolutionProfile::forProject('other-service');

        $this->assertSame(AtlasExternalBrainCrossProjectEvolutionProfile::SCHEMA, $atlas->toArray()['schema']);
        $this->assertSame(AtlasExternalBrainCrossProjectEvolutionProfile::SCHEMA, $other->toArray()['schema']);
        $this->assertNotSame($atlas->autonomyLevel(), $other->autonomyLevel());
    }

    public function test_atlas_and_project_profiles_have_distinct_evidence_contracts(): void
    {
        $atlas = AtlasExternalBrainCrossProjectEvolutionProfile::forAtlas();
        $other = AtlasExternalBrainCrossProjectEvolutionProfile::forProject('other-service');

        // Atlas requires more evidence fields than the minimal project default.
        $this->assertGreaterThan(count($other->requiredEvidenceFields()), count($atlas->requiredEvidenceFields()));
    }

    public function test_atlas_and_project_profiles_have_distinct_target_policies(): void
    {
        $atlas = AtlasExternalBrainCrossProjectEvolutionProfile::forAtlas();
        $other = AtlasExternalBrainCrossProjectEvolutionProfile::forProject('other-service');

        // Atlas has explicit forbidden targets; bare project profile has none.
        $this->assertNotEmpty($atlas->forbiddenTargets());
        $this->assertSame([], $other->forbiddenTargets());
        $this->assertNotEmpty($atlas->allowedTargets());
        $this->assertSame([], $other->allowedTargets());
    }

    // --- toArray / schema ----------------------------------------------------

    public function test_to_array_includes_schema_key_and_all_profile_fields(): void
    {
        $arr = AtlasExternalBrainCrossProjectEvolutionProfile::forAtlas()->toArray();

        $this->assertSame(AtlasExternalBrainCrossProjectEvolutionProfile::SCHEMA, $arr['schema']);
        foreach (['project_id', 'autonomy_level', 'required_evidence_fields', 'task_lanes',
            'source_of_truth_docs', 'allowed_targets', 'forbidden_targets',
            'property_gated_targets', 'ledger_policy'] as $key) {
            $this->assertArrayHasKey($key, $arr, "toArray() must include '$key'");
        }
    }

    public function test_ledger_policy_forbids_provider_sensitive_fields_by_default(): void
    {
        $policy = AtlasExternalBrainCrossProjectEvolutionProfile::forProject('x')->ledgerPolicy();

        $forbidden = $policy['forbidden_fields'] ?? [];
        foreach (['raw_prompt', 'provider_trace', 'conversation_text', 'secret'] as $field) {
            $this->assertContains($field, $forbidden, "ledger_policy must forbid '$field'");
        }
    }

    public function test_project_id_is_preserved_verbatim(): void
    {
        $p = AtlasExternalBrainCrossProjectEvolutionProfile::forProject('acme-payments');

        $this->assertSame('acme-payments', $p->projectId());
    }
}
