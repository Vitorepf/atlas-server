<?php

declare(strict_types=1);

namespace App\Services\Ai\Aaeos\Quarantine;

/**
 * Atlas AI CLI Multimodal — runtime decider for the canonical multimodal input
 * contract of the Atlas CLI surface (paste/attach of images in `atlas chat`
 * and `atlas dev`).
 *
 * This service turns the doc's executable rules into pure, deterministic
 * decisions:
 *
 *  - "Comportamento Esperado": dispatches a raw terminal input event
 *    (Ctrl+V, Cmd+V remapped to 0x16, bracketed paste, /paste-image, /image,
 *    /images, /open-image N, /clear-images) to the expected action + result.
 *  - "UI De Terminal": renders the pending-attachment label "[imagem 1]" /
 *    "[imagem 1, imagem 2]", links each label via OSC 8 when the terminal
 *    supports it, and falls back to "/open-image N" (e.g. Apple Terminal.app).
 *  - "Safety": validates attached paths against allowed roots + permitted MIME,
 *    turns a capture-mechanism failure (pngpaste / AppleScript / sips) into a
 *    clear error instead of a silent incomplete send, and asserts pending
 *    images do not survive after send/clear.
 *  - "Input Boundary Contract" (atlas.input.surface_capability_boundary.v1):
 *    flags the four forbidden patterns and enforces the surface-adapter parity
 *    rule (atlas_cli must be covered by atlas_cli_dev/chat/forge).
 *
 * Pure, deterministic, DB-free. No clipboard/filesystem side effects: the real
 * capture lives in App\Services\Ai\Cli\AtlasImageAttachmentService; this class
 * only decides what the surface is allowed to do.
 *
 * @see docs/engineering-knowledge-base/atlas-ai-cli-multimodal.md
 */
final class AtlasAiCliMultimodalService
{
    /** Cmd+V is remapped to the same control byte as Ctrl+V (SYN, 0x16). */
    public const PASTE_CONTROL_BYTE = 0x16;

    /** MIME types the CLI multimodal contract permits as image attachments. */
    public const ALLOWED_IMAGE_MIME = ['image/png', 'image/jpeg', 'image/gif', 'image/webp'];

    /** Local capture mechanisms; their failure must surface a clear error. */
    public const CAPTURE_MECHANISMS = ['pngpaste', 'applescript', 'sips'];

    /** Kernel surface that owns multimodal input and the adapters that cover it. */
    public const CLI_SURFACE = 'atlas_cli';

    /** @var array<int,string> */
    public const CLI_ADAPTERS = ['atlas_cli_dev', 'atlas_cli_chat', 'atlas_cli_forge'];

    /** Patterns the boundary contract forbids (doc "Input Boundary Contract"). */
    public const FORBIDDEN_PATTERNS = [
        'surface_only_image_paste',
        'ask_only_attachment_flow',
        'provider_direct_attachment_bypass',
        'domain_owned_attachment_parser',
    ];

    /**
     * Dispatch one raw multimodal input event to the documented action + result.
     *
     * Mirrors the doc's "Comportamento Esperado" table exactly. The decision is
     * about WHAT the surface should do; it never captures a clipboard or touches
     * a file.
     *
     * @param  array{
     *     kind?:string,
     *     control_byte?:int|null,
     *     command?:string|null,
     *     argument?:string|null,
     *     bracketed_payload?:string|null,
     *     clipboard_has_image?:bool,
     *     image_capture_supported?:bool
     * }  $event
     * @return array{
     *     recognized:bool,
     *     trigger:string,
     *     action:string,
     *     result:string,
     *     keybind_independent:bool,
     *     requires_path_validation:bool
     * }
     */
    public function dispatchInput(array $event): array
    {
        $kind = (string) ($event['kind'] ?? '');

        // Ctrl+V / Cmd+V: both arrive as the same control byte (0x16).
        if ($kind === 'key' || $kind === 'control_byte') {
            $byte = $event['control_byte'] ?? null;
            if ($byte === self::PASTE_CONTROL_BYTE) {
                return $this->decision(
                    trigger: 'paste_control_byte',
                    action: 'capture_clipboard',
                    result: 'Captura clipboard e adiciona imagem pendente.',
                    keybindIndependent: false,
                );
            }

            return $this->unrecognized('key');
        }

        // Bracketed paste: empty payload + image-only clipboard captures the
        // image (when supported); a payload that is an image path attaches it.
        if ($kind === 'bracketed_paste') {
            $payload = trim((string) ($event['bracketed_payload'] ?? ''));

            if ($payload === '') {
                $hasImage = (bool) ($event['clipboard_has_image'] ?? false);
                $supported = (bool) ($event['image_capture_supported'] ?? false);

                if ($hasImage && $supported) {
                    return $this->decision(
                        trigger: 'bracketed_paste_image_only',
                        action: 'capture_clipboard',
                        result: 'Captura imagem se suportado pela implementacao.',
                        keybindIndependent: false,
                    );
                }

                return $this->decision(
                    trigger: 'bracketed_paste_image_only',
                    action: 'noop',
                    result: 'Sem captura: clipboard sem imagem ou captura nao suportada.',
                    keybindIndependent: false,
                );
            }

            return $this->decision(
                trigger: 'bracketed_paste_path',
                action: 'attach_path',
                result: 'Anexa arquivo se path for valido e permitido.',
                keybindIndependent: false,
                requiresPathValidation: true,
            );
        }

        // Slash commands: keybind-independent fallbacks.
        if ($kind === 'slash') {
            return $this->dispatchSlash(
                (string) ($event['command'] ?? ''),
                $event['argument'] ?? null,
            );
        }

        return $this->unrecognized($kind === '' ? 'unknown' : $kind);
    }

