<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class AtlasMobileDevice extends Model
{
    use HasUuids;

    protected $fillable = [
        'user_id',
        'device_label',
        'platform',
        'app_version',
        'os_version',
        'expo_push_token',
        'push_token_hash',
        'device_token_hash',
        'notification_permissions',
        'last_seen_at',
        'paired_at',
        'revoked_at',
        'metadata',
    ];

    protected function casts(): array
    {
        return [
            'last_seen_at' => 'immutable_datetime',
            'paired_at' => 'immutable_datetime',
            'revoked_at' => 'immutable_datetime',
            'metadata' => 'array',
            'created_at' => 'immutable_datetime',
            'updated_at' => 'immutable_datetime',
        ];
    }

    public function pushDeliveries(): HasMany
    {
        return $this->hasMany(MobilePushDelivery::class, 'device_id');
    }

    public function isRevoked(): bool
    {
        return $this->revoked_at !== null;
    }
}
