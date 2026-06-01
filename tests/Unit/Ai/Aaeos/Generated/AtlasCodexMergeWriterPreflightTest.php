<?php

declare(strict_types=1);

namespace Tests\Unit\Ai\Aaeos\Generated;

use App\Services\Ai\Aaeos\Generated\AtlasCodexMergeWriterPreflightService;
use Tests\TestCase;

/**
 * Pins the documented Codex Merge Post-Execution Action Persistence Writer
 * Preflight: boundary, the single readiness rule (payload-template-ready), the
 * standing-blocker report (unproven capabilities + unmet release conditions),
 * the blocked/ready statuses and the "consideration is never authorization"
 * invariant.
 *
 * @see docs/engineering-knowledge-base/self-construction/codex-merge-post-execution-action-persistence-writer-preflight.md
 */
class AtlasCodexMergeWriterPreflightTest extends TestCase
{
    private function service(): AtlasCodexMergeWriterPreflightService
    {
        return new AtlasCodexMergeWriterPreflightService();
    }

    /**
     * Every documented required capability proven present.
     *
     * @return array<string,true>
     */
    private function allCapabilitiesProven(): array
    {
        $out = [];
        foreach (AtlasCodexMergeWriterPreflightService::REQUIRED_CAPABILITIES as $cap) {
            $out[$cap] = true;
        }

        return $out;
    }

    /**
     * Every documented future-release condition met.
     *
     * @return array<string,true>
     */
    private function allReleaseConditionsMet(): array
    {
        $out = [];
        foreach (AtlasCodexMergeWriterPreflightService::RELEASE_CONDITIONS as $cond) {
            $out[$cond] = true;
        }

        return $out;
    }

    /**
     * A fully-unblocked input: payload ready, all capabilities proven, all
     * release conditions met.
     *
     * @return array<string,mixed>
     */
    private function fullyUnblocked(): array
    {
        return [
            'payload_template_ready' => true,
            'capabilities_proven' => $this->allCapabilitiesProven(),
            'release_conditions' => $this->allReleaseConditionsMet(),
        ];
    }

    /**
     * Doc "Boundary" + "Readiness Rule": empty input keeps all eight boundary
     * keys false, the status is BLOCKED (payload template not ready), and the
     * readiness gate plus all seven capabilities and six release conditions are
     * reported as standing blockers (7 + 6 + 1 = 14), while the boundary holds.
     */
    public function test_empty_input_is_blocked_and_lists_every_documented_blocker(): void
    {
        $r = $this->service()->preflight([]);

        // Boundary intact.
        foreach (AtlasCodexMergeWriterPreflightService::BOUNDARY_KEYS as $key) {
            $this->assertArrayHasKey($key, $r['required_capabilities']['boundary']);
            $this->assertFalse($r['required_capabilities']['boundary'][$key], "boundary $key must be false");
        }
        $this->assertCount(8, AtlasCodexMergeWriterPreflightService::BOUNDARY_KEYS);
        $this->assertTrue($r['boundary_held']);
        $this->assertSame([], $r['boundary_violations']);

        // Readiness Rule: not ready, status blocked.
        $this->assertFalse($r['ready']);
        $this->assertFalse($r['payload_template_ready']);
        $this->assertSame(
            AtlasCodexMergeWriterPreflightService::STATUS_BLOCKED,
            $r['status'],
        );

        // 1 readiness gate + 7 capabilities + 6 release conditions = 14 blockers.
        $this->assertCount(14, $r['standing_blockers']);
        $this->assertSame(14, $r['blocker_count']);
        $this->assertContains(
            'readiness_gate_unmet:'.AtlasCodexMergeWriterPreflightService::READINESS_GATE,
            $r['standing_blockers'],
        );
        $this->assertContains('capability_unproven:append_only_ledger_write_only_behavior', $r['standing_blockers']);
        $this->assertContains('release_condition_unmet:writer_surface_implemented', $r['standing_blockers']);

        // Consideration blocked; authorization unconditionally false.
        $this->assertFalse($r['writer_may_be_considered']);
        $this->assertFalse($r['writer_authorized']);
    }

    /**
     * Doc "Readiness Rule": ready depends SOLELY on the payload template. With the
     * payload template ready but capabilities/conditions still missing, status
     * flips to READY (blocker reporting complete) — yet the writer still may NOT
     * be considered and is NOT authorized, and the remaining blockers persist.
     */
    public function test_payload_template_ready_makes_status_ready_but_not_authorized(): void
    {
        $r = $this->service()->preflight(['payload_template_ready' => true]);

        $this->assertTrue($r['ready']);
        $this->assertSame(
            AtlasCodexMergeWriterPreflightService::STATUS_READY,
            $r['status'],
        );
        // The readiness gate blocker is gone, but the 7+6 others remain.
        $this->assertNotContains(
            'readiness_gate_unmet:'.AtlasCodexMergeWriterPreflightService::READINESS_GATE,
            $r['standing_blockers'],
        );
        $this->assertCount(13, $r['standing_blockers']);

        // "Ready means blocker reporting is complete. It does not authorize a writer."
        $this->assertFalse($r['writer_may_be_considered']);
        $this->assertFalse($r['writer_authorized']);
        $this->assertTrue($r['boundary_held']);
    }

