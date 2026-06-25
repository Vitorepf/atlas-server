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

        // Output must be EXACTLY the canonical 6 keys — no extra narrative.
        $this->assertSame(
            ['schema_version', 'class', 'packet_id', 'allowed_files', 'blocking_facts', 'evidence_refs'],
            array_keys($verdict),
        );
    }
}
