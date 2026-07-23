<?php

namespace App\Services\Ai\TerminalDev\Session;

use App\Services\Ai\AtlasOpenBrainContextPackService;
use App\Services\Ai\TerminalDev\Hooks\TerminalHookRunner;
use App\Services\Ai\TerminalDev\Mcp\TerminalMcpClient;
use App\Services\Ai\TerminalDev\Plan\PlanModeGuard;
use App\Services\Ai\TerminalDev\Protocol\AapSchema;
use App\Services\Ai\TerminalDev\Skills\TerminalSkillCatalog;
use App\Services\Ai\TerminalDev\Subagents\TerminalSubagentRunner;
use App\Services\Ai\TerminalDev\Superiority\TerminalSuperiorityService;
use App\Services\Ai\TerminalDev\Providers\TerminalHermesBridge;
use App\Services\Ai\TerminalDev\Tools\TerminalToolHost;
use Illuminate\Support\Facades\File;

/**
 * Multi-turn Terminal Dev runtime (Grok-class session kernel).
 */
final class AtlasTerminalSessionRuntime
{
    public function __construct(
        private readonly AtlasTerminalSessionStore $store,
        private readonly TerminalToolHost $tools,
        private readonly TerminalSkillCatalog $skills,
        private readonly PlanModeGuard $planGuard,
        private readonly AtlasOpenBrainContextPackService $openBrain,
        private readonly TerminalHookRunner $hooks,
        private readonly TerminalMcpClient $mcp,
        private readonly TerminalSubagentRunner $subagents,
        private readonly TerminalSuperiorityService $superiority,
        private readonly TerminalSessionOps $ops,
        private readonly TerminalHermesBridge $hermes,
    ) {}

    /**
     * @param  array<string,mixed>  $options
     * @return array<string,mixed>
     */
    public function start(string $workspace, array $options = []): array
    {
        $workspace = realpath($workspace) ?: $workspace;

        if (! empty($options['resume'])) {
            $session = is_string($options['resume']) && $options['resume'] !== '' && $options['resume'] !== true
                ? $this->store->load($options['resume'], $workspace)
                : $this->store->latestForWorkspace($workspace);
            if ($session !== null) {
                $hook = $this->hooks->run('SessionStart', $workspace, ['session_id' => $session['id'] ?? null, 'resumed' => true]);
                $session['_last_hook'] = $hook;

                return $session;
            }
        }

        $session = $this->store->create($workspace, [
            'mode' => (string) ($options['mode'] ?? 'normal'),
            'profile' => (string) ($options['profile'] ?? 'dev'),
            'permission_mode' => (string) ($options['permission_mode'] ?? config('atlas_terminal.permission.default_mode', 'write')),
            'yolo' => (bool) ($options['yolo'] ?? config('atlas_terminal.permission.yolo_default', false)),
        ]);
        $hook = $this->hooks->run('SessionStart', $workspace, ['session_id' => $session['id'] ?? null, 'resumed' => false]);
        $session['_last_hook'] = $hook;
        $this->store->save($session);

        return $session;
    }

