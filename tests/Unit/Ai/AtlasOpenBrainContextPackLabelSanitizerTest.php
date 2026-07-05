<?php

declare(strict_types=1);

namespace Tests\Unit\Ai;

use App\Services\Ai\AtlasOpenBrainContextPackService;
use PHPUnit\Framework\TestCase;

/**
 * The reality-graph section treats node labels as UNTRUSTED display text: a session-capture mission
 * node seeds its label from the first user prompt, so a past (possibly hostile / instruction-shaped)
 * prompt could otherwise be replayed into the model context. These two pure guards neutralize the
 * label and drop pure session-echo paths. Wiper-safe: pure static logic, zero DB, zero bootstrap.
 */
final class AtlasOpenBrainContextPackLabelSanitizerTest extends TestCase
{
    public function test_sanitize_collapses_multiline_prompt_text_to_a_single_capped_line(): void
    {
        $raw = "Que merda é que você tá fazendo?\nVocê não tá entendendo nada.\n\nEram duas partes:\n1. A";
        $out = AtlasOpenBrainContextPackService::sanitizeGraphLabel($raw);

        self::assertStringNotContainsString("\n", $out, 'no newline may survive into the model context');
        self::assertSame('Que merda é que você tá fazendo? Você não tá entendendo nada. Eram duas partes: 1. A', $out);
    }

    public function test_sanitize_hard_caps_length(): void
    {
        $out = AtlasOpenBrainContextPackService::sanitizeGraphLabel(str_repeat('x', 5000));

        self::assertSame(160, mb_strlen($out));
    }

    public function test_sanitize_trims_and_is_idempotent_on_clean_labels(): void
    {
        self::assertSame('AtlasDevFastPathOrchestrator', AtlasOpenBrainContextPackService::sanitizeGraphLabel('  AtlasDevFastPathOrchestrator  '));
    }

    public function test_pure_session_echo_markers_are_flagged(): void
    {
        foreach (['session capture', 'Session Capture', '[Request interrupted by user]', '  [request interrupted by user]  '] as $noise) {
            self::assertTrue(AtlasOpenBrainContextPackService::isSessionArtifactLabel($noise), "should flag: '{$noise}'");
        }
    }

    public function test_empty_label_is_not_an_echo_a_real_node_can_have_no_label(): void
    {
        // an empty/absent label is a legitimate code/mission node (it renders by node id), so it must
        // NOT drop the path — flagging empty as echo previously discarded a real code neighbor path.
        self::assertFalse(AtlasOpenBrainContextPackService::isSessionArtifactLabel(''));
        self::assertFalse(AtlasOpenBrainContextPackService::isSessionArtifactLabel('   '));
    }

    public function test_real_architectural_labels_are_never_flagged(): void
    {
        foreach (['Application Services', 'Eloquent Models', 'AtlasDevFastPathOrchestrator', 'SovereignHonestyFloor::certify'] as $real) {
            self::assertFalse(AtlasOpenBrainContextPackService::isSessionArtifactLabel($real), "must not flag: '{$real}'");
        }
    }

    public function test_a_raw_prompt_label_is_sanitized_but_NOT_classified_as_a_dropped_artifact(): void
    {
        // honest boundary: a raw past prompt (not a fallback marker) is collapsed+capped so it can't
        // inject multiline text, but it is NOT auto-dropped — categorically excluding those needs a
        // write-back source-marker + a one-time graph prune (the operator-approved follow-up).
        self::assertFalse(AtlasOpenBrainContextPackService::isSessionArtifactLabel('vc esta mentindo para mim'));
    }
}
