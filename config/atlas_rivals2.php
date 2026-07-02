<?php

/*
|--------------------------------------------------------------------------
| Atlas Rivals 2.0 — benchmark interno (model-vs-model + Atlas uplift)
|--------------------------------------------------------------------------
| Rivals 1.0 está morto (ver docs/engineering-knowledge-base/atlas-rivals2-rebuild-map-v1.md).
| Este arquivo governa APENAS o Rivals 2.0. O juiz final é sempre o Rivals 2.0
| local; suites externas são adapters. claim_allowed=false é o default pétreo.
*/

return [
    'enabled' => env('ATLAS_RIVALS2_ENABLED', false),

    // Nenhum run pode gastar provider sem esta flag E aprovação explícita por run.
    'provider_spend_allowed' => env('ATLAS_RIVALS2_PROVIDER_SPEND', false),

    'storage_root' => env('ATLAS_RIVALS2_STORAGE', storage_path('atlas/rivals2')),

    'claim' => [
        'min_repetitions' => 3,
        'require_replay_verified' => true,
        'require_evidence_pack' => true,
        // Claims são SEMPRE escopados (task_type, suite, cases, model, runtime,
        // budget, environment, repetitions, judge_config?). Nunca "best overall".
        'scoped_only' => true,
    ],

    'runtimes' => ['bare', 'atlas_dev', 'forge', 'loop', 'autonomous'],

    'task_types' => [
        'coding_patch',
        'feature_under_specified',
        'bug_investigation',
        'refactor',
        'architecture_design',
        'terminal_agent',
        'tool_use_function_calling',
        'long_horizon_engineering',
        'planning',
        'review_adversarial_critique',
        'repair_regression_fixing',
        'cost_sensitive_work',
        'local_offline_model_work',
    ],

    // Modelos que o operador consegue usar no Mac (CLI/API/local).
    // access_type: cli | api | local. Custos são hints (USD por 1M tokens);
    // o custo REAL de cada run vem do receipt, nunca daqui.
    'models' => [
        'claude_opus_4_8' => ['provider' => 'anthropic', 'access_type' => 'cli', 'cost_hint_in' => 15.0, 'cost_hint_out' => 75.0, 'local' => false, 'enabled' => true],
        'claude_sonnet_5' => ['provider' => 'anthropic', 'access_type' => 'cli', 'cost_hint_in' => 3.0, 'cost_hint_out' => 15.0, 'local' => false, 'enabled' => true],
        'codex_gpt_5_5' => ['provider' => 'openai', 'access_type' => 'cli', 'cost_hint_in' => null, 'cost_hint_out' => null, 'local' => false, 'enabled' => true],
        'gemini' => ['provider' => 'google', 'access_type' => 'cli', 'cost_hint_in' => null, 'cost_hint_out' => null, 'local' => false, 'enabled' => true],
        'minimax_m3' => ['provider' => 'minimax', 'access_type' => 'cli', 'cost_hint_in' => null, 'cost_hint_out' => null, 'local' => false, 'enabled' => true],
        'kimi' => ['provider' => 'moonshot', 'access_type' => 'api', 'cost_hint_in' => null, 'cost_hint_out' => null, 'local' => false, 'enabled' => false],
        'composer_2_5' => ['provider' => 'cursor', 'access_type' => 'cli', 'cost_hint_in' => null, 'cost_hint_out' => null, 'local' => false, 'enabled' => false],
        'glm_5_2' => ['provider' => 'zai', 'access_type' => 'api', 'cost_hint_in' => null, 'cost_hint_out' => null, 'local' => false, 'enabled' => true],
        'local_fake_model' => ['provider' => 'local', 'access_type' => 'local', 'cost_hint_in' => 0.0, 'cost_hint_out' => 0.0, 'local' => true, 'enabled' => true],
        // harness_* existem SÓ para validar a mecânica do AtlasBench (null = não faz nada,
        // golden = reaplica o patch real minerado). Nunca são medição de modelo.
        'harness_null' => ['provider' => 'local', 'access_type' => 'local', 'cost_hint_in' => 0.0, 'cost_hint_out' => 0.0, 'local' => true, 'enabled' => true],
        'harness_golden' => ['provider' => 'local', 'access_type' => 'local', 'cost_hint_in' => 0.0, 'cost_hint_out' => 0.0, 'local' => true, 'enabled' => true],
    ],

    // AtlasBench interno: cases frescos minerados do histórico git dos repos Atlas
    // (estilo SWE-smith): reverte um commit real e pede a reimplementação; o
    // check é o teste real que o commit tocou. Geração 100% provider-free.
    'atlasbench' => [
        'repo_path' => env('ATLAS_RIVALS2_ATLASBENCH_REPO', base_path()),
        'mine_window_commits' => 300,
        'max_diff_lines' => 400,
        'check_timeout_seconds' => 300,
    ],
];