    /**
     * Doc "Writer Capabilities Required": the seven named behaviours, in order,
     * and the preflight implements none of them. A single unproven capability is
     * surfaced by exact name as a standing blocker.
     */
    public function test_required_capabilities_are_the_seven_documented_and_unproven_is_named(): void
    {
        $cap = $this->service()->requiredCapabilities();

        $this->assertSame([
            'append_only_ledger_write_only_behavior',
            'payload_hash_recomputation',
            'source_hash_match_enforcement',
            'hot_scope_recheck_enforcement',
            'human_confirmation_hash_enforcement',
            'no_merge_authority',
            'no_dispatch_authority',
        ], $cap['required_capabilities']);
        $this->assertSame(7, $cap['count']);
        $this->assertFalse($cap['implements_writer']);

        // Prove all but one capability; the missing one is the only blocker named.
        $proven = $this->allCapabilitiesProven();
        unset($proven['hot_scope_recheck_enforcement']);
        $r = $this->service()->preflight([
            'payload_template_ready' => true,
            'capabilities_proven' => $proven,
            'release_conditions' => $this->allReleaseConditionsMet(),
        ]);

        $this->assertSame(['hot_scope_recheck_enforcement'], $r['required_capabilities']['unproven_capabilities']);
        $this->assertContains('capability_unproven:hot_scope_recheck_enforcement', $r['standing_blockers']);
        $this->assertCount(1, $r['standing_blockers']);
        // One capability short => still not considerable.
        $this->assertFalse($r['writer_may_be_considered']);
    }

    /**
     * Doc "Future Release Conditions": exactly the six documented conditions; a
     * single unmet one (here the preflight-hash binding) keeps consideration off
     * and is surfaced by its exact documented name.
     */
    public function test_release_conditions_are_the_six_documented_and_unmet_blocks_consideration(): void
    {
        $this->assertSame([
            'writer_surface_implemented',
            'writer_surface_separately_authorized',
            'all_payload_fields_non_null',
            'all_writer_contract_capabilities_present',
            'all_blocking_conditions_resolved',
            'writer_preflight_hash_bound_to_writer_contract',
        ], AtlasCodexMergeWriterPreflightService::RELEASE_CONDITIONS);

        $conditions = $this->allReleaseConditionsMet();
        unset($conditions['writer_preflight_hash_bound_to_writer_contract']);

        $r = $this->service()->preflight([
            'payload_template_ready' => true,
            'capabilities_proven' => $this->allCapabilitiesProven(),
            'release_conditions' => $conditions,
        ]);

        $this->assertSame(
            ['writer_preflight_hash_bound_to_writer_contract'],
            $r['release_conditions']['unmet_conditions'],
        );
        $this->assertContains(
            'release_condition_unmet:writer_preflight_hash_bound_to_writer_contract',
            $r['standing_blockers'],
        );
        $this->assertFalse($r['writer_may_be_considered']);
    }

    /**
     * Doc full preflight: payload ready AND every capability proven AND every
     * release condition met => zero standing blockers and the writer MAY be
     * considered — yet per "Human Meaning" it is STILL not authorized and the
     * boundary still holds (consideration is never authorization or invocation).
     */
    public function test_fully_unblocked_allows_consideration_but_never_authorization(): void
    {
        $r = $this->service()->preflight($this->fullyUnblocked());

        $this->assertSame([], $r['standing_blockers']);
        $this->assertSame(0, $r['blocker_count']);
        $this->assertTrue($r['ready']);
        $this->assertSame(AtlasCodexMergeWriterPreflightService::STATUS_READY, $r['status']);
        $this->assertTrue($r['writer_may_be_considered']);

        // The sacred line: never authorization, boundary intact, no writer.
        $this->assertFalse($r['writer_authorized']);
        $this->assertTrue($r['boundary_held']);
        $this->assertSame([], $r['boundary_violations']);
        $this->assertFalse($r['required_capabilities']['implements_writer']);

        // Doc "Human Meaning": the question it answers vs the one it refuses.
        $this->assertSame(
            'What still blocks a real append-only persistence writer?',
            $r['human_question'],
        );
        $this->assertSame(
            'Should the writer be released or invoked now?',
            $r['does_not_answer'],
        );
    }

    /**
     * Guard: assertBoundaryHeld must actually catch a flipped key (proves the
     * boundary check is real, not vacuous), and a flipped boundary on any surface
     * is reported as a standing blocker that kills consideration.
     */
    public function test_boundary_assertion_catches_a_flipped_key(): void
    {
        $svc = $this->service();
        $tampered = [
            'surface' => 'tampered',
            'boundary' => ['ledger_write_allowed' => true] + $svc->boundary(),
        ];

        $violations = $svc->assertBoundaryHeld([$tampered]);

        $this->assertContains('tampered.ledger_write_allowed', $violations);
    }
}
