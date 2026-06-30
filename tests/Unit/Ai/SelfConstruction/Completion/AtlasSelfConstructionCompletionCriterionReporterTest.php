<?php

declare(strict_types=1);

namespace Tests\Unit\Ai\SelfConstruction\Completion;

use App\Services\Ai\SelfConstruction\Completion\AtlasSelfConstructionCompletionCriterionReporter;
use Tests\TestCase;

class AtlasSelfConstructionCompletionCriterionReporterTest extends TestCase
{
    public function test_criterion_metadata_returns_all_keys(): void
    {
        $meta = AtlasSelfConstructionCompletionCriterionReporter::criterionMetadata();

        self::assertArrayHasKey('runtime_gap_matrix_all_runtime_y', $meta);
        self::assertArrayHasKey('release_dossier_green', $meta);
        self::assertArrayHasKey('agent_control_plane_terminal_loop_certification_green', $meta);
    }

    public function test_enrich_failed_criterion(): void
    {
        $criterion = [
            'id' => 'release_dossier_green',
            'requirement' => 'release dossier green',
            'evidence' => ['status' => 'blocked'],
        ];

        $result = AtlasSelfConstructionCompletionCriterionReporter::enrichFailedCriterion($criterion);

        self::assertSame('release_dossier_green', $result['id']);
        self::assertSame('technical', $result['blocker_type']);
        self::assertSame('blocked', $result['observed_status']);
        self::assertNotEmpty($result['remediation_command']);
    }

    public function test_enrich_failed_criterion_unknown_id_uses_defaults(): void
    {
        $result = AtlasSelfConstructionCompletionCriterionReporter::enrichFailedCriterion(
            ['id' => 'unknown_id', 'evidence' => []],
        );

        self::assertSame('technical', $result['blocker_type']);
        self::assertSame('', $result['doc_anchor']);
    }

    public function test_summarise_passed_criterion(): void
    {
        $criterion = [
            'id' => 'release_dossier_green',
            'requirement' => 'release dossier green',
            'evidence' => ['status' => 'available', 'release_dossier_hash' => 'abc123'],
        ];

        $result = AtlasSelfConstructionCompletionCriterionReporter::summarisePassedCriterion($criterion);

        self::assertSame('available', $result['observed_status']);
        self::assertSame('abc123', $result['evidence_hash']);
    }

    public function test_summarise_passed_criterion_uses_first_available_hash(): void
    {
        $criterion = [
            'id' => 'release_dossier_green',
            'evidence' => ['hash' => 'main_hash'],
        ];

        $result = AtlasSelfConstructionCompletionCriterionReporter::summarisePassedCriterion($criterion);

        self::assertSame('main_hash', $result['evidence_hash']);
    }

    public function test_summarise_passed_criterion_empty_evidence(): void
    {
        $result = AtlasSelfConstructionCompletionCriterionReporter::summarisePassedCriterion([]);

        self::assertSame('', $result['evidence_hash']);
        self::assertSame('passed', $result['observed_status']);
    }

    public function test_classify_blockers_empty(): void
    {
        $result = AtlasSelfConstructionCompletionCriterionReporter::classifyBlockers([]);

        self::assertTrue($result['no_blockers_at_all']);
        self::assertTrue($result['completion_allowed']);
        self::assertSame(0, $result['human_blocker_count']);
    }

    public function test_classify_blockers_buckets(): void
    {
        $failed = [
            ['id' => 'human_1', 'blocker_type' => 'human'],
            ['id' => 'human_2', 'blocker_type' => 'human'],
            ['id' => 'tech_1', 'blocker_type' => 'technical'],
            ['id' => 'rp_1', 'blocker_type' => 'real_provider'],
            ['id' => 'unknown', 'blocker_type' => 'something_new'],
        ];

        $result = AtlasSelfConstructionCompletionCriterionReporter::classifyBlockers($failed);

        self::assertSame(['human_1', 'human_2'], $result['human_blockers']);
        self::assertSame(['tech_1'], $result['technical_blockers']);
        self::assertSame(['rp_1'], $result['real_provider_blockers']);
        self::assertSame(['unknown'], $result['other_blockers']);
        self::assertFalse($result['no_blockers_at_all']);
        self::assertFalse($result['completion_allowed']);
    }

