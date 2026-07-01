<?php

declare(strict_types=1);

namespace Tests\Unit\Ai\SelfConstruction\LearningTransfer;

use App\Services\Ai\SelfConstruction\LearningTransfer\AtlasSelfConstructionLearningTransferGiveBackClassifier;
use Tests\TestCase;

final class AtlasSelfConstructionLearningTransferGiveBackClassifierTest extends TestCase
{
    private function fact(array $overrides = []): array
    {
        return $overrides + [
            'task_packet_id' => 'pkt-001',
            'allowed_files' => ['app/Foo.php'],
            'blocking_facts' => ['fact:a'],
            'evidence_refs' => ['receipt:r1'],
        ];
    }

    public function test_duplicate_capability_class(): void
    {
        $verdict = (new AtlasSelfConstructionLearningTransferGiveBackClassifier)
            ->classify($this->fact(['reason' => 'duplicate_capability:already_exists_as_AtlasFoo']));

        $this->assertSame(AtlasSelfConstructionLearningTransferGiveBackClassifier::CLASS_DUPLICATE_CAPABILITY, $verdict['class']);
        $this->assertSame('pkt-001', $verdict['packet_id']);
        $this->assertSame(['app/Foo.php'], $verdict['allowed_files']);
        $this->assertSame(['fact:a'], $verdict['blocking_facts']);
        $this->assertSame(['receipt:r1'], $verdict['evidence_refs']);
    }

    public function test_scope_gap_class(): void
    {
        $verdict = (new AtlasSelfConstructionLearningTransferGiveBackClassifier)
            ->classify($this->fact(['reason' => 'scope_gap:allowed_files_insufficient_to_cover_acceptance']));

        $this->assertSame(AtlasSelfConstructionLearningTransferGiveBackClassifier::CLASS_SCOPE_GAP, $verdict['class']);
    }

    public function test_forbidden_target_class(): void
    {
        $verdict = (new AtlasSelfConstructionLearningTransferGiveBackClassifier)
            ->classify($this->fact(['reason' => 'forbidden_target:petreo_core_path']));

        $this->assertSame(AtlasSelfConstructionLearningTransferGiveBackClassifier::CLASS_FORBIDDEN_TARGET, $verdict['class']);
    }

    public function test_contradictory_acceptance_class(): void
    {
        $verdict = (new AtlasSelfConstructionLearningTransferGiveBackClassifier)
            ->classify($this->fact(['reason' => 'contradictory_acceptance:breaks_sibling_tests']));

        $this->assertSame(AtlasSelfConstructionLearningTransferGiveBackClassifier::CLASS_CONTRADICTORY_ACCEPTANCE, $verdict['class']);
    }

    public function test_missing_dependency_class(): void
    {
        $verdict = (new AtlasSelfConstructionLearningTransferGiveBackClassifier)
            ->classify($this->fact(['reason' => 'missing_extractor:AtlasLoopObraAcceptanceContractExtractor']));

        $this->assertSame(AtlasSelfConstructionLearningTransferGiveBackClassifier::CLASS_MISSING_DEPENDENCY, $verdict['class']);
    }

    public function test_stale_context_class(): void
    {
        $verdict = (new AtlasSelfConstructionLearningTransferGiveBackClassifier)
            ->classify($this->fact(['reason' => 'stale_context:context_pack_stale']));

        $this->assertSame(AtlasSelfConstructionLearningTransferGiveBackClassifier::CLASS_STALE_CONTEXT, $verdict['class']);
    }

    public function test_insufficient_evidence_class(): void
    {
        $verdict = (new AtlasSelfConstructionLearningTransferGiveBackClassifier)
            ->classify($this->fact(['reason' => 'insufficient_evidence:verification_amber']));

        $this->assertSame(AtlasSelfConstructionLearningTransferGiveBackClassifier::CLASS_INSUFFICIENT_EVIDENCE, $verdict['class']);
    }

    public function test_unknown_when_no_signal_lights(): void
    {
        $verdict = (new AtlasSelfConstructionLearningTransferGiveBackClassifier)
            ->classify($this->fact(['reason' => 'something_else_entirely']));

        $this->assertSame(AtlasSelfConstructionLearningTransferGiveBackClassifier::CLASS_UNKNOWN, $verdict['class']);
    }

    public function test_structural_signal_overrides_missing_reason_string(): void
    {
        $verdict = (new AtlasSelfConstructionLearningTransferGiveBackClassifier)
            ->classify($this->fact(['reason' => '', 'target_is_forbidden_core' => true]));

        $this->assertSame(AtlasSelfConstructionLearningTransferGiveBackClassifier::CLASS_FORBIDDEN_TARGET, $verdict['class']);
    }

