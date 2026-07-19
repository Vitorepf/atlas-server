<?php

/**
 * Endpoint OpenAI-compatível na frente do runtime governado (hermes → Verboo).
 *
 * Router para `php -S 127.0.0.1:8791 scripts/rivals-atlas-openai-endpoint.php`.
 * Existe para o braço com-Atlas das suítes que dirigem a API do modelo
 * diretamente (inspect_evals, tau2_bench): cada /v1/chat/completions vira uma
 * passada one-shot do hermes com o provider Verboo, com recibo append-only em
 * storage/atlas/rivals/endpoint_receipts.jsonl. Sem Laravel: standalone.
 *
 * ponytail: tool-calls são bridgeados por prompt (JSON) — suficiente para o
 * tau2; se um harness exigir parallel tool calls ou streaming, promover para
 * um AP próprio da lane do servidor.
 */

declare(strict_types=1);

// O php -S impõe max_execution_time=30s por request, e uma pergunta dura de
// tau2/inspect leva mais que isso no hermes → fatal na linha da chamada, e o
// worker do endpoint caía (last exit 124), derrubando o braço com-Atlas dessas
// suítes. O teto real de tempo é o do hermes (request_timeout 900s); aqui só
// tiramos o gatilho prematuro do PHP.
set_time_limit(0);

const ENDPOINT_MODEL_DEFAULT = 'kimi-k2.7';

$root = dirname(__DIR__);
$home = rtrim((string) getenv('HOME'), '/');
$hermesHome = '/tmp/rivals-atlas-endpoint-hermes';
$receipts = $root.'/storage/atlas/rivals/endpoint_receipts.jsonl';

$path = parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH);
if ($path === '/health') {
    respond(200, ['ok' => true, 'runtime' => 'hermes', 'provider' => 'verboo']);
}
if ($path === '/v1/models' || $path === '/models') {
    respond(200, ['object' => 'list', 'data' => [
        ['id' => ENDPOINT_MODEL_DEFAULT, 'object' => 'model', 'owned_by' => 'atlas'],
    ]]);
}
if ($path !== '/v1/chat/completions' && $path !== '/chat/completions') {
    respond(404, ['error' => ['message' => 'not_found', 'type' => 'invalid_request_error']]);
}

$body = json_decode((string) file_get_contents('php://input'), true);
if (! is_array($body) || ! is_array($body['messages'] ?? null)) {
    respond(400, ['error' => ['message' => 'messages_required', 'type' => 'invalid_request_error']]);
}
$model = basename((string) ($body['model'] ?? ENDPOINT_MODEL_DEFAULT));
$tools = is_array($body['tools'] ?? null) ? $body['tools'] : [];

$prompt = renderMessages($body['messages']);
if ($tools !== []) {
    $prompt .= "\n\n[available tools]\n".json_encode($tools, JSON_UNESCAPED_SLASHES)
        ."\n\nIf (and only if) you need to call a tool, reply with ONLY this JSON, nothing else:\n"
        .'{"tool_calls":[{"name":"<tool name>","arguments":{...}}]}'
        ."\nOtherwise reply with the plain assistant message.";
}

