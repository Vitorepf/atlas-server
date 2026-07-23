<?php

namespace App\Services\Ai\TerminalDev\Providers;

use App\Models\AiJob;
use App\Services\Ai\HermesCliProvider;
use App\Services\Ai\Programming\HermesWorkspaceDefaults;
use App\Services\Ai\AiProviderResult;
use Illuminate\Support\Str;

/**
 * Terminal Dev → Hermes CLI muscle (subscription/local hermes binary).
 *
 * Uses HermesCliProvider with cli oneshot (hermes -z) so headless turns do not hang on chat TTY.
 * No Anthropic/OpenAI API keys — same path as Atlas Dev / Forge hermes.
 */
final class TerminalHermesBridge
{
    public function __construct(
        private readonly HermesCliProvider $hermes,
    ) {}

    public function isEnabled(): bool
    {
        if (! (bool) config('atlas_terminal.hermes_allowed', true)) {
            return false;
        }

        $provider = (string) config('atlas_terminal.provider', 'hermes_cli');
        if ($provider !== 'hermes_cli' && $provider !== 'hermes') {
            return false;
        }

        $order = config('atlas_terminal.default_provider_order', ['hermes_cli']);
        if (is_array($order) && $order !== [] && ! in_array('hermes_cli', $order, true) && ! in_array('hermes', $order, true)) {
            return false;
        }

        return true;
    }

    public function isDryRun(): bool
    {
        return (bool) config('atlas_terminal.hermes_dry_run', false);
    }

    /**
     * @param  array<string,mixed>  $session
     * @param  array<string,mixed>  $options open_brain_markdown?, skill_body?, timeout?
     * @return array{
     *   ok:bool,
     *   provider:string,
     *   dry_run:bool,
     *   text:string,
     *   error:?string,
     *   duration_ms:int,
     *   metadata:array<string,mixed>,
     *   timed_out:bool
     * }
     */
    public function runTurn(array $session, string $userText, array $options = []): array
    {
        $workspace = (string) ($session['workspace'] ?? getcwd() ?: base_path());
        $timeout = (int) ($options['timeout'] ?? $session['timeout_seconds'] ?? config('atlas_terminal.timeout_seconds', 300));
        $timeout = max(30, min(3600, $timeout));

        if ($this->isDryRun() || ! $this->isEnabled()) {
            return [
                'ok' => true,
                'provider' => 'hermes_cli',
                'dry_run' => true,
                'text' => $this->dryRunText($userText, $options),
                'error' => null,
                'duration_ms' => 0,
                'metadata' => ['dry_run' => true, 'reason' => $this->isDryRun() ? 'hermes_dry_run' : 'hermes_disabled'],
                'timed_out' => false,
            ];
        }

        $prompt = $this->composePrompt($session, $userText, $options);
        $yolo = (bool) ($session['yolo'] ?? false) || ($session['profile'] ?? '') === 'forge';
        $permissionMode = $yolo ? 'danger' : (string) ($session['permission_mode'] ?? 'write');
        if ($permissionMode === 'read') {
            // explore-style: still call hermes but without yolo danger
            $toolPermissions = [
                'workspace' => $workspace,
                'mode' => 'read',
            ];
        } else {
            $toolPermissions = HermesWorkspaceDefaults::toolPermissions($workspace);
            if (! $yolo && $permissionMode === 'write') {
                // write mode (not full danger) — Hermes may still need yolo for non-TTY; keep danger for autonomous edits
                $toolPermissions = HermesWorkspaceDefaults::toolPermissions($workspace);
            }
        }

        $attachments = $this->normalizeAttachments($options['attachments'] ?? []);
        $hasImages = ($attachments['images'] ?? []) !== [];
        $cliOneShot = (bool) config('atlas_terminal.hermes_cli_oneshot', true);
        if ($hasImages) {
            // hermes -z has no --image; chat path required for vision.
            $cliOneShot = false;
        }

        $hermes = [
            'cli_oneshot' => $cliOneShot,
            'source' => 'atlas_terminal',
            'max_turns' => (int) config('atlas_terminal.max_tool_rounds', 8),
            'memory_policy' => 'off',
            'schedule_policy' => 'off',
        ];
        if ($hasImages) {
            $hermes['capabilities'] = ['vision' => true];
        }

        $job = new AiJob([
            'trace_id' => 'atlas-terminal:'.(string) ($session['id'] ?? Str::uuid()),
            'kind' => 'atlas_terminal_turn',
            'provider' => 'hermes_cli',
            'model' => HermesWorkspaceDefaults::model(),
            'prompt' => $prompt,
            'input_text' => $prompt,
            'timeout_seconds' => $timeout,
            'payload' => [
                'workspace' => $workspace,
                'tool_permissions' => $toolPermissions,
                'hermes' => $hermes,
                'surface' => 'atlas_terminal',
                'session_id' => $session['id'] ?? null,
                'attachments' => $attachments,
            ],
        ]);

        $started = microtime(true);
        try {
            /** @var AiProviderResult $result */
            $result = $this->hermes->run($job, $prompt);
            $durationMs = (int) round((microtime(true) - $started) * 1000);
            $text = trim((string) $result->output);
            if ($text === '') {
                $text = trim((string) $result->stdout);
            }


            $timedOut = (bool) data_get($result->metadata ?? [], 'timed_out', false)
                || str_contains(strtolower((string) ($result->errorMessage ?? '')), 'timeout');

            
            if ($text === '' && $result->ok) {
                $stderr = trim((string) $result->stderr);
                $text = $stderr !== ''
                    ? ("## Hermes empty stdout\n\nstderr:\n".$stderr)
                    : 'Hermes returned empty output (ok=true, no stdout/stderr).';
            }
            $hasBody = trim((string) ($result->output ?: $result->stdout)) !== '';
return [
                'ok' => (bool) $result->ok && $hasBody,
                'provider' => 'hermes_cli',
                'dry_run' => false,
                'text' => $text !== '' ? $text : (string) ($result->errorMessage ?? 'Hermes returned empty output.'),
                'error' => ((bool) $result->ok && $hasBody) ? null : (string) ($result->errorMessage ?: 'hermes_empty_or_failed'),
                'duration_ms' => $durationMs,
                'metadata' => is_array($result->metadata ?? null) ? $result->metadata : [],
                'timed_out' => $timedOut,
            ];
        } catch (\Throwable $e) {
            $durationMs = (int) round((microtime(true) - $started) * 1000);
            $msg = $e->getMessage();
            $timedOut = str_contains(strtolower($msg), 'timeout') || str_contains(strtolower($msg), 'timed out');

            return [
                'ok' => false,
                'provider' => 'hermes_cli',
                'dry_run' => false,
                'text' => '',
                'error' => $msg,
                'duration_ms' => $durationMs,
                'metadata' => ['exception' => $e::class],
                'timed_out' => $timedOut,
            ];
        }
    }

