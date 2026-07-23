<?php

namespace App\Services\Ai\TerminalDev\Subagents;

use App\Services\Ai\TerminalDev\Session\AtlasTerminalSessionStore;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Str;

/**
 * Spawn isolated child sessions (explore=read-only tools, plan=plan mode, general=full).
 * Runtime resolved lazily to avoid container cycles with AtlasTerminalSessionRuntime.
 */
final class TerminalSubagentRunner
{
    public function __construct(
        private readonly AtlasTerminalSessionStore $store,
    ) {}

    private function runtime(): \App\Services\Ai\TerminalDev\Session\AtlasTerminalSessionRuntime
    {
        return app(\App\Services\Ai\TerminalDev\Session\AtlasTerminalSessionRuntime::class);
    }

    /**
     * @param  array<string,mixed>  $parent
     * @return array{child:array<string,mixed>,events:list<array<string,mixed>>,summary:string,worktree:?string}
     */
    public function spawn(array $parent, string $task, string $type = 'general-purpose', bool $worktree = false): array
    {
        $type = strtolower(trim($type));
        if (! in_array($type, ['general-purpose', 'explore', 'plan'], true)) {
            $type = 'general-purpose';
        }

        $workspace = (string) $parent['workspace'];
        $worktreePath = null;
        if ($worktree) {
            $worktreePath = $this->createWorktree($workspace, (string) $parent['id']);
            if ($worktreePath !== null) {
                $workspace = $worktreePath;
            }
        }

        $permission = match ($type) {
            'explore' => 'read',
            default => (string) ($parent['permission_mode'] ?? 'write'),
        };
        $mode = $type === 'plan' ? 'plan' : 'normal';

        $child = $this->store->create($workspace, [
            'mode' => $mode,
            'profile' => (string) ($parent['profile'] ?? 'dev'),
            'permission_mode' => $permission,
            'yolo' => $type === 'explore' ? true : (bool) ($parent['yolo'] ?? false),
            'parent_session_id' => $parent['id'] ?? null,
            'subagent_type' => $type,
        ]);
        $child['parent_session_id'] = $parent['id'] ?? null;
        $child['subagent_type'] = $type;
        $this->store->save($child);

        $prefixed = match ($type) {
            'explore' => "You are an explore subagent (read-only). Investigate and report.\n\nTask: {$task}",
            'plan' => "You are a plan subagent. Produce an implementation plan only.\n\nTask: {$task}",
            default => $task,
        };

        $events = $this->runtime()->prompt($child, $prefixed);
        $summary = $this->extractSummary($events);

        return [
            'child' => $this->runtime()->publicSession($child),
            'events' => $events,
            'summary' => $summary,
            'worktree' => $worktreePath,
        ];
    }

    private function createWorktree(string $workspace, string $parentId): ?string
    {
        if (! is_dir($workspace.'/.git') && ! is_file($workspace.'/.git')) {
            return null;
        }
        $branch = 'atlas-term/'.substr(preg_replace('/[^a-zA-Z0-9]+/', '-', $parentId) ?? 'child', 0, 24).'-'.Str::random(4);
        $path = rtrim(sys_get_temp_dir(), '/').'/atlas-term-wt-'.Str::random(8);
        $cmd = sprintf(
            'git -C %s worktree add -b %s %s HEAD 2>&1',
            escapeshellarg($workspace),
            escapeshellarg($branch),
            escapeshellarg($path)
        );
        exec($cmd, $out, $code);
        if ($code !== 0 || ! is_dir($path)) {
            return null;
        }
        File::put($path.'/.atlas-terminal-worktree.json', json_encode([
            'parent_workspace' => $workspace,
            'branch' => $branch,
            'created_at' => now()->toJSON(),
        ], JSON_PRETTY_PRINT));

        return $path;
    }

    /**
     * @param  list<array<string,mixed>>  $events
     */
    private function extractSummary(array $events): string
    {
        $chunks = [];
        foreach ($events as $event) {
            if (($event['method'] ?? '') !== 'session/update') {
                continue;
            }
            $kind = data_get($event, 'params.kind');
            if ($kind === 'agent_message_chunk' || $kind === 'plan') {
                $chunks[] = (string) data_get($event, 'params.text', '');
            }
        }
        $text = trim(implode("\n\n", array_filter($chunks)));

        return $text !== '' ? mb_substr($text, 0, 4000) : 'subagent finished with no text';
    }
}
