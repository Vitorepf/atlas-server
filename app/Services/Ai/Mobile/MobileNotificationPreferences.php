<?php

namespace App\Services\Ai\Mobile;

use App\Models\AiInboxItem;
use App\Models\AtlasMobileDevice;

class MobileNotificationPreferences
{
    public const METADATA_KEY = 'notification_preferences';

    /**
     * @return array<string,bool>
     */
    public function defaults(): array
    {
        return [
            'critical_push_enabled' => true,
            'telemetry_health_push_enabled' => true,
            'daily_report_push_enabled' => true,
            'quiet_hours_enabled' => false,
            'proactive_push_enabled' => true,
            'manual_eclipse_enabled' => false,
        ];
    }

    /**
     * @return array<string,bool>
     */
    public function forDevice(AtlasMobileDevice $device): array
    {
        $metadata = is_array($device->metadata) ? $device->metadata : [];
        $stored = $metadata[self::METADATA_KEY] ?? [];

        return $this->normalize(is_array($stored) ? $stored : []);
    }

    /**
     * @param  array<string,mixed>  $input
     * @return array<string,bool>
     */
    public function merge(AtlasMobileDevice $device, array $input): array
    {
        return $this->normalize(array_merge($this->forDevice($device), $input));
    }

    public function disabledReason(AtlasMobileDevice $device, AiInboxItem $item): ?string
    {
        $preferences = $this->forDevice($device);

        if ($item->severity === 'critical' && $preferences['critical_push_enabled'] === false) {
            return 'critical_push_disabled';
        }

        if ($preferences['manual_eclipse_enabled'] === true && $this->severityRank($item->severity) < $this->severityRank('critical')) {
            return 'manual_eclipse_active';
        }

        if ($preferences['proactive_push_enabled'] === false && $item->type !== 'approval' && $this->severityRank($item->severity) < $this->severityRank('critical')) {
            return 'proactive_push_disabled';
        }

        if ($this->isTelemetryHealthInsight($item) && $preferences['telemetry_health_push_enabled'] === false) {
            return 'telemetry_health_push_disabled';
        }

        if ($this->isPerformanceReport($item) && $preferences['daily_report_push_enabled'] === false) {
            return 'daily_report_push_disabled';
        }

        return null;
    }

    /**
     * @param  array<string,mixed>  $input
     * @return array<string,bool>
     */
    private function normalize(array $input): array
    {
        $normalized = [];

        foreach ($this->defaults() as $key => $default) {
            $value = $input[$key] ?? $default;
            $normalized[$key] = is_bool($value) ? $value : (bool) $default;
        }

        return $normalized;
    }

    private function isTelemetryHealthInsight(AiInboxItem $item): bool
    {
        return $item->type === 'insight'
            && (data_get($item->payload ?? [], 'insight_kind') === 'atlas_ai_telemetry_health'
                || str_contains((string) $item->dedupe_key, 'atlas-ai-telemetry-health'));
    }

    private function isPerformanceReport(AiInboxItem $item): bool
    {
        return $item->category === 'atlas_ai_performance'
            || $item->source_type === 'atlas_ai_performance_report'
            || data_get($item->payload ?? [], 'category') === 'atlas_ai_performance'
            || str_contains((string) $item->dedupe_key, 'atlas-ai-performance');
    }

    private function severityRank(string $severity): int
    {
        return match ($severity) {
            'critical' => 3,
            'warning' => 2,
            'info' => 1,
            default => 0,
        };
    }
}