    /**
     * @param  array<string,mixed>  $session
     * @return list<array<string,mixed>>
     */
    public function handleSlash(array &$session, string $line): array
    {
        $events = [];
        $raw = trim($line);
        $parts = preg_split('/\s+/', $raw, 2) ?: [];
        $cmd = strtolower(ltrim((string) ($parts[0] ?? ''), '/'));
        $args = trim((string) ($parts[1] ?? ''));
        $sessionId = (string) $session['id'];
        $workspace = (string) $session['workspace'];

        $emit = function (string $kind, array $data = []) use (&$events, $sessionId): void {
            $events[] = AapSchema::sessionUpdate($sessionId, $kind, $data);
        };

        switch ($cmd) {
            case 'help':
                $emit('agent_message_chunk', ['text' => $this->helpText()]);
                break;

            case 'skills':
                $list = $this->skills->list($workspace);
                $lines = array_map(
                    fn (array $s): string => sprintf('- **%s** (%s) — %s', $s['name'], $s['source_tier'], $s['description']),
                    $list
                );
                $emit('agent_message_chunk', [
                    'text' => $lines === [] ? 'No skills discovered.' : "Skills:\n".implode("\n", $lines),
                ]);
                break;

            case 'new':
            case 'clear':
                $session = $this->store->create($workspace, [
                    'mode' => $session['mode'] ?? 'normal',
                    'profile' => $session['profile'] ?? 'dev',
                    'permission_mode' => $session['permission_mode'] ?? 'write',
                    'yolo' => $session['yolo'] ?? false,
                ]);
                $sessionId = (string) $session['id'];
                $emit = function (string $kind, array $data = []) use (&$events, $sessionId): void {
                    $events[] = AapSchema::sessionUpdate($sessionId, $kind, $data);
                };
                $emit('status', ['text' => 'New session '.$session['id'], 'session' => $this->publicSession($session)]);
                break;

            case 'resume':
                $target = $args !== '' ? $args : 'latest';
                $loaded = $target === 'latest'
                    ? $this->store->latestForWorkspace($workspace)
                    : $this->store->load($target, $workspace);
                if ($loaded === null) {
                    $emit('agent_message_chunk', ['text' => 'Session not found: '.$target]);
                    break;
                }
                $session = $loaded;
                $emit('status', ['text' => 'Resumed '.$session['id'], 'session' => $this->publicSession($session)]);
                break;

            case 'sessions':
                $rows = $this->store->listForWorkspace($workspace, 25);
                $lines = array_map(
                    fn (array $r): string => sprintf(
                        '- `%s` turns=%d mode=%s updated=%s %s',
                        $r['id'],
                        $r['turn_count'],
                        $r['mode'],
                        $r['updated_at'] ?? '?',
                        $r['title'] ?? ''
                    ),
                    $rows
                );
                $emit('agent_message_chunk', [
                    'text' => $lines === [] ? 'No sessions for this workspace.' : "Sessions:\n".implode("\n", $lines),
                ]);
                break;

            case 'fork':
                $session = $this->store->fork($session);
                $emit('status', ['text' => 'Forked to '.$session['id'], 'session' => $this->publicSession($session)]);
                break;

            case 'rewind':
                $n = max(0, (int) ($args !== '' ? $args : ((int) ($session['turn_count'] ?? 1) - 1)));
                $session = $this->store->rewind($session, $n);
                $emit('status', ['text' => "Rewound to {$n} turns", 'session' => $this->publicSession($session)]);
                break;

            case 'compact':
                $session = $this->store->compact($session, $args);
                $emit('status', ['text' => 'Compacted (count='.($session['compact_count'] ?? 1).')']);
                break;

            case 'context':
                $emit('agent_message_chunk', [
                    'text' => "```json\n".json_encode($this->ops->contextBreakdown($session), JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES)."\n```",
                ]);
                break;

            case 'rename':
            case 'title':
                $session['title'] = $args !== '' ? $args : ($session['title'] ?? 'session');
                $this->store->save($session);
                $emit('status', ['text' => 'Title: '.$session['title']]);
                break;

            case 'export':
                $path = $this->ops->exportMarkdown($session, $args !== '' ? $args : null);
                $emit('status', ['text' => 'Exported to '.$path]);
                break;

            case 'copy':
                $path = $this->ops->copyLastAssistant($session);
                $emit('status', ['text' => $path ? 'Copied last assistant reply → '.$path : 'No assistant message to copy']);
                break;

            case 'yolo':
            case 'always-approve':
                $session['yolo'] = ! (bool) ($session['yolo'] ?? false);
                $this->store->save($session);
                $emit('status', ['text' => $session['yolo'] ? 'YOLO on' : 'YOLO off']);
                break;

            case 'auto':
                $session['permission_mode'] = ($session['permission_mode'] ?? '') === 'write' ? 'read' : 'write';
                // auto classifier: treat as write for safe tools only via yolo=false + write mode
                $session['auto_safe'] = ! (bool) ($session['auto_safe'] ?? false);
                $this->store->save($session);
                $emit('status', [
                    'text' => ($session['auto_safe'] ?? false)
                        ? 'Auto-safe on (read tools free; writes still ask via yolo off)'
                        : 'Auto-safe off',
                ]);
                break;

            case 'plan':
                $session['mode'] = 'plan';
                $path = $this->planGuard->ensurePlanFile($session);
                $this->store->save($session);
                if ($args !== '') {
                    return array_merge($events, $this->prompt($session, $args));
                }
                $emit('status', ['text' => 'Plan mode ON — only '.$path.' is writable. /approve to leave.']);
                break;

            case 'approve':
            case 'approve-plan':
                $session['mode'] = 'normal';
                $session['plan_approved_at'] = now()->toJSON();
                $this->store->save($session);
                $receipt = $this->superiority->evidenceReceipt($session, 'plan_approved', [
                    'plan_path' => $session['plan_path'] ?? null,
                ]);
                $events[] = AapSchema::notification('atlas/evidence', [
                    'session_id' => $sessionId,
                    'receipt' => $receipt,
                ]);
                $emit('status', ['text' => 'Plan approved → normal mode', 'evidence_id' => $receipt['id']]);
                break;

            case 'revise-plan':
            case 'request-changes':
                $session['mode'] = 'plan';
                $this->store->save($session);
                $note = $args !== '' ? $args : 'Revise the plan based on operator feedback.';
                return array_merge($events, $this->prompt($session, $note));

            case 'view-plan':
            case 'show-plan':
                $planPath = (string) ($session['plan_path'] ?? '');
                $body = ($planPath !== '' && File::isFile($planPath)) ? File::get($planPath) : '_no plan.md yet_';
                $emit('plan', ['path' => $planPath, 'text' => $body]);
                break;

            case 'normal':
            case 'unplan':
            case 'quit-plan':
                $session['mode'] = 'normal';
                $this->store->save($session);
                $emit('status', ['text' => 'Normal mode']);
                break;

            case 'status':
            case 'session-info':
            case 'info':
                $emit('status', ['session' => $this->publicSession($session)]);
                break;

            case 'paste-image':
            case 'paste_image':
            case 'clipboard-image':
                try {
                    $img = app(\App\Services\Ai\Cli\AtlasImageAttachmentService::class)->fromClipboard($workspace);
                    $session['pending_attachments'] = array_values(array_merge(
                        (array) ($session['pending_attachments'] ?? []),
                        [[
                            'path' => $img['path'],
                            'mime' => $img['mime_type'] ?? 'image/png',
                            'bytes' => $img['bytes'] ?? null,
                            'source' => 'clipboard',
                            'label' => $img['original_name'] ?? 'clipboard.png',
                        ]]
                    ));
                    $this->store->save($session);
                    $emit('status', [
                        'text' => 'Clipboard image attached [img:'.count($session['pending_attachments']).'] '.$img['path'],
                        'attachments' => $session['pending_attachments'],
                    ]);
                } catch (\Throwable $e) {
                    $emit('agent_message_chunk', ['text' => 'Paste image failed: '.$e->getMessage()]);
                }
                break;

            case 'tools':
                $emit('agent_message_chunk', [
                    'text' => 'Tools: '.implode(', ', $this->tools->available()),
                ]);
                break;

            case 'mcp':
            case 'mcps':
                $servers = $this->mcp->servers($workspace);
                if ($args === 'tools') {
                    $tools = $this->mcp->listTools($workspace);
                    $emit('agent_message_chunk', [
                        'text' => $tools === []
                            ? 'No MCP tools (configure ~/.atlas/mcp.json).'
                            : "MCP tools:\n".implode("\n", array_map(
                                fn (array $t): string => '- '.$t['server'].'/'.$t['name'].' — '.$t['description'],
                                $tools
                            )),
                    ]);
                } else {
                    $emit('agent_message_chunk', [
                        'text' => $servers === []
                            ? 'No MCP servers. Add ~/.atlas/mcp.json'
                            : "MCP servers:\n".implode("\n", array_map(
                                fn (array $s): string => '- **'.$s['name'].'** ('.$s['source'].') `'.$s['command'].'`',
                                $servers
                            )),
                    ]);
                }
                break;

            case 'plugins':
                $plugins = app(\App\Services\Ai\TerminalDev\Plugins\TerminalPluginCatalog::class)->list($workspace);
                $emit('agent_message_chunk', [
                    'text' => $plugins === []
                        ? 'No plugins under ~/.atlas/plugins or .atlas/plugins'
                        : "Plugins:\n".implode("\n", array_map(
                            fn (array $pl): string => sprintf(
                                '- **%s** (%s) skills=%s hooks=%s mcp=%s',
                                $pl['name'],
                                $pl['source'],
                                $pl['has_skills'] ? 'y' : 'n',
                                $pl['has_hooks'] ? 'y' : 'n',
                                $pl['has_mcp'] ? 'y' : 'n'
                            ),
                            $plugins
                        )),
                ]);
                break;

            case 'hooks':
                $emit('agent_message_chunk', [
                    'text' => "Hook events: ".implode(', ', TerminalHookRunner::EVENTS)
                        ."\nPlace JSON under `~/.atlas/hooks/` or `.atlas/hooks/`.\nExit code 2 on PreToolUse/Stop blocks.",
                ]);
                break;

            case 'subagent':
            case 'spawn':
                // /subagent explore task...  or /subagent plan --worktree task
                $useWt = str_contains($args, '--worktree');
                $args2 = trim(str_replace('--worktree', '', $args));
                $sp = preg_split('/\s+/', $args2, 2) ?: [];
                $type = strtolower((string) ($sp[0] ?? 'general-purpose'));
                $task = trim((string) ($sp[1] ?? ''));
                if ($task === '' && ! in_array($type, ['explore', 'plan', 'general-purpose'], true)) {
                    $task = $args2;
                    $type = 'general-purpose';
                }
                if ($task === '') {
                    $emit('agent_message_chunk', ['text' => 'Usage: /subagent explore|plan|general-purpose [--worktree] <task>']);
                    break;
                }
                if (! in_array($type, ['explore', 'plan', 'general-purpose'], true)) {
                    $type = 'general-purpose';
                }
                $emit('status', ['text' => "Spawning subagent type={$type}".($useWt ? ' worktree' : '')]);
                $result = $this->subagents->spawn($session, $task, $type, $useWt);
                $session['last_subagent'] = $result['child'];
                $this->store->save($session);
                $emit('agent_message_chunk', [
                    'text' => "## Subagent ({$type})\n\nchild: `".($result['child']['id'] ?? '?')."`\n\n".$result['summary'],
                ]);
                break;

            case 'review':
                $rev = $this->superiority->review($session);
                foreach ($rev as $e) {
                    $events[] = $e;
                    $this->store->appendEvent($session, $e);
                }

                return $events;

            case 'doctor':
                $doc = $this->superiority->doctor($session);
                $doc['hermes_bridge'] = [
                    'enabled' => $this->hermes->isEnabled(),
                    'dry_run' => $this->hermes->isDryRun(),
                ];
                $doc['protocol'] = \App\Services\Ai\TerminalDev\Protocol\AapSchema::VERSION;
                $emit('agent_message_chunk', [
                    'text' => "## Terminal doctor\n\n```json\n".json_encode($doc, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES)."\n```",
                ]);
                break;

            case 'dev':
            case 'forge':
            case 'autonomos':
            case 'autonomous':
                $profile = $cmd === 'autonomous' ? 'autonomos' : $cmd;
                $session['profile'] = $profile;
                if ($profile === 'forge') {
                    $session['permission_mode'] = 'danger';
                }
                $this->store->save($session);
                $receipt = $this->superiority->evidenceReceipt($session, 'profile_switch', ['profile' => $profile]);
                $events[] = AapSchema::notification('atlas/evidence', [
                    'session_id' => $sessionId,
                    'receipt' => $receipt,
                ]);
                $emit('status', [
                    'text' => "Profile → {$profile}",
                    'session' => $this->publicSession($session),
                    'evidence_id' => $receipt['id'],
                ]);
                if ($args !== '') {
                    return array_merge($events, $this->prompt($session, $args));
                }
                break;

            case 'promote-forge':
                $session['profile'] = 'forge';
                $session['promotion'] = [
                    'to' => 'forge',
                    'at' => now()->toJSON(),
                    'note' => $args,
                ];
                $this->store->save($session);
                $receipt = $this->superiority->evidenceReceipt($session, 'promote_forge', ['note' => $args]);
                $events[] = AapSchema::notification('atlas/evidence', [
                    'session_id' => $sessionId,
                    'receipt' => $receipt,
                ]);
                $emit('status', ['text' => 'Promoted session profile to forge (Obra still operator-gated)', 'evidence_id' => $receipt['id']]);
                break;

            default:
                $skill = $this->skills->find($workspace, $cmd);
                if ($skill !== null) {
                    $session['active_skill'] = $skill['name'];
                    $this->store->save($session);
                    $prompt = "Follow this skill carefully.\n\n# Skill: {$skill['name']}\n\n{$skill['body']}\n\n";
                    $prompt .= $args !== ''
                        ? "User request: {$args}\n"
                        : "User invoked /{$cmd}. Summarize the skill and wait for a task if needed.\n";

                    return array_merge($events, $this->prompt($session, $prompt));
                }
                $emit('agent_message_chunk', ['text' => "Unknown slash /{$cmd}. Try /help."]);
        }

        foreach ($events as $event) {
            $this->store->appendEvent($session, $event);
        }

        return $events;
    }

