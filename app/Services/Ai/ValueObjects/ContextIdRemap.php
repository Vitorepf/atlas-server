<?php

namespace App\Services\Ai\ValueObjects;

/**
 * Atlas Cognition Operating System — Integer ID Remap value object.
 *
 * Schema canonico: atlas.context.id_remap.v1.
 *
 * Imutavel. Construido por AtlasContextIdRemapService::remap().
 * Suporta tres operacoes principais:
 *  - internal(string $realId): retorna "[N]" provider-safe.
 *  - real(string $internalLabel): retorna UUID original. Para parser reverso.
 *  - toArray(): serializacao canonica.
 *
 * Conventions:
 *  - internalToReal usa string keys numericas ("0", "1", ...) por consistencia JSON.
 *  - bracketStyle e fixo "square_bracket" em v1 -> "[0]", "[1]", "[2]".
 */
final class ContextIdRemap
{
    /**
     * @param  array<string,string>  $internalToReal
     * @param  array<string,string>  $realToInternal
     * @param  array<string,array<string,mixed>>  $refTypes
     */
    public function __construct(
        public readonly string $contextPackId,
        public readonly string $mappingHash,
        public readonly array $internalToReal,
        public readonly array $realToInternal,
        public readonly array $refTypes,
        public readonly string $schemaVersion = 'atlas.context.id_remap.v1',
        public readonly string $scope = 'context_pack',
        public readonly bool $providerSafe = true,
    ) {
    }

    public static function empty(string $contextPackId): self
    {
        return new self(
            contextPackId: $contextPackId,
            mappingHash: self::hashOf([], []),
            internalToReal: [],
            realToInternal: [],
            refTypes: [],
        );
    }

    public function isEmpty(): bool
    {
        return $this->internalToReal === [];
    }

    /**
     * Provider-safe label dado UUID real.
     * Retorna "[N]" quando real existe; retorna null quando nao mapeado.
     */
    public function internalLabel(string $realId): ?string
    {
        if (! isset($this->realToInternal[$realId])) {
            return null;
        }

        return '['.$this->realToInternal[$realId].']';
    }

    /**
     * UUID real dado label provider-safe.
     * Aceita tanto "0" quanto "[0]" como input para flexibilidade do parser.
     */
    public function realId(string $internalLabel): ?string
    {
        $key = trim($internalLabel);
        if (str_starts_with($key, '[') && str_ends_with($key, ']')) {
            $key = substr($key, 1, -1);
        }
        $key = trim($key);

        return $this->internalToReal[$key] ?? null;
    }

    /**
     * Batch reverse: aceita lista de labels e retorna UUIDs reais (ou null por slot).
     *
     * @param  array<int,string>  $internalLabels
     * @return array<int,?string>
     */
    public function realIdsBatch(array $internalLabels): array
    {
        return array_map(fn (string $label): ?string => $this->realId($label), $internalLabels);
    }

    /**
     * Ref type metadata para um label interno.
     * @return array<string,mixed>|null
     */
    public function refType(string $internalLabel): ?array
    {
        $key = trim($internalLabel);
        if (str_starts_with($key, '[') && str_ends_with($key, ']')) {
            $key = substr($key, 1, -1);
        }
        $key = trim($key);

        return $this->refTypes[$key] ?? null;
    }

    public function size(): int
    {
        return count($this->internalToReal);
    }

    /**
     * Iteracao ordenada por internal label numerico ascendente.
     * Yields [internal_label, real_id, ref_type] tuples.
     *
     * @return iterable<int,array{0:string,1:string,2:array<string,mixed>}>
     */
    public function entries(): iterable
    {
        // PHP coage chaves de array numericas string -> int internamente, entao
        // normalizamos como string aqui para garantir contrato estavel "[0]", "[1]".
        $keys = array_map(fn ($k): string => (string) $k, array_keys($this->internalToReal));

        usort($keys, function (string $a, string $b): int {
            $aNum = is_numeric($a) ? (int) $a : PHP_INT_MAX;
            $bNum = is_numeric($b) ? (int) $b : PHP_INT_MAX;

            return $aNum <=> $bNum;
        });

        foreach ($keys as $internalKey) {
            yield [
                $internalKey,
                (string) ($this->internalToReal[$internalKey] ?? $this->internalToReal[(int) $internalKey] ?? ''),
                (array) ($this->refTypes[$internalKey] ?? $this->refTypes[(int) $internalKey] ?? []),
            ];
        }
    }

    /**
     * @return array<string,mixed>
     */
    public function toArray(): array
    {
        return [
            'schema_version' => $this->schemaVersion,
            'context_pack_id' => $this->contextPackId,
            'mapping_hash' => $this->mappingHash,
            'scope' => $this->scope,
            'provider_safe' => $this->providerSafe,
            'internal_to_real' => $this->internalToReal,
            'real_to_internal' => $this->realToInternal,
            'ref_types' => $this->refTypes,
            'size' => $this->size(),
        ];
    }

    /**
     * Hash deterministico do mapping. Usado para drift detection.
     *
     * @param  array<string,string>  $internalToReal
     * @param  array<string,array<string,mixed>>  $refTypes
     */
    public static function hashOf(array $internalToReal, array $refTypes): string
    {
        ksort($internalToReal);
        ksort($refTypes);

        $payload = [
            'schema_version' => 'atlas.context.id_remap.v1',
            'internal_to_real' => $internalToReal,
            'ref_types' => $refTypes,
        ];

        return hash('sha256', json_encode($payload, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) ?: '');
    }
}