    /**
     * @return array{
     *     recognized:bool,
     *     trigger:string,
     *     action:string,
     *     result:string,
     *     keybind_independent:bool,
     *     requires_path_validation:bool
     * }
     */
    private function dispatchSlash(string $command, ?string $argument): array
    {
        $command = ltrim(trim($command), '/');

        return match ($command) {
            'paste-image' => $this->decision(
                trigger: '/paste-image',
                action: 'capture_clipboard',
                result: 'Captura clipboard sem depender de keybind.',
                keybindIndependent: true,
            ),
            'image' => $this->decision(
                trigger: '/image',
                action: 'attach_path',
                result: 'Anexa arquivo local permitido.',
                keybindIndependent: true,
                requiresPathValidation: true,
            ),
            'images' => $this->decision(
                trigger: '/images',
                action: 'list_pending',
                result: 'Lista anexos pendentes.',
                keybindIndependent: true,
            ),
            'open-image' => $this->decision(
                trigger: '/open-image',
                action: 'open_attachment',
                result: 'Abre anexo N como fallback a OSC 8.',
                keybindIndependent: true,
            ),
            'clear-images' => $this->decision(
                trigger: '/clear-images',
                action: 'clear_pending',
                result: 'Remove anexos pendentes.',
                keybindIndependent: true,
            ),
            default => $this->unrecognized('slash'),
        };
    }

    /**
     * @return array{
     *     recognized:bool,
     *     trigger:string,
     *     action:string,
     *     result:string,
     *     keybind_independent:bool,
     *     requires_path_validation:bool
     * }
     */
    private function decision(
        string $trigger,
        string $action,
        string $result,
        bool $keybindIndependent,
        bool $requiresPathValidation = false,
    ): array {
        return [
            'recognized' => true,
            'trigger' => $trigger,
            'action' => $action,
            'result' => $result,
            'keybind_independent' => $keybindIndependent,
            'requires_path_validation' => $requiresPathValidation,
        ];
    }

    /**
     * @return array{
     *     recognized:bool,
     *     trigger:string,
     *     action:string,
     *     result:string,
     *     keybind_independent:bool,
     *     requires_path_validation:bool
     * }
     */
    private function unrecognized(string $trigger): array
    {
        return [
            'recognized' => false,
            'trigger' => $trigger,
            'action' => 'ignore',
            'result' => 'Evento nao reconhecido pelo contrato multimodal.',
            'keybind_independent' => false,
            'requires_path_validation' => false,
        ];
    }

