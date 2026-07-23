<?php

namespace App\Services\Ai\TerminalDev\Desktop;

final class TerminalDesktopBridgeContract
{
    public const SCHEMA = 'atlas.terminal.desktop_bridge.v1';

    /**
     * @return array<string,mixed>
     */
    public function describe(): array
    {
        return [
            'schema' => self::SCHEMA,
            'transport' => [
                'primary' => 'stdio_aap',
                'command' => ['php', 'artisan', 'atlas:terminal', '--stdio'],
                'protocol' => 'atlas.agent.protocol.v0',
            ],
            'shared_identity' => [
                'session_id' => 'Terminal session dir id',
                'ai_thread_id' => 'optional bind to AiThread for mobile continuity',
                'workspace' => 'absolute path',
            ],
            'forbidden' => [
                'No second chat product that ignores AAP',
                'No memory authority in desktop binary',
            ],
            'methods_required' => [
                'initialize',
                'session/new',
                'session/load',
                'session/prompt',
                'slash',
            ],
            'notifications_required' => [
                'session/update',
                'atlas/context_pack',
                'atlas/evidence',
            ],
        ];
    }
}
