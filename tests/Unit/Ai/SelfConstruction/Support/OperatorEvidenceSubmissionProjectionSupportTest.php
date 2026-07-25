<?php

declare(strict_types=1);

namespace Tests\Unit\Ai\SelfConstruction\Support;

use App\Services\Ai\SelfConstruction\OperatorEvidence\TerminalLoopOperationalProofCommandFactory;
use App\Services\Ai\SelfConstruction\Support\OperatorEvidenceSubmissionProjectionSupport as Support;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use ReflectionClass;
use ReflectionMethod;

/**
 * Pure-unit lock for {@see Support}
 * (string/array-only; no FS / DI / Storage / Artisan / provider I/O).
 *
 * Explicit path proof: readiness host imports Support and no longer declares the peeled privates.
 */
final class OperatorEvidenceSubmissionProjectionSupportTest extends TestCase
{
    private const SUPPORT_PATH = 'app/Services/Ai/SelfConstruction/Support/OperatorEvidenceSubmissionProjectionSupport.php';

    private const HOST_PATH = 'app/Services/Ai/SelfConstruction/NativeImplementation/AtlasSelfConstructionOperatorEvidenceSubmissionReadinessService.php';

    /** @var list<string> */
    private const PEELED = [
        'operatorEvidenceSequenceIntegrity',
        'draftWorkspaceRefreshDecision',
        'payloadOrWorkspaceOrCanonicalSubmission',
        'canonicalSubmissionStaleContextHashes',
        'envelopeStatus',
        'diagnosticRow',
        'nextRequiredCommand',
        'closureArtifactSequence',
        'nextActionGraph',
        'promptToArtifactChecklist',
        'externalCompletionClaimPolicy',
    ];

    #[Test]
    public function explicit_path_proof_support_and_host_files_exist_and_host_calls_support(): void
    {
        $root = dirname(__DIR__, 5);
        $supportAbs = $root.'/'.self::SUPPORT_PATH;
        $hostAbs = $root.'/'.self::HOST_PATH;

        $this->assertFileExists($supportAbs, 'Support peel must live at '.self::SUPPORT_PATH);
        $this->assertFileExists($hostAbs, 'Host must remain at '.self::HOST_PATH);

        $hostSrc = (string) file_get_contents($hostAbs);
        $this->assertStringContainsString(
            'use App\Services\Ai\SelfConstruction\Support\OperatorEvidenceSubmissionProjectionSupport;',
            $hostSrc,
            'Host must import OperatorEvidenceSubmissionProjectionSupport',
        );

        foreach (self::PEELED as $method) {
            $this->assertStringContainsString(
                'OperatorEvidenceSubmissionProjectionSupport::'.$method,
                $hostSrc,
                "Host must call Support::{$method}",
            );
            $this->assertStringNotContainsString(
                'private function '.$method,
                $hostSrc,
                "Peeled method residual on host: {$method}",
            );
        }
    }

    #[Test]
    public function pure_support_is_static_and_final_with_no_instance_state(): void
    {
        $ref = new ReflectionClass(Support::class);
        $this->assertTrue($ref->isFinal());
        $this->assertTrue($ref->getConstructor()?->isPrivate() ?? false);

        foreach (self::PEELED as $method) {
            $m = new ReflectionMethod(Support::class, $method);
            $this->assertTrue($m->isPublic() && $m->isStatic(), "{$method} must be public static");
        }
    }

    #[Test]
    public function envelope_status_blocks_until_payload_exists_then_ready_when_diagnostic_ready(): void
    {
        $this->assertSame(
            'blocked_until_operator_runtime_promotion_receipt_exists',
            Support::envelopeStatus('runtime_promotion_receipt', ['supplied' => false]),
        );
        $this->assertSame(
            'ready_for_explicit_operator_persistence',
            Support::envelopeStatus('runtime_promotion_receipt', ['supplied' => true, 'ready' => true]),
        );
        $this->assertSame(
            'blocked_until_real_provider_smoke_verifier_passes',
            Support::envelopeStatus('real_provider_smoke', ['supplied' => true, 'ready' => false]),
        );
        $this->assertSame(
            'blocked_until_runtime_and_smoke_envelopes_are_green',
            Support::envelopeStatus('human_completion_receipt', [
                'supplied' => true,
                'ready' => false,
                'errors' => ['human_completion_receipt_supplied_before_runtime_and_smoke_green'],
            ]),
        );
    }