    /**
     * Render the pending-attachment prompt label per "UI De Terminal".
     *
     * - Labels read "[imagem 1]" / "[imagem 1, imagem 2]" (1-based).
     * - When the terminal supports OSC 8, every label is hyperlinked to its
     *   file path; otherwise no escape is emitted and "/open-image N" is the
     *   documented fallback (e.g. Apple Terminal.app, which may ignore OSC 8).
     * - The label is a single line and never duplicates on redraw.
     *
     * @param  array<int,array{path?:string}|string>  $pendingAttachments
     * @return array{
     *     count:int,
     *     label:string,
     *     osc8:bool,
     *     fallback_command:string,
     *     items:array<int,array{index:int,label:string,path:string,osc8_link:?string,open_command:string}>,
     *     stable_on_redraw:bool
     * }
     */
    public function renderPromptLabel(array $pendingAttachments, bool $terminalSupportsOsc8): array
    {
        $items = [];
        $labelParts = [];
        $index = 0;

        foreach ($pendingAttachments as $attachment) {
            $index++;
            $path = is_array($attachment) ? (string) ($attachment['path'] ?? '') : (string) $attachment;
            $itemLabel = "imagem {$index}";
            $labelParts[] = $itemLabel;

            $items[] = [
                'index' => $index,
                'label' => $itemLabel,
                'path' => $path,
                'osc8_link' => ($terminalSupportsOsc8 && $path !== '')
                    ? $this->osc8Hyperlink($path, $itemLabel)
                    : null,
                'open_command' => "/open-image {$index}",
            ];
        }

        return [
            'count' => $index,
            'label' => $index === 0 ? '' : '['.implode(', ', $labelParts).']',
            'osc8' => $terminalSupportsOsc8 && $index > 0,
            'fallback_command' => '/open-image N',
            'items' => $items,
            // Deterministic single render of the current pending set: rebuilding
            // the prompt from state never appends or duplicates the label.
            'stable_on_redraw' => true,
        ];
    }

    /**
     * OSC 8 hyperlink escape sequence: ESC ] 8 ; ; file://PATH ST text ESC ] 8 ; ; ST.
     */
    private function osc8Hyperlink(string $path, string $text): string
    {
        $esc = "\033";
        $uri = 'file://'.$path;

        return $esc.']8;;'.$uri.$esc.'\\'.$text.$esc.']8;;'.$esc.'\\';
    }

    /**
     * Safety verdict for an attempt to attach a path (doc "Safety").
     *
     * A path is only safe when it sits inside an allowed root AND its MIME is a
     * permitted image type. Anything else is rejected with a clear reason — the
     * surface must never silently drop or forward a disallowed attachment.
     *
     * @param  array<int,string>  $allowedRoots
     * @return array{safe:bool,reason:string,path:string,mime:string}
     */
    public function evaluateAttachmentPath(string $path, string $mime, array $allowedRoots): array
    {
        $path = trim($path);
        $mime = strtolower(trim($mime));

        if ($path === '') {
            return ['safe' => false, 'reason' => 'empty_path', 'path' => $path, 'mime' => $mime];
        }

        if (! $this->isInsideAllowedRoot($path, $allowedRoots)) {
            return ['safe' => false, 'reason' => 'outside_allowed_roots', 'path' => $path, 'mime' => $mime];
        }

        if (! in_array($mime, self::ALLOWED_IMAGE_MIME, true)) {
            return ['safe' => false, 'reason' => 'mime_not_permitted', 'path' => $path, 'mime' => $mime];
        }

        return ['safe' => true, 'reason' => 'allowed', 'path' => $path, 'mime' => $mime];
    }

    /**
     * Outcome of a local clipboard-capture attempt (doc "Safety").
     *
     * When a capture mechanism (pngpaste / AppleScript / sips) fails, the
     * contract requires a clear error and that the message is NOT sent — never a
     * silent incomplete send.
     *
     * @return array{
     *     captured:bool,
     *     send_allowed:bool,
     *     error:?string,
     *     mechanism:string,
     *     pending_added:bool
     * }
     */
    public function captureOutcome(string $mechanism, bool $succeeded): array
    {
        $mechanism = strtolower(trim($mechanism));
        $known = in_array($mechanism, self::CAPTURE_MECHANISMS, true);

        if ($succeeded && $known) {
            return [
                'captured' => true,
                'send_allowed' => true,
                'error' => null,
                'mechanism' => $mechanism,
                'pending_added' => true,
            ];
        }

        $reason = $known
            ? "Captura via {$mechanism} falhou; mensagem nao enviada para evitar envio incompleto."
            : "Mecanismo de captura desconhecido: {$mechanism}.";

        return [
            'captured' => false,
            'send_allowed' => false,
            'error' => $reason,
            'mechanism' => $mechanism,
            'pending_added' => false,
        ];
    }

