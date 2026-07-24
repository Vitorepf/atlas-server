<?php

namespace App\Console\Commands;

use App\Services\Ai\Cli\AtlasImageAttachmentService;
use Illuminate\Console\Command;
use App\Console\Concerns\EmitsCanonicalJson;

/**
 * Capture macOS clipboard image for Atlas Terminal (TUI paste / AAP attachments).
 */
class AtlasTerminalClipboardImageCommand extends Command
{
    use EmitsCanonicalJson;

    protected $signature = 'atlas:terminal:clipboard-image
        {--workspace= : Workspace path}
        {--json : Machine JSON}';

    protected $description = 'Capture current macOS clipboard image for Terminal Dev attachments.';

    public function handle(AtlasImageAttachmentService $images): int
    {
        $workspace = $this->option('workspace');
        $workspace = is_string($workspace) && $workspace !== ''
            ? (realpath($workspace) ?: $workspace)
            : (realpath(getcwd() ?: base_path()) ?: base_path());

        try {
            $attachment = $images->fromClipboard($workspace);
            $payload = [
                'ok' => true,
                'schema' => 'atlas.terminal.clipboard_image.v1',
                'attachment' => [
                    'path' => $attachment['path'] ?? null,
                    'mime' => $attachment['mime_type'] ?? 'image/png',
                    'bytes' => $attachment['bytes'] ?? null,
                    'source' => $attachment['source'] ?? 'clipboard',
                    'label' => $attachment['original_name'] ?? 'clipboard.png',
                    'sha256' => $attachment['sha256'] ?? null,
                ],
                'workspace' => $workspace,
            ];
            if ((bool) $this->option('json')) {
                $this->line($this->encode($payload));
            } else {
                $this->info('clipboard image: '.($payload['attachment']['path'] ?? '?'));
            }

            return self::SUCCESS;
        } catch (\Throwable $e) {
            $payload = [
                'ok' => false,
                'schema' => 'atlas.terminal.clipboard_image.v1',
                'error' => $e->getMessage(),
            ];
            if ((bool) $this->option('json')) {
                $this->line($this->encode($payload));
            } else {
                $this->error($e->getMessage());
            }

            return self::FAILURE;
        }
    }
}
