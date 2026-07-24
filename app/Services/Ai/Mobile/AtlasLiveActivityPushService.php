<?php

namespace App\Services\Ai\Mobile;

use App\Models\AiStreamEvent;
use App\Models\AiTrace;
use App\Models\AtlasLiveActivityPushToken;
use App\Models\AtlasLiveActivityStartToken;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;
use Throwable;

/**
 * Projeta eventos públicos já registrados pelo Terminal/CLI para ActivityKit.
 *
 * Esta classe não cria estado de execução e nunca envia stdout, prompt ou
 * raciocínio. Ela só lê o ledger de stream, reduz para um checkpoint humano e
 * tenta APNs quando a infraestrutura foi explicitamente configurada.
 */
final class AtlasLiveActivityPushService
{
    private const APPLE_REFERENCE_EPOCH_OFFSET = 978_307_200;

    /** Retorna quantas activities receberam uma atualização remota. */
    public function publish(AiStreamEvent $event): int
    {
        if (! $this->isEnabled() || ! $this->shouldProject($event)) {
            return 0;
        }

        $trace = AiTrace::query()->find($event->trace_id);
        if (! $trace) {
            return 0;
        }

        $terminal = in_array($trace->status, ['succeeded', 'completed', 'failed', 'cancelled'], true);
        $registrations = AtlasLiveActivityPushToken::query()
            ->where('trace_id', $trace->id)
            ->where('status', 'active')
            ->get();
        $published = 0;

        foreach ($registrations as $registration) {
            if (! $terminal && ! $this->maySendUpdateNow($registration)) {
                continue;
            }

            try {
                $response = $this->send($registration, $this->payloadFor($registration, $event, $terminal));
                if ($response->successful()) {
                    $registration->update([
                        'last_pushed_at' => now(),
                        'status' => $terminal ? 'ended' : 'active',
                        'invalidated_at' => $terminal ? now() : null,
                    ]);
                    $published++;
                } elseif (in_array($response->status(), [400, 410], true)) {
                    // APNs declarou o token inválido; nunca tentamos ressuscitá-lo.
                    $registration->update([
                        'status' => 'invalidated',
                        'invalidated_at' => now(),
                    ]);
                }
            } catch (Throwable) {
                // Push é uma projeção complementar. Falha de APNs não pode
                // romper o recorder canônico nem interromper o agente.
            }
        }

        return $published;
    }

    /**
     * Inicia a presença no iPhone quando a missão nasceu fora do app (CLI,
     * Terminal ou Autônomos). Um chat iniciado pelo próprio iOS já abre sua
     * activity localmente e não recebe uma duplicada por este caminho.
     */
    public function startFor(AiStreamEvent $event): int
    {
        if (! $this->isEnabled() || ! $this->shouldProject($event)) {
            return 0;
        }

        $trace = AiTrace::query()->find($event->trace_id);
        if (! $trace || $trace->source_type === 'app'
            || in_array($trace->status, ['succeeded', 'completed', 'failed', 'cancelled'], true)
            || AtlasLiveActivityPushToken::query()->where('trace_id', $trace->id)->where('status', 'active')->exists()) {
            return 0;
        }

        $started = 0;
        foreach (AtlasLiveActivityStartToken::query()->get() as $token) {
            if ($token->last_started_trace_id === $trace->id) {
                continue;
            }
            try {
                $response = $this->sendStart($token, $this->startPayloadFor($trace, $event, $token));
                if ($response->successful()) {
                    $token->update([
                        'last_seen_at' => now(),
                        'last_started_trace_id' => $trace->id,
                    ]);
                    $started++;
                }
            } catch (Throwable) {
                // Igual à atualização: APNs é projeção; o ledger nunca depende dela.
            }
        }

        return $started;
    }

    /** @return array{aps:array<string,mixed>} */
    public function startPayloadFor(AiTrace $trace, AiStreamEvent $event, AtlasLiveActivityStartToken $token): array
    {
        return [
            'aps' => [
                'timestamp' => now()->getTimestamp(),
                'event' => 'start',
                'attributes-type' => 'AtlasTurnAttributes',
                // A chave é o trace público, para o iOS recuperar o token de
                // update sem expor prompt, stdout, argumento de tool ou CoT.
                'attributes' => [
                    'threadTitle' => $this->threadTitle($trace),
                    'threadKey' => $trace->id,
                ],
                'content-state' => [
                    'phaseTitle' => $this->phaseTitle($event),
                    'startedAt' => now()->getTimestamp() - self::APPLE_REFERENCE_EPOCH_OFFSET,
                    'finished' => false,
                    // O token de update da activity recém-iniciada chega logo
                    // depois; enquanto isso, contamos as sessões já conhecidas
                    // desta instalação + a que estamos abrindo agora.
                    'activeSessions' => max(1, AtlasLiveActivityPushToken::query()
                        ->where('installation_id', $token->installation_id)
                        ->where('status', 'active')
                        ->count() + 1),
                ],
            ],
        ];
    }