    public function test_completion_claim_authority_verdict_complete_no_failures(): void
    {
        $verdict = AtlasSelfConstructionCompletionCriterionReporter::completionClaimAuthorityVerdict('complete', [], ['completion_allowed' => true]);

        self::assertSame('completion_claim_authorized_by_audit', $verdict['status']);
        self::assertTrue($verdict['completion_allowed_by_audit']);
        self::assertTrue($verdict['completion_claim_allowed_by_audit']);
        self::assertFalse($verdict['external_agent_claim_can_mark_os_complete']);
        self::assertSame(64, strlen($verdict['completion_claim_authority_verdict_hash']));
    }

    public function test_completion_claim_authority_verdict_blocked_when_failures_present(): void
    {
        $verdict = AtlasSelfConstructionCompletionCriterionReporter::completionClaimAuthorityVerdict(
            'complete',
            [['id' => 'release_dossier_green', 'blocker_type' => 'technical']],
            ['completion_allowed' => false],
        );

        self::assertSame('completion_claim_rejected_by_audit', $verdict['status']);
        self::assertFalse($verdict['completion_allowed_by_audit']);
        self::assertCount(1, $verdict['missing_evidence']);
    }

    public function test_build_audit_blocks_passes_for_passed_criterion(): void
    {
        $criteria = [
            ['id' => 'release_dossier_green', 'requirement' => 'X', 'passed' => true],
        ];
        $releaseDossier = ['status' => 'available', 'agent_control_plane_release_dossier_status' => ['release_dossier_hash' => 'h1']];
        $empty = [];

        $blocks = AtlasSelfConstructionCompletionCriterionReporter::buildAuditBlocks(
            $criteria, $releaseDossier, $empty, $empty, $empty, $empty, $empty, $empty, $empty, $empty, $empty,
        );

        self::assertSame('green', $blocks['release_dossier_block']['status']);
        self::assertTrue($blocks['release_dossier_block']['passed']);
    }

    public function test_build_audit_blocks_blocks_for_failed_criterion(): void
    {
        $criteria = [
            ['id' => 'release_dossier_green', 'requirement' => 'X', 'passed' => false],
        ];

        $blocks = AtlasSelfConstructionCompletionCriterionReporter::buildAuditBlocks(
            $criteria, [], [], [], [], [], [], [], [], [], [],
        );

        self::assertSame('blocked', $blocks['release_dossier_block']['status']);
        self::assertFalse($blocks['release_dossier_block']['passed']);
        self::assertSame('technical', $blocks['release_dossier_block']['blocker_type']);
        self::assertNotEmpty($blocks['release_dossier_block']['remediation_command']);
    }

    public function test_build_audit_blocks_chain_integrity_status(): void
    {
        $blocks = AtlasSelfConstructionCompletionCriterionReporter::buildAuditBlocks(
            [], ['agent_control_plane_release_dossier_status' => ['chain_integrity_status' => 'available']],
            [], [], [], [], [], [], [], [], [],
        );

        self::assertSame('green', $blocks['chain_integrity_block']['status']);
        self::assertTrue($blocks['chain_integrity_block']['passed']);
    }

    public function test_prompt_to_artifact_checklist_basic(): void
    {
        $checklist = AtlasSelfConstructionCompletionCriterionReporter::promptToArtifactChecklist(
            [], [], [], [], [],
        );

        self::assertNotEmpty($checklist);
        self::assertSame('Atlas Self-Construction OS complete', $checklist[0]['requirement']);
    }

