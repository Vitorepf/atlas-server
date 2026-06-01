<?php

declare(strict_types=1);

namespace Tests\Unit\Ai\Aaeos\Generated;

use App\Services\Ai\Aaeos\Generated\AtlasPacketCompletionGateContractService;
use Tests\TestCase;

/**
 * Pins the documented Packet Completion Gate decision rules.
 *
 * Pure, no DB. Each test maps to one rule in the doc's Decision Rules / Non
 * Goals / Required Evidence sections.
 *
 * @see docs/engineering-knowledge-base/self-construction/packet-completion-gate-contract.md
 */
class AtlasPacketCompletionGateContractTest extends TestCase
{
    private function service(): AtlasPacketCompletionGateContractService
    {
        return new AtlasPacketCompletionGateContractService();
    }

    /**
     * A clean evidence report with everything passing and NO human review.
     *
     * @return array<string, mixed>
     */
    private function cleanInput(): array
    {
        return [
            'selected_packet_id' => 'AIP-SPLIT-20260601-0001',
            'evidence_report_status' => 'clean',
            'evidence_report_hash' => 'sha256:abc',
            'scope_validator_status' => 'pass',
            'required_gate_statuses' => [
                ['id' => 'focused_tests', 'status' => 'pass'],
                ['id' => 'docs_health', 'status' => 'pass'],
            ],
            'required_evidence_statuses' => [
                ['id' => 'focused_tests', 'status' => 'present'],
            ],
            'external_blockers' => [],
            'human_review_present' => false,
        ];
    }

    /**
     * Rule: "If evidence report is clean but no human review exists, status must
     * be human_review_required." Also pins the Non Goals invariants that hold in
     * every state: execution_allowed, completion_allowed, durable_completion_written
     * are all false.
     */
    public function test_clean_report_without_human_review_requests_review(): void
    {
        $out = $this->service()->decide($this->cleanInput());

        $this->assertSame(
            AtlasPacketCompletionGateContractService::STATUS_HUMAN_REVIEW_REQUIRED,
            $out['status'],
        );
        $this->assertSame(
            AtlasPacketCompletionGateContractService::DECISION_REQUEST_HUMAN_REVIEW,
            $out['decision'],
        );
        $this->assertSame([], $out['blocking_reasons']);
        // Read-only invariants (Non Goals) — never true in any state.
        $this->assertFalse($out['execution_allowed']);
        $this->assertFalse($out['completion_allowed']);
        $this->assertFalse($out['durable_completion_written']);
        $this->assertSame(
            AtlasPacketCompletionGateContractService::SCHEMA,
            $out['schema_version'],
        );
    }

    /**
     * Rule: "If evidence report is clean and human review exists, read-only
     * status may be completion_candidate." completion_allowed STILL stays false
     * ("remains false until durable completion persistence is implemented by a
     * future AP").
     */
    public function test_clean_report_with_human_review_is_completion_candidate(): void
    {
        $input = $this->cleanInput();
        $input['human_review_present'] = true;

        $out = $this->service()->decide($input);

        $this->assertSame(
            AtlasPacketCompletionGateContractService::STATUS_COMPLETION_CANDIDATE,
            $out['status'],
        );
        $this->assertSame(
            AtlasPacketCompletionGateContractService::DECISION_CANDIDATE_ONLY,
            $out['decision'],
        );
        // The whole point of the gate: a candidate is NOT a completion.
        $this->assertFalse($out['completion_allowed']);
        $this->assertFalse($out['durable_completion_written']);
    }

    /**
     * Rule: "If evidence report is blocked, status must be blocked." A blocked
     * upstream report can never be promoted, even if a human review is present.
     */
    public function test_blocked_report_stays_blocked_even_with_human_review(): void
    {
        $input = $this->cleanInput();
        $input['evidence_report_status'] = 'blocked';
        $input['human_review_present'] = true;

        $out = $this->service()->decide($input);

        $this->assertSame(
            AtlasPacketCompletionGateContractService::STATUS_BLOCKED,
            $out['status'],
        );
        $this->assertSame(
            AtlasPacketCompletionGateContractService::DECISION_BLOCK,
            $out['decision'],
        );
        $this->assertContains('evidence_report_blocked', $out['blocking_reasons']);
    }

    /**
     * Frontmatter decision: "External blockers must prevent automated completion
     * claims." A clean report WITH human review is still forced to blocked when
     * an external (hot) blocker is present, and the blocker is reported.
     */
    public function test_external_blocker_forces_block_despite_clean_report_and_review(): void
    {
        $input = $this->cleanInput();
        $input['human_review_present'] = true;
        $input['external_blockers'] = [
            ['path' => 'runtimes/python/voice_realtime/server.py'],
        ];

        $out = $this->service()->decide($input);

        $this->assertSame(
            AtlasPacketCompletionGateContractService::STATUS_BLOCKED,
            $out['status'],
        );
        $this->assertSame(
            [['path' => 'runtimes/python/voice_realtime/server.py']],
            $out['external_blockers'],
        );
        $this->assertContains(
            'external_blocker:runtimes/python/voice_realtime/server.py',
            $out['blocking_reasons'],
        );
    }

    /**
     * Required Evidence: the gate inspects required gate statuses and required
     * evidence statuses. A missing required gate AND a missing required evidence
     * item each surface as a distinct blocking reason and force status=blocked,
     * with the missing evidence id echoed in missing_evidence.
     */
    public function test_missing_gate_and_missing_evidence_block_with_reasons(): void
    {
        $input = $this->cleanInput();
        $input['human_review_present'] = true;
        $input['required_gate_statuses'] = [
            ['id' => 'focused_tests', 'status' => 'missing'],
        ];
        $input['required_evidence_statuses'] = [
            ['id' => 'architecture_validate', 'status' => 'missing'],
        ];

        $out = $this->service()->decide($input);

        $this->assertSame(
            AtlasPacketCompletionGateContractService::STATUS_BLOCKED,
            $out['status'],
        );
        $this->assertContains('required_gate_missing:focused_tests', $out['blocking_reasons']);
        $this->assertContains('required_evidence_missing:architecture_validate', $out['blocking_reasons']);
        $this->assertSame(['architecture_validate'], $out['missing_evidence']);
    }

    /**
     * Determinism: the same inputs always produce the same gate id (read-only,
     * no clock dependency), so the gate is reproducible/auditable.
     */
    public function test_gate_id_is_deterministic_for_same_inputs(): void
    {
        $a = $this->service()->decide($this->cleanInput());
        $b = $this->service()->decide($this->cleanInput());

        $this->assertSame($a['gate_id'], $b['gate_id']);
        $this->assertStringStartsWith('COMPLETION-GATE-', $a['gate_id']);
    }
}
