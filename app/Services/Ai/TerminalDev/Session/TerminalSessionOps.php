<?php

namespace App\Services\Ai\TerminalDev\Session;

use Illuminate\Support\Facades\File;

final class TerminalSessionOps
{
    /**
     * @param  array<string,mixed>  $session
     */
    public function exportMarkdown(array $session, ?string $path = null): string
    {
        $lines = [
            '# Atlas Terminal Session',
            '',
            '- id: `'.($session['id'] ?? '').'`',
            '- workspace: `'.($session['workspace'] ?? '').'`',
            '- mode: '.($session['mode'] ?? 'normal'),
            '- profile: '.($session['profile'] ?? 'dev'),
            '- turns: '.(string) ($session['turn_count'] ?? 0),
            '',
            '---',
            '',
        ];
        foreach ((array) ($session['messages'] ?? []) as $m) {
            $role = (string) ($m['role'] ?? 'unknown');
            $lines[] = '## '.$role;
            $lines[] = '';
            $lines[] = (string) ($m['content'] ?? '');
            $lines[] = '';
        }
        $md = implode("\n", $lines);
        $target = $path;
        if ($target === null || $target === '') {
            $dir = (string) ($session['dir'] ?? sys_get_temp_dir());
            $target = rtrim($dir, '/').'/export-'.($session['id'] ?? 'session').'.md';
        }
        File::ensureDirectoryExists(dirname($target));
        File::put($target, $md);

        return $target;
    }

    public function copyLastAssistant(array $session): ?string
    {
        $messages = array_reverse(array_values((array) ($session['messages'] ?? [])));
        foreach ($messages as $m) {
            if (($m['role'] ?? '') === 'assistant') {
                $text = (string) ($m['content'] ?? '');
                $home = $_SERVER['HOME'] ?? getenv('HOME') ?: sys_get_temp_dir();
                $backup = rtrim((string) $home, '/').'/.atlas/last-copy.txt';
                File::ensureDirectoryExists(dirname($backup));
                File::put($backup, $text);
                // best-effort pbcopy on macOS
                if (PHP_OS_FAMILY === 'Darwin') {
                    $proc = proc_open('pbcopy', [['pipe', 'r'], ['pipe', 'w'], ['pipe', 'w']], $pipes);
                    if (is_resource($proc)) {
                        fwrite($pipes[0], $text);
                        fclose($pipes[0]);
                        proc_close($proc);
                    }
                }

                return $backup;
            }
        }

        return null;
    }

    /**
     * @param  array<string,mixed>  $session
     * @return array<string,mixed>
     */
    public function contextBreakdown(array $session): array
    {
        $messages = (array) ($session['messages'] ?? []);
        $chars = 0;
        $byRole = [];
        foreach ($messages as $m) {
            $role = (string) ($m['role'] ?? 'unknown');
            $len = strlen((string) ($m['content'] ?? ''));
            $chars += $len;
            $byRole[$role] = ($byRole[$role] ?? 0) + $len;
        }
        $eventsPath = rtrim((string) ($session['dir'] ?? ''), '/').'/events.jsonl';
        $eventBytes = is_file($eventsPath) ? filesize($eventsPath) : 0;

        return [
            'schema' => 'atlas.terminal.context.v1',
            'message_count' => count($messages),
            'message_chars' => $chars,
            'by_role' => $byRole,
            'events_bytes' => $eventBytes,
            'turn_count' => (int) ($session['turn_count'] ?? 0),
            'compact_count' => (int) ($session['compact_count'] ?? 0),
            'auto_compact_threshold_turns' => (int) config('atlas_terminal.auto_compact_turns', 40),
        ];
    }
}