    /** @return array{aps:array<string,mixed>} */
    public function payloadFor(AtlasLiveActivityPushToken $registration, AiStreamEvent $event, bool $terminal): array
    {
        $startedAt = $registration->started_at ?? now();
        $activeSessions = AtlasLiveActivityPushToken::query()
            ->where('installation_id', $registration->installation_id)
            ->where('status', 'active')
            ->count();
        $activeSessions = $terminal ? max(0, $activeSessions - 1) : max(1, $activeSessions);

        $aps = [
            'timestamp' => now()->getTimestamp(),
            'event' => $terminal ? 'end' : 'update',
            // CodingKeys implícitas do AtlasTurnAttributes.ContentState.
            // Date Codable usa segundos desde 2001-01-01 por padrão.
            'content-state' => [
                'phaseTitle' => $terminal ? $this->terminalTitle($event, $registration) : $this->phaseTitle($event),
                'startedAt' => $startedAt->getTimestamp() - self::APPLE_REFERENCE_EPOCH_OFFSET,
                'finished' => $terminal,
                'activeSessions' => $activeSessions,
            ],
        ];
        if ($terminal) {
            // Notificação de conclusão é deliberadamente editorial e genérica:
            // a pessoa recebe o marco; abre o Atlas para ver resposta/provas.
            $aps['alert'] = [
                'title' => 'Atlas concluiu uma execução',
                'body' => $this->notificationBody($event, $registration),
                'sound' => 'default',
            ];
        }

        return ['aps' => $aps];
    }

    private function shouldProject(AiStreamEvent $event): bool
    {
        // Tokens / stdout podem chegar dezenas de vezes por minuto e não são
        // uma mudança editorial para Lock Screen. Tool/lifecycle/progress são.
        return in_array($event->event_type, ['lifecycle', 'permission', 'progress', 'tool', 'response', 'error'], true);
    }

    private function maySendUpdateNow(AtlasLiveActivityPushToken $registration): bool
    {
        $seconds = max(2, (int) config('atlas.mobile.live_activities.minimum_update_interval_seconds', 5));

        return $registration->last_pushed_at === null
            || $registration->last_pushed_at->addSeconds($seconds)->isPast();
    }

    private function phaseTitle(AiStreamEvent $event): string
    {
        $checkpoint = is_array($event->metadata) ? strtolower((string) ($event->metadata['checkpoint'] ?? '')) : '';

        return match ($checkpoint) {
            'intent' => 'Entendendo o pedido',
            'context' => 'Reunindo contexto',
            'plan' => 'Planejando a execução',
            'provider' => 'Executando o agente',
            'verify' => 'Verificando o resultado',
            'evidence' => 'Registrando evidências',
            default => match ($event->event_type) {
                'tool' => 'Executando uma ferramenta',
                'permission' => 'Verificando permissões',
                'response' => 'Preparando a resposta',
                'error' => 'Atenção necessária',
                default => 'Atualizando a execução',
            },
        };
    }

    private function terminalTitle(AiStreamEvent $event, AtlasLiveActivityPushToken $registration): string
    {
        return $event->event_type === 'error' || $registration->trace?->status === 'failed'
            ? 'Execução encerrada com atenção'
            : 'Resposta pronta';
    }

    private function notificationBody(AiStreamEvent $event, AtlasLiveActivityPushToken $registration): string
    {
        $trace = AiTrace::query()->find($event->trace_id);
        $title = $trace ? $this->threadTitle($trace) : 'Atlas';

        return $title.' · '.$this->terminalTitle($event, $registration);
    }

    private function isEnabled(): bool
    {
        return (bool) config('atlas.mobile.live_activities.enabled', false)
            && $this->credentials() !== null;
    }

    private function mayUseSandbox(AtlasLiveActivityPushToken $registration): bool
    {
        return $registration->environment === 'sandbox';
    }