    public function test_classification_is_deterministic_byte_identical(): void
    {
        $svc = new AtlasSelfConstructionLearningTransferGiveBackClassifier;
        $a = $svc->classify($this->fact(['reason' => 'scope_gap:x']));
        $b = $svc->classify($this->fact(['reason' => 'scope_gap:x']));

        $this->assertSame(json_encode($a), json_encode($b));
    }

    public function test_classifier_does_not_invent_narrative_fields(): void
    {
        $verdict = (new AtlasSelfConstructionLearningTransferGiveBackClassifier)
            ->classify($this->fact(['reason' => 'duplicate_capability:x']));

        // Output must be EXACTLY the canonical fact-only keys — no extra narrative.
        $this->assertSame(
            [
                'schema_version', 'class', 'action_hint', 'packet_id', 'allowed_files', 'blocking_facts', 'evidence_refs',
                'root_cause', 'next_action', 'requeue_eligible', 'scope_repair_needed', 'poison_confidence', 'evidence_snippets',
            ],
            array_keys($verdict),
        );
    }

    public function test_forbidden_scope_class_produces_respec_allowed_files_hint(): void
    {
        $verdict = (new AtlasSelfConstructionLearningTransferGiveBackClassifier)
            ->classify($this->fact(['reason' => 'forbidden_scope:files_outside_allowed_scope']));

        $this->assertSame(AtlasSelfConstructionLearningTransferGiveBackClassifier::CLASS_FORBIDDEN_SCOPE, $verdict['class']);
        $this->assertSame('respec_allowed_files', $verdict['action_hint']);
    }

    public function test_missing_implementation_class_produces_enqueue_dependency_hint(): void
    {
        $verdict = (new AtlasSelfConstructionLearningTransferGiveBackClassifier)
            ->classify($this->fact(['reason' => 'missing_implementation:AtlasLoopSomeOrgan']));

        $this->assertSame(AtlasSelfConstructionLearningTransferGiveBackClassifier::CLASS_MISSING_IMPLEMENTATION, $verdict['class']);
        $this->assertSame('enqueue_dependency_packet', $verdict['action_hint']);
    }

    public function test_schema_drift_class_produces_fix_schema_contract_hint(): void
    {
        $verdict = (new AtlasSelfConstructionLearningTransferGiveBackClassifier)
            ->classify($this->fact(['reason' => 'schema_drift:schema_version_mismatch_v1_vs_v2']));

        $this->assertSame(AtlasSelfConstructionLearningTransferGiveBackClassifier::CLASS_SCHEMA_DRIFT, $verdict['class']);
        $this->assertSame('fix_schema_contract', $verdict['action_hint']);
    }

    public function test_worker_error_class_produces_worker_prompt_repair_hint(): void
    {
        $verdict = (new AtlasSelfConstructionLearningTransferGiveBackClassifier)
            ->classify($this->fact(['reason' => 'worker_error:execution_error_in_PhpUnit']));

        $this->assertSame(AtlasSelfConstructionLearningTransferGiveBackClassifier::CLASS_WORKER_ERROR, $verdict['class']);
        $this->assertSame('worker_prompt_repair', $verdict['action_hint']);
    }

    public function test_forbidden_target_class_produces_quarantine_poison_hint(): void
    {
        $verdict = (new AtlasSelfConstructionLearningTransferGiveBackClassifier)
            ->classify($this->fact(['reason' => 'forbidden_target:petreo_core_path']));

        $this->assertSame(AtlasSelfConstructionLearningTransferGiveBackClassifier::CLASS_FORBIDDEN_TARGET, $verdict['class']);
        $this->assertSame('quarantine_poison', $verdict['action_hint']);
    }

    // ── AC: packet_shape_defect and worker_feed_starvation w/ queue_floor_facts ──────

    public function test_packet_shape_defect_class_produces_respec_packet_shape_hint(): void
    {
        $verdict = (new AtlasSelfConstructionLearningTransferGiveBackClassifier)
            ->classify($this->fact(['reason' => 'malformed_packet:missing_required_field']));

        $this->assertSame(AtlasSelfConstructionLearningTransferGiveBackClassifier::CLASS_PACKET_SHAPE_DEFECT, $verdict['class']);
        $this->assertSame('respec_packet_shape', $verdict['action_hint']);
    }

    public function test_no_claimable_task_below_worker_floor_becomes_worker_feed_starvation_with_queue_floor_facts(): void
    {
        $verdict = (new AtlasSelfConstructionLearningTransferGiveBackClassifier)
            ->classify($this->fact([
                'reason' => 'no_claimable_task',
                'claimable_per_active_worker' => 1.0,
                'claimable_depth' => 2,
                'active_worker_count' => 4,
            ]));

        $this->assertSame(AtlasSelfConstructionLearningTransferGiveBackClassifier::CLASS_WORKER_FEED_STARVATION, $verdict['class']);
        $this->assertArrayHasKey('queue_floor_facts', $verdict);
        $this->assertSame(2, $verdict['queue_floor_facts']['claimable_depth']);
        $this->assertSame(4, $verdict['queue_floor_facts']['active_worker_count']);
        $this->assertSame(1.0, $verdict['queue_floor_facts']['claimable_per_active_worker']);
    }