    /**
     * @param  array<string,mixed>  $session
     * @return list<array<string,mixed>>
     */
    /**
     * @param  array<string,mixed>  $options
     */
    public function prompt(array &$session, string $text, array $options = []): array
    {
        $text = trim($text);
        $events = [];
        $sessionId = (string) $session['id'];
        $workspace = (string) $session['workspace'];

        $emit = function (string $kind, array $data = []) use (&$events, $sessionId, &$session): void {
            $event = AapSchema::sessionUpdate($sessionId, $kind, $data);
            $events[] = $event;
            $this->store->appendEvent($session, $event);
        };

        if ($text === '') {
            $emit('agent_message_chunk', ['text' => 'Empty prompt.']);

            return $events;
        }

        if (str_starts_with($text, '/')) {
            return $this->handleSlash($session, $text);
        }

        // auto-compact
        $threshold = (int) config('atlas_terminal.auto_compact_turns', 40);
        if ($threshold > 0 && (int) ($session['turn_count'] ?? 0) >= $threshold) {
            $session = $this->store->compact($session, 'auto-compact threshold');
            $emit('status', ['text' => "Auto-compacted at turn threshold {$threshold}"]);
        }

        // Merge pending session attachments (e.g. /paste-image) with call options.
        $pending = array_values((array) ($session['pending_attachments'] ?? []));
        if ($pending !== []) {
            $optsAtt = $options['attachments'] ?? [];
            if (isset($optsAtt['images']) && is_array($optsAtt['images'])) {
                $options['attachments'] = array_merge($optsAtt['images'], $pending);
            } elseif (is_array($optsAtt)) {
                $options['attachments'] = array_merge($optsAtt, $pending);
            } else {
                $options['attachments'] = $pending;
            }
            $session['pending_attachments'] = [];
        }

        $session['messages'][] = ['role' => 'user', 'content' => $text, 'at' => now()->toJSON()];
        $session['turn_count'] = (int) ($session['turn_count'] ?? 0) + 1;

        $packMeta = null;
        if ((bool) config('atlas_terminal.open_brain.enabled', true)) {
            try {
                $pack = $this->openBrain->packFor($text, [
                    'workspace' => $workspace,
                    'cwd' => $workspace,
                    'budget' => (int) config('atlas_terminal.open_brain.budget_chars', 4000),
                    'task_type' => 'dev',
                    'domain' => 'programming',
                ]);
                $packMeta = [
                    'schema' => $pack['schema'] ?? null,
                    'hash' => $pack['pack_hash'] ?? $pack['content_hash'] ?? data_get($pack, 'provenance.pack_hash'),
                    'honesty' => $pack['honesty'] ?? null,
                    'markdown_chars' => isset($pack['markdown']) ? strlen((string) $pack['markdown']) : 0,
                ];
                $events[] = AapSchema::notification(AapSchema::NOTIFY_CONTEXT_PACK, [
                    'session_id' => $sessionId,
                    'context_pack' => $packMeta,
                ]);
                $this->store->appendEvent($session, end($events));
            } catch (\Throwable $e) {
                if (! (bool) config('atlas_terminal.open_brain.fail_open', true)) {
                    throw $e;
                }
                $emit('status', ['text' => 'Open Brain fail-open: '.$e->getMessage()]);
            }
        }

        if (! empty($session['active_skill'])) {
            $emit('status', ['text' => 'Active skill: '.$session['active_skill']]);
        }
        if (($session['profile'] ?? 'dev') !== 'dev') {
            $emit('status', ['text' => 'Profile: '.$session['profile']]);
        }

        if (($session['mode'] ?? 'normal') === 'plan') {
            $planPath = $this->planGuard->ensurePlanFile($session);
            $planBody = $this->buildPlanDraft($session, $text, $packMeta);
            File::put($planPath, $planBody);
            $emit('plan', ['path' => $planPath, 'text' => $planBody]);
            $emit('agent_message_chunk', [
                'text' => "Plan mode → `{$planPath}`\n\n/approve to build · /revise-plan <notes> · /view-plan\n\n".$planBody,
            ]);
            $session['messages'][] = ['role' => 'assistant', 'content' => $planBody, 'at' => now()->toJSON()];
            $this->store->save($session);

            return $events;
        }

        // Explicit tool DSL stays on local tool host (tests + precise ops).
        $forceLocal = (bool) preg_match('/\btool:[a-z0-9_.]+/i', $text)
            || (bool) config('atlas_terminal.force_local_planner', false);

        if (! $forceLocal && $this->hermes->isEnabled()) {
            $attachCount = 0;
            $optsAtt = $options['attachments'] ?? [];
            if (is_array($optsAtt)) {
                if (isset($optsAtt['images']) && is_array($optsAtt['images'])) {
                    $attachCount = count($optsAtt['images']);
                } else {
                    $attachCount = count($optsAtt);
                }
            }
            $modeLabel = $this->hermes->isDryRun()
                ? 'dry-run'
                : ($attachCount > 0 ? 'chat · image' : 'cli oneshot');
            $emit('status', [
                'text' => 'hermes · '.$modeLabel.($attachCount > 0 ? " · {$attachCount} img" : ''),
                'provider' => 'hermes_cli',
            ]);

            $openBrainMd = null;
            // re-fetch pack markdown if enabled (second call may be cached)
            if ((bool) config('atlas_terminal.open_brain.enabled', true) && $packMeta !== null) {
                try {
                    $pack = $this->openBrain->packFor($text, [
                        'workspace' => $workspace,
                        'cwd' => $workspace,
                        'budget' => (int) config('atlas_terminal.open_brain.budget_chars', 4000),
                    ]);
                    $openBrainMd = is_string($pack['markdown'] ?? null) ? $pack['markdown'] : null;
                } catch (\Throwable) {
                    $openBrainMd = null;
                }
            }

            $skillBody = null;
            if (! empty($session['active_skill'])) {
                $skill = $this->skills->find($workspace, (string) $session['active_skill']);
                $skillBody = $skill['body'] ?? null;
            }

            $hermesResult = $this->hermes->runTurn($session, $text, [
                'open_brain_markdown' => $openBrainMd,
                'skill_body' => $skillBody,
                'timeout' => (int) ($session['timeout_seconds'] ?? config('atlas_terminal.timeout_seconds', 300)),
                'attachments' => $options['attachments'] ?? [],
            ]);

            if ($hermesResult['timed_out'] ?? false) {
                $emit('status', ['text' => 'Hermes timed out — session kept; try smaller task or raise --timeout']);
            }

            $body = trim((string) ($hermesResult['text'] ?? ''));
            $err = (string) ($hermesResult['error'] ?? '');
            $ms = (int) ($hermesResult['duration_ms'] ?? 0);
            $ok = (bool) ($hermesResult['ok'] ?? false);
            $dry = (bool) ($hermesResult['dry_run'] ?? false);

            // Clean assistant body only (Grok-style). Meta → status, not prose.
            if ($dry) {
                $answer = $body !== '' ? $body : '(hermes dry-run — empty body)';
            } elseif (! $ok) {
                $answer = 'Hermes error: '.($err !== '' ? $err : 'failed')." ({$ms}ms)";
            } elseif ($body === '') {
                $answer = "Hermes returned empty output ({$ms}ms). Retry or check `hermes` health.";
            } else {
                $answer = $body;
            }

            $metaBits = [];
            if ($ms > 0) {
                $metaBits[] = sprintf('%.1fs', $ms / 1000);
            }
            if ($attachCount > 0) {
                $metaBits[] = "{$attachCount} img";
            }
            if ($metaBits !== []) {
                $emit('status', ['text' => 'done · '.implode(' · ', $metaBits)]);
            }

            $emit('agent_message_chunk', ['text' => $answer]);
            $session['messages'][] = ['role' => 'assistant', 'content' => $answer, 'at' => now()->toJSON()];
            $receipt = $this->superiority->evidenceReceipt($session, 'hermes_turn', [
                'ok' => (bool) $hermesResult['ok'],
                'dry_run' => (bool) ($hermesResult['dry_run'] ?? false),
                'duration_ms' => (int) ($hermesResult['duration_ms'] ?? 0),
                'timed_out' => (bool) ($hermesResult['timed_out'] ?? false),
            ]);
            $events[] = AapSchema::notification('atlas/evidence', [
                'session_id' => $sessionId,
                'receipt' => $receipt,
            ]);
            $this->store->appendEvent($session, end($events));
            $this->hooks->run('Stop', $workspace, ['session_id' => $sessionId, 'provider' => 'hermes_cli']);
            if (count($session['messages']) > 80) {
                $session['messages'] = array_slice($session['messages'], -80);
            }
            $this->store->save($session);

            return $events;
        }

        $plan = $this->planTools($session, $text);
        $toolOutputs = [];

        foreach ($plan as $step) {
            $tool = (string) $step['tool'];
            $toolArgs = is_array($step['arguments'] ?? null) ? $step['arguments'] : [];

            $pre = $this->hooks->run('PreToolUse', $workspace, [
                'tool' => $tool,
                'arguments' => $toolArgs,
                'session_id' => $sessionId,
            ]);
            if (! $pre['allowed']) {
                $emit('tool_call_update', [
                    'tool' => $tool,
                    'status' => 'blocked',
                    'result' => [
                        'ok' => false,
                        'summary' => 'Blocked by hook',
                        'output' => (string) ($pre['blocked_by'] ?? ''),
                        'stderr' => '',
                        'error_code' => 'hook_blocked',
                        'changed_files' => [],
                    ],
                ]);
                continue;
            }

            $emit('tool_call', [
                'tool' => $tool,
                'arguments' => $toolArgs,
                'status' => 'running',
            ]);

            $result = $this->tools->execute($session, $tool, $toolArgs);
            $this->hooks->run('PostToolUse', $workspace, [
                'tool' => $tool,
                'ok' => $result->ok,
                'session_id' => $sessionId,
            ]);

            $emit('tool_call_update', [
                'tool' => $tool,
                'status' => $result->ok ? 'ok' : 'error',
                'result' => [
                    'ok' => $result->ok,
                    'summary' => $result->summary,
                    'output' => mb_substr((string) ($result->output !== '' ? $result->output : $result->stdout), 0, 12000),
                    'stderr' => mb_substr((string) $result->stderr, 0, 2000),
                    'error_code' => $result->errorCode,
                    'changed_files' => $result->changedFiles,
                ],
            ]);
            $toolOutputs[] = [
                'tool' => $tool,
                'ok' => $result->ok,
                'summary' => $result->summary,
                'output' => mb_substr((string) ($result->output !== '' ? $result->output : $result->stdout), 0, 8000),
                'changed_files' => $result->changedFiles,
            ];
        }

        $answer = $this->composeAnswer($text, $toolOutputs, $packMeta, $session);
        $emit('agent_message_chunk', ['text' => $answer]);
        $session['messages'][] = ['role' => 'assistant', 'content' => $answer, 'at' => now()->toJSON()];

        $receipt = $this->superiority->evidenceReceipt($session, 'turn', [
            'tools' => array_map(fn ($t) => $t['tool'], $toolOutputs),
            'ok_count' => count(array_filter($toolOutputs, fn ($t) => $t['ok'])),
        ]);
        $events[] = AapSchema::notification('atlas/evidence', [
            'session_id' => $sessionId,
            'receipt' => $receipt,
        ]);
        $this->store->appendEvent($session, end($events));

        $this->hooks->run('Stop', $workspace, ['session_id' => $sessionId, 'turn' => $session['turn_count']]);

        if (count($session['messages']) > 80) {
            $session['messages'] = array_slice($session['messages'], -80);
        }
        $this->store->save($session);

        return $events;
    }

