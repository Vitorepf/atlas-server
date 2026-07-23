<?php

namespace App\Services\Ai\TerminalDev\Session;

use Illuminate\Support\Facades\File;
use Illuminate\Support\Str;

final class AtlasTerminalSessionStore
{
    public function root(): string
    {
        $root = (string) config('atlas_terminal.sessions_root');
        if ($root === '') {
            $home = $_SERVER['HOME'] ?? getenv('HOME') ?: storage_path('app');
            $root = rtrim((string) $home, '/').'/.atlas/sessions';
        }

        File::ensureDirectoryExists($root);

        return $root;
    }

    public function cwdHash(string $workspace): string
    {
        $resolved = realpath($workspace) ?: $workspace;

        return substr(hash('sha256', $resolved), 0, 16);
    }

    /**
     * @param  array<string,mixed>  $meta
     * @return array<string,mixed>
     */
    public function create(string $workspace, array $meta = []): array
    {
        $workspace = realpath($workspace) ?: $workspace;
        $id = (string) Str::orderedUuid();
        $dir = $this->root().'/'.$this->cwdHash($workspace).'/'.$id;
        File::ensureDirectoryExists($dir);

        $session = [
            'schema' => 'atlas.terminal.session.v1',
            'id' => $id,
            'workspace' => $workspace,
            'cwd_hash' => $this->cwdHash($workspace),
            'created_at' => now()->toJSON(),
            'updated_at' => now()->toJSON(),
            'mode' => (string) ($meta['mode'] ?? 'normal'),
            'profile' => (string) ($meta['profile'] ?? 'dev'),
            'permission_mode' => (string) ($meta['permission_mode'] ?? config('atlas_terminal.permission.default_mode', 'write')),
            'yolo' => (bool) ($meta['yolo'] ?? config('atlas_terminal.permission.yolo_default', false)),
            'provider_order' => $meta['provider_order'] ?? config('atlas_terminal.default_provider_order', []),
            'hermes_allowed' => (bool) ($meta['hermes_allowed'] ?? config('atlas_terminal.hermes_allowed', false)),
            'turn_count' => 0,
            'messages' => [],
            'active_skill' => null,
            'plan_path' => $dir.'/'.(string) config('atlas_terminal.plan.filename', 'plan.md'),
            'dir' => $dir,
        ];

        $this->writeSession($session);
        File::put($dir.'/events.jsonl', '');

        return $session;
    }

    /**
     * @return array<string,mixed>|null
     */
    public function load(string $sessionId, ?string $workspace = null): ?array
    {
        $sessionId = trim($sessionId);
        if ($sessionId === '') {
            return null;
        }

        if ($workspace !== null) {
            $path = $this->root().'/'.$this->cwdHash($workspace).'/'.$sessionId.'/session.json';
            if (File::isFile($path)) {
                return $this->readSession($path);
            }
        }

        foreach (File::directories($this->root()) as $cwdDir) {
            $path = $cwdDir.'/'.$sessionId.'/session.json';
            if (File::isFile($path)) {
                return $this->readSession($path);
            }
        }

        return null;
    }

    /**
     * @return array<string,mixed>|null
     */
    /**
     * @return list<array{id:string,updated_at:?string,turn_count:int,mode:string,title:?string}>
     */
    public function listForWorkspace(string $workspace, int $limit = 20): array
    {
        $base = $this->root().'/'.$this->cwdHash($workspace);
        if (! File::isDirectory($base)) {
            return [];
        }
        $rows = [];
        foreach (File::directories($base) as $dir) {
            $path = $dir.'/session.json';
            if (! File::isFile($path)) {
                continue;
            }
            $s = $this->readSession($path);
            if ($s === null) {
                continue;
            }
            $rows[] = [
                'id' => (string) ($s['id'] ?? basename($dir)),
                'updated_at' => $s['updated_at'] ?? null,
                'turn_count' => (int) ($s['turn_count'] ?? 0),
                'mode' => (string) ($s['mode'] ?? 'normal'),
                'title' => $s['title'] ?? null,
                'mtime' => File::lastModified($path),
            ];
        }
        usort($rows, fn ($a, $b) => ($b['mtime'] ?? 0) <=> ($a['mtime'] ?? 0));
        $rows = array_slice($rows, 0, max(1, $limit));
        foreach ($rows as &$r) {
            unset($r['mtime']);
        }

        return $rows;
    }

