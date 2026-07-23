<?php

namespace App\Console\Commands;

use App\Services\Ai\TerminalDev\Protocol\AapSchema;
use App\Services\Ai\TerminalDev\Session\AtlasTerminalSessionRuntime;
use App\Services\Ai\TerminalDev\Session\AtlasTerminalSessionStore;
use Illuminate\Console\Command;

/**
 * Atlas Terminal Dev — multi-turn agent session (Grok-class surface).
 *
 * atlas:terminal | bin/atlas terminal | term | agent
 */
class AtlasTerminalCommand extends Command
{
    protected $signature = 'atlas:terminal
        {task?* : Initial prompt (oneshot if provided; else interactive REPL)}
        {--workspace= : Workspace path (default: cwd)}
        {--resume= : Resume session id (or "latest")}
        {--yolo : Always-approve tools}
        {--plan : Start in plan mode}
        {--profile=dev : Session profile: dev|forge}
        {--permission=write : Tool permission mode: read|write|danger}
        {--json : Emit AAP-shaped JSON events (one JSON object per line for stream; final envelope if oneshot)}
        {--stdio : AAP JSON-RPC server on stdin/stdout}
        {--once : With empty task, print session status and exit}
        {--timeout= : Hermes/provider timeout seconds}
        {--provider=hermes_cli : Provider muscle (hermes_cli)}
        {--hermes-dry : Do not spawn hermes; dry-run bridge}';

    protected $description = 'Atlas Terminal Dev — multi-turn agent session with tools, skills, Open Brain (Grok-class surface).';

    public function handle(
        AtlasTerminalSessionRuntime $runtime,
        AtlasTerminalSessionStore $store,
    ): int {
        $workspace = $this->workspace();
        $json = (bool) $this->option('json');
        $stdio = (bool) $this->option('stdio');

        if ($stdio) {
            return $this->runStdio($runtime, $workspace);
        }

        $resume = $this->option('resume');
        $session = $runtime->start($workspace, [
            'resume' => $resume === 'latest' ? true : ($resume ?: null),
            'mode' => (bool) $this->option('plan') ? 'plan' : 'normal',
            'profile' => (string) $this->option('profile'),
            'permission_mode' => (string) $this->option('permission'),
            'yolo' => (bool) $this->option('yolo'),
        ]);

        if ((bool) $this->option('yolo')) {
            $session['yolo'] = true;
            $store->save($session);
        }
        if ((bool) $this->option('plan')) {
            $session['mode'] = 'plan';
            $store->save($session);
        }
        if ($this->option('timeout') !== null && $this->option('timeout') !== '') {
            $session['timeout_seconds'] = max(30, (int) $this->option('timeout'));
            $store->save($session);
        }
        if ((bool) $this->option('hermes-dry')) {
            config(['atlas_terminal.hermes_dry_run' => true]);
        }
        $providerOpt = (string) $this->option('provider');
        if ($providerOpt !== '') {
            config(['atlas_terminal.provider' => $providerOpt]);
        }

        $task = trim(implode(' ', (array) $this->argument('task')));

        if ($task === '' && (bool) $this->option('once')) {
            return $this->emitSession($runtime, $session, $json);
        }

        if ($task !== '') {
            $events = $runtime->prompt($session, $task);

            return $this->emitEvents($events, $session, $runtime, $json, oneshot: true);
        }

        if ($json) {
            $this->line(json_encode([
                'ok' => true,
                'schema' => 'atlas.terminal.session_ready.v1',
                'session' => $runtime->publicSession($session),
                'hint' => 'Interactive JSON mode: send lines as prompts; /quit to exit',
            ], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));
        } else {
            $this->info('Atlas Terminal Dev  ·  session '.$session['id']);
            $this->line('workspace: '.$session['workspace']);
            $this->line('mode: '.($session['mode'] ?? 'normal').'  yolo: '.((bool) ($session['yolo'] ?? false) ? 'on' : 'off'));
            $this->line('Open Brain: '.(config('atlas_terminal.open_brain.enabled') ? 'on' : 'off').'  ·  hermes default: never');
            $this->line('Type a prompt, /help, or /quit');
            $this->newLine();
        }

        while (true) {
            $line = $this->readLine($json ? 'json> ' : 'atlas> ');
            if ($line === null) {
                break;
            }
            $trim = trim($line);
            if ($trim === '') {
                continue;
            }
            if (in_array(strtolower($trim), ['/quit', '/exit', 'quit', 'exit'], true)) {
                if (! $json) {
                    $this->line('bye');
                }
                break;
            }

            $events = $runtime->prompt($session, $trim);
            // reload session from last runtime mutations — prompt receives by ref but we need local
            $reloaded = $store->load((string) $session['id'], $workspace);
            if ($reloaded !== null) {
                $session = $reloaded;
            }
            $this->emitEvents($events, $session, $runtime, $json, oneshot: false);
        }

        return self::SUCCESS;
    }