    /**
     * @return array<string,mixed>
     */
    public function publicSession(array $session): array
    {
        return [
            'id' => $session['id'] ?? null,
            'workspace' => $session['workspace'] ?? null,
            'mode' => $session['mode'] ?? 'normal',
            'profile' => $session['profile'] ?? 'dev',
            'title' => $session['title'] ?? null,
            'yolo' => (bool) ($session['yolo'] ?? false),
            'auto_safe' => (bool) ($session['auto_safe'] ?? false),
            'permission_mode' => $session['permission_mode'] ?? 'write',
            'turn_count' => (int) ($session['turn_count'] ?? 0),
            'compact_count' => (int) ($session['compact_count'] ?? 0),
            'active_skill' => $session['active_skill'] ?? null,
            'dir' => $session['dir'] ?? null,
            'forked_from' => $session['forked_from'] ?? null,
            'last_subagent' => $session['last_subagent'] ?? null,
            'provider_order' => $session['provider_order'] ?? config('atlas_terminal.default_provider_order'),
            'hermes_allowed' => (bool) ($session['hermes_allowed'] ?? false),
            'protocol_version' => AapSchema::VERSION,
            'provider' => (string) config('atlas_terminal.provider', 'hermes_cli'),
            'hermes_dry_run' => (bool) config('atlas_terminal.hermes_dry_run', false),
        ];
    }

