<?php

return [
    /*
    | Atlas Terminal Dev — session agent surface (Grok-class).
    | Kernel/brain stay in atlas-server; this config only shapes Terminal Dev.
    |
    | Operator pivot 2026-07-23: muscle = Hermes only (local hermes CLI),
    | not Claude/Codex API keys. Transport oneshot cli (hermes -z) for headless.
    */
    'schema' => 'atlas.terminal.config.v1',

    'sessions_root' => env('ATLAS_TERMINAL_SESSIONS_ROOT', ($_SERVER['HOME'] ?? getenv('HOME') ?: storage_path('app')).'/.atlas/sessions'),

    // Hermes-only default (operator). Override via ATLAS_TERMINAL_PROVIDER_ORDER if needed.
    'default_provider_order' => array_values(array_filter(array_map('trim', explode(',', (string) env(
        'ATLAS_TERMINAL_PROVIDER_ORDER',
        'hermes_cli'
    ))))),

    'provider' => env('ATLAS_TERMINAL_PROVIDER', 'hermes_cli'),

    'hermes_allowed' => (bool) env('ATLAS_TERMINAL_HERMES_ALLOWED', true),

    // When true, bridge does not spawn hermes (tests / offline). Runtime falls back to local planner.
    'hermes_dry_run' => (bool) env('ATLAS_TERMINAL_HERMES_DRY', false),

    // Force hermes -z oneshot (non-interactive). Required to avoid chat TTY hang.
    'hermes_cli_oneshot' => (bool) env('ATLAS_TERMINAL_HERMES_CLI_ONESHOT', true),

    'timeout_seconds' => (int) env('ATLAS_TERMINAL_TIMEOUT_SECONDS', 300),

    'max_tool_rounds' => (int) env('ATLAS_TERMINAL_MAX_TOOL_ROUNDS', 8),

    'open_brain' => [
        'enabled' => (bool) env('ATLAS_TERMINAL_OPEN_BRAIN', true),
        'budget_chars' => (int) env('ATLAS_TERMINAL_OPEN_BRAIN_BUDGET', 4000),
        'fail_open' => true,
    ],

    'permission' => [
        'default_mode' => env('ATLAS_TERMINAL_PERMISSION_MODE', 'write'),
        'yolo_default' => (bool) env('ATLAS_TERMINAL_YOLO', false),
    ],

    'plan' => [
        'filename' => 'plan.md',
    ],

    'skills' => [
        'compat_claude' => (bool) env('ATLAS_TERMINAL_SKILLS_CLAUDE', true),
        'compat_grok' => (bool) env('ATLAS_TERMINAL_SKILLS_GROK', true),
    ],

    'protocol_version' => 'atlas.agent.protocol.v0',

    'auto_compact_turns' => (int) env('ATLAS_TERMINAL_AUTO_COMPACT_TURNS', 40),

    'plugins_paths' => array_values(array_filter(array_map('trim', explode(',', (string) env(
        'ATLAS_TERMINAL_PLUGIN_PATHS',
        ''
    ))))),
];
