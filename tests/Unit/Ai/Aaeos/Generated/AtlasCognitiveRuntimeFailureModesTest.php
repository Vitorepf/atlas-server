<?php

declare(strict_types=1);

namespace Tests\Unit\Ai\Aaeos\Generated;

use App\Services\Ai\Aaeos\Generated\AtlasCognitiveRuntimeFailureModesService;
use Tests\TestCase;

/**
 * Pins the documented Atlas AI Cognitive Runtime Failure Modes rules: the
 * 15-row Failure Matrix, the four-level Severity ladder + postures, and the
 * Recovery Rules.
 *
 * @see docs/engineering-knowledge-base/cognitive-runtime/failure-modes.md
 */
class AtlasCognitiveRuntimeFailureModesTest extends TestCase
{
    private function service(): AtlasCognitiveRuntimeFailureModesService
    {
        return new AtlasCognitiveRuntimeFailureModesService();
    }

    /**
     * "Failure Matrix" lists exactly fifteen modes; "Severity" lists exactly the
     * four ordered levels info < watch < blocked < critical, each with a posture.
     */
    public function test_matrix_and_severity_ladder_taxonomy(): void
    {
        $this->assertCount(15, AtlasCognitiveRuntimeFailureModesService::FAILURE_MATRIX);

        $this->assertSame(
            ['info', 'watch', 'blocked', 'critical'],
            AtlasCognitiveRuntimeFailureModesService::SEVERITY_ORDER,
        );

        // Documented runtime postures (Severity table).
        $this->assertSame('log', AtlasCognitiveRuntimeFailureModesService::SEVERITY_POSTURE['info']);
        $this->assertSame('continue_with_warning', AtlasCognitiveRuntimeFailureModesService::SEVERITY_POSTURE['watch']);
        $this->assertSame('stop_before_execution', AtlasCognitiveRuntimeFailureModesService::SEVERITY_POSTURE['blocked']);
        $this->assertSame('stop_audit_repair', AtlasCognitiveRuntimeFailureModesService::SEVERITY_POSTURE['critical']);

        // The three modes whose required response is explicitly "critical; ...".
        $this->assertCount(3, AtlasCognitiveRuntimeFailureModesService::CRITICAL_MODES);
    }

    /**
     * Each documented mode resolves to the severity its required-response column
     * implies: privacy_leak / raw_capture_admitted / critical_context_missed are
     * critical (stop+audit); missing_evidence_refs blocks (stop, no audit);
     * the cost/efficiency modes only watch (may proceed).
     */
    public function test_mode_severity_assignment_follows_required_response(): void
    {
        $service = $this->service();

        $privacy = $service->describeMode('privacy_leak');
        $this->assertSame('critical', $privacy['severity']);
        $this->assertSame('stop_audit_repair', $privacy['posture']);
        $this->assertTrue($privacy['withholds_execution']);
        $this->assertTrue($privacy['requires_audit']);

        $missingRefs = $service->describeMode('missing_evidence_refs');
        $this->assertSame('blocked', $missingRefs['severity']);
        $this->assertTrue($missingRefs['withholds_execution']); // safe failure > contaminated continuity
        $this->assertFalse($missingRefs['requires_audit']);     // blocked stops, does not force audit

        $cost = $service->describeMode('cost_without_gain');
        $this->assertSame('watch', $cost['severity']);
        $this->assertFalse($cost['withholds_execution']);       // quality degrades, continuity is safe
        $this->assertSame('continue_with_warning', $cost['posture']);
    }

    /**
     * "Recovery Rules": each rule is keyed to its failure condition. A privacy
     * issue redacts+tombstones+audits; hot-file ambiguity demands git status +
     * owner report; lost decisions use ledger/receipt refs, not chat memory.
     */
    public function test_recovery_rules_map_to_documented_actions(): void
    {
        $service = $this->service();

        $this->assertSame(
            'redact, tombstone the unsafe packet, emit an audit',
            $service->recoveryFor('privacy_leak'),
        );
        $this->assertSame(
            'require git status --short and an owner report',
            $service->recoveryFor('hot_file_ambiguity'),
        );
        $this->assertSame(
            'use ledger/receipt refs, not chat memory',
            $service->recoveryFor('compaction_requires_chat'),
        );

        $this->assertNull($service->recoveryFor('not_a_real_mode'));
    }

    /**
     * classify() returns the SINGLE WORST active severity: a critical privacy
     * leak is never masked by a watch-level cost signal. Execution is withheld
     * and an audit is required; both modes' recovery actions are surfaced.
     */
    public function test_classify_returns_worst_severity_and_withholds_execution(): void
    {
        $verdict = $this->service()->classify([
            'cost_without_gain',  // watch
            'privacy_leak',       // critical — must dominate
        ]);

        $this->assertSame('critical', $verdict['severity']);
        $this->assertSame('stop_audit_repair', $verdict['posture']);
        $this->assertTrue($verdict['withhold_execution']);
        $this->assertFalse($verdict['can_proceed']);
        $this->assertTrue($verdict['requires_audit']);
        $this->assertSame(['privacy_leak'], $verdict['critical_modes']);
        $this->assertContains('redact, tombstone the unsafe packet, emit an audit', $verdict['recovery_actions']);
    }

    /**
     * A blocked-only set stops execution but does NOT force an audit (only
     * critical does). An empty set is a clean session: info / log / proceed.
     * Unknown keys are reported, never silently treated as safe.
     */
    public function test_classify_blocked_vs_clean_vs_unknown(): void
    {
        $service = $this->service();

        $blocked = $service->classify(['lost_objective', 'handoff_without_receipt']);
        $this->assertSame('blocked', $blocked['severity']);
        $this->assertTrue($blocked['withhold_execution']);
        $this->assertFalse($blocked['requires_audit']);
        $this->assertSame([], $blocked['critical_modes']);

        $clean = $service->classify([]);
        $this->assertSame('info', $clean['severity']);
        $this->assertSame('log', $clean['posture']);
        $this->assertTrue($clean['can_proceed']);
        $this->assertFalse($clean['withhold_execution']);

        $unknown = $service->classify(['totally_made_up', 'stale_canonical_doc']);
        $this->assertSame('watch', $unknown['severity']); // only the known watch mode counts
        $this->assertSame(['totally_made_up'], $unknown['unknown_modes']);
        $this->assertSame(['stale_canonical_doc'], $unknown['active_modes']);
    }
}