    #[Test]
    public function diagnostic_row_marks_ready_only_when_passed_without_placeholders_or_forbidden_flags(): void
    {
        $row = Support::diagnosticRow(
            supplied: true,
            verification: [
                'status' => 'passed',
                'violations' => [],
                'violation_count' => 0,
            ],
            composedHash: 'abc',
            placeholders: [],
            forbiddenFlagsTrue: [],
            forbiddenFlagList: ['auto_promote'],
        );
        $this->assertTrue($row['ready']);
        $this->assertSame('passed', $row['status']);
        $this->assertSame([], $row['errors']);
        $this->assertSame('abc', $row['composed_hash']);

        $blocked = Support::diagnosticRow(
            supplied: true,
            verification: [
                'status' => 'failed',
                'violations' => [['code' => 'runtime_gap_matrix_hash_mismatch']],
                'violation_count' => 1,
            ],
            composedHash: 'x',
            placeholders: ['<operator>'],
            forbiddenFlagsTrue: ['auto_promote'],
            forbiddenFlagList: ['auto_promote'],
        );
        $this->assertFalse($blocked['ready']);
        $this->assertContains('verifier_status_failed', $blocked['errors']);
        $this->assertContains('violation_code_runtime_gap_matrix_hash_mismatch', $blocked['errors']);
        $this->assertContains('placeholder_field_<operator>', $blocked['errors']);
        $this->assertContains('forbidden_flag_true_auto_promote', $blocked['errors']);
        $this->assertSame(['runtime_gap_matrix_hash_mismatch'], $blocked['violation_codes']);
    }

    #[Test]
    public function next_required_command_maps_known_artifacts_and_defaults_empty(): void
    {
        $this->assertStringContainsString(
            '--atlas-self-construction-runtime-promotion-receipt-draft-status',
            Support::nextRequiredCommand('runtime_promotion_receipt'),
        );
        $this->assertStringContainsString(
            '--atlas-self-construction-os-completion-audit-status',
            Support::nextRequiredCommand('rerun_completion_audit'),
        );
        $this->assertSame('', Support::nextRequiredCommand('unknown'));
    }

    #[Test]
    public function closure_artifact_sequence_orders_four_steps_with_live_command_shapes(): void
    {
        $seq = Support::closureArtifactSequence(true, false, false, false);
        $this->assertCount(4, $seq);
        $this->assertSame('runtime_promotion_receipt', $seq[0]['artifact']);
        $this->assertTrue($seq[0]['passed']);
        $this->assertSame('real_provider_smoke', $seq[1]['artifact']);
        $this->assertFalse($seq[1]['passed']);
        $this->assertSame(
            TerminalLoopOperationalProofCommandFactory::completionAuditWithCanonicalTerminalLoopOperationalProofCommand(),
            $seq[3]['draft_command'],
        );

        $checklist = Support::promptToArtifactChecklist($seq);
        $this->assertCount(4, $checklist);
        $this->assertSame('runtime_gap_matrix_all_runtime_y', $checklist[0]['requirement']);
        $this->assertSame($seq[0]['draft_command'], $checklist[0]['command']);
    }

    #[Test]
    public function next_action_graph_has_ordered_owners_and_stable_hash(): void
    {
        $graph = Support::nextActionGraph(
            runtimePassed: false,
            smokePassed: false,
            humanPassed: false,
            diagnostics: [
                'runtime_promotion_receipt' => ['errors' => ['not_supplied']],
            ],
        );

        $this->assertSame('atlas.self_construction.operator_evidence_next_action_graph.v1', $graph['schema_version']);
        $this->assertSame('blocked_on_operator_or_provider_owned_steps', $graph['status']);
        $this->assertFalse($graph['can_run_from_graph']);
        $this->assertSame(4, $graph['node_count']);
        $this->assertNotSame('', $graph['next_action_graph_hash']);

        $ids = array_column($graph['nodes'], 'id');
        $this->assertSame([
            'runtime_promotion_receipt',
            'real_provider_smoke',
            'human_completion_receipt',
            'rerun_completion_audit',
        ], $ids);
        $this->assertSame('operator', $graph['nodes'][0]['worker_or_operator_owner']);
        $this->assertSame('provider', $graph['nodes'][1]['worker_or_operator_owner']);
        $this->assertSame('worker', $graph['nodes'][3]['worker_or_operator_owner']);
        $this->assertSame(['not_supplied'], $graph['nodes'][0]['blocking_reasons']);

        $ready = Support::nextActionGraph(true, true, true, []);
        $this->assertSame('ready_for_completion_audit_rerun', $ready['status']);
        $this->assertTrue($ready['nodes'][3]['ready']);
    }

    #[Test]
    public function sequence_integrity_detects_out_of_order_supply_and_hashes(): void
    {
        $ok = Support::operatorEvidenceSequenceIntegrity(
            diagnostics: [
                'runtime_promotion_receipt' => ['supplied' => false, 'ready' => false],
                'real_provider_smoke' => ['supplied' => false, 'ready' => false],
                'human_completion_receipt' => ['supplied' => false, 'ready' => false],
            ],
            canonicalSubmissionPersistencePlan: ['sequence_ordered' => true, 'next_step_id' => 'step1', 'steps' => []],
            persistedEvidenceState: [],
            nextRequired: 'runtime_promotion_receipt',
        );
        $this->assertTrue($ok['sequence_valid']);
        $this->assertSame('sequence_integrity_ok', $ok['status']);
        $this->assertNotSame('', $ok['sequence_integrity_hash']);

        $blocked = Support::operatorEvidenceSequenceIntegrity(
            diagnostics: [
                'runtime_promotion_receipt' => ['supplied' => false, 'ready' => false],
                'real_provider_smoke' => ['supplied' => true, 'ready' => false],
                'human_completion_receipt' => ['supplied' => false, 'ready' => false],
            ],
            canonicalSubmissionPersistencePlan: ['sequence_ordered' => true, 'next_step_id' => '', 'steps' => []],
            persistedEvidenceState: [],
            nextRequired: 'runtime_promotion_receipt',
        );
        $this->assertFalse($blocked['sequence_valid']);
        $this->assertContains('real_provider_smoke_supplied_before_previous_artifacts_green', $blocked['sequence_violations']);
    }