    private function send(AtlasLiveActivityPushToken $registration, array $payload): Response
    {
        $host = $this->mayUseSandbox($registration)
            ? 'https://api.sandbox.push.apple.com'
            : 'https://api.push.apple.com';
        $topic = (string) config('atlas.mobile.live_activities.topic', 'com.vitor.atlas.native.push-type.liveactivity');

        return Http::withToken($this->authorizationToken())
            ->withHeaders([
                'apns-push-type' => 'liveactivity',
                'apns-topic' => $topic,
                // Fundo é suficiente para checkpoints; reduz o orçamento APNs.
                'apns-priority' => '5',
            ])
            ->withOptions(['version' => 2.0])
            ->timeout(max(2, (int) config('atlas.mobile.live_activities.timeout_seconds', 8)))
            ->post($host.'/3/device/'.$registration->push_token, $payload);
    }

    private function sendStart(AtlasLiveActivityStartToken $registration, array $payload): Response
    {
        $host = $registration->environment === 'sandbox'
            ? 'https://api.sandbox.push.apple.com'
            : 'https://api.push.apple.com';
        $topic = (string) config('atlas.mobile.live_activities.start_topic', 'com.vitor.atlas.native.push-type.liveactivity');

        return Http::withToken($this->authorizationToken())
            ->withHeaders([
                'apns-push-type' => 'liveactivity',
                'apns-topic' => $topic,
                'apns-priority' => '10',
            ])
            ->withOptions(['version' => 2.0])
            ->timeout(max(2, (int) config('atlas.mobile.live_activities.timeout_seconds', 8)))
            ->post($host.'/3/device/'.$registration->push_token, $payload);
    }

    private function threadTitle(AiTrace $trace): string
    {
        $metadata = is_array($trace->metadata) ? $trace->metadata : [];
        $title = $metadata['thread_title'] ?? null;

        return is_string($title) && trim($title) !== '' ? trim($title) : 'Execução Atlas';
    }

    private function authorizationToken(): string
    {
        $credentials = $this->credentials();
        if ($credentials === null) {
            throw new \RuntimeException('APNs Live Activity não configurado.');
        }

        $header = $this->base64Url(json_encode(['alg' => 'ES256', 'kid' => $credentials['key_id']], JSON_THROW_ON_ERROR));
        $claims = $this->base64Url(json_encode(['iss' => $credentials['team_id'], 'iat' => now()->getTimestamp()], JSON_THROW_ON_ERROR));
        $input = $header.'.'.$claims;
        $signature = '';
        if (! openssl_sign($input, $signature, $credentials['private_key'], OPENSSL_ALGO_SHA256)) {
            throw new \RuntimeException('Não foi possível assinar o JWT APNs.');
        }

        return $input.'.'.$this->base64Url($this->derEcdsaToJose($signature, 32));
    }

    /** @return array{key_id:string,team_id:string,private_key:string}|null */
    private function credentials(): ?array
    {
        $keyId = trim((string) config('atlas.mobile.live_activities.apns_key_id', ''));
        $teamId = trim((string) config('atlas.mobile.live_activities.apns_team_id', ''));
        $privateKey = trim(str_replace('\\n', "\n", (string) config('atlas.mobile.live_activities.apns_private_key', '')));

        return $keyId !== '' && $teamId !== '' && $privateKey !== ''
            ? ['key_id' => $keyId, 'team_id' => $teamId, 'private_key' => $privateKey]
            : null;
    }

    private function base64Url(string $value): string
    {
        return rtrim(strtr(base64_encode($value), '+/', '-_'), '=');
    }

    /** Converte assinatura ASN.1 DER do OpenSSL em R||S de 64 bytes para JWT. */
    private function derEcdsaToJose(string $der, int $partLength): string
    {
        $offset = 0;
        if (ord($der[$offset++] ?? "\0") !== 0x30) {
            throw new \RuntimeException('Assinatura ECDSA DER inválida.');
        }
        $this->readDerLength($der, $offset);
        $r = $this->readDerInteger($der, $offset);
        $s = $this->readDerInteger($der, $offset);

        return str_pad(ltrim($r, "\0"), $partLength, "\0", STR_PAD_LEFT)
            .str_pad(ltrim($s, "\0"), $partLength, "\0", STR_PAD_LEFT);
    }

    private function readDerLength(string $der, int &$offset): int
    {
        $length = ord($der[$offset++] ?? "\0");
        if (($length & 0x80) === 0) {
            return $length;
        }
        $bytes = $length & 0x7F;
        $length = 0;
        for ($i = 0; $i < $bytes; $i++) {
            $length = ($length << 8) | ord($der[$offset++] ?? "\0");
        }

        return $length;
    }

    private function readDerInteger(string $der, int &$offset): string
    {
        if (ord($der[$offset++] ?? "\0") !== 0x02) {
            throw new \RuntimeException('Inteiro ECDSA DER inválido.');
        }
        $length = $this->readDerLength($der, $offset);
        $value = substr($der, $offset, $length);
        $offset += $length;

        return $value;
    }
}
