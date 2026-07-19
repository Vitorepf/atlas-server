<?php

/**
 * OpenAI-compatible HTTP edge for preregistered Rivals response units.
 *
 * Router for:
 *   php -S 127.0.0.1:8791 scripts/rivals-atlas-openai-endpoint.php
 *
 * The HTTP edge contains no provider runtime. Chat requests bootstrap Laravel
 * and delegate to HermesOpenAiResponseAdapter, which invokes the canonical
 * HermesCliProvider and persists ExecutiveMission/ResultPacket proof plus both
 * captured streams in the exact native unit scratch directory.
 */

declare(strict_types=1);

use App\Services\Ai\Hermes\HermesOpenAiResponseAdapter;
use Illuminate\Contracts\Console\Kernel;

set_time_limit(0);

const ENDPOINT_MODEL_DEFAULT = 'kimi-k2.7';

$path = parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH);
if ($path === '/health') {
    respond(200, [
        'ok' => true,
        'runtime' => 'hermes_cli',
        'provider' => 'verboo',
        'governed' => true,
    ]);
}
if ($path === '/v1/models' || $path === '/models') {
    respond(200, ['object' => 'list', 'data' => [[
        'id' => ENDPOINT_MODEL_DEFAULT,
        'object' => 'model',
        'owned_by' => 'atlas',
    ]]]);
}
if ($path !== '/v1/chat/completions' && $path !== '/chat/completions') {
    respond(404, ['error' => ['message' => 'not_found', 'type' => 'invalid_request_error']]);
}

$root = dirname(__DIR__);
require $root.'/vendor/autoload.php';
$app = require $root.'/bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();

$body = json_decode((string) file_get_contents('php://input'), true);
$body = is_array($body) ? $body : [];
$headers = [];
foreach ($_SERVER as $key => $value) {
    if (! str_starts_with((string) $key, 'HTTP_') || ! is_string($value)) {
        continue;
    }
    $name = str_replace('_', '-', substr((string) $key, 5));
    $headers[$name] = $value;
}

$result = $app->make(HermesOpenAiResponseAdapter::class)->complete($body, $headers);
respond($result['status'], $result['body']);

/** @param array<string,mixed> $payload */
function respond(int $status, array $payload): never
{
    http_response_code($status);
    header('Content-Type: application/json');
    echo json_encode($payload, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    exit(0);
}
