<?php

declare(strict_types=1);

namespace Tests\Unit\Ai\Aaeos\Generated;

use App\Services\Ai\Aaeos\Generated\AtlasAiCliMultimodalService;
use Tests\TestCase;

/**
 * Pins the canonical rules of the Atlas AI CLI Multimodal contract.
 *
 * @see docs/engineering-knowledge-base/atlas-ai-cli-multimodal.md
 */
class AtlasAiCliMultimodalTest extends TestCase
{
    private function service(): AtlasAiCliMultimodalService
    {
        return new AtlasAiCliMultimodalService();
    }

    /**
     * Doc "Comportamento Esperado": Cmd+V is remapped to 0x16, so the same
     * control byte as Ctrl+V captures the clipboard and adds a pending image;
     * /paste-image does the same but is keybind-independent; /image attaches a
     * path and requires validation; an unknown control byte is not recognized.
     */
    public function test_dispatch_table_matches_documented_behavior(): void
    {
        $service = $this->service();

        $ctrlV = $service->dispatchInput([
            'kind' => 'control_byte',
            'control_byte' => AtlasAiCliMultimodalService::PASTE_CONTROL_BYTE,
        ]);
        $this->assertSame(0x16, AtlasAiCliMultimodalService::PASTE_CONTROL_BYTE);
        $this->assertTrue($ctrlV['recognized']);
        $this->assertSame('capture_clipboard', $ctrlV['action']);
        $this->assertFalse($ctrlV['keybind_independent']);

        $pasteImage = $service->dispatchInput(['kind' => 'slash', 'command' => '/paste-image']);
        $this->assertSame('capture_clipboard', $pasteImage['action']);
        $this->assertTrue($pasteImage['keybind_independent']);

        $image = $service->dispatchInput(['kind' => 'slash', 'command' => '/image', 'argument' => 'a.png']);
        $this->assertSame('attach_path', $image['action']);
        $this->assertTrue($image['requires_path_validation']);

        $openImage = $service->dispatchInput(['kind' => 'slash', 'command' => '/open-image', 'argument' => '2']);
        $this->assertSame('open_attachment', $openImage['action']);

        $unknown = $service->dispatchInput(['kind' => 'control_byte', 'control_byte' => 0x01]);
        $this->assertFalse($unknown['recognized']);
    }

    /**
     * Doc "Comportamento Esperado" bracketed paste: an EMPTY bracketed paste
     * with an image-only clipboard captures only when the implementation
     * supports it; a bracketed paste carrying a path attaches the file and
     * requires path validation.
     */
    public function test_bracketed_paste_image_only_requires_capture_support(): void
    {
        $service = $this->service();

        $supported = $service->dispatchInput([
            'kind' => 'bracketed_paste',
            'bracketed_payload' => '',
            'clipboard_has_image' => true,
            'image_capture_supported' => true,
        ]);
        $this->assertSame('capture_clipboard', $supported['action']);

        $unsupported = $service->dispatchInput([
            'kind' => 'bracketed_paste',
            'bracketed_payload' => '',
            'clipboard_has_image' => true,
            'image_capture_supported' => false,
        ]);
        $this->assertSame('noop', $unsupported['action']);

        $withPath = $service->dispatchInput([
            'kind' => 'bracketed_paste',
            'bracketed_payload' => '/ws/shot.png',
        ]);
        $this->assertSame('attach_path', $withPath['action']);
        $this->assertTrue($withPath['requires_path_validation']);
    }

    /**
     * Doc "UI De Terminal": labels read "[imagem 1, imagem 2]" (1-based); with
     * OSC 8 each item carries a hyperlink; without OSC 8 (Apple Terminal.app)
     * no link is emitted but "/open-image N" stays the documented fallback.
     */
    public function test_prompt_label_and_osc8_fallback(): void
    {
        $service = $this->service();

        $linked = $service->renderPromptLabel(
            [['path' => '/ws/a.png'], ['path' => '/ws/b.png']],
            true,
        );
        $this->assertSame('[imagem 1, imagem 2]', $linked['label']);
        $this->assertTrue($linked['osc8']);
        $this->assertNotNull($linked['items'][0]['osc8_link']);
        $this->assertStringContainsString('file:///ws/a.png', (string) $linked['items'][0]['osc8_link']);
        $this->assertSame('/open-image 1', $linked['items'][0]['open_command']);

        $appleTerminal = $service->renderPromptLabel([['path' => '/ws/a.png']], false);
        $this->assertSame('[imagem 1]', $appleTerminal['label']);
        $this->assertFalse($appleTerminal['osc8']);
        $this->assertNull($appleTerminal['items'][0]['osc8_link']);
        $this->assertSame('/open-image N', $appleTerminal['fallback_command']);

        $this->assertSame('', $service->renderPromptLabel([], true)['label']);
    }

