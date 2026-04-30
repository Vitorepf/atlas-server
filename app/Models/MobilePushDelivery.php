<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class MobilePushDelivery extends Model
{
    use HasUuids;

    protected $fillable = [
        'inbox_item_id',
        'device_id',
        'status',
        'provider',
        'provider_ticket_id',
        'provider_receipt_id',
        'request_payload',
        'response_payload',
        'error_code',
        'error_message',
        'attempted_at',
    ];

    protected function casts(): array
    {
        return [
            'inbox_item_id' => 'string',
            'device_id' => 'string',
            'request_payload' => 'array',
            'response_payload' => 'array',
            'attempted_at' => 'immutable_datetime',
            'created_at' => 'immutable_datetime',
            'updated_at' => 'immutable_datetime',
        ];
    }

    public function inboxItem(): BelongsTo
    {
        return $this->belongsTo(AiInboxItem::class, 'inbox_item_id');
    }

    public function device(): BelongsTo
    {
        return $this->belongsTo(AtlasMobileDevice::class, 'device_id');
    }
}
