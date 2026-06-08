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

    // Comprehension extractor (the LLM brain that learns the 170 by understanding +
    // inference, beyond the regex trigger floor). NEVER inline — the hot path is
    // perf-bound; the LLM runs ONLY in the batch command (atlas:ai:operator-comprehend)
    // or a deferred per-turn job. mode: off | observe (DEFAULT) | enforce.
    'comprehension_extraction_mode' => env('ATLAS_OPERATOR_COMPREHENSION_MODE', 'observe'),
    'comprehension_per_turn_enabled' => env('ATLAS_OPERATOR_COMPREHENSION_PER_TURN', true), // ALIVE: learn on every turn (deferred, off the hot path)
    'comprehension_provider_key' => env('ATLAS_OPERATOR_COMPREHENSION_PROVIDER', null),
    'comprehension_model' => env('ATLAS_OPERATOR_COMPREHENSION_MODEL', null),
    'comprehension_timeout_seconds' => (int) env('ATLAS_OPERATOR_COMPREHENSION_TIMEOUT', 120),
    'comprehension_batch_limit' => (int) env('ATLAS_OPERATOR_COMPREHENSION_BATCH_LIMIT', 200),
    // Adversarial refute pass: a 2nd model must CONFIRM an inferred/high-stakes preference
    // or it is dropped (the strongest defense against over-generalized inference).
    'comprehension_refute_enabled' => env('ATLAS_OPERATOR_COMPREHENSION_REFUTE', true),
];
