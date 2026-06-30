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

    // ── proof_gates, maturity_risk, lane_boundaries ───────────────────────────

    public function test_atlas_profile_exposes_non_empty_proof_gates(): void
    {
        $p = AtlasExternalBrainCrossProjectEvolutionProfile::forAtlas();

        $this->assertNotEmpty($p->proofGates());
        $this->assertContains('phpunit_green', $p->proofGates());
    }

    public function test_atlas_profile_has_low_maturity_risk(): void
    {
        $p = AtlasExternalBrainCrossProjectEvolutionProfile::forAtlas();
        $risk = $p->maturityRisk();

        $this->assertSame('low', $risk['level']);
        $this->assertNotEmpty($risk['factors']);
    }

    public function test_atlas_profile_lane_boundaries_cover_all_task_lanes(): void
    {
        $p = AtlasExternalBrainCrossProjectEvolutionProfile::forAtlas();
        $boundaries = $p->laneBoundaries();

        foreach ($p->taskLanes() as $lane) {
            $this->assertArrayHasKey($lane, $boundaries, "lane_boundaries must include lane '$lane'");
            $this->assertNotEmpty($boundaries[$lane]['allowed_scope_prefixes']);
            $this->assertNotEmpty($boundaries[$lane]['proof_gate']);
        }
    }

    public function test_project_profile_lane_boundaries_derived_from_allowed_targets(): void
    {
        $p = AtlasExternalBrainCrossProjectEvolutionProfile::forProject('my-service', [
            'task_lanes' => ['feature', 'bug-fix'],
            'allowed_targets' => ['src/'],
            'source_of_truth_docs' => ['docs/'],
        ]);
        $boundaries = $p->laneBoundaries();

        $this->assertArrayHasKey('feature', $boundaries);
        $this->assertContains('src/', $boundaries['feature']['allowed_scope_prefixes']);
        $this->assertArrayHasKey('bug-fix', $boundaries);
    }

    public function test_project_profile_has_high_maturity_risk_by_default(): void
    {
        $p = AtlasExternalBrainCrossProjectEvolutionProfile::forProject('external-app');

        $this->assertSame('high', $p->maturityRisk()['level']);
    }

    // ── isDistinctProjectFrom (collapse guard) ────────────────────────────────

    public function test_atlas_and_project_profiles_are_distinct(): void
    {
        $atlas = AtlasExternalBrainCrossProjectEvolutionProfile::forAtlas();
        $other = AtlasExternalBrainCrossProjectEvolutionProfile::forProject('other-service');

        $this->assertTrue($atlas->isDistinctProjectFrom($other));
        $this->assertTrue($other->isDistinctProjectFrom($atlas));
    }

    public function test_two_identical_project_ids_with_same_roots_and_gates_are_not_distinct(): void
    {
        $a = AtlasExternalBrainCrossProjectEvolutionProfile::forProject('svc', [
            'allowed_targets' => ['src/'],
            'source_of_truth_docs' => ['docs/'],
            'proof_gates' => ['tests_green'],
        ]);
        $b = AtlasExternalBrainCrossProjectEvolutionProfile::forProject('svc', [
            'allowed_targets' => ['src/'],
            'source_of_truth_docs' => ['docs/'],
            'proof_gates' => ['tests_green'],
        ]);

        $this->assertFalse($a->isDistinctProjectFrom($b));
    }

    public function test_same_project_id_but_different_allowed_roots_are_distinct(): void
    {
        $a = AtlasExternalBrainCrossProjectEvolutionProfile::forProject('svc', [
            'allowed_targets' => ['src/'],
            'source_of_truth_docs' => ['docs/'],
        ]);
        $b = AtlasExternalBrainCrossProjectEvolutionProfile::forProject('svc', [
            'allowed_targets' => ['lib/'],
            'source_of_truth_docs' => ['docs/'],
        ]);

        $this->assertTrue($a->isDistinctProjectFrom($b));
    }

    // ── test readiness, candidate lanes, safe first chain, transfer block ────

    private function fullProjectConfig(array $overrides = []): array
    {
        return array_merge([
            'source_of_truth_docs' => ['docs/README.md'],
            'allowed_targets' => ['src/'],
            'task_lanes' => ['feature', 'bug-fix', 'test', 'doc'],
            'has_test_suite' => true,
            'test_command' => 'npm test',
            'autonomy_level' => AtlasExternalBrainCrossProjectEvolutionProfile::AUTONOMY_SUPERVISED,
        ], $overrides);
    }

    public function test_atlas_profile_has_test_readiness_lanes_and_safe_first_chain(): void
    {
        $profile = AtlasExternalBrainCrossProjectEvolutionProfile::forAtlas();

        $this->assertSame('ready', $profile->testReadiness()['status']);
        $this->assertNotEmpty($profile->candidateEvolutionLanes());
        $this->assertNotEmpty($profile->safeFirstTaskChain());
        $this->assertFalse($profile->isTransferBlocked());
    }

    public function test_full_context_project_is_not_transfer_blocked_and_gets_safe_chain(): void
    {
        $profile = AtlasExternalBrainCrossProjectEvolutionProfile::forProject('widget-app', $this->fullProjectConfig());

        $this->assertFalse($profile->isTransferBlocked());
        $this->assertSame([], $profile->transferBlockedReasons());
        $this->assertNotEmpty($profile->safeFirstTaskChain());
        $this->assertSame('ready', $profile->testReadiness()['status']);
    }

    public function test_safe_first_task_chain_prioritizes_test_and_doc_lanes_first(): void
    {
        $profile = AtlasExternalBrainCrossProjectEvolutionProfile::forProject('widget-app', $this->fullProjectConfig());
        $chain = $profile->safeFirstTaskChain();

        $this->assertSame('test', $chain[0]);
        $this->assertSame('doc', $chain[1]);
    }

    public function test_thin_context_project_is_transfer_blocked(): void
    {
        $profile = AtlasExternalBrainCrossProjectEvolutionProfile::forProject('mystery-project', []);

        $this->assertTrue($profile->isTransferBlocked());
        $this->assertContains('no_source_of_truth_docs_provided', $profile->transferBlockedReasons());
        $this->assertContains('no_allowed_targets_provided', $profile->transferBlockedReasons());
        $this->assertContains('no_runnable_test_command_provided', $profile->transferBlockedReasons());
        $this->assertSame([], $profile->safeFirstTaskChain());
    }

    public function test_project_missing_only_test_readiness_is_still_blocked(): void
    {
        $profile = AtlasExternalBrainCrossProjectEvolutionProfile::forProject('widget-app', $this->fullProjectConfig([
            'has_test_suite' => false,
            'test_command' => '',
        ]));

        $this->assertTrue($profile->isTransferBlocked());
        $this->assertContains('no_runnable_test_command_provided', $profile->transferBlockedReasons());
        $this->assertSame('not_ready', $profile->testReadiness()['status']);
        $this->assertSame([], $profile->safeFirstTaskChain());
    }

    public function test_blocked_transfer_does_not_assume_atlas_internals(): void
    {
        $profile = AtlasExternalBrainCrossProjectEvolutionProfile::forProject('totally-foreign-project', []);

        $this->assertFalse($profile->isCanonicalAtlas());
        $this->assertSame(AtlasExternalBrainCrossProjectEvolutionProfile::AUTONOMY_READONLY_PLANNING, $profile->autonomyLevel());
    }

    public function test_candidate_evolution_lanes_orders_by_safety_and_includes_every_lane(): void
    {
        $profile = AtlasExternalBrainCrossProjectEvolutionProfile::forProject('widget-app', $this->fullProjectConfig([
            'task_lanes' => ['feature', 'test', 'custom-lane'],
        ]));

        $lanes = $profile->candidateEvolutionLanes();
        $this->assertSame(['test', 'feature', 'custom-lane'], $lanes);
    }

    public function test_to_array_exposes_new_fields(): void
    {
        $profile = AtlasExternalBrainCrossProjectEvolutionProfile::forProject('widget-app', $this->fullProjectConfig());
        $array = $profile->toArray();

        foreach (['test_readiness', 'candidate_evolution_lanes', 'safe_first_task_chain', 'transfer_blocked', 'transfer_blocked_reasons'] as $field) {
            $this->assertArrayHasKey($field, $array);
        }
    }
}
