<?php

declare(strict_types=1);

namespace Tests\Unit\Ai\Aaeos\Generated;

use App\Services\Ai\Aaeos\Generated\AtlasBuilderPersonaAndHandoffService;
use Tests\TestCase;

/**
 * Pins the documented Builder Persona, Required Opening Move, Long Session
 * Continuity, Handoff Packet and Tone Of Work rules.
 *
 * @see docs/engineering-knowledge-base/self-construction/builder-persona-and-handoff.md
 */
class AtlasBuilderPersonaAndHandoffTest extends TestCase
{
    private function service(): AtlasBuilderPersonaAndHandoffService
    {
        return new AtlasBuilderPersonaAndHandoffService();
    }

    /**
     * A fully-satisfied posture: all eight opening-move fields, all six
     * continuity answers, a complete and consistent 13-key handoff packet,
     * clean tone.
     *
     * @return array<string, mixed>
     */
    private function readyInput(): array
    {
        return [
            'current_goal' => 'ship runtime',
            'target_capability' => 'cap',
            'authoritative_docs' => ['docs/x.md'],
            'hot_files' => ['app/Services/Foo.php'],
            'current_git_delta' => 'one file',
            'risk' => 'low',
            'smallest_safe_slice' => 'add pure method',
            'required_validation' => 'php artisan test',
            'continuity_answers' => [
                'what_is_being_built' => 'a',
                'why_this_priority' => 'b',
                'which_docs_are_law' => 'c',
                'what_changed' => 'd',
                'what_remains_unsafe' => 'e',
                'next_smallest_step' => 'f',
            ],
            'handoff' => [
                'objective' => 'o',
                'target_capability' => 'cap',
                'maturity_before' => 0,
                'maturity_after' => 1,
                'docs_changed' => ['x.md'],
                'code_changed' => ['Foo.php'],
                'hot_files' => ['Foo.php'],
                'commands_run' => ['php artisan test'],
                'gates_passed' => ['unit'],
                'gates_failed' => [],
                'evidence' => ['tests/FooTest.php'],
                'residual_risk' => 'low',
                'next_safe_step' => 'wire it',
                'do_not_touch' => ['Kernel'],
            ],
            'summary' => 'Small read-only change; three files touched.',
        ];
    }

    /**
     * Persona is fixed: the builder is the governed architect, NEVER a generic
     * code generator. The not-this list is emitted explicitly. A fully
     * satisfied posture => status=ready, edits allowed, handoff valid.
     */
    public function test_persona_is_governed_architect_and_full_posture_is_ready(): void
    {
        $r = $this->service()->certifyHandoff($this->readyInput());

        $this->assertSame('governed_architect', $r['persona']);
        $this->assertSame(AtlasBuilderPersonaAndHandoffService::PERSONA, $r['decision']['persona']);
        $this->assertContains('generic_code_generator', $r['decision']['persona_not_this']);

        $this->assertSame(AtlasBuilderPersonaAndHandoffService::STATUS_READY, $r['status']);
        $this->assertTrue($r['edits_allowed']);
        $this->assertTrue($r['handoff_valid']);
        $this->assertSame(
            AtlasBuilderPersonaAndHandoffService::NEXT_PROCEED_SMALL_SLICE,
            $r['decision']['required_next_action'],
        );

        // Read-only guarantees are always present.
        $this->assertContains('certifier_does_not_perform_edits', $r['non_execution_guarantees']);
        $this->assertContains('certifier_does_not_promote_maturity', $r['non_execution_guarantees']);
        $this->assertSame(AtlasBuilderPersonaAndHandoffService::SCHEMA, $r['schema_version']);
    }

    /**
     * Required Opening Move: ALL eight fields are mandatory. Drop one
     * (smallest_safe_slice) => edits are NOT allowed and the builder must
     * complete the opening move first; the missing field is named.
     */
    public function test_missing_opening_move_field_blocks_edits(): void
    {
        $input = $this->readyInput();
        unset($input['smallest_safe_slice']);

        $r = $this->service()->certifyHandoff($input);

        $this->assertSame(AtlasBuilderPersonaAndHandoffService::STATUS_OPENING_MOVE_INCOMPLETE, $r['status']);
        $this->assertFalse($r['edits_allowed']);
        $this->assertFalse($r['decision']['opening_move']['complete']);
        $this->assertContains('smallest_safe_slice', $r['decision']['opening_move']['missing']);
        $this->assertSame(
            AtlasBuilderPersonaAndHandoffService::NEXT_COMPLETE_OPENING_MOVE,
            $r['decision']['required_next_action'],
        );

        // Opening move is checked even when the handoff packet itself is fine.
        $this->assertCount(8, AtlasBuilderPersonaAndHandoffService::OPENING_MOVE_FIELDS);
    }