    /**
     * @param  array<string,mixed>  $session
     * @return array<string,mixed>
     */
    public function fork(array $session): array
    {
        $child = $this->create((string) $session['workspace'], [
            'mode' => $session['mode'] ?? 'normal',
            'profile' => $session['profile'] ?? 'dev',
            'permission_mode' => $session['permission_mode'] ?? 'write',
            'yolo' => $session['yolo'] ?? false,
        ]);
        $child['messages'] = array_values((array) ($session['messages'] ?? []));
        $child['turn_count'] = (int) ($session['turn_count'] ?? 0);
        $child['forked_from'] = $session['id'] ?? null;
        $child['title'] = ($session['title'] ?? 'session').' (fork)';
        $child['active_skill'] = $session['active_skill'] ?? null;
        $this->save($child);

        return $child;
    }

    /**
     * @param  array<string,mixed>  $session
     * @return array<string,mixed>
     */
    public function rewind(array $session, int $keepTurns): array
    {
        $keepTurns = max(0, $keepTurns);
        $messages = array_values((array) ($session['messages'] ?? []));
        // keep pairs roughly: each turn = user+assistant (~2 messages)
        $keepMessages = $keepTurns * 2;
        if ($keepMessages < count($messages)) {
            $session['messages'] = array_slice($messages, 0, $keepMessages);
        }
        $session['turn_count'] = $keepTurns;
        $session['rewound_at'] = now()->toJSON();
        $this->save($session);

        return $session;
    }

    /**
     * @param  array<string,mixed>  $session
     * @return array<string,mixed>
     */
    public function compact(array $session, string $note = ''): array
    {
        $messages = array_values((array) ($session['messages'] ?? []));
        $summaryParts = [];
        foreach (array_slice($messages, -12) as $m) {
            $role = (string) ($m['role'] ?? '?');
            $content = mb_substr((string) ($m['content'] ?? ''), 0, 400);
            $summaryParts[] = strtoupper($role).': '.$content;
        }
        $summary = "COMPACTED HISTORY\n".($note !== '' ? "Keep note: {$note}\n" : '').implode("\n---\n", $summaryParts);
        $session['messages'] = [
            ['role' => 'system', 'content' => $summary, 'at' => now()->toJSON()],
        ];
        $session['compacted_at'] = now()->toJSON();
        $session['compact_count'] = (int) ($session['compact_count'] ?? 0) + 1;
        $this->save($session);

        return $session;
    }

    public function latestForWorkspace(string $workspace): ?array
    {
        $base = $this->root().'/'.$this->cwdHash($workspace);
        if (! File::isDirectory($base)) {
            return null;
        }

        $best = null;
        $bestMtime = 0;
        foreach (File::directories($base) as $dir) {
            $path = $dir.'/session.json';
            if (! File::isFile($path)) {
                continue;
            }
            $mtime = File::lastModified($path);
            if ($mtime >= $bestMtime) {
                $bestMtime = $mtime;
                $best = $this->readSession($path);
            }
        }

        return $best;
    }

    /**
     * @param  array<string,mixed>  $session
     */
    public function save(array $session): void
    {
        $session['updated_at'] = now()->toJSON();
        $this->writeSession($session);
    }

    /**
     * @param  array<string,mixed>  $session
     * @param  array<string,mixed>  $event
     */
    public function appendEvent(array $session, array $event): void
    {
        $dir = (string) ($session['dir'] ?? '');
        if ($dir === '') {
            return;
        }
        $line = json_encode($event, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        if ($line === false) {
            return;
        }
        File::append($dir.'/events.jsonl', $line."\n");
    }

    /**
     * @param  array<string,mixed>  $session
     */
    private function writeSession(array $session): void
    {
        $dir = (string) ($session['dir'] ?? '');
        File::ensureDirectoryExists($dir);
        File::put(
            $dir.'/session.json',
            json_encode($session, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE)."\n"
        );
    }

    /**
     * @return array<string,mixed>|null
     */
    private function readSession(string $path): ?array
    {
        $raw = File::get($path);
        $data = json_decode($raw, true);
        if (! is_array($data)) {
            return null;
        }
        $data['dir'] = dirname($path);

        return $data;
    }
}