    #[Test]
    public function draft_workspace_refresh_decision_requires_refresh_on_stale_or_violations(): void
    {
        $na = Support::draftWorkspaceRefreshDecision([], [], []);
        $this->assertSame('not_applicable', $na['status']);
        $this->assertFalse($na['required']);
        $this->assertFalse($na['can_refresh_from_readiness']);

        $refresh = Support::draftWorkspaceRefreshDecision(
            workspaceInput: [
                'status' => 'loaded_for_read_only_submission_readiness',
                'warning_count' => 0,
                'violation_count' => 1,
            ],
            staleContextHashes: ['runtime_gap_matrix_hash'],
            runtimeGapMatrix: [
                'runtime_gap_matrix_hash' => 'h1',
                'expected_runtime_gap_matrix_hash_for_promotion_receipt' => 'h2',
                'runtime_promotion_basis_hash' => 'h3',
                'runtime_promotion_closure_basis_hash' => 'h4',
            ],
        );
        $this->assertTrue($refresh['required']);
        $this->assertSame('refresh_recommended_before_operator_signature', $refresh['status']);
        $this->assertContains('runtime_promotion_receipt_context_hashes_are_stale', $refresh['reasons']);
        $this->assertContains('draft_workspace_inspector_reported_violations', $refresh['reasons']);
        $this->assertSame('h1', $refresh['current_hashes_for_new_workspace']['runtime_gap_matrix_hash']);
    }

    #[Test]
    public function payload_or_workspace_or_canonical_prefers_explicit_then_workspace_then_canonical(): void
    {
        $this->assertSame(
            ['a' => 1],
            Support::payloadOrWorkspaceOrCanonicalSubmission(['a' => 1], ['k' => ['b' => 2]], ['k' => ['c' => 3]], 'k'),
        );
        $this->assertSame(
            ['b' => 2],
            Support::payloadOrWorkspaceOrCanonicalSubmission([], ['k' => ['b' => 2]], ['k' => ['c' => 3]], 'k'),
        );
        $this->assertSame(
            ['c' => 3],
            Support::payloadOrWorkspaceOrCanonicalSubmission([], [], ['k' => ['c' => 3]], 'k'),
        );
        $this->assertSame(
            [],
            Support::payloadOrWorkspaceOrCanonicalSubmission([], [], [], 'k'),
        );
    }

    #[Test]
    public function canonical_stale_hashes_map_known_violation_codes_only(): void
    {
        $this->assertSame(
            ['runtime_gap_matrix_hash', 'graduation_evidence_hashes'],
            Support::canonicalSubmissionStaleContextHashes([
                'runtime_gap_matrix_hash_mismatch',
                'unrelated',
                'graduation_hash_mismatch',
            ]),
        );
        $this->assertSame([], Support::canonicalSubmissionStaleContextHashes([]));
    }

    #[Test]
    public function external_completion_claim_policy_rejects_until_audit_complete(): void
    {
        $seq = Support::closureArtifactSequence(false, false, false, false);
        $policy = Support::externalCompletionClaimPolicy(
            completionAudit: ['status' => 'incomplete', 'completion_allowed' => false, 'failed_count' => 2, 'failed_criteria' => ['a', 'b']],
            closureArtifactSequence: $seq,
            nextRequired: 'runtime_promotion_receipt',
        );

        $this->assertSame('reject_external_completion_claim', $policy['status']);
        $this->assertFalse($policy['external_agent_claim_accepted']);
        $this->assertFalse($policy['external_agent_claim_can_mark_os_complete']);
        $this->assertFalse($policy['completion_claim_allowed_by_audit']);
        $this->assertSame(4, $policy['missing_required_evidence_artifact_count']);
        $this->assertNotSame('', $policy['external_completion_claim_policy_hash']);
        $this->assertSame(
            TerminalLoopOperationalProofCommandFactory::completionAuditWithCanonicalTerminalLoopOperationalProofCommand(),
            $policy['operator_verification_commands']['completion_audit_with_canonical_terminal_loop_operational_proof'],
        );

        $greenSeq = Support::closureArtifactSequence(true, true, true, true);
        $green = Support::externalCompletionClaimPolicy(
            completionAudit: ['status' => 'complete', 'completion_allowed' => true, 'failed_count' => 0, 'failed_criteria' => []],
            closureArtifactSequence: $greenSeq,
            nextRequired: 'rerun_completion_audit',
        );
        $this->assertSame('audit_authorizes_completion_claim', $green['status']);
        $this->assertTrue($green['completion_claim_allowed_by_audit']);
        $this->assertSame(0, $green['missing_required_evidence_artifact_count']);
    }
}
