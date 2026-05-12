<?php

namespace App\Services\Ai\Voice;

final class AtlasVoiceLiveKitServerProbe
{
    /**
     * @return array<string,mixed>
     */
    public function probe(): array
    {
        $url = rtrim(trim((string) config('atlas.voice.livekit.url', '')), '/');
        $parsed = $url !== '' ? parse_url($url) : false;
        $scheme = is_array($parsed) ? strtolower((string) ($parsed['scheme'] ?? '')) : '';
        $host = is_array($parsed) ? trim((string) ($parsed['host'] ?? '')) : '';
        $port = $this->port($parsed, $scheme);
        $configured = $url !== '' && $host !== '' && in_array($scheme, ['http', 'https', 'ws', 'wss'], true) && $port !== null;

        $probe = [
            'attempted' => false,
            'reachable' => false,
            'transport' => 'tcp_connect',
            'timeout_ms' => 500,
            'error_hash' => null,
        ];

        if ($configured) {
            $probe = $this->tcpProbe($host, $port);
        }

        $ready = $configured && (bool) $probe['reachable'];

        return [
            'schema_version' => 'atlas.voice_realtime.livekit_server_probe.v1',
            'status' => $ready ? 'reachable' : 'blocked',
            'surface_id' => 'voice_realtime',
            'runtime_id' => 'livekit_agents_sdk',
            'runtime_family' => 'python_ai_data',
            'mobile_first' => true,
            'kernel_only' => true,
            'configured' => $configured,
            'livekit_url_configured' => $url !== '',
            'livekit_url_redacted' => $configured ? $this->redactedUrl($scheme, $host, $port) : null,
            'host_redacted' => $configured ? $this->redactHost($host) : null,
            'port' => $configured ? $port : null,
            'probe' => $probe,
            'security_contract' => [
                'secrets_exposed' => false,
                'api_key_read' => false,
                'api_secret_read' => false,
                'token_issued' => false,
                'daemon_started' => false,
                'process_launch_attempted' => false,
                'livekit_sdk_imported' => false,
                'provider_calls_made' => false,
                'tool_calls_made' => false,
                'raw_audio_touched' => false,
                'memory_write_allowed' => false,
            ],
            'next_action' => match (true) {
                ! $configured => 'configure_livekit_url_for_local_server_probe',
                $ready => 'run_supervised_voice_worker_handshake_smoke_contract',
                default => 'start_or_fix_livekit_server_local_then_rerun_probe',
            },
        ];
    }

    /**
     * @param  array<string,mixed>|false  $parsed
     */
    private function port(array|false $parsed, string $scheme): ?int
    {
        if (! is_array($parsed)) {
            return null;
        }

        $port = $parsed['port'] ?? null;
        if (is_int($port) && $port > 0 && $port <= 65535) {
            return $port;
        }

        return match ($scheme) {
            'http', 'ws' => 80,
            'https', 'wss' => 443,
            default => null,
        };
    }

    /**
     * @return array<string,mixed>
     */
    private function tcpProbe(string $host, int $port): array
    {
        $errorCode = 0;
        $errorMessage = '';
        $timeoutSeconds = 0.5;
        $socket = @fsockopen($host, $port, $errorCode, $errorMessage, $timeoutSeconds);

        if (is_resource($socket)) {
            fclose($socket);

            return [
                'attempted' => true,
                'reachable' => true,
                'transport' => 'tcp_connect',
                'timeout_ms' => 500,
                'error_hash' => null,
            ];
        }

        $error = trim($errorCode.':'.$errorMessage);

        return [
            'attempted' => true,
            'reachable' => false,
            'transport' => 'tcp_connect',
            'timeout_ms' => 500,
            'error_hash' => $error !== ':' ? hash('sha256', $error) : null,
        ];
    }

    private function redactedUrl(string $scheme, string $host, int $port): string
    {
        return $scheme.'://'.$this->redactHost($host).':'.$port;
    }

    private function redactHost(string $host): string
    {
        if (in_array($host, ['127.0.0.1', 'localhost', '::1'], true)) {
            return $host;
        }

        $parts = explode('.', $host);
        if (count($parts) <= 2) {
            return '<livekit-host>';
        }

        return '<livekit-host>.'.implode('.', array_slice($parts, -2));
    }
}
