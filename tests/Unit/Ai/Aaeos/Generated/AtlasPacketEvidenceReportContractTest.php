<?php

declare(strict_types=1);

namespace Tests\Unit\Ai\Aaeos\Generated;

use App\Services\Ai\Aaeos\Generated\AtlasPacketEvidenceReportContractService;
use Tests\TestCase;

/**
 * Pins the documented Packet Evidence Report completion + blocked rules.
 *
 * @see docs/engineering-knowledge-base/self-construction/packet-evidence-report-contract.md
 */
class AtlasPacketEvidenceReportContractTest extends TestCase
{
    private function service(): AtlasPacketEvidenceReportContractService
    {
        return new AtlasPacketEvidenceReportContractService();
    }

    /**
     * A fully-satisfied packet (Completion Rules: packet+runbook exist, scope
     * passes, all gates present+passing, all evidence present, owned scope
     * clean, no external blockers, residual risk low) => status=completion_ready.
     * Even then, completion_allowed and evidence_ledger_write_allowed stay false
     * (Read-Only Phase).
     */
    private function readyInput(): array
    {
        return [
            'selected_packet_id' => 'AIP-SPLIT-20260601-0001',
            'runbook_exists' => true,
            'assignment_preview_exists' => true,
            'scope_validator_status' => 'pass',
            'required_gates' => [
                ['id' => 'focused_tests', 'status' => 'pass'],
                ['id' => 'architecture_validate', 'status' => 'pass'],
                ['id' => 'docs_health', 'status' => 'pass'],
                ['id' => 'git_diff_check', 'status' => 'pass'],
            ],
            'required_evidence' => ['focused_tests', 'architecture_validate'],
            'present_evidence' => ['focused_tests', 'architecture_validate'],
            'packet_owned_files' => [
                ['path' => 'app/Services/Ai/Aaeos/Generated/Foo.php', 'classification' => 'allowed'],
            ],
            'external_blockers' => [],
            'residual_risk' => 'low',
        ];
    }

    public function test_all_rules_hold_is_completion_ready_but_never_marks_completion(): void
    {
        $r = $this->service()->report($this->readyInput());

        $this->assertSame(AtlasPacketEvidenceReportContractService::STATUS_COMPLETION_READY, $r['status']);
        $this->assertSame([], $r['report']['blocking_reasons']);
        $this->assertSame(
            AtlasPacketEvidenceReportContractService::NEXT_HUMAN_REVIEW,
            $r['report']['required_next_action'],
        );

        // Read-Only Phase invariants: always false regardless of readiness.
        $this->assertFalse($r['completion_allowed']);
        $this->assertFalse($r['execution_allowed']);
        $this->assertFalse($r['evidence_ledger_write_allowed']);
        $this->assertFalse($r['report']['completion_allowed']);
        $this->assertFalse($r['report']['evidence_ledger_write_allowed']);

        // Every completion check must be true in the ready case.
        $this->assertNotContains(false, $r['report']['completion_checks'], 'a completion rule was false in the ready case');

        $this->assertSame(
            AtlasPacketEvidenceReportContractService::SCHEMA,
            $r['schema_version'],
        );
    }

    /**
     * Blocked State: "Scope Validator is blocked" => status=blocked, the
     * specific reason is recorded, completion stays disallowed.
     */
    public function test_scope_validator_blocked_blocks_completion(): void
    {
        $input = $this->readyInput();
        $input['scope_validator_status'] = 'blocked';

        $r = $this->service()->report($input);

        $this->assertSame(AtlasPacketEvidenceReportContractService::STATUS_BLOCKED, $r['status']);
        $this->assertContains('scope_validator_blocked', $r['report']['blocking_reasons']);
        $this->assertFalse($r['report']['completion_checks']['scope_validator_passed']);
        $this->assertFalse($r['completion_allowed']);
        $this->assertSame(
            AtlasPacketEvidenceReportContractService::NEXT_RESOLVE_BLOCKERS,
            $r['report']['required_next_action'],
        );
    }

