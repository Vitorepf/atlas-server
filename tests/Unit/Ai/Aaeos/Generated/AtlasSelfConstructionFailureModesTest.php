<?php

declare(strict_types=1);

namespace Tests\Unit\Ai\Aaeos\Generated;

use App\Services\Ai\Aaeos\Generated\AtlasSelfConstructionFailureModesService;
use Tests\TestCase;

/**
 * Pins the documented Atlas Self-Construction Failure Modes rules: the 12-row
 * Failure Table + countermeasures, the "Stop construction if ANY" Red Flags, the
 * 8-field Incident Packet completeness gate, and the strict 7-step Recovery Order.
 *
 * @see docs/engineering-knowledge-base/self-construction/failure-modes.md
 */
class AtlasSelfConstructionFailureModesTest extends TestCase
{
    private function service(): AtlasSelfConstructionFailureModesService
    {
        return new AtlasSelfConstructionFailureModesService();
    }

    /**
     * "Failure Table" lists exactly twelve modes; each carries its documented
     * countermeasure verbatim, and the three frontmatter-named "most dangerous"
     * modes (drift, false completeness, unsafe learning) are flagged.
     */
    public function test_failure_table_taxonomy_and_countermeasures(): void
    {
        $service = $this->service();

        $this->assertCount(12, AtlasSelfConstructionFailureModesService::FAILURE_TABLE);

        // Documented countermeasures (Failure Table column 3).
        $this->assertSame(
            'block; require spec and receipt',
            $service->countermeasureFor(AtlasSelfConstructionFailureModesService::MODE_VIBE_SELF_CODING),
        );
        $this->assertSame(
            'proposal-first learning',
            $service->countermeasureFor(AtlasSelfConstructionFailureModesService::MODE_UNSAFE_LEARNING),
        );
        $this->assertSame(
            'small slice rule',
            $service->countermeasureFor(AtlasSelfConstructionFailureModesService::MODE_OVERENGINEERING),
        );

        // The three most dangerous modes per frontmatter decision.
        $this->assertTrue($service->describeFailure('drift')['most_dangerous']);
        $this->assertTrue($service->describeFailure('false_completeness')['most_dangerous']);
        $this->assertTrue($service->describeFailure('unsafe_learning')['most_dangerous']);
        // A routine mode is not flagged.
        $this->assertFalse($service->describeFailure('scope_creep')['most_dangerous']);

        // Unknown mode resolves to null, never silently "safe".
        $this->assertNull($service->describeFailure('not_a_real_mode'));
        $this->assertNull($service->countermeasureFor('not_a_real_mode'));
    }

    /**
     * "Red Flags": construction proceeds only when ZERO flags are raised; a single
     * raised flag stops it. Also accepts the map form with a false value.
     */
    public function test_red_flags_any_single_flag_stops_construction(): void
    {
        $service = $this->service();

        // No flags -> may proceed.
        $clean = $service->evaluateRedFlags([]);
        $this->assertFalse($clean['stop_construction']);
        $this->assertTrue($clean['can_proceed']);
        $this->assertSame([], $clean['raised_flags']);

        // One flag (list form) -> stop.
        $oneFlag = $service->evaluateRedFlags([
            AtlasSelfConstructionFailureModesService::FLAG_COMPLETE_WITHOUT_EVIDENCE,
        ]);
        $this->assertTrue($oneFlag['stop_construction']);
        $this->assertFalse($oneFlag['can_proceed']);
        $this->assertSame(['complete_without_evidence'], $oneFlag['raised_flags']);
        $this->assertContains('the agent says "complete" but cannot cite evidence', $oneFlag['raised_reasons']);

        // Map form: a flag explicitly NOT raised does not stop construction.
        $mapForm = $service->evaluateRedFlags([
            AtlasSelfConstructionFailureModesService::FLAG_DOCS_CODE_DISAGREE => false,
        ]);
        $this->assertFalse($mapForm['stop_construction']);
        $this->assertSame([], $mapForm['raised_flags']);
    }

    /**
     * "Required Incident Packet": all eight documented fields are required; the
     * packet is `complete` only when every field is present and non-empty, and
     * missing fields are reported precisely.
     */
    public function test_incident_packet_completeness_gate(): void
    {
        $service = $this->service();

        $this->assertCount(8, AtlasSelfConstructionFailureModesService::INCIDENT_PACKET_FIELDS);

        // Missing root_cause + rollback (and empty affected_files array) -> incomplete.
        $partial = $service->buildIncidentPacket([
            'operation_id' => 'op-1',
            'failure_mode' => 'drift',
            'root_cause' => '',          // empty string counts as missing
            'affected_docs' => ['d.md'],
            'affected_files' => [],      // empty array counts as missing
            'failed_gates' => ['gate'],
            // rollback omitted entirely
            'prevention_proposal' => 'add gate',
        ]);
        $this->assertFalse($partial['complete']);
        $this->assertSame(['root_cause', 'affected_files', 'rollback'], $partial['missing_fields']);

        // Every field present and non-empty -> complete.
        $full = $service->buildIncidentPacket([
            'operation_id' => 'op-2',
            'failure_mode' => 'false_completeness',
            'root_cause' => 'claimed done without evidence',
            'affected_docs' => ['failure-modes.md'],
            'affected_files' => ['Service.php'],
            'failed_gates' => ['docs-health'],
            'rollback' => 'revert owned change',
            'prevention_proposal' => 'evidence closeout required',
        ]);
        $this->assertTrue($full['complete']);
        $this->assertSame([], $full['missing_fields']);
    }

    /**
     * "Recovery Order": the seven steps are strict. From a valid prefix the next
     * step is the immediate successor; completing all steps in order reports
     * all_complete; doing a later step before an earlier one breaks the sequence.
     */
    public function test_recovery_order_is_strict(): void
    {
        $service = $this->service();

        $this->assertCount(7, AtlasSelfConstructionFailureModesService::RECOVERY_ORDER);

        // Fresh start -> first step is stop_writes (step 1).
        $start = $service->nextRecoveryStep([]);
        $this->assertTrue($start['ordered']);
        $this->assertSame('stop_writes', $start['next_step']);
        $this->assertSame(1, $start['next_step_number']);
        $this->assertFalse($start['all_complete']);

        // Valid prefix (steps 1-2 done) -> next is identify_failure_mode (step 3).
        $mid = $service->nextRecoveryStep(['stop_writes', 'preserve_evidence']);
        $this->assertTrue($mid['ordered']);
        $this->assertSame('identify_failure_mode', $mid['next_step']);
        $this->assertSame(3, $mid['next_step_number']);

        // All seven in order -> complete, no next step.
        $allDone = $service->nextRecoveryStep(AtlasSelfConstructionFailureModesService::RECOVERY_ORDER);
        $this->assertTrue($allDone['ordered']);
        $this->assertTrue($allDone['all_complete']);
        $this->assertNull($allDone['next_step']);

        // Out of order: resuming before evidence is preserved breaks the sequence.
        $broken = $service->nextRecoveryStep(['stop_writes', 'resume_with_smaller_receipt']);
        $this->assertFalse($broken['ordered']);
        $this->assertNull($broken['next_step']);
        $this->assertFalse($broken['all_complete']);
    }
}
