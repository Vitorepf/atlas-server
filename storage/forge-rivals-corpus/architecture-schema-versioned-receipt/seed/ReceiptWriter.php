<?php

declare(strict_types=1);

namespace App\Services\Receipt;

/**
 * Grava receipts de execução em formato canônico.
 *
 * BUG (seed): receipt atual é só {timestamp, status, body}. Falta:
 *   - schema_version explícito.
 *   - sha256 reproduzível do payload (sem timestamp).
 *   - replay_manifest mínimo.
 *
 * O arm precisa:
 *   1. Adicionar `schema_version = 'atlas.receipt.v1'` em todo receipt.
 *   2. Calcular sha256 do payload normalizado (excluindo `generated_at`)
 *      via canonical JSON (UNESCAPED_SLASHES + SORT_FLAG_STRING nas keys).
 *   3. Devolver receipt com keys: schema_version, generated_at, status,
 *      body, payload_hash, replay_manifest {schema_version, payload_hash}.
 *   4. Mesma input em hosts diferentes deve produzir o mesmo payload_hash.
 */
final class ReceiptWriter
{
    public function __construct(
        /** @var callable():string Closure que devolve ISO8601 UTC; injetada para testabilidade. */
        private readonly mixed $clock = 'gmdate',
    ) {}

    /**
     * @param  array<string,mixed>  $body
     * @return array<string,mixed>
     */
    public function write(string $status, array $body): array
    {
        // BUG: receipt sem schema_version nem hash; não é replayable.
        return [
            'generated_at' => is_callable($this->clock) ? ($this->clock)('Y-m-d\TH:i:s\Z') : gmdate('Y-m-d\TH:i:s\Z'),
            'status' => $status,
            'body' => $body,
        ];
    }
}
