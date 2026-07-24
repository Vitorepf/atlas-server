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

    'import_roots' => array_values(array_filter([
        base_path(),
        storage_path('atlas/rivals'),
        base_path('tools/rivals/benchmarks'),
        env('ATLAS_RIVALS2_IMPORT_ROOT'),
    ])),

    'claim' => [
        'min_repetitions' => 3,
        'min_distinct_cases_internal' => 3,
        'max_ci_width_internal' => 0.50,
        'min_distinct_cases_public' => 10,
        'max_ci_width_public' => 0.25,
        'smoke_max_age_hours' => 168,
        'max_environment_failure_rate' => 0.05,
        'block_dirty_workspace' => true,
        'require_replay_verified' => true,
        'require_evidence_pack' => true,
        // Claims são SEMPRE escopados (task_type, suite, cases, model, runtime,
        // budget, environment, repetitions, judge_config?). Nunca "best overall".
        'scoped_only' => true,
        // P2g-CURR: strong multiplier / M_excellence claims only on non-saturated frontier.
        'multiplier_requires_frontier' => true,
        'block_saturated_frontier_multiplier' => true,
        'block_sanity_multiplier' => true,
    ],

    // P2g-CURR curriculum ladder (S_sanity / S_frontier / S_horizon). Metadata only —
    // not a second organ. Promotion is pure policy in RivalsCurriculumLadder.
    'curriculum' => [
        'schema' => 'atlas.rivals.curriculum_ladder.v1',
        'levels' => [
            [
                'level_id' => 'L0_sanity_smoke',
                'curriculum_role' => 'sanity',
                'promotion_bar' => 0.95,
                'predecessor_level_id' => null,
                'next_level_id' => 'L1_frontier_engineering',
                'description' => 'Regression/parity smoke — never max multiplier claim surface',
            ],
            [
                'level_id' => 'L1_frontier_engineering',
                'curriculum_role' => 'frontier',
                'promotion_bar' => 0.90,
                'predecessor_level_id' => 'L0_sanity_smoke',
                'next_level_id' => 'L2_horizon_unsolved',
                'description' => 'Active excellence / multiplier measure surface when not saturated',
            ],
            [
                'level_id' => 'L2_horizon_unsolved',
                'curriculum_role' => 'horizon',
                'promotion_bar' => 0.80,
                'predecessor_level_id' => 'L1_frontier_engineering',
                'next_level_id' => null,
                'description' => 'Open research frontier beyond current school level',
            ],
        ],
    ],


    'runtimes' => ['bare', 'atlas_dev', 'forge', 'loop', 'autonomous'],

    // Perfis são conjuntos explícitos e imutáveis no plano. O legado Fase A
    // continua reproduzível; Engenharia Nativa não herda suites Docker/x86.
    'profiles' => [
        'fase_a' => [
            'suite_ids' => [
                'tau2_bench',
                'bfcl',
                'terminal_bench',
                'senior_swe_bench',
                'swe_bench_live',
                'live_code_bench',
                'inspect_evals',
                'hal_harness',
                'aider_polyglot',
                'swe_marathon',
            ],
        ],
        'engineering_native' => [
            'native_only' => true,
            'suite_ids' => [
                'bfcl',
                'live_code_bench',
                'aider_polyglot',
                'archbench',
                'cruxeval',
                'classeval',
                'repobench',
                'locagent',
                'debug_gym',
                'testeval',
                'evalplus',
                'crosscodeeval',
                'bigcodebench',
                'deveval',
                'long_code_arena',
                'reval',
            ],
            'excluded_suite_ids' => ['r2abench'],
            // Packs de 10 casos DISTINTOS (expansão 20/07, e387f0eaf3): a lista
            // executada DEVE espelhar tests/Fixtures/Rivals/cases/<suite> — a
            // battery roda o que está AQUI, não o que o import materializa
            // (provado: bfcl fechou com 9 pares tendo 10 casos no storage).
            'case_packs' => [
                'bfcl' => [
                    'simple', 'multiple', 'parallel',
                    'simple_python_001', 'multiple_001', 'parallel_001',
                    'simple_python_002', 'multiple_002', 'parallel_002',
                    'simple_python_003',
                ],
                'live_code_bench' => [
                    '1873_A', '1873_B', '1873_D', '1883_B', '1883_C',
                    '1899_A', '1899_B', '1899_C', '1899_D', '2727',
                ],
                'aider_polyglot' => [
                    'polyglot_001', 'polyglot_002', 'polyglot_003', 'polyglot_004',
                    'polyglot_005', 'polyglot_006', 'polyglot_007', 'polyglot_008',
                    'polyglot_009', 'polyglot_010',
                ],
                'archbench' => [
                    'archbench_adr_000', 'archbench_adr_001', 'archbench_adr_002',
                    'archbench_adr_003', 'archbench_adr_004', 'archbench_adr_005',
                    'archbench_adr_006', 'archbench_adr_007', 'archbench_adr_008',
                    'archbench_adr_009',
                ],
                'cruxeval' => [
                    'cruxeval_output_000', 'cruxeval_output_001', 'cruxeval_output_002',
                    'cruxeval_output_003', 'cruxeval_output_004', 'cruxeval_output_005',
                    'cruxeval_output_006', 'cruxeval_output_007', 'cruxeval_output_008',
                    'cruxeval_output_009',
                ],
                'classeval' => [
                    'classeval_000', 'classeval_001', 'classeval_002', 'classeval_003',
                    'classeval_004', 'classeval_005', 'classeval_006', 'classeval_007',
                    'classeval_008', 'classeval_009',
                ],
                'repobench' => [
                    'repobench_python_000', 'repobench_python_001', 'repobench_python_002',
                    'repobench_python_003', 'repobench_python_004', 'repobench_python_005',
                    'repobench_python_006', 'repobench_python_007', 'repobench_python_008',
                    'repobench_python_009',
                ],
                'locagent' => [
                    'locagent_000', 'locagent_001', 'locagent_002', 'locagent_003',
                    'locagent_004', 'locagent_005', 'locagent_006', 'locagent_007',
                    'locagent_008', 'locagent_009',
                ],
                'debug_gym' => [
                    'debug_gym_counter', 'debug_gym_knapsack', 'debug_gym_tic_tac_toe',
                    'debug_gym_config', 'debug_gym_grader', 'debug_gym_pandas_dataframe',
                    'debug_gym_patcher', 'debug_gym_scientific_calculator',
                    'debug_gym_sum_tree', 'debug_gym_tomorrow_date',
                ],
                'testeval' => [
                    'testeval_000', 'testeval_001', 'testeval_002', 'testeval_003',
                    'testeval_004', 'testeval_005', 'testeval_006', 'testeval_007',
                    'testeval_008', 'testeval_009',
                ],
                'evalplus' => [
                    'evalplus_humaneval_000', 'evalplus_humaneval_001', 'evalplus_humaneval_002',
                    'evalplus_humaneval_003', 'evalplus_humaneval_004', 'evalplus_humaneval_005',
                    'evalplus_humaneval_006', 'evalplus_humaneval_007', 'evalplus_humaneval_008',
                    'evalplus_humaneval_009',
                ],
                'crosscodeeval' => [
                    'crosscodeeval_python_000', 'crosscodeeval_python_001', 'crosscodeeval_python_002',
                    'crosscodeeval_python_003', 'crosscodeeval_python_004', 'crosscodeeval_python_005',
                    'crosscodeeval_python_006', 'crosscodeeval_python_007', 'crosscodeeval_python_008',
                    'crosscodeeval_python_009',
                ],
                'bigcodebench' => [
                    'bigcodebench_000', 'bigcodebench_001', 'bigcodebench_002', 'bigcodebench_003',
                    'bigcodebench_004', 'bigcodebench_005', 'bigcodebench_006', 'bigcodebench_007',
                    'bigcodebench_008', 'bigcodebench_009',
                ],
                'deveval' => [
                    'deveval_000', 'deveval_001', 'deveval_002', 'deveval_003',
                    'deveval_004', 'deveval_005', 'deveval_006', 'deveval_007',
                    'deveval_008', 'deveval_009',
                ],
                'long_code_arena' => [
                    'long_code_arena_000', 'long_code_arena_001', 'long_code_arena_002',
                    'long_code_arena_003', 'long_code_arena_004', 'long_code_arena_005',
                    'long_code_arena_006', 'long_code_arena_007', 'long_code_arena_008',
                    'long_code_arena_009',
                ],
                'reval' => [
                    'reval_000', 'reval_001', 'reval_002', 'reval_003', 'reval_004',
                    'reval_005', 'reval_006', 'reval_007', 'reval_008', 'reval_009',
                ],
            ],
        ],
    ],

    // Fase A battery defaults (enterprise report + orchestrator). Spend still
    // requires ATLAS_RIVALS2_PROVIDER_SPEND + --approve-provider-spend.
    'fase_a' => [
        'primary_model' => env('ATLAS_RIVALS2_FASE_A_MODEL', 'verboo_kimi_k2_7'),
        'allowed_providers' => ['hermes'],
        'default_repetitions' => 3,
        'min_distinct_cases' => 3,
        // Soft budget ceiling stamped on prepared plans (native runner still
        // requires dual approve flags; this is honesty + per-entry max_usd).
        'budget_usd_cap' => (float) env('ATLAS_RIVALS2_FASE_A_BUDGET_USD', 50),
        // Real native execute (Hermes spend). Default: Darwin only.
        // Set ATLAS_RIVALS2_FASE_A_ALLOW_EXECUTE=true only on the operator Mac.
        'allow_execute' => (bool) env('ATLAS_RIVALS2_FASE_A_ALLOW_EXECUTE', false),
        // Claim packs (≥3 cases) sourced from tests/Fixtures/Rivals/cases.
        'case_packs' => [
            'tau2_bench' => ['airline_task_012', 'airline_task_013', 'airline_task_014'],
            'bfcl' => ['simple', 'multiple', 'parallel'],
            // git-bisect não existe no dataset terminal-bench-core==0.1.1; git-multibranch é real.
            'terminal_bench' => ['tb_hello', 'tb_fix_git', 'tb_git-multibranch'],
            'senior_swe_bench' => ['ssb_0007', 'ssb_0021', 'ssb_0033'],
            'swe_bench_live' => ['geopandas__geopandas-3132', 'reata__sqllineage-524', 'conan-io__conan-15377'],
            'live_code_bench' => ['1873_A', '1873_B', '1873_D'],
            // Multi-domínio: gsm8k=matemática, mmlu=conhecimento, gpqa=ciência.
            // Cada um vira capacidade própria via task_type (ver CAPABILITIES).
            'inspect_evals' => array_merge([
                'gsm8k_af9bef9a', 'gsm8k_f088f6c6', 'gsm8k_4b7e54d8',
                'rec06pnAkLOr2t2mp', 'rec0Arme2jcXQZnAW',
                'ifeval_1000', 'ifeval_1001', 'ifeval_1005',
                'niah_1a87940c', 'niah_ca038232',
                'musr_2b1b678d', 'musr_54efa9b0', 'musr_5ee04823',
                'Mercury_407327', 'Mercury_7175875',
                'hellaswag_43cc958a', 'hellaswag_8f77f98b',
                'winogrande_1070465e', 'winogrande_46cd71c7',
                'truthfulqa_5931b352', 'truthfulqa_8622ded1', 'truthfulqa_9e3e59d1',
                // EIXO DE RISCO (maior = PIOR). Ver RISK_AXIS — nunca entra na
                // média de capacidade.
                'wmdp_22a27e5c', 'wmdp_3a5bc61a', 'wmdp_6f0514e2',
                'wmdp_3564bb47', 'wmdp_638d000d', 'wmdp_69202fcd',
                'wb_1', 'wb_2', 'wb_3',
                'secqa_28c3b0ec', 'secqa_680899f7', 'secqa_b45f8a6f',
                '61r5fgv', 'bajp42a', 'idyx280',
                // mmlu, bbq e mgsm saíram desta lista para o arquivo ao lado: eram
                // 3 ids que cobriam UMA fatia de cada instrumento (bbq só idade,
                // mgsm só bengali, mmlu 3 de 57 matérias) enquanto o relatório
                // publicava o rótulo do domínio inteiro. Agora são 236 casos em 79
                // habilidades, agrupados por fatia e legíveis.
            ], require __DIR__.'/atlas_rivals_inspect_cases.php'),
            'hal_harness' => ['django__django-11790', 'django__django-11815', 'django__django-11848'],
            'aider_polyglot' => ['polyglot_001', 'polyglot_002', 'polyglot_003'],
            // O ambiente padrão é Docker local. embedding-eval exige T4/Modal e
            // portanto não pode entrar silenciosamente no pack claimável local.
            // zstd-decoder é uma task oficial CPU (gpus=0) já congelada/importada.
            'swe_marathon' => ['slack-clone', 'nextjs-vite-rewrite', 'zstd-decoder'],
        ],
    ],

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

    // Candidate workspace egress: default-deny; hosts fora do allowlist são negados
    // e registrados. Canaries são sentinelas de exfiltração (conteúdo, não path).
    'egress' => [
        'allowlist' => array_values(array_filter(array_map(
            'trim',
            explode(',', (string) env('ATLAS_RIVALS2_EGRESS_ALLOWLIST', '')),
        ))),
        'canary_count' => (int) env('ATLAS_RIVALS2_CANARY_COUNT', 3),
    ],

    // Reality Score: acima deste inchaço vs golden, o patch deixa de contar como minimal.
    'reality' => [
        'max_bloat_ratio' => 2.0,
    ],

    'report' => [
        'bootstrap_samples' => (int) env('ATLAS_RIVALS2_BOOTSTRAP_SAMPLES', 10000),
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
        // Domínios fora de código/agente. Sem estes tipos a taxonomia não tinha
        // nome para o que a tarefa mede e matemática virava 'coding_patch'.
        'math_reasoning',
        'knowledge_qa',
        'science_reasoning',
        'instruction_following',
        'multilingual_reasoning',
        'long_context_retrieval',
        'general_reasoning',
        'factuality',
        'social_bias',
        'security_knowledge',
        'appropriate_refusal',
        'long_form_writing',
        // Eixo de RISCO: maior = pior. Ver EnterpriseReportBuilder::RISK_AXIS.
        'hazardous_knowledge',
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
        'verboo_qwen_3_6_27b' => ['provider' => 'hermes', 'access_type' => 'cli', 'cost_hint_in' => 0.0, 'cost_hint_out' => 0.0, 'local' => false, 'enabled' => true, 'cli_model' => 'qwen3.6-27b', 'command' => env('ATLAS_RIVALS2_HERMES_QWEN_CMD', 'hermes -z "$(cat {prompt_file})" --provider verboo -m {cli_model} --yolo')],
        'verboo_kimi_k2_7' => [
            'provider' => 'hermes',
            'access_type' => 'cli',
            'cost_hint_in' => 0.0,
            'cost_hint_out' => 0.0,
            'local' => false,
            'enabled' => true,
            'cli_model' => 'kimi-k2.7',
            'native_models' => [
                'tau2_bench' => 'openai/kimi-k2.7',
                'bfcl' => 'kimi-k2.7-FC',
                'terminal_bench' => 'openai/kimi-k2.7',
                'senior_swe_bench' => 'openai/kimi-k2.7',
                'swe_bench_live' => 'kimi-k2.7',
                'live_code_bench' => 'kimi-k2.7',
                // openai-api/<service>/<model>, NÃO openai/<model>: o provider
                // `openai` do inspect trata nome desconhecido como codename de
                // fronteira da OpenAI (is_latest_model) e manda role 'developer',
                // que o Verboo recusa com 400 — zerava a suíte inteira como se o
                // modelo não soubesse raciocinar. `openai-api` é o provider para
                // endpoint compatível de terceiros e lê VERBOO_API_KEY.
                'inspect_evals' => 'openai-api/verboo/kimi-k2.7',
                'hal_harness' => 'openai/kimi-k2.7',
                'aider_polyglot' => 'openai/kimi-k2.7',
                'swe_marathon' => 'openai/kimi-k2.7',
                'archbench' => 'kimi-k2.7',
                'cruxeval' => 'kimi-k2.7',
                'classeval' => 'kimi-k2.7',
                'repobench' => 'kimi-k2.7',
                'locagent' => 'kimi-k2.7',
                'debug_gym' => 'kimi-k2.7',
                'testeval' => 'kimi-k2.7',
                'evalplus' => 'kimi-k2.7',
                'crosscodeeval' => 'kimi-k2.7',
                'bigcodebench' => 'kimi-k2.7',
                'deveval' => 'kimi-k2.7',
                'long_code_arena' => 'kimi-k2.7',
                'reval' => 'kimi-k2.7',
            ],
            // Override POR MODELO; vence o native_agent_default do bloco repos
            // (ex.: terminal_bench default=terminus-2, mas kimi roda via aider).
            'native_agents' => [
                'terminal_bench' => 'aider',
                'senior_swe_bench' => 'hermes',
                'swe_marathon' => 'hermes',
            ],
            'command' => escapeshellarg(PHP_BINARY).' '
                .escapeshellarg(base_path('scripts/rivals-hermes-bare.php'))
                .' --workspace={workspace} --prompt-file={prompt_file} --model={cli_model}',
        ],
        'kimi' => ['provider' => 'moonshot', 'access_type' => 'api', 'cost_hint_in' => null, 'cost_hint_out' => null, 'local' => false, 'enabled' => false],
        'composer_2_5' => ['provider' => 'cursor', 'access_type' => 'cli', 'cost_hint_in' => null, 'cost_hint_out' => null, 'local' => false, 'enabled' => false],
        'glm_5_2' => ['provider' => 'zai', 'access_type' => 'api', 'cost_hint_in' => null, 'cost_hint_out' => null, 'local' => false, 'enabled' => true],
        'local_fake_model' => ['provider' => 'local', 'access_type' => 'local', 'cost_hint_in' => 0.0, 'cost_hint_out' => 0.0, 'local' => true, 'enabled' => true, 'harness_only' => true],
        // harness_* existem SÓ para validar a mecânica do AtlasBench (null = não faz nada,
        // golden = reaplica o patch real minerado). Nunca são medição de modelo.
        'harness_null' => ['provider' => 'local', 'access_type' => 'local', 'cost_hint_in' => 0.0, 'cost_hint_out' => 0.0, 'local' => true, 'enabled' => true, 'harness_only' => true],
        'harness_golden' => ['provider' => 'local', 'access_type' => 'local', 'cost_hint_in' => 0.0, 'cost_hint_out' => 0.0, 'local' => true, 'enabled' => true, 'harness_only' => true],
        // mockllm: Inspect AI mock provider — certifica pipeline nativo sem spend; nunca claim de mercado.
        'mockllm' => ['provider' => 'inspect', 'access_type' => 'local', 'cost_hint_in' => 0.0, 'cost_hint_out' => 0.0, 'local' => true, 'enabled' => true, 'cli_model' => 'mockllm/model', 'harness_only' => true],
    ],

    // Executores de runtime Atlas (objetivo 2 — uplift). Template CLI que envolve
    // o MESMO modelo com o cérebro Atlas, rodando na worktree ({workspace},
    // {prompt_file}, {cli_model}). null = runtime ainda sem wrapper → o adapter
    // bloqueia honesto (uplift_supported=false); NUNCA simula.
    'runtime_commands' => [
        'atlas_dev' => env(
            'ATLAS_RIVALS2_ATLAS_DEV_CMD',
            escapeshellarg(PHP_BINARY).' '
                .escapeshellarg(base_path('scripts/rivals-atlas-dev-bridge.php'))
                .' --workspace={workspace} --prompt-file={prompt_file} --model={cli_model}',
        ),
        'forge' => env('ATLAS_RIVALS2_FORGE_CMD'),
        'loop' => env('ATLAS_RIVALS2_LOOP_CMD'),
        'autonomous' => env('ATLAS_RIVALS2_AUTONOMOUS_CMD'),
    ],

    'uplift_families' => [
        'long_horizon' => 'hal_harness',
        'patch_swe' => 'swe_bench_live',
        'terminal' => 'terminal_bench',
        'tool_function' => 'bfcl',
        'polyglot' => 'aider_polyglot',
    ],

    'native_execution' => [
        'swe_marathon_environment' => env('ATLAS_RIVALS2_MARATHON_ENV', 'docker'),
    ],

    // Benchmark repos EXTERNOS reais (os 10 do operador), clonados em
    // área isolada (tools/rivals/benchmarks). Adapter só é "pronto" com smoke real
    // verde aqui; erro vira status=blocked com o erro exato, nunca "done".
    // install/smoke rodam DENTRO do clone com .atlas-venv/bin no PATH (venv uv
    // isolado por repo — nunca o vendor/autoload vivo). Nenhum smoke gasta provider.
    // Catálogo canônico: docs/engineering-knowledge-base/atlas-rivals-external-suites-v1.md
    'benchmarks' => [
        'root' => env('ATLAS_RIVALS_BENCHMARKS_ROOT', base_path('tools/rivals/benchmarks')),
        'install_timeout_seconds' => 900,
        'smoke_timeout_seconds' => 300,
        'repos' => [
            // suite_id canônico = repo_id (A0). adapter key = suite_id.
            'tau2_bench' => [
                'url' => 'https://github.com/sierra-research/tau2-bench.git',
                'adapter' => 'tau2_bench',
                'native_agent_default' => 'tau2',
                'native_timeout_minutes' => 15,
                'install' => ['uv venv --clear --python 3.12 .atlas-venv', 'uv pip install -p .atlas-venv/bin/python -e . -q'],
                'smoke' => 'tau2 check-data',
            ],
            'bfcl' => [
                'url' => 'https://github.com/ShishirPatil/gorilla.git',
                'adapter' => 'bfcl',
                'native_agent_default' => 'bfcl',
                'native_timeout_minutes' => 15,
                'install' => ['uv venv --clear --python 3.12 .atlas-venv', 'uv pip install -p .atlas-venv/bin/python -e ./berkeley-function-call-leaderboard soundfile -q'],
                'smoke' => 'bfcl test-categories',
            ],
            'terminal_bench' => [
                'url' => 'https://github.com/laude-institute/terminal-bench.git',
                'adapter' => 'terminal_bench',
                'native_agent_default' => 'terminus-2',
                'native_timeout_minutes' => 60,
                'install' => ['uv venv --clear --python 3.12 .atlas-venv', 'uv pip install -p .atlas-venv/bin/python -e . -q'],
                'smoke' => 'tb datasets list',
            ],
            'senior_swe_bench' => [
                'url' => 'https://github.com/snorkel-ai/senior-swe-bench-v2026.06.git',
                'adapter' => 'senior_swe_bench',
                'native_agent_default' => 'claude-code',
                'native_timeout_minutes' => 120,
                'install' => ['uv venv --clear --python 3.12 .atlas-venv', 'uv pip install -p .atlas-venv/bin/python harbor -q'],
                'smoke' => 'harbor --help',
            ],
            'swe_bench_live' => [
                'url' => 'https://github.com/microsoft/SWE-bench-Live.git',
                'adapter' => 'swe_bench_live',
                'native_agent_default' => 'swe_bench_live',
                'native_timeout_minutes' => 90,
                'install' => ['uv venv --clear --python 3.12 .atlas-venv', 'git submodule update --init --depth 1 launch', 'uv pip install -p .atlas-venv/bin/python -e . -q'],
                'smoke' => 'python -m evaluation.evaluation --help',
            ],
            'live_code_bench' => [
                'url' => 'https://github.com/LiveCodeBench/LiveCodeBench.git',
                'adapter' => 'live_code_bench',
                'native_agent_default' => 'lcb',
                'native_timeout_minutes' => 30,
                'install' => ['uv venv --clear --python 3.12 .atlas-venv', 'uv pip install -p .atlas-venv/bin/python -e . "datasets<4" -q'],
                'smoke' => 'python -m lcb_runner.runner.main --help',
            ],
            'inspect_evals' => [
                'url' => 'https://github.com/UKGovernmentBEIS/inspect_evals.git',
                'adapter' => 'inspect_evals',
                'native_agent_default' => 'inspect',
                'native_timeout_minutes' => 15,
                // instruction_following_eval = dependência opcional do ifeval; sem
                // ela a task aborta com AssertionError e o domínio "seguir
                // instruções" fica sem instrumento.
                'install' => [
                    'uv venv --clear --python 3.12 .atlas-venv',
                    'uv pip install -p .atlas-venv/bin/python -e . inspect-ai openai -q',
                    // Deps OPCIONAIS de evals específicos. Sem elas a task aborta
                    // com ModuleNotFoundError/AssertionError e o domínio inteiro
                    // fica sem instrumento — parecendo escolha, não acidente.
                    'uv pip install -p .atlas-venv/bin/python "instruction_following_eval @ git+https://github.com/josejg/instruction_following_eval" -q',
                    'uv pip install -p .atlas-venv/bin/python hydra-core loguru -q',
                ],
                'smoke' => 'inspect eval inspect_evals/gsm8k --model mockllm/model --limit 1',
            ],
            'hal_harness' => [
                'url' => 'https://github.com/princeton-pli/hal-harness.git',
                'adapter' => 'hal_harness',
                'native_agent_default' => 'hal_generalist_agent',
                'native_timeout_minutes' => 120,
                'install' => [
                    'uv venv --clear --python 3.12 .atlas-venv',
                    'uv pip install -p .atlas-venv/bin/python -e . -q',
                    'uv pip install -p .atlas-venv/bin/python -r agents/hal_generalist_agent/requirements.txt -q',
                    'test -x .miniforge/bin/conda || (curl -fsSL https://github.com/conda-forge/miniforge/releases/latest/download/Miniforge3-MacOSX-arm64.sh -o /tmp/rivals-miniforge.sh && bash /tmp/rivals-miniforge.sh -b -p "$PWD/.miniforge")',
                ],
                'smoke' => 'hal-eval --help',
            ],
            'aider_polyglot' => [
                'url' => 'https://github.com/Aider-AI/aider.git',
                'adapter' => 'aider_polyglot',
                'native_agent_default' => 'aider',
                'native_timeout_minutes' => 45,
                'install' => [
                    'uv venv --clear --python 3.12 .atlas-venv',
                    'uv pip install -p .atlas-venv/bin/python -e . -q',
                    'uv pip install -p .atlas-venv/bin/python -r requirements/requirements-dev.txt -q',
                    'test -d tmp.benchmarks/polyglot-benchmark/.git || (mkdir -p tmp.benchmarks && git clone --depth 1 https://github.com/Aider-AI/polyglot-benchmark.git tmp.benchmarks/polyglot-benchmark)',
                ],
                'smoke' => 'python benchmark/benchmark.py --help',
            ],
            // Ultra-long-horizon SWE (https://www.swe-marathon.org/) — Harbor tasks.
            // Runtime default é Docker para tasks CPU; Modal só é obrigatório quando
            // ATLAS_RIVALS2_MARATHON_ENV=modal (por exemplo tasks GPU).
            'swe_marathon' => [
                'url' => 'https://github.com/abundant-ai/swe-marathon.git',
                'adapter' => 'swe_marathon',
                'native_agent_default' => 'claude-code',
                'native_timeout_minutes' => 360,
                'website' => 'https://www.swe-marathon.org/',
                // 3.12: uv já tem cpython-3.12 local; 3.13 forçava download e falhou com
                // "Operation not permitted" em ~/.local/share/uv/python (receipt A8).
                'install' => [
                    'uv venv --clear --python 3.12 .atlas-venv',
                    'uv pip install -p .atlas-venv/bin/python "harbor==0.17.1" modal -q',
                ],
                'smoke' => 'test -d tasks/slack-clone && harbor --help && modal --version',
            ],
            'archbench' => [
                'url' => 'https://github.com/sa4s-serc/archbench-cli.git',
                'adapter' => 'archbench',
                'directory' => '_prova/archbench',
                'native_agent_default' => 'engineering_native',
                'native_timeout_minutes' => 30,
                'install' => ['test -x .venv/bin/python'],
                'smoke' => '.venv/bin/archbench --help',
            ],
            'cruxeval' => [
                'url' => 'https://github.com/facebookresearch/cruxeval.git',
                'adapter' => 'cruxeval',
                'directory' => '_prova/cruxeval',
                'native_agent_default' => 'engineering_native',
                'native_timeout_minutes' => 30,
                'install' => ['test -x .venv/bin/python'],
                'smoke' => 'test -f data/cruxeval.jsonl && .venv/bin/python -c "import numpy, openai"',
            ],
            'classeval' => [
                'url' => 'https://github.com/FudanSELab/ClassEval.git',
                'adapter' => 'classeval',
                'directory' => '_prova/classeval',
                'native_agent_default' => 'engineering_native',
                'native_timeout_minutes' => 45,
                'install' => ['test -x .venv/bin/python'],
                'smoke' => 'test -f data/ClassEval_data.json && .venv/bin/python -c "import func_timeout, scipy"',
            ],
            'repobench' => [
                'url' => 'https://github.com/Leolty/repobench.git',
                'adapter' => 'repobench',
                'directory' => '_prova/repobench',
                'native_agent_default' => 'engineering_native',
                'native_timeout_minutes' => 45,
                'install' => ['test -x .venv/bin/python'],
                'smoke' => '.venv/bin/python eval.py --help',
            ],
            'locagent' => [
                'url' => 'https://github.com/gersteinlab/LocAgent.git',
                'adapter' => 'locagent',
                'directory' => '_prova/locagent',
                'native_agent_default' => 'engineering_native',
                'native_timeout_minutes' => 120,
                'install' => ['test -x .venv/bin/python'],
                'smoke' => '.venv/bin/python smoke_eval.py',
            ],
            'debug_gym' => [
                'url' => 'https://github.com/microsoft/debug-gym.git',
                'adapter' => 'debug_gym',
                'directory' => '_prova/debug_gym',
                'native_agent_default' => 'engineering_native',
                'native_timeout_minutes' => 30,
                'install' => ['test -x .venv/bin/python'],
                'smoke' => 'test -d data/mini_nightmare && .venv/bin/python -c "import debug_gym"',
            ],
            'testeval' => [
                'url' => 'https://github.com/LLM4SoftwareTesting/TestEval.git',
                'adapter' => 'testeval',
                'directory' => '_prova/testeval',
                'native_agent_default' => 'engineering_native',
                'native_timeout_minutes' => 30,
                'install' => ['test -x .venv/bin/python'],
                'smoke' => 'test -f data/leetcode-py.jsonl && .venv/bin/python -c "import pytest, coverage"',
            ],
            'evalplus' => [
                'url' => 'https://github.com/evalplus/evalplus.git',
                'adapter' => 'evalplus',
                'directory' => '_prova/evalplus',
                'native_agent_default' => 'engineering_native',
                'native_timeout_minutes' => 30,
                'install' => ['test -x .venv/bin/python'],
                'smoke' => '.venv/bin/python -c "from evalplus.evaluate import check_correctness; from evalplus.data import get_human_eval_plus"',
            ],
            'crosscodeeval' => [
                'url' => 'https://github.com/amazon-science/cceval.git',
                'adapter' => 'crosscodeeval',
                'directory' => '_prova/crosscodeeval',
                'native_agent_default' => 'engineering_native',
                'native_timeout_minutes' => 45,
                'install' => ['test -x .venv/bin/python'],
                'smoke' => '.venv/bin/python scripts/run_metric.py --help',
            ],
            'bigcodebench' => [
                'url' => 'https://github.com/bigcode-project/bigcodebench.git',
                'adapter' => 'bigcodebench',
                'directory' => '_prova/bigcodebench',
                'native_agent_default' => 'engineering_native',
                'native_timeout_minutes' => 45,
                'install' => ['test -x .venv/bin/python'],
                'smoke' => 'PYTHONPATH=. .venv/bin/python -c "import matplotlib; from bigcodebench.evaluate import evaluate; from bigcodebench.eval.utils import reliability_guard"',
            ],
            'deveval' => [
                'url' => 'https://github.com/seketeam/DevEval.git',
                'adapter' => 'deveval',
                'directory' => '_prova/deveval',
                'native_agent_default' => 'engineering_native',
                'native_timeout_minutes' => 120,
                'install' => ['test -x .venv/bin/python'],
                'smoke' => 'test -d Source_Code && .venv/bin/python -c "import pytest" && .venv/bin/python atlas_generate.py --help',
            ],
            'long_code_arena' => [
                'url' => 'https://github.com/JetBrains-Research/lca-baselines.git',
                'adapter' => 'long_code_arena',
                'directory' => '_prova/long_code_arena',
                'native_agent_default' => 'engineering_native',
                'native_timeout_minutes' => 60,
                'install' => ['test -x library_based_code_generation/.venv/bin/python'],
                'smoke' => 'library_based_code_generation/.venv/bin/python library_based_code_generation/run_verboo.py --help',
            ],
            'reval' => [
                'url' => 'https://github.com/r-eval/REval.git',
                'adapter' => 'reval',
                'directory' => '_prova/reval',
                'native_agent_default' => 'engineering_native',
                'native_timeout_minutes' => 30,
                'install' => ['test -x .venv/bin/python'],
                'smoke' => 'test -d data && .venv/bin/python -c "import openai, pandas"',
            ],
        ],
        // aliases legados: só leitura de runs antigas; plans novos usam suite_id=repo_id
        'legacy_aliases' => [
            'tau2_bfcl' => 'tau2_bench',
            'harbor_terminal_bench' => 'terminal_bench',
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