    /**
     * Pending-attachment lifecycle after a send or clear (doc "Safety":
     * "Imagens pendentes nao devem sobreviver indevidamente apos envio/clear").
     *
     * After "send" or "clear" the pending set must be empty; only an explicit
     * "keep" (no send) retains it.
     *
     * @param  array<int,mixed>  $pending
     * @return array{remaining:array<int,mixed>,cleared:bool,count_after:int}
     */
    public function pendingAfter(string $event, array $pending): array
    {
        $cleared = in_array($event, ['send', 'clear'], true);
        $remaining = $cleared ? [] : array_values($pending);

        return [
            'remaining' => $remaining,
            'cleared' => $cleared,
            'count_after' => count($remaining),
        ];
    }

    /**
     * Surface-adapter parity verdict (doc "Relation To Capability Registry").
     *
     * The kernel surface atlas_cli must be covered by atlas_cli_dev,
     * atlas_cli_chat AND atlas_cli_forge; a multimodal capability available in
     * only some of them is an architectural violation.
     *
     * @param  array<int,string>  $coveredAdapters
     * @return array{
     *     ok:bool,
     *     surface:string,
     *     required:array<int,string>,
     *     missing:array<int,string>,
     *     violation:?string
     * }
     */
    public function evaluateCliParity(array $coveredAdapters): array
    {
        $covered = array_map(static fn ($a) => trim((string) $a), $coveredAdapters);
        $missing = array_values(array_diff(self::CLI_ADAPTERS, $covered));
        $ok = $missing === [];

        return [
            'ok' => $ok,
            'surface' => self::CLI_SURFACE,
            'required' => self::CLI_ADAPTERS,
            'missing' => $missing,
            'violation' => $ok ? null : 'surface_only_image_paste',
        ];
    }

    /**
     * Boundary-contract verdict for a proposed input flow (doc "Input Boundary
     * Contract"). The four forbidden patterns are detected from the flow shape:
     * a single-surface capability, an ask-only attachment path, a provider that
     * receives an attachment outside the normalized pipeline, or a domain that
     * owns an attachment parser.
     *
     * @param  array{
     *     capability_surfaces?:array<int,string>,
     *     attachment_entrypoints?:array<int,string>,
     *     provider_receives_normalized?:bool,
     *     domain_parses_attachment?:bool
     * }  $flow
     * @return array{
     *     ok:bool,
     *     violations:array<int,string>,
     *     schema_version:string,
     *     forbidden_patterns:array<int,string>
     * }
     */
    public function evaluateBoundary(array $flow): array
    {
        $violations = [];

        $surfaces = array_values(array_unique(array_map(
            static fn ($s) => trim((string) $s),
            $flow['capability_surfaces'] ?? [],
        )));
        if (count($surfaces) === 1) {
            // A multimodal input capability trapped in one surface.
            $violations[] = 'surface_only_image_paste';
        }

        $entrypoints = array_map(
            static fn ($e) => trim((string) $e),
            $flow['attachment_entrypoints'] ?? [],
        );
        if ($entrypoints === ['ask'] || $entrypoints === ['chat']) {
            $violations[] = 'ask_only_attachment_flow';
        }

        if (array_key_exists('provider_receives_normalized', $flow)
            && $flow['provider_receives_normalized'] === false) {
            $violations[] = 'provider_direct_attachment_bypass';
        }

        if (($flow['domain_parses_attachment'] ?? false) === true) {
            $violations[] = 'domain_owned_attachment_parser';
        }

        return [
            'ok' => $violations === [],
            'violations' => array_values(array_unique($violations)),
            'schema_version' => 'atlas.input.surface_capability_boundary.v1',
            'forbidden_patterns' => self::FORBIDDEN_PATTERNS,
        ];
    }

    /**
     * @param  array<int,string>  $allowedRoots
     */
    private function isInsideAllowedRoot(string $path, array $allowedRoots): bool
    {
        if ($allowedRoots === []) {
            return false;
        }

        // Reject traversal regardless of root prefix.
        if (str_contains($path, '..')) {
            return false;
        }

        $normalizedPath = rtrim($path, '/');

        foreach ($allowedRoots as $root) {
            $root = rtrim(trim((string) $root), '/');
            if ($root === '') {
                continue;
            }
            if ($normalizedPath === $root || str_starts_with($normalizedPath, $root.'/')) {
                return true;
            }
        }

        return false;
    }
}
