<?php

namespace App\Services\Ai\TerminalDev\Superiority;

use App\Services\Ai\Runtime\AiToolRuntime;
use App\Services\Ai\Runtime\ToolInvocation;
use App\Services\Ai\TerminalDev\Protocol\AapSchema;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Str;

/**
 * Atlas-superior in-session: evidence receipts, review stub, forge profile, doctor snapshot.
 */
final class TerminalSuperiorityService
{
    public function __construct(
        private readonly AiToolRuntime $tools,
    ) {}

    /**
     * @param  array<string,mixed>  $session
     * @return array<string,mixed>
     */
    public function evidenceReceipt(array $session, string $kind, array $payload = []): array
    {
        $receipt = [
            'schema' => 'atlas.terminal.evidence_receipt.v1',
            'id' => (string) Str::orderedUuid(),
            'kind' => $kind,
            'session_id' => $session['id'] ?? null,
            'workspace_hash' => hash('sha256', (string) ($session['workspace'] ?? '')),
            'at' => now()->toJSON(),
            'payload_hashes' => [
                'payload_sha256' => hash('sha256', json_encode($payload, JSON_UNESCAPED_SLASHES) ?: ''),
            ],
            // provider-safe: no raw secrets
            'notes' => 'receipt only — raw content stays local in session events',
        ];

        $dir = (string) ($session['dir'] ?? '');
        if ($dir !== '') {
            File::ensureDirectoryExists($dir.'/evidence');
            File::put(
                $dir.'/evidence/'.$receipt['id'].'.json',
                json_encode($receipt, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES)."\n"
            );
        }

        return $receipt;
    }

    /**
     * @param  array<string,mixed>  $session
     * @return list<array<string,mixed>>
     */
    public function review(array $session): array
    {
        $events = [];
        $workspace = (string) $session['workspace'];
        $sessionId = (string) $session['id'];

        $git = $this->tools->execute(ToolInvocation::make('git.status', $workspace, [], [
            'permission_mode' => 'read',
            'source' => 'atlas_terminal_review',
        ]));
        $diff = $this->tools->execute(ToolInvocation::make('git.diff', $workspace, [], [
            'permission_mode' => 'read',
            'source' => 'atlas_terminal_review',
        ]));

        $statusOut = (string) ($git->output !== '' ? $git->output : $git->stdout);
        $diffOut = (string) ($diff->output !== '' ? $diff->output : $diff->stdout);
        $receipt = $this->evidenceReceipt($session, 'review', [
            'git_status_sha' => hash('sha256', $statusOut),
            'git_diff_sha' => hash('sha256', $diffOut),
            'status_lines' => substr_count($statusOut, "\n"),
            'diff_bytes' => strlen($diffOut),
        ]);

        $events[] = AapSchema::notification('atlas/evidence', [
            'session_id' => $sessionId,
            'receipt' => $receipt,
        ]);

        $text = "## In-session review\n\n"
            ."### git status\n```\n".mb_substr($statusOut !== '' ? $statusOut : '(empty)', 0, 3000)."\n```\n\n"
            ."### git diff (truncated)\n```\n".mb_substr($diffOut !== '' ? $diffOut : '(no diff)', 0, 5000)."\n```\n\n"
            .'### evidence receipt: `'.$receipt['id']."`\n\n"
            .'_Optional deep packet: `atlas:review:deep --task-id=<id> --json`._'."\n";

        $events[] = AapSchema::sessionUpdate($sessionId, 'agent_message_chunk', ['text' => $text]);
        $events[] = AapSchema::sessionUpdate($sessionId, 'status', [
            'text' => 'review complete',
            'evidence_id' => $receipt['id'],
        ]);

        return $events;
    }

    public function doctor(array $session): array
    {
        $workspace = (string) $session['workspace'];
        $home = $_SERVER['HOME'] ?? getenv('HOME') ?: '';

        return [
            'schema' => 'atlas.terminal.doctor.v1',
            'session' => [
                'id' => $session['id'] ?? null,
                'mode' => $session['mode'] ?? null,
                'profile' => $session['profile'] ?? null,
                'yolo' => (bool) ($session['yolo'] ?? false),
            ],
            'open_brain' => (bool) config('atlas_terminal.open_brain.enabled', true),
            'hermes_allowed' => (bool) config('atlas_terminal.hermes_allowed', true),
            'provider' => (string) config('atlas_terminal.provider', 'hermes_cli'),
            'hermes_dry_run' => (bool) config('atlas_terminal.hermes_dry_run', false),
            'hermes_cli_oneshot' => (bool) config('atlas_terminal.hermes_cli_oneshot', true),
            'provider_order' => config('atlas_terminal.default_provider_order', []),
            'sessions_root_exists' => is_dir((string) config('atlas_terminal.sessions_root')),
            'hooks_user' => $home !== '' && is_dir(rtrim((string) $home, '/').'/.atlas/hooks'),
            'hooks_project' => is_dir($workspace.'/.atlas/hooks'),
            'mcp_user' => $home !== '' && is_file(rtrim((string) $home, '/').'/.atlas/mcp.json'),
            'mcp_project' => is_file($workspace.'/.atlas/mcp.json') || is_file($workspace.'/.mcp.json'),
            'atlas_term_release' => is_file(base_path('../atlas-terminal/target/release/atlas-term')),
            'protocol_version' => AapSchema::VERSION,
        ];
    }
}