ensureHermesHome($hermesHome, $home);
$usageFile = tempnam(sys_get_temp_dir(), 'rivals-endpoint-usage-');
$started = hrtime(true);
$descriptors = [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']];
$process = proc_open(
    [
        $home.'/.local/bin/hermes',
        '--yolo',
        '--ignore-user-config',
        '--safe-mode',
        '-z', $prompt,
        '--provider', 'verboo',
        '--model', $model,
        '--usage-file', $usageFile,
    ],
    $descriptors,
    $pipes,
    '/tmp',
    [
        'HOME' => $home,
        'PATH' => $home.'/.local/bin:/opt/homebrew/bin:/usr/bin:/bin',
        'HERMES_HOME' => $hermesHome,
        'TERMINAL_ENV' => 'local',
        'VERBOO_API_KEY' => verbooKey($home),
    ],
);
fclose($pipes[0]);
$stdout = (string) stream_get_contents($pipes[1]);
$stderr = (string) stream_get_contents($pipes[2]);
fclose($pipes[1]);
fclose($pipes[2]);
$exit = proc_close($process);
$wallMs = (int) ((hrtime(true) - $started) / 1_000_000);
$usage = is_file($usageFile) ? (json_decode((string) file_get_contents($usageFile), true) ?: []) : [];
@unlink($usageFile);

if ($exit !== 0 || trim($stdout) === '') {
    receipt($receipts, $model, $usage, $wallMs, 'error');
    respond(500, ['error' => [
        'message' => 'atlas_runtime_failed:'.substr(trim($stderr), -300),
        'type' => 'server_error',
    ]]);
}

$content = trim($stdout);
$message = ['role' => 'assistant', 'content' => $content];
$finish = 'stop';
if ($tools !== [] && ($calls = parseToolCalls($content)) !== null) {
    $message = ['role' => 'assistant', 'content' => null, 'tool_calls' => $calls];
    $finish = 'tool_calls';
}
receipt($receipts, $model, $usage, $wallMs, 'ok');
respond(200, [
    'id' => 'chatcmpl-atlas-'.bin2hex(random_bytes(8)),
    'object' => 'chat.completion',
    'created' => time(),
    'model' => $model,
    'choices' => [[
        'index' => 0,
        'message' => $message,
        'finish_reason' => $finish,
    ]],
    'usage' => [
        'prompt_tokens' => (int) ($usage['input_tokens'] ?? 0),
        'completion_tokens' => (int) ($usage['output_tokens'] ?? 0),
        'total_tokens' => (int) ($usage['total_tokens'] ?? 0),
    ],
]);

function renderMessages(array $messages): string
{
    $parts = [];
    foreach ($messages as $message) {
        if (! is_array($message)) {
            continue;
        }
        $role = (string) ($message['role'] ?? 'user');
        $content = $message['content'] ?? '';
        if (is_array($content)) {
            $content = implode("\n", array_map(
                static fn ($block) => (string) (is_array($block) ? ($block['text'] ?? '') : $block),
                $content,
            ));
        }
        if (isset($message['tool_calls']) && is_array($message['tool_calls'])) {
            foreach ($message['tool_calls'] as $call) {
                $fn = $call['function'] ?? [];
                $content .= sprintf(
                    "\n[assistant called tool %s(%s)]",
                    (string) ($fn['name'] ?? '?'),
                    (string) ($fn['arguments'] ?? '{}'),
                );
            }
        }
        $parts[] = "[{$role}]\n".trim((string) $content);
    }

    return implode("\n\n", $parts);
}

/** @return list<array<string, mixed>>|null */
function parseToolCalls(string $content): ?array
{
    $json = $content;
    if (! str_starts_with(trim($json), '{')) {
        $start = strpos($json, '{');
        $end = strrpos($json, '}');
        if ($start === false || $end === false || $end <= $start) {
            return null;
        }
        $json = substr($json, $start, $end - $start + 1);
    }
    $decoded = json_decode($json, true);
    if (! is_array($decoded) || ! is_array($decoded['tool_calls'] ?? null)) {
        return null;
    }
    $calls = [];
    foreach ($decoded['tool_calls'] as $i => $call) {
        if (! is_array($call) || ! is_string($call['name'] ?? null)) {
            return null;
        }
        $calls[] = [
            'id' => 'call_atlas_'.$i.'_'.bin2hex(random_bytes(4)),
            'type' => 'function',
            'function' => [
                'name' => $call['name'],
                'arguments' => json_encode($call['arguments'] ?? new stdClass, JSON_UNESCAPED_SLASHES),
            ],
        ];
    }

    return $calls === [] ? null : $calls;
}

function ensureHermesHome(string $hermesHome, string $home): void
{
    if (is_file($hermesHome.'/config.yaml')) {
        return;
    }
    foreach (['', '/sessions', '/skills', '/memories'] as $dir) {
        @mkdir($hermesHome.$dir, 0755, true);
    }
    file_put_contents($hermesHome.'/config.yaml', <<<'YAML'
model:
  default: kimi-k2.7
  provider: verboo
  base_url: ""
providers:
  verboo:
    api: https://code.verboo.ai/router/v1
    key_env: VERBOO_API_KEY
    transport: chat_completions
    default_model: kimi-k2.7
    discover_models: true
    request_timeout_seconds: 900
    stale_timeout_seconds: 600
    models:
      kimi-k2.7:
        context_length: 1048576
YAML);
}

function verbooKey(string $home): string
{
    foreach (file($home.'/.hermes/.env', FILE_IGNORE_NEW_LINES) ?: [] as $line) {
        if (str_starts_with($line, 'VERBOO_API_KEY=')) {
            return substr($line, strlen('VERBOO_API_KEY='));
        }
    }

    return '';
}

function receipt(string $path, string $model, array $usage, int $wallMs, string $status): void
{
    @file_put_contents($path, json_encode([
        'schema_version' => 'atlas.rivals2.endpoint_receipt.v1',
        'at' => gmdate('c'),
        'runtime' => 'hermes',
        'provider' => 'verboo',
        'model' => $model,
        'status' => $status,
        'wall_ms' => $wallMs,
        'input_tokens' => (int) ($usage['input_tokens'] ?? 0),
        'output_tokens' => (int) ($usage['output_tokens'] ?? 0),
    ], JSON_UNESCAPED_SLASHES)."\n", FILE_APPEND | LOCK_EX);
}

function respond(int $status, array $payload): never
{
    http_response_code($status);
    header('Content-Type: application/json');
    echo json_encode($payload, JSON_UNESCAPED_SLASHES);
    exit(0);
}
