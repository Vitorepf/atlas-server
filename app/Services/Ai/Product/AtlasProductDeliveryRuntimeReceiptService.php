<?php

namespace App\Services\Ai\Product;

use App\Models\AtlasProductDeliveryRuntimeReceipt;
use App\Services\Ai\Mission\MissionCanonicalHash;

class AtlasProductDeliveryRuntimeReceiptService
{
    public const SCHEMA_VERSION = 'atlas.product_delivery.runtime_receipt.v1';

    /**
     * @param  array<string,mixed>  $payload
     */
    public function record(string $type, array $payload): AtlasProductDeliveryRuntimeReceipt
    {
        $receipt = [
            'schema_version' => self::SCHEMA_VERSION,
            'receipt_type' => $this->type($type),
            'status' => $this->status($payload),
            'route' => $this->nullableString($payload['route'] ?? data_get($payload, 'patch_proposal_gate.route')),
            'delivery_hash' => $this->nullableString($payload['delivery_hash'] ?? null),
            'proof_hash' => $this->nullableString($payload['proof_hash'] ?? null),
            'writes' => (bool) ($payload['writes'] ?? false),
            'payload' => $payload,
        ];
        $receipt['receipt_hash'] = MissionCanonicalHash::sha256($receipt);

        return AtlasProductDeliveryRuntimeReceipt::query()->create($receipt);
    }

    /**
     * @return array<string,mixed>
     */
    public function envelope(AtlasProductDeliveryRuntimeReceipt $record): array
    {
        return [
            'schema_version' => self::SCHEMA_VERSION,
            'id' => (string) $record->id,
            'receipt_type' => $record->receipt_type,
            'status' => $record->status,
            'route' => $record->route,
            'delivery_hash' => $record->delivery_hash,
            'proof_hash' => $record->proof_hash,
            'writes' => (bool) $record->writes,
            'receipt_hash' => $record->receipt_hash,
            'created_at' => $record->created_at?->toIso8601String(),
        ];
    }

    private function type(string $type): string
    {
        $type = trim($type);

        return in_array($type, ['patch_request', 'patch_gate', 'repair_execution'], true)
            ? $type
            : 'runtime_event';
    }

    /**
     * @param  array<string,mixed>  $payload
     */
    private function status(array $payload): string
    {
        $status = $payload['status'] ?? 'unknown';

        return is_scalar($status) && trim((string) $status) !== '' ? trim((string) $status) : 'unknown';
    }

    private function nullableString(mixed $value): ?string
    {
        if (! is_scalar($value)) {
            return null;
        }

        $value = trim((string) $value);

        return $value === '' ? null : $value;
    }
}