    /**
     * Doc "Safety": attached paths must respect allowed roots AND permitted
     * MIME; traversal and out-of-root paths are rejected with a clear reason.
     */
    public function test_attachment_path_safety_enforces_roots_and_mime(): void
    {
        $service = $this->service();

        $this->assertTrue(
            $service->evaluateAttachmentPath('/ws/a.png', 'image/png', ['/ws'])['safe'],
        );

        $outside = $service->evaluateAttachmentPath('/etc/passwd', 'image/png', ['/ws']);
        $this->assertFalse($outside['safe']);
        $this->assertSame('outside_allowed_roots', $outside['reason']);

        $badMime = $service->evaluateAttachmentPath('/ws/a.txt', 'text/plain', ['/ws']);
        $this->assertFalse($badMime['safe']);
        $this->assertSame('mime_not_permitted', $badMime['reason']);

        $traversal = $service->evaluateAttachmentPath('/ws/../etc/x.png', 'image/png', ['/ws']);
        $this->assertFalse($traversal['safe']);
    }

    /**
     * Doc "Safety": a failed capture mechanism (pngpaste / AppleScript / sips)
     * yields a clear error and BLOCKS the send — never a silent incomplete
     * send; pending images do not survive after send or clear.
     */
    public function test_capture_failure_blocks_send_and_pending_clears(): void
    {
        $service = $this->service();

        $failure = $service->captureOutcome('pngpaste', false);
        $this->assertFalse($failure['captured']);
        $this->assertFalse($failure['send_allowed']);
        $this->assertNotNull($failure['error']);
        $this->assertFalse($failure['pending_added']);

        $success = $service->captureOutcome('sips', true);
        $this->assertTrue($success['captured']);
        $this->assertTrue($success['send_allowed']);

        $afterSend = $service->pendingAfter('send', [['path' => '/ws/a.png']]);
        $this->assertTrue($afterSend['cleared']);
        $this->assertSame(0, $afterSend['count_after']);

        $afterKeep = $service->pendingAfter('keep', [['path' => '/ws/a.png']]);
        $this->assertFalse($afterKeep['cleared']);
        $this->assertSame(1, $afterKeep['count_after']);
    }

    /**
     * Doc "Relation To Capability Registry" + "Input Boundary Contract":
     * atlas_cli must be covered by dev/chat/forge (partial coverage =
     * surface_only_image_paste violation), and the four forbidden patterns are
     * detected from a non-compliant flow while a normalized multi-surface flow
     * passes clean.
     */
    public function test_parity_and_boundary_contract(): void
    {
        $service = $this->service();

        $partial = $service->evaluateCliParity(['atlas_cli_dev', 'atlas_cli_chat']);
        $this->assertFalse($partial['ok']);
        $this->assertSame(['atlas_cli_forge'], $partial['missing']);
        $this->assertSame('surface_only_image_paste', $partial['violation']);

        $full = $service->evaluateCliParity(['atlas_cli_dev', 'atlas_cli_chat', 'atlas_cli_forge']);
        $this->assertTrue($full['ok']);

        $bad = $service->evaluateBoundary([
            'capability_surfaces' => ['atlas_cli'],
            'attachment_entrypoints' => ['ask'],
            'provider_receives_normalized' => false,
            'domain_parses_attachment' => true,
        ]);
        $this->assertFalse($bad['ok']);
        $this->assertSame(
            [
                'surface_only_image_paste',
                'ask_only_attachment_flow',
                'provider_direct_attachment_bypass',
                'domain_owned_attachment_parser',
            ],
            $bad['violations'],
        );
        $this->assertSame('atlas.input.surface_capability_boundary.v1', $bad['schema_version']);

        $good = $service->evaluateBoundary([
            'capability_surfaces' => ['atlas_cli', 'atlas_app', 'atlas_api'],
            'attachment_entrypoints' => ['dev', 'chat', 'forge'],
            'provider_receives_normalized' => true,
            'domain_parses_attachment' => false,
        ]);
        $this->assertTrue($good['ok']);
        $this->assertSame([], $good['violations']);
    }
}