    private function workspace(): string
    {
        $ws = $this->option('workspace');
        if (is_string($ws) && $ws !== '') {
            return realpath($ws) ?: $ws;
        }
        $cwd = getcwd() ?: base_path();

        return realpath($cwd) ?: $cwd;
    }

    private function readLine(string $prompt): ?string
    {
        if (function_exists('readline')) {
            $line = readline($prompt);
            if ($line === false) {
                return null;
            }
            if (trim($line) !== '') {
                readline_add_history($line);
            }

            return $line;
        }

        $this->output->write($prompt);
        $line = fgets(STDIN);
        if ($line === false) {
            return null;
        }

        return rtrim($line, "\r\n");
    }

    /**
     * @param  list<array<string,mixed>>  $events
     * @param  array<string,mixed>  $session
     */
    private function emitEvents(
        array $events,
        array $session,
        AtlasTerminalSessionRuntime $runtime,
        bool $json,
        bool $oneshot,
    ): int {
        if ($json) {
            foreach ($events as $event) {
                $this->line(json_encode($event, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));
            }
            if ($oneshot) {
                $this->line(json_encode([
                    'ok' => true,
                    'schema' => 'atlas.terminal.turn_result.v1',
                    'session' => $runtime->publicSession($session),
                    'event_count' => count($events),
                ], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));
            }

            return self::SUCCESS;
        }

        foreach ($events as $event) {
            $method = (string) ($event['method'] ?? '');
            $params = is_array($event['params'] ?? null) ? $event['params'] : [];
            if ($method === AapSchema::NOTIFY_CONTEXT_PACK) {
                $hash = data_get($params, 'context_pack.hash', 'n/a');
                $this->line('<fg=cyan>open-brain</> hash='.$hash);

                continue;
            }
            if ($method === 'atlas/evidence' || str_starts_with($method, 'atlas/')) {
                $this->line('<fg=magenta>'.$method.'</> '.json_encode($params, JSON_UNESCAPED_SLASHES));

                continue;
            }
            $kind = (string) ($params['kind'] ?? '');
            if ($kind === 'tool_call') {
                $this->line(sprintf('<fg=yellow>→ tool</> %s %s', $params['tool'] ?? '', json_encode($params['arguments'] ?? [], JSON_UNESCAPED_SLASHES)));
            } elseif ($kind === 'tool_call_update') {
                $ok = data_get($params, 'result.ok');
                $summary = data_get($params, 'result.summary', '');
                $this->line(sprintf('<fg=%s>← %s</> %s', $ok ? 'green' : 'red', $params['tool'] ?? 'tool', $summary));
                $out = (string) data_get($params, 'result.output', '');
                if ($out !== '') {
                    $this->line(mb_substr($out, 0, 4000));
                }
            } elseif ($kind === 'agent_message_chunk' || $kind === 'plan') {
                $this->newLine();
                $this->line((string) ($params['text'] ?? ''));
                $this->newLine();
            } elseif ($kind === 'status') {
                $this->line('<fg=blue>status</> '.(string) ($params['text'] ?? json_encode($params['session'] ?? $params, JSON_UNESCAPED_SLASHES)));
            }
        }

        return self::SUCCESS;
    }