    /**
     * Long Session Continuity: if any of the six resume questions is
     * unanswered, "context reconstruction must happen before edits" — edits
     * blocked, status flips to context reconstruction required.
     */
    public function test_unanswered_continuity_question_forces_context_reconstruction(): void
    {
        $input = $this->readyInput();
        unset($input['continuity_answers']['what_remains_unsafe']);

        $r = $this->service()->certifyHandoff($input);

        $this->assertSame(
            AtlasBuilderPersonaAndHandoffService::STATUS_CONTEXT_RECONSTRUCTION_REQUIRED,
            $r['status'],
        );
        $this->assertFalse($r['edits_allowed']);
        $this->assertFalse($r['decision']['continuity']['answerable']);
        $this->assertContains('what_remains_unsafe', $r['decision']['continuity']['unanswered']);
        $this->assertSame(
            AtlasBuilderPersonaAndHandoffService::NEXT_RECONSTRUCT_CONTEXT,
            $r['decision']['required_next_action'],
        );
    }

    /**
     * Handoff Packet: all thirteen documented keys are mandatory. Drop one
     * (do_not_touch) on a low-risk packet => packet incomplete, handoff
     * invalid, the missing key is named. Edits stay allowed because the
     * opening move + continuity are fine, but the packet must be repaired.
     */
    public function test_missing_handoff_key_invalidates_packet(): void
    {
        $input = $this->readyInput();
        unset($input['handoff']['do_not_touch']);

        $r = $this->service()->certifyHandoff($input);

        $this->assertCount(14, AtlasBuilderPersonaAndHandoffService::HANDOFF_KEYS);
        $this->assertFalse($r['handoff_valid']);
        $this->assertFalse($r['decision']['handoff']['complete']);
        $this->assertContains('do_not_touch', $r['decision']['handoff']['missing_keys']);
        $this->assertSame(AtlasBuilderPersonaAndHandoffService::STATUS_HANDOFF_INVALID, $r['status']);
        $this->assertSame(
            AtlasBuilderPersonaAndHandoffService::NEXT_REPAIR_HANDOFF,
            $r['decision']['required_next_action'],
        );
    }

    /**
     * "Handoff must preserve state, constraints, files, gates, risks and next
     * action." Three semantic violations are enforced beyond key presence:
     *   (1) maturity advanced (0 -> 1) with EMPTY evidence => claim w/o evidence;
     *   (2) the same gate id in gates_passed AND gates_failed => contradiction;
     *   (3) high residual risk with empty do_not_touch => constraints not kept.
     */
    public function test_handoff_semantic_violations_are_caught(): void
    {
        // (1) maturity claim without evidence
        $a = $this->readyInput();
        $a['handoff']['evidence'] = [];
        $ra = $this->service()->certifyHandoff($a);
        $this->assertFalse($ra['handoff_valid']);
        $this->assertContains('maturity_claim_without_evidence', $ra['decision']['handoff']['violations']);

        // (2) same gate passed and failed
        $b = $this->readyInput();
        $b['handoff']['gates_passed'] = ['unit', 'docs_health'];
        $b['handoff']['gates_failed'] = ['docs_health'];
        $rb = $this->service()->certifyHandoff($b);
        $this->assertFalse($rb['handoff_valid']);
        $this->assertContains('gate_reported_passed_and_failed:docs_health', $rb['decision']['handoff']['violations']);

        // (3) high risk without do_not_touch boundary
        $c = $this->readyInput();
        $c['handoff']['residual_risk'] = 'high';
        $c['handoff']['do_not_touch'] = [];
        $rc = $this->service()->certifyHandoff($c);
        $this->assertFalse($rc['handoff_valid']);
        $this->assertContains('high_risk_without_do_not_touch_boundary', $rc['decision']['handoff']['violations']);
    }

    /**
     * Tone Of Work: avoid grand claims / vague "enterprise" language without
     * gates. A summary containing a banned phrase makes tone.clean=false and
     * adds a handoff violation, so an otherwise-complete packet is invalid.
     */
    public function test_tone_violation_in_summary_invalidates_handoff(): void
    {
        $input = $this->readyInput();
        $input['summary'] = 'Delivered an enterprise-grade, world-class platform.';

        $r = $this->service()->certifyHandoff($input);

        $this->assertFalse($r['decision']['tone']['clean']);
        $this->assertContains('enterprise-grade', $r['decision']['tone']['banned_phrases']);
        $this->assertContains('world-class', $r['decision']['tone']['banned_phrases']);
        $this->assertContains('tone_violation_vague_or_grand_claim', $r['decision']['handoff']['violations']);
        $this->assertFalse($r['handoff_valid']);
    }

    /**
     * Determinism: identical input yields an identical decision_hash.
     */
    public function test_decision_hash_is_deterministic(): void
    {
        $a = $this->service()->certifyHandoff($this->readyInput());
        $b = $this->service()->certifyHandoff($this->readyInput());

        $this->assertSame($a['decision_hash'], $b['decision_hash']);
        $this->assertStringStartsWith('sha256:', $a['decision_hash']);
    }
}
