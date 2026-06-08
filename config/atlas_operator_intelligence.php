<?php

return [
    'enabled' => env('ATLAS_OPERATOR_INTELLIGENCE_ENABLED', true),
    'shadow_mode' => env('ATLAS_OPERATOR_INTELLIGENCE_SHADOW_MODE', true),
    'default_operator_id' => env('ATLAS_OPERATOR_INTELLIGENCE_OPERATOR_ID', 'default'),
    'max_injected_profile_items' => (int) env('ATLAS_OPERATOR_INTELLIGENCE_MAX_INJECTED_ITEMS', 8),
    'min_auto_apply_confidence' => (float) env('ATLAS_OPERATOR_INTELLIGENCE_MIN_AUTO_APPLY_CONFIDENCE', 0.85),
    'projection_path' => env('ATLAS_OPERATOR_INTELLIGENCE_PROJECTION_PATH', storage_path('app/atlas/operator-intelligence')),
    'digest_recent_days' => (int) env('ATLAS_OPERATOR_INTELLIGENCE_DIGEST_RECENT_DAYS', 7),
    'provider_safe_privacy_classes' => ['normal'],
    'internal_privacy_classes' => ['normal', 'private'],
    'blocked_external_privacy_classes' => ['sensitive', 'secret'],
    'auto_apply_enabled' => env('ATLAS_OPERATOR_INTELLIGENCE_AUTO_APPLY_ENABLED', false),
    'chat_capture_enabled' => env('ATLAS_OPERATOR_INTELLIGENCE_CHAT_CAPTURE_ENABLED', true),
    'chat_capture_source_types' => ['manual', 'app', 'voice_realtime'],
    'default_automation_level' => 'observe',
];