    /**
     * @param  array<string,mixed>  $session
     */
    private function emitSession(AtlasTerminalSessionRuntime $runtime, array $session, bool $json): int
    {
        $payload = [
            'ok' => true,
            'schema' => 'atlas.terminal.session_ready.v1',
            'session' => $runtime->publicSession($session),
            'protocol_version' => AapSchema::VERSION,
        ];
        if ($json) {
            $this->line(json_encode($payload, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));
        } else {
            $this->info('session '.$session['id']);
            $this->line(json_encode($payload['session'], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
        }

        return self::SUCCESS;
    }

    private function runStdio(AtlasTerminalSessionRuntime $runtime, string $workspace): int
    {
        $session = null;
        while (($line = fgets(STDIN)) !== false) {
            $line = trim($line);
            if ($line === '') {
                continue;
            }
            $msg = json_decode($line, true);
            if (! is_array($msg)) {
                $this->line(json_encode(['jsonrpc' => '2.0', 'error' => ['code' => -32700, 'message' => 'parse error']], JSON_UNESCAPED_SLASHES));

                continue;
            }
            $id = $msg['id'] ?? null;
            $method = (string) ($msg['method'] ?? '');
            $params = is_array($msg['params'] ?? null) ? $msg['params'] : [];

            try {
                if ($method === AapSchema::METHOD_INITIALIZE) {
                    $this->line(json_encode(AapSchema::response($id, [
                        'protocolVersion' => AapSchema::VERSION,
                        'serverInfo' => ['name' => 'atlas-terminal', 'version' => '0.1.0'],
                        'capabilities' => [
                            'tools' => true,
                            'skills' => true,
                            'plan' => true,
                            'open_brain' => true,
                        ],
                    ]), JSON_UNESCAPED_SLASHES));

                    continue;
                }

                if ($method === AapSchema::METHOD_SESSION_NEW) {
                    $session = $runtime->start($workspace, [
                        'mode' => (string) ($params['mode'] ?? 'normal'),
                        'profile' => (string) ($params['profile'] ?? 'dev'),
                        'yolo' => (bool) ($params['yolo'] ?? false),
                        'permission_mode' => (string) ($params['permission_mode'] ?? 'write'),
                    ]);
                    $this->line(json_encode(AapSchema::response($id, [
                        'session' => $runtime->publicSession($session),
                    ]), JSON_UNESCAPED_SLASHES));

                    continue;
                }

                if ($method === AapSchema::METHOD_SESSION_LOAD) {
                    $session = $runtime->start($workspace, [
                        'resume' => (string) ($params['sessionId'] ?? $params['session_id'] ?? 'latest'),
                    ]);
                    $this->line(json_encode(AapSchema::response($id, [
                        'session' => $runtime->publicSession($session),
                    ]), JSON_UNESCAPED_SLASHES));

                    continue;
                }

                if ($session === null) {
                    $session = $runtime->start($workspace, []);
                }

                if ($method === AapSchema::METHOD_SESSION_CANCEL) {
                    // Best-effort: mark session cancelled; Hermes job cancel needs job id (future).
                    if (is_array($session)) {
                        $session['cancel_requested_at'] = now()->toJSON();
                        $store = app(\App\Services\Ai\TerminalDev\Session\AtlasTerminalSessionStore::class);
                        $store->save($session);
                    }
                    $this->line(json_encode(AapSchema::response($id, ['ok' => true, 'cancelled' => true]), JSON_UNESCAPED_SLASHES));
                    $this->line(json_encode(AapSchema::sessionUpdate((string) ($session['id'] ?? ''), 'status', [
                        'text' => 'cancel requested',
                    ]), JSON_UNESCAPED_SLASHES));

                    continue;
                }

                if ($method === AapSchema::METHOD_SESSION_PROMPT || $method === AapSchema::METHOD_SLASH) {
                    $text = (string) ($params['text'] ?? $params['prompt'] ?? '');
                    if ($method === AapSchema::METHOD_SLASH) {
                        $name = ltrim((string) ($params['name'] ?? ''), '/');
                        $args = (string) ($params['args'] ?? '');
                        $text = '/'.$name.($args !== '' ? ' '.$args : '');
                    }
                    $attach = $params['attachments'] ?? [];
                    $events = $runtime->prompt($session, $text, [
                        'attachments' => is_array($attach) ? $attach : [],
                    ]);
                    foreach ($events as $event) {
                        $this->line(json_encode($event, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));
                    }
                    $this->line(json_encode(AapSchema::response($id, [
                        'ok' => true,
                        'event_count' => count($events),
                        'session' => $runtime->publicSession($session),
                    ]), JSON_UNESCAPED_SLASHES));

                    continue;
                }

                $this->line(json_encode([
                    'jsonrpc' => '2.0',
                    'id' => $id,
                    'error' => ['code' => -32601, 'message' => 'method not found: '.$method],
                ], JSON_UNESCAPED_SLASHES));
            } catch (\Throwable $e) {
                $this->line(json_encode([
                    'jsonrpc' => '2.0',
                    'id' => $id,
                    'error' => ['code' => -32000, 'message' => $e->getMessage()],
                ], JSON_UNESCAPED_SLASHES));
            }
        }

        return self::SUCCESS;
    }
}
