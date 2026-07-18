<?php

return [
    'schema_prefix' => 'atlas.arena',

    'suites' => [
        'terminal_bench',
        'inspect_evals',
        'tau2_bench',
        'bfcl',
        'senior_swe_bench',
        'swe_bench_live',
        'live_code_bench',
        'hal_harness',
        'aider_polyglot',
        'swe_marathon',
    ],

    // Public, versioned composite weights. Must sum to 1.0.
    'weights' => [
        'terminal_bench' => 0.15,
        'inspect_evals' => 0.10,
        'tau2_bench' => 0.08,
        'bfcl' => 0.08,
        'senior_swe_bench' => 0.10,
        'swe_bench_live' => 0.10,
        'live_code_bench' => 0.09,
        'hal_harness' => 0.10,
        'aider_polyglot' => 0.10,
        'swe_marathon' => 0.10,
    ],

    'arm_pair_window_minutes' => 120,
    'live_stale_minutes' => 240,

    // Worker de medição (M61/A12): drena a fila via pipeline Rivals real.
    // Ligar = autorizar spend real de provider nas rodadas enfileiradas
    // (o schedule repassa --approve-provider-spend). Default: desligado.
    'worker_enabled' => (bool) env('ATLAS_ARENA_WORKER_ENABLED', false),
    'worker_budget_per_run' => (int) env('ATLAS_ARENA_WORKER_BUDGET', 5),
    // Rodada bounded: suites externas têm centenas de cases; o worker corta
    // em N por rodada (determinístico) — cobertura cresce por rodadas, não
    // por uma maratona de horas por drain.
    'worker_max_cases_per_run' => (int) env('ATLAS_ARENA_WORKER_MAX_CASES', 10),

    'capability_map' => [
        'terminal_bench' => [
            ['capability' => 'terminal_operation', 'weight' => 0.70],
            ['capability' => 'code_editing', 'weight' => 0.30],
        ],
        'inspect_evals' => [
            ['capability' => 'reasoning', 'weight' => 0.45],
            ['capability' => 'context_retrieval', 'weight' => 0.20],
            ['capability' => 'instruction_following', 'weight' => 0.20],
            ['capability' => 'security_knowledge', 'weight' => 0.15],
        ],
        'tau2_bench' => [
            ['capability' => 'tool_use', 'weight' => 0.60],
            ['capability' => 'agentic_dialogue', 'weight' => 0.40],
        ],
        'bfcl' => [
            ['capability' => 'tool_use', 'weight' => 1.00],
        ],
        'senior_swe_bench' => [
            ['capability' => 'code_editing', 'weight' => 0.60],
            ['capability' => 'bug_fixing', 'weight' => 0.40],
        ],
        'swe_bench_live' => [
            ['capability' => 'bug_fixing', 'weight' => 1.00],
        ],
        'live_code_bench' => [
            ['capability' => 'code_editing', 'weight' => 0.50],
            ['capability' => 'reasoning', 'weight' => 0.50],
        ],
        'hal_harness' => [
            ['capability' => 'long_horizon', 'weight' => 1.00],
        ],
        'aider_polyglot' => [
            ['capability' => 'code_editing', 'weight' => 1.00],
        ],
        'swe_marathon' => [
            ['capability' => 'long_horizon', 'weight' => 0.70],
            ['capability' => 'code_editing', 'weight' => 0.30],
        ],
    ],

    'capability_labels_pt' => [
        'terminal_operation' => 'Operação de terminal',
        'code_editing' => 'Edição de código',
        'bug_fixing' => 'Correção de bugs',
        'reasoning' => 'Raciocínio',
        'context_retrieval' => 'Recuperação de contexto',
        'tool_use' => 'Uso de ferramentas',
        'agentic_dialogue' => 'Diálogo agêntico',
        'instruction_following' => 'Seguir instruções',
        'security_knowledge' => 'Segurança defensiva',
        'long_horizon' => 'Trabalho de longo prazo',
    ],
];