    private function helpText(): string
    {
        return <<<'MD'
# Atlas Terminal Dev

Grok-class session · brain = Open Brain · muscle = Hermes CLI (local)

## Session
`/new` `/resume [id|latest]` `/sessions` `/fork` `/rewind N` `/compact [note]` `/context` `/rename title` `/export [path]` `/copy` `/status`

## Mode
`/plan` `/approve` `/revise-plan` `/view-plan` `/normal` `/yolo` `/auto`

## Skills & tools
`/skills` `/<skill>` `/tools` `/mcp` `/mcp tools` `/hooks`

## Agentic
`/subagent explore|plan|general-purpose [--worktree] <task>`

## Atlas superiority
`/review` `/doctor` `/dev` `/forge` `/autonomos` `/promote-forge [note]`

## Tips
- Natural: `leia README.md`, `busca Foo`, `liste arquivos`
- Explicit: `tool:file.read path=README.md`
- Muscle: **hermes_cli** (local `hermes`, oneshot `-z`). No API keys.
- `atlas dev` = oneshot complete-loop; this is the **session** product
MD;
    }

    /**
     * @param  array<string,mixed>  $session
     * @return list<array{tool:string,arguments:array<string,mixed>}>
     */
    private function planTools(array $session, string $text): array
    {
        $lower = mb_strtolower($text);
        $steps = [];
        $readOnlyProfile = ($session['permission_mode'] ?? '') === 'read'
            || ($session['subagent_type'] ?? '') === 'explore';

        if (preg_match_all('/tool:([a-z0-9_.]+)((?:\s+[a-z_]+=\S+)*)/i', $text, $matches, PREG_SET_ORDER)) {
            foreach ($matches as $m) {
                $tool = strtolower($m[1]);
                $args = [];
                if (preg_match_all('/([a-z_]+)=(\S+)/i', $m[2] ?? '', $am, PREG_SET_ORDER)) {
                    foreach ($am as $a) {
                        $args[strtolower($a[1])] = trim($a[2], "\"'");
                    }
                }
                if ($readOnlyProfile && in_array($tool, ['file.write', 'file.patch', 'shell.run'], true)) {
                    continue;
                }
                $steps[] = ['tool' => $tool, 'arguments' => $args];
            }
            if ($steps !== []) {
                return array_slice($steps, 0, (int) config('atlas_terminal.max_tool_rounds', 8));
            }
        }

        if (preg_match('/\b(git\s+status|status do git|git status)\b/u', $lower)) {
            $steps[] = ['tool' => 'git.status', 'arguments' => []];
        }

        if (preg_match('/\b(liste|list|ls|arquivos|structure|estrutura)\b/u', $lower) && ! $readOnlyProfile) {
            $steps[] = ['tool' => 'shell.run', 'arguments' => ['command' => 'ls -la']];
        } elseif (preg_match('/\b(liste|list|ls|arquivos)\b/u', $lower)) {
            $steps[] = ['tool' => 'workspace.profile', 'arguments' => []];
        }

        if (preg_match('/\b(?:leia|read|mostrar|show|open)\s+[\'\"]?([\w.\/\-]+\.[A-Za-z0-9]+)/ui', $text, $m)
            || preg_match('/@([\w.\/\-]+\.[A-Za-z0-9]+)/', $text, $m)) {
            $steps[] = ['tool' => 'file.read', 'arguments' => ['path' => $m[1]]];
        }

        if (preg_match('/\b(busca|search|grep|encontre|find)\b\s+["\']?([^"\']+)["\']?/u', $text, $m)) {
            $q = trim($m[2] ?? '');
            if ($q !== '') {
                $steps[] = ['tool' => 'search.rg', 'arguments' => ['query' => $q]];
            }
        }

        if ($steps === []) {
            $steps[] = ['tool' => 'workspace.profile', 'arguments' => []];
            $steps[] = ['tool' => 'git.status', 'arguments' => []];
        }

        return array_slice($steps, 0, (int) config('atlas_terminal.max_tool_rounds', 8));
    }

