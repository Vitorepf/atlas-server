<?php

namespace App\Services\Ai\SelfConstruction;

final class AtlasSelfConstructionCompletionEvidenceHashService
{
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

    /**
     * @param  array<string, mixed>  $payload
     * @return array<string, mixed>
     */
    private function withoutVolatileReceiptFields(array $payload, string $hashField): array
    {
        unset(
            $payload[$hashField],
            $payload['schema_version'],
            $payload['persisted_at'],
            $payload['verified_at'],
            $payload['certified_at'],
            $payload['receipt_verification_hash'],
            $payload['certification_hash'],
        );

        return $payload;
    }

    /** @param array<string, mixed> $payload */
    private function stableHash(array $payload): string
    {
        return hash('sha256', (string) json_encode($this->ksortRecursive($payload), JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));
    }

    /** @param array<string, mixed> $value */
    private function ksortRecursive(array $value): array
    {
        foreach ($value as $key => $entry) {
            if (is_array($entry)) {
                $value[$key] = $this->ksortRecursive($entry);
            }
        }
        if ($value !== [] && array_keys($value) !== range(0, count($value) - 1)) {
            ksort($value);
        }

        return $value;
    }
}
