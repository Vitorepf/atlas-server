<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Models\ReceivedEvent;

/**
 * Handler de webhook do provider externo.
 *
 * BUG (seed): handle() processa o mesmo event_id em todo retry, duplicando
 * o efeito colateral. O arm precisa:
 *   1. Verificar se event_id já foi gravado em ReceivedEvent.
 *   2. Se já: retornar 200 sem side-effect (provider precisa de OK no retry).
 *   3. Se não: gravar event_id em ReceivedEvent (atômico) ANTES de aplicar
 *      o side-effect, e responder 200.
 *
 * Contract local do test: handle($payload) devolve array com chaves
 * 'status' (string), 'side_effect_applied' (bool).
 *
 * @phpstan-type WebhookPayload array{event_id:string, kind?:string, body?:mixed}
 * @phpstan-type WebhookResult array{status:string, side_effect_applied:bool}
 */
final class WebhookController
{
    /** @var list<string> */
    private array $sideEffectsApplied = [];

    /**
     * @param  WebhookPayload  $payload
     * @return WebhookResult
     */
    public function handle(array $payload): array
    {
        $eventId = (string) ($payload['event_id'] ?? '');
        if ($eventId === '') {
            return ['status' => 'invalid_event', 'side_effect_applied' => false];
        }

        // BUG: nunca consulta ReceivedEvent; sempre aplica side-effect.
        $this->sideEffectsApplied[] = $eventId;

        return ['status' => 'ok', 'side_effect_applied' => true];
    }

    /**
     * @return list<string>
     */
    public function appliedSideEffects(): array
    {
        return $this->sideEffectsApplied;
    }
}