    /**
     * @param  list<array{tool:string,ok:bool,summary:string,output:string}>  $toolOutputs
     * @param  array<string,mixed>|null  $packMeta
     * @param  array<string,mixed>  $session
     */
    private function composeAnswer(string $text, array $toolOutputs, ?array $packMeta, array $session): string
    {
        $parts = [];
        $parts[] = '## Atlas Terminal';
        $parts[] = 'Task: '.$text;
        $parts[] = 'Profile: `'.($session['profile'] ?? 'dev').'` · mode: `'.($session['mode'] ?? 'normal').'`';
        if ($packMeta !== null) {
            $parts[] = sprintf(
                'Open Brain: hash=%s chars=%s',
                (string) ($packMeta['hash'] ?? 'n/a'),
                (string) ($packMeta['markdown_chars'] ?? 0)
            );
        }
        if ($toolOutputs === []) {
            $parts[] = '_No tools ran._';
        } else {
            $parts[] = '### Tool results';
            foreach ($toolOutputs as $i => $t) {
                $parts[] = sprintf(
                    "#### %d. `%s` — %s\n```\n%s\n```",
                    $i + 1,
                    $t['tool'],
                    $t['ok'] ? 'ok' : 'error',
                    mb_substr($t['output'] !== '' ? $t['output'] : $t['summary'], 0, 6000)
                );
            }
        }
        $parts[] = "\n_/review for gates · /subagent for parallel · muscle=hermes_cli_";

        return implode("\n\n", $parts);
    }

    /**
     * @param  array<string,mixed>  $session
     * @param  array<string,mixed>|null  $packMeta
     */
    private function buildPlanDraft(array $session, string $text, ?array $packMeta): string
    {
        $brain = $packMeta ? json_encode($packMeta, JSON_UNESCAPED_SLASHES) : 'n/a';

        return <<<MD
# Plan

## Context
{$text}

## Open Brain
```
{$brain}
```

## Recommended approach
1. Inspect workspace structure and relevant modules.
2. Reuse existing Atlas services (do not reinvent brain).
3. Implement the smallest change that satisfies the request.
4. Verify with targeted tests.

## Critical files
- (fill after exploration)

## Verification
- [ ] Unit/feature test green
- [ ] Hermes oneshot completed or clean timeout
- [ ] Session events + evidence receipt recorded

## Session
- id: {$session['id']}
- workspace: {$session['workspace']}
- profile: {$session['profile']}

## Approval
Run `/approve` to leave plan mode and implement, or `/revise-plan <notes>`.
MD;
    }
}
