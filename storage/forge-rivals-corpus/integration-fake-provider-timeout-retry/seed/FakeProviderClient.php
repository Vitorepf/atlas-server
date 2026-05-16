<?php

declare(strict_types=1);

namespace App\Services\Integration;

/**
 * Cliente para um provider fake (em-memória).
 *
 * O construtor recebe uma callable `$dispatcher` que simula a chamada
 * remota: devolve um array {status: 'ok'|'flaky'|'timeout', ...}. Em prod
 * esse dispatcher seria um cliente HTTP real, mas o caso mede a *política*
 * de timeout/retry, não a transport layer.
 *
 * BUG (seed):
 *   - call() invoca dispatcher uma única vez, sem timeout nem retry.
 *   - Quando dispatcher devolve 'flaky' ou 'timeout', o cliente repassa
 *     o status cru — não tenta recuperar nem bloqueia honestamente.
 *
 * O arm precisa adicionar:
 *   - `timeoutSeconds` no construtor.
 *   - retry com backoff até `maxAttempts` (ex: 3 tentativas) ao receber 'flaky'.
 *   - hard timeout: se elapsed virtual > timeout, devolver
 *     ['status' => 'blocked', 'reason' => 'timeout', 'attempts' => N].
 *
 * Clock injetável (Closure que devolve int) permite testar sem `sleep` real.
 *
 * @phpstan-type DispatchResult array{status:string, payload?:mixed, latency_seconds?:int}
 */
final class FakeProviderClient
{
    public function __construct(
        /** @var callable():DispatchResult */
        private readonly mixed $dispatcher,
        private readonly int $timeoutSeconds = 5,
        private readonly int $maxAttempts = 3,
        /** @var callable():int */
        private readonly mixed $clock = 'time',
    ) {}

    /**
     * @return array{status:string, attempts:int, reason?:string, payload?:mixed}
     */
    public function call(): array
    {
        // BUG: dispara dispatcher uma vez, sem timeout nem retry policy.
        $result = ($this->dispatcher)();

        return [
            'status' => (string) ($result['status'] ?? 'unknown'),
            'attempts' => 1,
            'payload' => $result['payload'] ?? null,
        ];
    }
}
