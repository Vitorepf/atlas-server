<?php

namespace App\Http\Resources;

use App\Services\Ai\Mobile\MobileNotificationPreferences;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class MobileDeviceResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'user_id' => $this->user_id,
            'device_label' => $this->device_label,
            'platform' => $this->platform,
            'app_version' => $this->app_version,
            'os_version' => $this->os_version,
            'has_push_token' => $this->expo_push_token !== null,
            'notification_permissions' => $this->notification_permissions,
            'notification_preferences' => app(MobileNotificationPreferences::class)->forDevice($this->resource),
            'last_seen_at' => $this->last_seen_at?->toJSON(),
            'paired_at' => $this->paired_at?->toJSON(),
            'revoked_at' => $this->revoked_at?->toJSON(),
            'created_at' => $this->created_at?->toJSON(),
            'updated_at' => $this->updated_at?->toJSON(),
        ];
    }
}
