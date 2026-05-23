<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use LogicException;

class AtlasProductDeliveryRuntimeReceipt extends Model
{
    use HasUuids;

    protected $table = 'atlas_product_delivery_runtime_receipts';

    protected $fillable = [
        'schema_version',
        'receipt_type',
        'status',
        'route',
        'delivery_hash',
        'proof_hash',
        'payload',
        'writes',
        'receipt_hash',
    ];

    protected function casts(): array
    {
        return [
            'payload' => 'array',
            'writes' => 'boolean',
            'created_at' => 'immutable_datetime',
            'updated_at' => 'immutable_datetime',
        ];
    }

    public function save(array $options = []): bool
    {
        if ($this->exists && $this->isDirty()) {
            throw new LogicException('Atlas product delivery runtime receipts are append-only; update is not allowed.');
        }

        return parent::save($options);
    }

    public function delete()
    {
        throw new LogicException('Atlas product delivery runtime receipts are append-only; delete is not allowed.');
    }
}
