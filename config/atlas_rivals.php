<?php

/*
|--------------------------------------------------------------------------
| Atlas Rivals — benchmark interno (model-vs-model + Atlas uplift)
|--------------------------------------------------------------------------
| Produto público: Rivals. Versão: 2.0. Comando: atlas:rivals.
| Rivals 1.0 está morto (ver docs/engineering-knowledge-base/atlas-rivals2-rebuild-map-v1.md).
| O juiz final é sempre o núcleo Rivals local; suites externas são adapters.
| claim_allowed=false é o default pétreo.
| Compat interno preservado de propósito (runs existentes em disco + .env vivo):
| schema ids atlas.rivals2.* e env vars ATLAS_RIVALS2_*. Storage público é
| storage/atlas/rivals (decisão do operador 03/07); atlas/rivals2 vira symlink.
*/

return [
    'version' => '2.0',

    'enabled' => env('ATLAS_RIVALS2_ENABLED', false),

    // Nenhum run pode gastar provider sem esta flag E aprovação explícita por run.
    'provider_spend_allowed' => env('ATLAS_RIVALS2_PROVIDER_SPEND', false),

    'storage_root' => env('ATLAS_RIVALS2_STORAGE', storage_path('atlas/rivals')),

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
    // ~20-30% em braço bare. Acima disso a SUITE é acusada de fácil demais —
    // o report levanta difficulty_flags em vez de celebrar o número.
    // Bandas do DifficultyCalibrator (success_rate do braço bare mais forte):
    // >35% too_easy | 30-35% borderline | 20-30% elite_valid | 5-20% hard | <5% frontier (válida).
    'difficulty' => [
        'frontier_bare_target_max' => 0.35,
        'bands' => [
            'too_easy' => 0.35,
            'borderline' => 0.30,
            'elite_valid' => 0.20,
            'hard' => 0.05,
        ],
    ],

    // Contamination Guard: case só é prova se for fresh; corpus long-lived expira.
    'contamination' => [
        'max_case_age_days' => (int) env('ATLAS_RIVALS2_CASE_MAX_AGE_DAYS', 30),
    ],

    // Reality Score: acima deste inchaço vs golden, o patch deixa de contar como minimal.
    'reality' => [
        'max_bloat_ratio' => 2.0,
    ],

    // Elite Reality Suite: pisos mais duros que o atlasbench (chaves omitidas herdam dele).
    'elite' => [
        'mine_window_commits' => 1000,
        'min_diff_lines' => (int) env('ATLAS_RIVALS2_ELITE_MIN_DIFF_LINES', 80),
        'min_code_files' => (int) env('ATLAS_RIVALS2_ELITE_MIN_CODE_FILES', 3),
        'max_diff_lines' => 900,
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
        // task families da Elite Reality Suite (classificação sênior por conteúdo real)
        'senior_bug_investigation',
        'architecture_refactor',
        'migration_backward_compat',
        'performance_regression',
        'concurrency_state_bug',
        'security_privacy_boundary',
        'flaky_behavior',
        'long_horizon_repair',
        'unknown_unknown',
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
        // gpt-5.5-codex via Hermes (baseline forte p/ calibração; aprovado pelo operador 02/07)
        'hermes_gpt_5_5_codex' => ['provider' => 'hermes', 'access_type' => 'cli', 'cost_hint_in' => null, 'cost_hint_out' => null, 'local' => false, 'enabled' => true, 'cli_model' => 'gpt-5.5-codex', 'command' => env('ATLAS_RIVALS2_HERMES_CODEX_CMD', 'hermes -z "$(cat {prompt_file})" -m {cli_model} --yolo')],
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

    // Benchmark repos EXTERNOS reais (os 9 fornecidos pelo operador), clonados em
    // área isolada (tools/rivals/benchmarks). Adapter só é "pronto" com smoke real
    // verde aqui; erro vira status=blocked com o erro exato, nunca "done".
    // install/smoke rodam DENTRO do clone com .atlas-venv/bin no PATH (venv uv
    // isolado por repo — nunca o vendor/autoload vivo). Nenhum smoke gasta provider.
    'benchmarks' => [
        'root' => env('ATLAS_RIVALS_BENCHMARKS_ROOT', base_path('tools/rivals/benchmarks')),
        'install_timeout_seconds' => 900,
        'smoke_timeout_seconds' => 300,
        'repos' => [
            'tau2_bench' => [
                'url' => 'https://github.com/sierra-research/tau2-bench.git',
                'adapter' => 'tau2_bfcl',
                'install' => ['uv venv --clear --python 3.12 .atlas-venv', 'uv pip install -p .atlas-venv/bin/python -e . -q'],
                'smoke' => 'tau2 check-data',
            ],
            'bfcl' => [
                'url' => 'https://github.com/ShishirPatil/gorilla.git',
                'adapter' => 'tau2_bfcl',
                'install' => ['uv venv --clear --python 3.12 .atlas-venv', 'uv pip install -p .atlas-venv/bin/python -e ./berkeley-function-call-leaderboard soundfile -q'],
                'smoke' => 'bfcl test-categories',
            ],
            'terminal_bench' => [
                'url' => 'https://github.com/laude-institute/terminal-bench.git',
                'adapter' => 'harbor_terminal_bench',
                'install' => ['uv venv --clear --python 3.12 .atlas-venv', 'uv pip install -p .atlas-venv/bin/python -e . -q'],
                'smoke' => 'tb datasets list',
            ],
            'senior_swe_bench' => [
                'url' => 'https://github.com/snorkel-ai/senior-swe-bench-v2026.06.git',
                'adapter' => 'senior_swe_bench',
                'install' => ['uv venv --clear --python 3.12 .atlas-venv', 'uv pip install -p .atlas-venv/bin/python harbor -q'],
                'smoke' => 'harbor --help',
            ],
            'swe_bench_live' => [
                'url' => 'https://github.com/microsoft/SWE-bench-Live.git',
                'adapter' => 'swe_bench_live',
                'install' => ['uv venv --clear --python 3.12 .atlas-venv', 'git submodule update --init --depth 1 launch', 'uv pip install -p .atlas-venv/bin/python -e . -q'],
                'smoke' => 'python -m evaluation.evaluation --help',
            ],
            'live_code_bench' => [
                'url' => 'https://github.com/LiveCodeBench/LiveCodeBench.git',
                'adapter' => 'live_code_bench',
                'install' => ['uv venv --clear --python 3.12 .atlas-venv', 'uv pip install -p .atlas-venv/bin/python -e . -q'],
                'smoke' => 'python -m lcb_runner.runner.main --help',
            ],
            'inspect_evals' => [
                'url' => 'https://github.com/UKGovernmentBEIS/inspect_evals.git',
                'adapter' => 'inspect_evals',
                'install' => ['uv venv --clear --python 3.12 .atlas-venv', 'uv pip install -p .atlas-venv/bin/python -e . inspect-ai -q'],
                'smoke' => 'inspect eval inspect_evals/gsm8k --model mockllm/model --limit 1',
            ],
            'hal_harness' => [
                'url' => 'https://github.com/princeton-pli/hal-harness.git',
                'adapter' => 'hal_harness',
                'install' => ['uv venv --clear --python 3.12 .atlas-venv', 'uv pip install -p .atlas-venv/bin/python -e . -q'],
                'smoke' => 'hal-eval --help',
            ],
            'aider_polyglot' => [
                'url' => 'https://github.com/Aider-AI/aider.git',
                'adapter' => 'aider_polyglot',
                'install' => ['uv venv --clear --python 3.12 .atlas-venv', 'uv pip install -p .atlas-venv/bin/python -e . -q', 'uv pip install -p .atlas-venv/bin/python -r requirements/requirements-dev.txt -q'],
                'smoke' => 'python benchmark/benchmark.py --help',
            ],
        ],
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
        // teto do SOLVER é separado e generoso (decisão do operador 02/07: benchmark
        // honesto não mata engenheiro no relógio) — 1h default, só backstop anti-hang
        'solver_timeout_seconds' => (int) env('ATLAS_RIVALS2_SOLVER_TIMEOUT', 3600),
    ],
];
