<?php

namespace App\Services\Ai\TerminalDev\Hooks;

use Illuminate\Support\Facades\File;
use Symfony\Component\Process\Process;

/**
 * Grok-compatible-ish hooks: ~/.atlas/hooks/*.json and <project>/.atlas/hooks/*.json
 *
 * JSON shape:
 * { "hooks": { "SessionStart": [ { "hooks": [ {"type":"command","command":"..."} ] } ] } }
 */
final class TerminalHookRunner
{
    public const EVENTS = [
        'SessionStart',
        'PreToolUse',
        'PostToolUse',
        'Stop',
        'SessionEnd',
    ];

    /**
     * @param  array<string,mixed>  $payload
     * @return array{allowed:bool,messages:list<string>,blocked_by:?string}
     */
    public function run(string $event, string $workspace, array $payload = []): array
    {
        $messages = [];
        $allowed = true;
        $blockedBy = null;

        foreach ($this->discoverHookFiles($workspace) as $file) {
            $raw = json_decode(File::get($file), true);
            if (! is_array($raw)) {
                continue;
            }
            $entries = data_get($raw, 'hooks.'.$event, data_get($raw, $event, []));
            if (! is_array($entries)) {
                continue;
            }
            foreach ($entries as $entry) {
                $hooks = is_array($entry['hooks'] ?? null) ? $entry['hooks'] : (isset($entry['type']) ? [$entry] : []);
                foreach ($hooks as $hook) {
                    if (($hook['type'] ?? '') !== 'command') {
                        continue;
                    }
                    $command = (string) ($hook['command'] ?? '');
                    if ($command === '') {
                        continue;
                    }
                    $env = [
                        'ATLAS_HOOK_EVENT' => $event,
                        'ATLAS_HOOK_WORKSPACE' => $workspace,
                        'ATLAS_HOOK_PAYLOAD' => json_encode($payload, JSON_UNESCAPED_SLASHES) ?: '{}',
                    ];
                    $process = Process::fromShellCommandline($command, $workspace, $env, null, 30.0);
                    $process->run();
                    $out = trim($process->getOutput().$process->getErrorOutput());
                    if ($out !== '') {
                        $messages[] = basename(dirname($file)).'/'.basename($file).': '.$out;
                    }
                    // Exit 2 = block (Claude Code convention)
                    if ($process->getExitCode() === 2 && in_array($event, ['PreToolUse', 'Stop'], true)) {
                        $allowed = false;
                        $blockedBy = $file.': '.$out;
                    }
                }
            }
        }

        return [
            'allowed' => $allowed,
            'messages' => $messages,
            'blocked_by' => $blockedBy,
        ];
    }

    /**
     * @return list<string>
     */
    private function discoverHookFiles(string $workspace): array
    {
        $files = [];
        $home = $_SERVER['HOME'] ?? getenv('HOME') ?: '';
        $roots = array_filter([
            $home !== '' ? rtrim((string) $home, '/').'/.atlas/hooks' : null,
            rtrim($workspace, '/').'/.atlas/hooks',
        ]);
        foreach ($roots as $root) {
            if (! File::isDirectory($root)) {
                continue;
            }
            foreach (File::files($root) as $file) {
                if (str_ends_with(strtolower($file->getFilename()), '.json')) {
                    $files[] = $file->getPathname();
                }
            }
        }

        return $files;
    }
}
