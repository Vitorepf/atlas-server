<?php

namespace App\Services\Ai\SelfConstruction\NativeImplementation;

use App\Services\Ai\SelfConstruction\Concerns\RecursivelyKsortsArrays;
use App\Services\Ai\SelfConstruction\Support\KsortsArraysByReference;

final class AtlasSelfConstructionCompletionEvidenceHashService
{
    use RecursivelyKsortsArrays { recursivelyKsort as ksortRecursive; }

    use KsortsArraysByReference;


    /**
     * @param  array<string,mixed>  $value
     * @return array<string,mixed>
     */
    /** @param array<string, mixed> $receipt */
    public function runtimePromotionReceiptHash(array $receipt): string
    {
        return $this->stableHash($this->withoutVolatileReceiptFields($receipt, 'receipt_hash'));
    }

    /** @param array<string, mixed> $receipt */
    public function humanCompletionReceiptHash(array $receipt): string
    {
        return $this->stableHash($this->withoutVolatileReceiptFields($receipt, 'receipt_hash'));
    }

    /** @param array<string, mixed> $smoke */
    public function realProviderSmokeHash(array $smoke): string
    {
        return $this->stableHash($this->withoutVolatileReceiptFields($smoke, 'smoke_hash'));
    }

    /** @var list<string> */
    private const VOLATILE_FIELDS = [
        'receipt_hash',
        'smoke_hash',
        'schema_version',
        'persisted_at',
        'verified_at',
        'certified_at',
        'receipt_verification_hash',
        'certification_hash',
    ];

    /**
     * @param  array<string, mixed>  $payload
     * @return array<string, mixed>
     */
    private function withoutVolatileReceiptFields(array $payload, string $hashField): array
    {
        unset($payload[$hashField]);

        return $this->stripVolatileFieldsRecursive($payload);
    }

    /**
     * @param  array<mixed, mixed>  $value
     * @return array<mixed, mixed>
     */
    private function stripVolatileFieldsRecursive(array $value): array
    {
        foreach (self::VOLATILE_FIELDS as $field) {
            unset($value[$field]);
        }

        foreach ($value as $key => $entry) {
            if (is_array($entry)) {
                $value[$key] = $this->stripVolatileFieldsRecursive($entry);
            }
        }

        return $value;
    }

    /** @param array<string, mixed> $payload */
    private function stableHash(array $payload): string
    {
        return hash('sha256', (string) json_encode($this->ksortRecursive($payload), JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));
    }

    /** @param array<string, mixed> $value */
}