    /**
     * @param  array<string,mixed>  $session
     * @param  array<string,mixed>  $options
     */
    private function composePrompt(array $session, string $userText, array $options): string
    {
        $parts = [];
        $parts[] = 'You are Atlas Terminal Dev (session agent). Workspace: '.($session['workspace'] ?? '.');
        $parts[] = 'Profile: '.($session['profile'] ?? 'dev').' · mode: '.($session['mode'] ?? 'normal');
        $parts[] = 'Use tools as needed. Prefer small safe edits. Report what you changed.';

        if (! empty($options['open_brain_markdown'])) {
            $parts[] = "--- Open Brain (local, curated top-K) ---\n".mb_substr((string) $options['open_brain_markdown'], 0, 3500);
        }

        if (! empty($options['skill_body'])) {
            $parts[] = "--- Active skill ---\n".mb_substr((string) $options['skill_body'], 0, 4000);
        }

        $history = array_slice(array_values((array) ($session['messages'] ?? [])), -8);
        if ($history !== []) {
            $hist = [];
            foreach ($history as $m) {
                $role = (string) ($m['role'] ?? '?');
                $hist[] = strtoupper($role).': '.mb_substr((string) ($m['content'] ?? ''), 0, 800);
            }
            $parts[] = "--- Recent session ---\n".implode("\n", $hist);
        }

        $parts[] = "--- User ---\n".$userText;

        return implode("\n\n", $parts);
    }

    /**
     * @param  array<string,mixed>  $options
     */
    /**
     * @param  mixed  $raw
     * @return array{images:list<array<string,mixed>>,files:list<array<string,mixed>>}
     */
    private function normalizeAttachments(mixed $raw): array
    {
        $images = [];
        $files = [];
        if (! is_array($raw)) {
            return ['images' => [], 'files' => []];
        }
        // allow {images:[...]} or flat list
        $list = isset($raw['images']) && is_array($raw['images']) ? $raw['images'] : $raw;
        foreach ($list as $item) {
            if (! is_array($item)) {
                continue;
            }
            $path = (string) ($item['path'] ?? '');
            if ($path === '' || ! is_file($path)) {
                continue;
            }
            $mime = (string) ($item['mime'] ?? $item['mime_type'] ?? 'image/png');
            $entry = [
                'path' => $path,
                'mime' => $mime,
                'mime_type' => $mime,
                'source' => (string) ($item['source'] ?? 'clipboard'),
                'label' => (string) ($item['label'] ?? basename($path)),
                'bytes' => $item['bytes'] ?? (is_file($path) ? filesize($path) : null),
            ];
            if (str_starts_with($mime, 'image/')) {
                $images[] = $entry;
            } else {
                $files[] = $entry;
            }
        }

        return ['images' => $images, 'files' => $files];
    }

    private function dryRunText(string $userText, array $options): string
    {
        return "## Hermes dry-run\n\n"
            ."Provider `hermes_cli` would run here (cli oneshot).\n\n"
            .'User: '.$userText."\n\n"
            .'_Set ATLAS_TERMINAL_HERMES_DRY=0 and ensure `hermes` is on PATH for live turns._';
    }
}
