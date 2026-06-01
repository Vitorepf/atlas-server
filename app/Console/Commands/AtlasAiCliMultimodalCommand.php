<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Services\Ai\Aaeos\Generated\AtlasAiCliMultimodalService;
use Illuminate\Console\Command;
use Throwable;

/**
 * Atlas AI CLI Multimodal contract validator CLI.
 *
 *   php artisan atlas:aaeos:ai-cli-multimodal [--json]
 *
 * With no options it exercises the doc's executable rules against representative
 * inputs: the "Comportamento Esperado" dispatch table, the "UI De Terminal"
 * label (with and without OSC 8), the "Safety" path / capture / pending-clear
 * verdicts, the surface-adapter parity rule and the "Input Boundary Contract"
 * (including one intentional forbidden-pattern flow). Read-only, deterministic,
 * DB-free.
 *
 * @see docs/engineering-knowledge-base/atlas-ai-cli-multimodal.md
 */
class AtlasAiCliMultimodalCommand extends Command
{
    protected $signature = 'atlas:aaeos:ai-cli-multimodal {--json : machine-readable JSON output (default true)}';

    protected $description = 'Atlas AAEOS · validate the CLI multimodal input dispatch, terminal label, safety and boundary rules.';

    public function handle(AtlasAiCliMultimodalService $service): int
    {
        try {
            $result = [
                'dispatch' => [
                    'paste_control_byte' => $service->dispatchInput([
                        'kind' => 'control_byte',
                        'control_byte' => AtlasAiCliMultimodalService::PASTE_CONTROL_BYTE,
                    ]),
                    'slash_paste_image' => $service->dispatchInput([
                        'kind' => 'slash',
                        'command' => '/paste-image',
                    ]),
                    'slash_image_path' => $service->dispatchInput([
                        'kind' => 'slash',
                        'command' => '/image',
                        'argument' => 'shot.png',
                    ]),
                    'open_image_fallback' => $service->dispatchInput([
                        'kind' => 'slash',
                        'command' => '/open-image',
                        'argument' => '1',
                    ]),
                ],
                'prompt_label_osc8' => $service->renderPromptLabel(
                    [['path' => '/ws/a.png'], ['path' => '/ws/b.png']],
                    true,
                ),
                'prompt_label_apple_terminal' => $service->renderPromptLabel(
                    [['path' => '/ws/a.png']],
                    false,
                ),
                'safety_path_outside_root' => $service->evaluateAttachmentPath(
                    '/etc/passwd',
                    'image/png',
                    ['/ws'],
                ),
                'safety_capture_failure' => $service->captureOutcome('pngpaste', false),
                'pending_after_send' => $service->pendingAfter('send', [['path' => '/ws/a.png']]),
                'cli_parity_partial' => $service->evaluateCliParity(['atlas_cli_dev', 'atlas_cli_chat']),
                'boundary_violation' => $service->evaluateBoundary([
                    'capability_surfaces' => ['atlas_cli'],
                    'attachment_entrypoints' => ['ask'],
                    'provider_receives_normalized' => false,
                    'domain_parses_attachment' => true,
                ]),
            ];

            $this->line((string) json_encode(
                ['ok' => true, 'result' => $result],
                JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES,
            ));

            return self::SUCCESS;
        } catch (Throwable $e) {
            $this->line((string) json_encode(
                ['ok' => false, 'error' => $e->getMessage()],
                JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES,
            ));

            return self::FAILURE;
        }
    }
}
