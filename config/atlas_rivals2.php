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

    // Régua de dificuldade (decisão do operador 02/07): frontier deve pontuar
    // ~20-35% em braço bare. Acima disso a SUITE é acusada de fácil demais —
    // o report levanta difficulty_flags em vez de celebrar o número.
    'difficulty' => [
        'frontier_bare_target_max' => 0.35,
    ],

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
    // 'command' = template CLI executado DENTRO da worktree ({workspace}, {prompt_file},
    // {cli_model}); só roda com provider_spend_allowed=true (gate fail-closed no adapter).
    'models' => [
        'claude_opus_4_8' => ['provider' => 'anthropic', 'access_type' => 'cli', 'cost_hint_in' => 15.0, 'cost_hint_out' => 75.0, 'local' => false, 'enabled' => true, 'cli_model' => 'claude-opus-4-8', 'command' => env('ATLAS_RIVALS2_CLAUDE_CMD', 'claude -p "$(cat {prompt_file})" --model {cli_model} --dangerously-skip-permissions')],
        'claude_sonnet_5' => ['provider' => 'anthropic', 'access_type' => 'cli', 'cost_hint_in' => 3.0, 'cost_hint_out' => 15.0, 'local' => false, 'enabled' => true, 'cli_model' => 'claude-sonnet-5', 'command' => env('ATLAS_RIVALS2_CLAUDE_CMD', 'claude -p "$(cat {prompt_file})" --model {cli_model} --dangerously-skip-permissions')],
        'codex_gpt_5_5' => ['provider' => 'openai', 'access_type' => 'cli', 'cost_hint_in' => null, 'cost_hint_out' => null, 'local' => false, 'enabled' => true, 'cli_model' => 'gpt-5.5-codex', 'command' => env('ATLAS_RIVALS2_CODEX_CMD', 'codex exec --full-auto "$(cat {prompt_file})"')],
        'gemini' => ['provider' => 'google', 'access_type' => 'cli', 'cost_hint_in' => null, 'cost_hint_out' => null, 'local' => false, 'enabled' => true, 'cli_model' => 'gemini', 'command' => env('ATLAS_RIVALS2_GEMINI_CMD', 'gemini --yolo -p "$(cat {prompt_file})"')],
        'minimax_m3' => ['provider' => 'minimax', 'access_type' => 'cli', 'cost_hint_in' => null, 'cost_hint_out' => null, 'local' => false, 'enabled' => true, 'cli_model' => 'minimax-m3', 'command' => env('ATLAS_RIVALS2_MINIMAX_CMD')],
        // assinatura Verboo via Hermes (custo marginal ~0; aprovado pelo operador 02/07)
        'verboo_qwen_3_6_35b' => ['provider' => 'hermes', 'access_type' => 'cli', 'cost_hint_in' => 0.0, 'cost_hint_out' => 0.0, 'local' => false, 'enabled' => true, 'cli_model' => 'verboo/qwen3.6-35b', 'command' => env('ATLAS_RIVALS2_HERMES_QWEN_CMD', 'hermes -z "$(cat {prompt_file})" -m {cli_model} --yolo')],
        'kimi' => ['provider' => 'moonshot', 'access_type' => 'api', 'cost_hint_in' => null, 'cost_hint_out' => null, 'local' => false, 'enabled' => false],
        'composer_2_5' => ['provider' => 'cursor', 'access_type' => 'cli', 'cost_hint_in' => null, 'cost_hint_out' => null, 'local' => false, 'enabled' => false],
        'glm_5_2' => ['provider' => 'zai', 'access_type' => 'api', 'cost_hint_in' => null, 'cost_hint_out' => null, 'local' => false, 'enabled' => true],
        'local_fake_model' => ['provider' => 'local', 'access_type' => 'local', 'cost_hint_in' => 0.0, 'cost_hint_out' => 0.0, 'local' => true, 'enabled' => true],
        // harness_* existem SÓ para validar a mecânica do AtlasBench (null = não faz nada,
        // golden = reaplica o patch real minerado). Nunca são medição de modelo.
        'harness_null' => ['provider' => 'local', 'access_type' => 'local', 'cost_hint_in' => 0.0, 'cost_hint_out' => 0.0, 'local' => true, 'enabled' => true],
        'harness_golden' => ['provider' => 'local', 'access_type' => 'local', 'cost_hint_in' => 0.0, 'cost_hint_out' => 0.0, 'local' => true, 'enabled' => true],
    ],

    // Executores de runtime Atlas (objetivo 2 — uplift). Template CLI que envolve
    // o MESMO modelo com o cérebro Atlas, rodando na worktree ({workspace},
    // {prompt_file}, {cli_model}). null = runtime ainda sem wrapper → o adapter
    // bloqueia honesto (uplift_supported=false); NUNCA simula.
    'runtime_commands' => [
        'atlas_dev' => env('ATLAS_RIVALS2_ATLAS_DEV_CMD'),
        'forge' => env('ATLAS_RIVALS2_FORGE_CMD'),
        'loop' => env('ATLAS_RIVALS2_LOOP_CMD'),
        'autonomous' => env('ATLAS_RIVALS2_AUTONOMOUS_CMD'),
    ],

    // AtlasBench interno: cases frescos minerados do histórico git dos repos Atlas
    // (estilo SWE-smith): reverte um commit real e pede a reimplementação; o
    // check é o teste real que o commit tocou. Geração 100% provider-free.
    'atlasbench' => [
        'repo_path' => env('ATLAS_RIVALS2_ATLASBENCH_REPO', base_path()),
        'mine_window_commits' => 300,
        // piso de dificuldade sênior: nada de micro-commit "receita"
        'min_diff_lines' => (int) env('ATLAS_RIVALS2_MIN_DIFF_LINES', 40),
        'min_code_files' => (int) env('ATLAS_RIVALS2_MIN_CODE_FILES', 2),
        'max_diff_lines' => 400,
        'check_timeout_seconds' => (int) env('ATLAS_RIVALS2_CHECK_TIMEOUT', 300),
    ],
];
