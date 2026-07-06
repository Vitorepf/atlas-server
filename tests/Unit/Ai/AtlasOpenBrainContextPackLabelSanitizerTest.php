<?php

declare(strict_types=1);

namespace Tests\Unit\Ai;

use App\Services\Ai\AtlasOpenBrainContextPackService;
use App\Services\Ai\AtlasOpenBrainWriteBackService;
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

    public function test_raw_session_prompt_labels_are_dropped_as_low_quality_context(): void
    {
        foreach ([
            'Que merda é que você tá fazendo?',
            'tem um codex rodando , e aparentemente funcionando',
            'precisamos fazer uma limpa mantendo so os de qualidade',
            'Você não entendeu qual é a função do que você tá fazendo.',
            'My request for Codex: faca uma analise disso',
            'Continue from where you left off.',
            'me fala mais , teoricamente Loop / ACDE ja era para fazer isso , pelo o que entendi e basicamente pegar uma area e evoluir ela',
            'vc pode usar o hermes com o verboo qwen. preciso que me entregue o rivals',
        ] as $noise) {
            self::assertTrue(AtlasOpenBrainContextPackService::isSessionArtifactLabel($noise), "should drop: '{$noise}'");
        }
    }

    public function test_session_echo_path_is_dropped_when_target_or_chain_contains_prompt_echo(): void
    {
        self::assertTrue(AtlasOpenBrainContextPackService::isSessionArtifactPath(
            ['target' => 'Continue from where you left off.', 'seed' => 'code:module:app'],
            [['label' => 'Application Services']]
        ));

        self::assertTrue(AtlasOpenBrainContextPackService::isSessionArtifactPath(
            ['target' => 'mission:uuid', 'seed' => 'code:module:app'],
            [['label' => 'Que merda é que você tá fazendo?']]
        ));

        self::assertFalse(AtlasOpenBrainContextPackService::isSessionArtifactPath(
            ['target' => 'domain:engineering', 'seed' => 'code:module:app'],
            [['label' => 'Application Services'], ['label' => 'Software Engineering']]
        ));
    }

    public function test_session_capture_origin_drops_the_path_by_provenance_even_with_innocuous_label(): void
    {
        // A raw prompt that no text heuristic recognizes ("vc esta mentindo..." leaked
        // on 06/07) must still be dropped: provenance beats heuristics.
        self::assertTrue(AtlasOpenBrainContextPackService::isSessionArtifactPath(
            ['target' => 'mission:uuid', 'seed' => 'code:module:app'],
            [['label' => 'vc esta mentindo para mim e nao esta rodando ciclo a ciclo', 'origin' => AtlasOpenBrainWriteBackService::MISSION_ORIGIN_SESSION_CAPTURE]]
        ));

        // The same origin on a clean-looking label is still session echo.
        self::assertTrue(AtlasOpenBrainContextPackService::isSessionArtifactPath(
            ['target' => 'mission:uuid', 'seed' => 'code:module:app'],
            [['label' => 'Application Services', 'origin' => AtlasOpenBrainWriteBackService::MISSION_ORIGIN_SESSION_CAPTURE]]
        ));

        // A node without the origin marker keeps the existing behavior.
        self::assertFalse(AtlasOpenBrainContextPackService::isSessionArtifactPath(
            ['target' => 'domain:engineering', 'seed' => 'code:module:app'],
            [['label' => 'Application Services', 'origin' => '']]
        ));
    }

    public function test_documentation_mission_paths_are_dropped_for_non_documentation_tasks(): void
    {
        $path = ['target' => 'mission:mission:docs-canonical-cleanup-aaeos-2026-07-05'];
        $chain = [
            ['label' => 'Engineering Knowledge Docs', 'source_kind' => 'code'],
            ['label' => 'Atualizar docs canonicas stale apos limpeza bruta AAEOS', 'source_kind' => 'mission'],
        ];

        self::assertTrue(AtlasOpenBrainContextPackService::isDocumentationMissionPath(
            'corrigir bug no context pack de Open Brain MCP runtime stale',
            $path,
            $chain
        ));

        self::assertFalse(AtlasOpenBrainContextPackService::isDocumentationMissionPath(
            'atualizar docs canonicas do AOBG',
            $path,
            $chain
        ));
    }
}