    public function test_no_claimable_task_above_worker_floor_stays_generic_queue_starvation(): void
    {
        $verdict = (new AtlasSelfConstructionLearningTransferGiveBackClassifier)
            ->classify($this->fact([
                'reason' => 'no_claimable_task',
                'claimable_per_active_worker' => 10.0,
            ]));

        $this->assertSame(AtlasSelfConstructionLearningTransferGiveBackClassifier::CLASS_NO_CLAIMABLE_TASK, $verdict['class']);
    }

    public function test_output_never_invents_narrative_and_includes_all_required_keys(): void
    {
        $verdict = (new AtlasSelfConstructionLearningTransferGiveBackClassifier)
            ->classify($this->fact(['reason' => 'scope_gap:requires_files_outside_scope']));

        foreach (['schema_version', 'class', 'action_hint', 'packet_id', 'allowed_files', 'blocking_facts', 'evidence_refs'] as $key) {
            $this->assertArrayHasKey($key, $verdict, "Missing key: {$key}");
        }
    }

    // ── AC: root_cause, next_action, requeue_eligible, scope_repair_needed, poison_confidence, evidence_snippets ──

    public function test_output_includes_new_learning_fields(): void
    {
        $verdict = (new AtlasSelfConstructionLearningTransferGiveBackClassifier)
            ->classify($this->fact(['reason' => 'scope_gap:x']));

        foreach (['root_cause', 'next_action', 'requeue_eligible', 'scope_repair_needed', 'poison_confidence', 'evidence_snippets'] as $key) {
            $this->assertArrayHasKey($key, $verdict, "Missing key: {$key}");
        }
    }

    public function test_allowed_files_gap_is_scope_repair_needed_and_requeue_eligible(): void
    {
        $verdict = (new AtlasSelfConstructionLearningTransferGiveBackClassifier)
            ->classify($this->fact(['reason' => 'scope_gap:allowed_files_insufficient_to_cover_acceptance']));

        $this->assertSame('scope_gap', $verdict['root_cause']);
        $this->assertTrue($verdict['scope_repair_needed']);
        $this->assertTrue($verdict['requeue_eligible']);
        $this->assertSame(0.0, $verdict['poison_confidence']);
    }

    public function test_acceptance_contradiction_is_scope_repair_needed(): void
    {
        $verdict = (new AtlasSelfConstructionLearningTransferGiveBackClassifier)
            ->classify($this->fact(['reason' => 'contradictory_acceptance:breaks_sibling_tests']));

        $this->assertSame('contradictory_acceptance', $verdict['root_cause']);
        $this->assertTrue($verdict['scope_repair_needed']);
    }

    public function test_duplicate_capability_already_implemented_is_poison_and_not_requeue_eligible(): void
    {
        $verdict = (new AtlasSelfConstructionLearningTransferGiveBackClassifier)
            ->classify($this->fact(['reason' => 'duplicate_capability:already_exists_as_AtlasFoo']));

        $this->assertSame('duplicate_capability', $verdict['root_cause']);
        $this->assertFalse($verdict['requeue_eligible']);
        $this->assertGreaterThan(0.5, $verdict['poison_confidence']);
    }

    public function test_transient_test_failure_is_requeue_eligible_not_poison(): void
    {
        $verdict = (new AtlasSelfConstructionLearningTransferGiveBackClassifier)
            ->classify($this->fact(['reason' => 'worker_error:execution_error_in_PhpUnit']));

        $this->assertSame('worker_error', $verdict['root_cause']);
        $this->assertTrue($verdict['requeue_eligible']);
        $this->assertSame(0.0, $verdict['poison_confidence']);
        $this->assertFalse($verdict['scope_repair_needed']);
    }

    public function test_evidence_snippets_redact_secret_looking_strings(): void
    {
        $verdict = (new AtlasSelfConstructionLearningTransferGiveBackClassifier)
            ->classify($this->fact([
                'reason' => 'scope_gap:x',
                'evidence_refs' => ['receipt:r1', 'api_key=sk-live-abc123'],
            ]));

        $this->assertContains('receipt:r1', $verdict['evidence_snippets']);
        $this->assertContains('[redacted]', $verdict['evidence_snippets']);
        $this->assertNotContains('api_key=sk-live-abc123', $verdict['evidence_snippets']);
    }
}