    /**
     * Blocked State: "tests are missing or failed" — a required gate marked
     * fail must surface a per-gate failure reason and block.
     */
    public function test_failed_required_gate_blocks_with_named_reason(): void
    {
        $input = $this->readyInput();
        $input['required_gates'][0] = ['id' => 'focused_tests', 'status' => 'fail'];

        $r = $this->service()->report($input);

        $this->assertSame(AtlasPacketEvidenceReportContractService::STATUS_BLOCKED, $r['status']);
        $this->assertContains('required_gate_failed:focused_tests', $r['report']['blocking_reasons']);
        $this->assertFalse($r['report']['completion_checks']['required_gates_passed']);
        $this->assertFalse($r['completion_allowed']);
    }

    /**
     * Decision: "A narrative 'done' response is never sufficient evidence."
     * Required evidence whose id is absent from present_evidence => missing,
     * blocking, regardless of any other signal.
     */
    public function test_missing_required_evidence_blocks(): void
    {
        $input = $this->readyInput();
        $input['present_evidence'] = ['focused_tests']; // architecture_validate missing

        $r = $this->service()->report($input);

        $this->assertSame(AtlasPacketEvidenceReportContractService::STATUS_BLOCKED, $r['status']);
        $this->assertContains('missing_evidence:architecture_validate', $r['report']['blocking_reasons']);
        $this->assertFalse($r['report']['completion_checks']['required_evidence_present']);
        $this->assertFalse($r['completion_allowed']);
    }

    /**
     * Decision: "External hot blockers must be reported separately from
     * packet-owned failures." An external blocker keeps its own array AND its
     * own blocking reason; it does not masquerade as a gate/evidence failure.
     * Read-Only Phase: hot external blockers force status=blocked.
     */
    public function test_external_hot_blocker_reported_separately_and_blocks(): void
    {
        $input = $this->readyInput();
        $input['external_blockers'] = [
            ['path' => 'runtimes/python/voice_realtime/session.py'],
        ];

        $r = $this->service()->report($input);

        $this->assertSame(AtlasPacketEvidenceReportContractService::STATUS_BLOCKED, $r['status']);
        $this->assertContains('external_hot_blockers_reported', $r['report']['blocking_reasons']);
        // Reported separately: the external file is in its own array, not in gate/evidence failures.
        $this->assertSame(
            [['path' => 'runtimes/python/voice_realtime/session.py']],
            $r['report']['external_blockers'],
        );
        $this->assertFalse($r['report']['completion_checks']['no_external_hot_blockers']);
        $this->assertFalse($r['completion_allowed']);
    }

    /**
     * Completion Rule: "no forbidden, unknown or hot external file is owned by
     * the packet." An owned file classified `unknown` blocks completion.
     * Also: Completion Rule "residual risk is low or accepted by review" —
     * residual high without review acceptance fails its check.
     */
    public function test_unsafe_owned_file_and_unaccepted_high_risk_block(): void
    {
        $input = $this->readyInput();
        $input['packet_owned_files'] = [
            ['path' => 'routes/api.php', 'classification' => 'unknown'],
        ];
        $input['residual_risk'] = 'high';
        $input['residual_risk_accepted_by_review'] = false;

        $r = $this->service()->report($input);

        $this->assertSame(AtlasPacketEvidenceReportContractService::STATUS_BLOCKED, $r['status']);
        $this->assertContains('packet_owns_unsafe_file_scope', $r['report']['blocking_reasons']);
        $this->assertSame(1, $r['report']['owned_file_classification']['unsafe_count']);
        $this->assertFalse($r['report']['completion_checks']['no_unsafe_owned_file']);
        $this->assertFalse($r['report']['completion_checks']['residual_risk_low_or_accepted']);

        // But high risk EXPLICITLY accepted by review satisfies that one rule.
        $accepted = $input;
        $accepted['residual_risk_accepted_by_review'] = true;
        $r2 = $this->service()->report($accepted);
        $this->assertTrue($r2['report']['completion_checks']['residual_risk_low_or_accepted']);
    }

    /**
     * Determinism: identical input yields an identical report_hash.
     */
    public function test_report_hash_is_deterministic(): void
    {
        $a = $this->service()->report($this->readyInput());
        $b = $this->service()->report($this->readyInput());

        $this->assertSame($a['report_hash'], $b['report_hash']);
        $this->assertStringStartsWith('sha256:', $a['report_hash']);
    }
}
