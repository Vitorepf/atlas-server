<?php

declare(strict_types=1);

namespace Tests\Unit\Ai\Aaeos\Generated;

use App\Services\Ai\Aaeos\Generated\AtlasResearchFailureModesService;
use Tests\TestCase;

/**
 * Pins the documented Atlas AI Research Self-Improvement Failure Modes rules: the
 * 10-row Failure Table, the six Stop-The-Line conditions (fail-closed), the
 * six-step ordered Recovery sequence, and the fused fail-closed decision.
 *
 * @see docs/engineering-knowledge-base/research-self-improvement/failure-modes.md
 */
class AtlasResearchFailureModesTest extends TestCase
{
    private function service(): AtlasResearchFailureModesService
    {
        return new AtlasResearchFailureModesService();
    }

    /**
     * "Failure Table" lists exactly ten modes; "Stop-The-Line Conditions" lists
     * exactly six; "Recovery" is exactly the six ordered steps; the severity
     * ladder is the closed set clean < route < stop with documented postures.
     */
    public function test_taxonomy_sizes_and_severity_ladder(): void
    {
        $this->assertCount(10, AtlasResearchFailureModesService::FAILURE_TABLE);
        $this->assertCount(6, AtlasResearchFailureModesService::STOP_THE_LINE_CONDITIONS);

        // Recovery is the six documented steps, verbatim and in order.
        $this->assertSame(
            [
                'Preserve raw evidence.',
                'Mark invalid packet or proposal.',
                'Identify contaminated docs/memory/code.',
                'Roll back or supersede.',
                'Add guardrail/test.',
                'Record Self-Improvement finding.',
            ],
            AtlasResearchFailureModesService::RECOVERY_SEQUENCE,
        );

        $this->assertSame(['clean', 'route', 'stop'], AtlasResearchFailureModesService::SEVERITY_ORDER);
        $this->assertSame('proceed', AtlasResearchFailureModesService::SEVERITY_POSTURE['clean']);
        $this->assertSame('route_through_gate', AtlasResearchFailureModesService::SEVERITY_POSTURE['route']);
        $this->assertSame('stop_the_line', AtlasResearchFailureModesService::SEVERITY_POSTURE['stop']);
    }

    /**
     * Severity is derived from the documented required response: a hallucinated
     * source STOPS (fails closed, withholds promotion) — the frontmatter calls it
     * a stop-the-line condition; a hype-driven release only ROUTES through a
     * stronger gate (does not stop). The exact documented response strings are
     * pinned.
     */
    public function test_mode_severity_follows_required_response(): void
    {
        $service = $this->service();

        $hallucination = $service->describeMode('hallucinated_source');
        $this->assertSame('stop', $hallucination['severity']);
        $this->assertSame('stop_the_line', $hallucination['posture']);
        $this->assertTrue($hallucination['withhold_promotion']);
        $this->assertTrue($hallucination['fails_closed']);
        $this->assertSame('Stop, mark packet invalid, require source verification.', $hallucination['required_response']);
        $this->assertSame('False truth enters Atlas', $hallucination['risk']);

        $hype = $service->describeMode('hype_driven_release');
        $this->assertSame('route', $hype['severity']);
        $this->assertFalse($hype['withhold_promotion']); // routed, not stopped
        $this->assertFalse($hype['fails_closed']);

        // The five fail-closed modes from the table.
        $this->assertCount(5, AtlasResearchFailureModesService::STOP_MODES);
        $this->assertNull($service->describeMode('not_a_real_mode'));
    }

    /**
     * The fail-closed Stop-The-Line gate: an invented citation alone forces a
     * stop; an empty set passes; unknown conditions are surfaced (never silently
     * treated as a pass), and the documented description text is returned.
     */
    public function test_stop_the_line_gate_fails_closed(): void
    {
        $service = $this->service();

        $invented = $service->evaluateStopTheLine(['invented_citation']);
        $this->assertTrue($invented['stop']);
        $this->assertSame(['invented_citation'], $invented['triggered']);
        $this->assertContains('invented citation', $invented['descriptions']);

        $empty = $service->evaluateStopTheLine([]);
        $this->assertFalse($empty['stop']);

        $unknown = $service->evaluateStopTheLine(['made_up_trigger']);
        $this->assertFalse($unknown['stop']);
        $this->assertSame(['made_up_trigger'], $unknown['unknown_conditions']);
    }

    /**
     * classify() returns the SINGLE WORST active severity: a `stop` evaluation-
     * missing mode is never masked by a `route` secondary-source mode. Promotion
     * is withheld; both required responses are surfaced.
     */
    public function test_classify_returns_worst_severity_and_withholds_promotion(): void
    {
        $verdict = $this->service()->classify([
            'secondary_source_treated_primary', // route
            'evaluation_missing',               // stop — must dominate
        ]);

        $this->assertSame('stop', $verdict['severity']);
        $this->assertSame('stop_the_line', $verdict['posture']);
        $this->assertTrue($verdict['withhold_promotion']);
        $this->assertFalse($verdict['can_promote']);
        $this->assertSame(['evaluation_missing'], $verdict['stop_modes']);
        $this->assertContains('Hold promotion.', $verdict['required_responses']);
        $this->assertContains('Downgrade tier, require primary source.', $verdict['required_responses']);
    }

    /**
     * decide() fuses both surfaces and fails closed if EITHER demands a stop. A
     * route-only failure mode combined with a stop-the-line trigger STILL fails
     * closed (the gate dominates) and attaches the full six-step recovery. A
     * fully-clean packet promotes with no recovery attached.
     */
    public function test_decide_fuses_table_and_stop_the_line(): void
    {
        $service = $this->service();

        // Route-level mode, but a stop-the-line trigger present -> fail closed.
        $fused = $service->decide(
            ['hype_driven_release'],
            ['runtime_change_from_research_only'],
        );
        $this->assertTrue($fused['fail_closed']);
        $this->assertFalse($fused['can_promote']);
        $this->assertSame('stop', $fused['severity']);
        $this->assertCount(6, $fused['recovery']);
        $this->assertSame('Preserve raw evidence.', $fused['recovery'][0]);

        // Nothing wrong at all -> promote, no recovery.
        $clean = $service->decide([], []);
        $this->assertFalse($clean['fail_closed']);
        $this->assertTrue($clean['can_promote']);
        $this->assertSame('clean', $clean['severity']);
        $this->assertSame([], $clean['recovery']);
    }
}
