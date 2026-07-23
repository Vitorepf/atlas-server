<?php

namespace App\Services\Ai\TerminalDev\Protocol;

final class AapSchema
{
    public const VERSION = 'atlas.agent.protocol.v0';

    public const METHOD_INITIALIZE = 'initialize';

    public const METHOD_SESSION_NEW = 'session/new';

    public const METHOD_SESSION_LOAD = 'session/load';

    public const METHOD_SESSION_PROMPT = 'session/prompt';

    public const METHOD_SESSION_CANCEL = 'session/cancel';

    public const METHOD_SLASH = 'slash';

    public const NOTIFY_SESSION_UPDATE = 'session/update';

    public const NOTIFY_CONTEXT_PACK = 'atlas/context_pack';

    /**
     * @param  array<string,mixed>  $params
     * @return array<string,mixed>
     */
    public static function request(string $method, array $params = [], int|string|null $id = 1): array
    {
        return [
            'jsonrpc' => '2.0',
            'id' => $id,
            'method' => $method,
            'params' => $params,
        ];
    }

    /**
     * @param  array<string,mixed>  $result
     * @return array<string,mixed>
     */
    public static function response(int|string|null $id, array $result): array
    {
        return [
            'jsonrpc' => '2.0',
            'id' => $id,
            'result' => $result,
        ];
    }

    /**
     * @param  array<string,mixed>  $params
     * @return array<string,mixed>
     */
    public static function notification(string $method, array $params): array
    {
        return [
            'jsonrpc' => '2.0',
            'method' => $method,
            'params' => $params,
        ];
    }

    /**
     * @param  array<string,mixed>  $data
     * @return array<string,mixed>
     */
    public static function sessionUpdate(string $sessionId, string $kind, array $data = []): array
    {
        return self::notification(self::NOTIFY_SESSION_UPDATE, array_merge([
            'session_id' => $sessionId,
            'kind' => $kind,
            'protocol_version' => self::VERSION,
            'at' => now()->toJSON(),
        ], $data));
    }
}