    public function test_prompt_to_artifact_checklist_runtime_gap_passed(): void
    {
        $checklist = AtlasSelfConstructionCompletionCriterionReporter::promptToArtifactChecklist(
            [['id' => 'runtime_gap_matrix_all_runtime_y', 'passed' => true]],
            [], [], [], [],
        );

        $row = collect($checklist)->firstWhere('requirement', 'all runtime gaps closed');
        self::assertSame('passed', $row['evidence_status']);
    }

    public function test_prompt_to_artifact_checklist_release_dossier_status(): void
    {
        $checklist = AtlasSelfConstructionCompletionCriterionReporter::promptToArtifactChecklist(
            [], [], ['status' => 'available'], [], [],
        );

        $row = collect($checklist)->firstWhere('requirement', 'release dossier green');
        self::assertSame('passed', $row['evidence_status']);
    }

    public function test_prompt_to_artifact_checklist_terminal_loop_passed(): void
    {
        $terminalLoop = ['passed' => true];

        $checklist = AtlasSelfConstructionCompletionCriterionReporter::promptToArtifactChecklist(
            [], [], [], [], $terminalLoop,
        );

        $row = collect($checklist)->firstWhere('requirement', 'multi-agent terminal loop wired (claim/complete/replenish/bootstrap/health-digest/recover/one-shot/multi-agent-cert)');
        self::assertSame('passed', $row['evidence_status']);
    }

    public function test_final_brain_report_classifies_complete_missing_and_blocked_criteria(): void
    {
        $criteria = [
            // Technical criterion — passed → complete
            ['id' => 'release_dossier_green', 'requirement' => 'release dossier green', 'passed' => true],
            // Technical criterion — unmet → missing (AI-actionable)
            ['id' => 'mutation_guard_green', 'requirement' => 'mutation guard green', 'passed' => false],
            // Human criterion — unmet → blocked (operator action)
            ['id' => 'human_signed_os_complete_receipt_present', 'requirement' => 'human signed receipt', 'passed' => false],
        ];

        $report = AtlasSelfConstructionCompletionCriterionReporter::finalBrainReport($criteria);

        self::assertSame('atlas.self_construction.completion_criterion_report.final_brain.v1', $report['schema']);
        self::assertCount(1, $report['complete']);
        self::assertCount(1, $report['missing']);
        self::assertCount(1, $report['blocked']);
        self::assertSame(3, $report['total_criteria']);
        self::assertFalse($report['all_complete']);

        self::assertSame('release_dossier_green', $report['complete'][0]['id']);
        self::assertSame('mutation_guard_green', $report['missing'][0]['id']);
        self::assertSame('human_signed_os_complete_receipt_present', $report['blocked'][0]['id']);
    }

    public function test_final_brain_report_proof_command_and_next_packet_hint_for_every_unmet_criterion(): void
    {
        $criteria = [
            ['id' => 'release_dossier_green', 'requirement' => 'release dossier green', 'passed' => false],
            ['id' => 'human_signed_os_complete_receipt_present', 'requirement' => 'human signed receipt', 'passed' => false],
        ];

        $report = AtlasSelfConstructionCompletionCriterionReporter::finalBrainReport($criteria);

        // Every unmet criterion in missing/blocked must carry proof_command and next_packet_hint.
        foreach (array_merge($report['missing'], $report['blocked']) as $entry) {
            self::assertArrayHasKey('proof_command', $entry, "criterion {$entry['id']} must have proof_command");
            self::assertArrayHasKey('next_packet_hint', $entry, "criterion {$entry['id']} must have next_packet_hint");
            self::assertNotEmpty($entry['proof_command'], "criterion {$entry['id']} proof_command must not be empty");
            self::assertNotEmpty($entry['next_packet_hint'], "criterion {$entry['id']} next_packet_hint must not be empty");
        }

        // Lane scores must be present.
        self::assertArrayHasKey('lane_scores', $report);
        self::assertArrayHasKey('technical', $report['lane_scores']);
        self::assertArrayHasKey('human', $report['lane_scores']);
    }
}