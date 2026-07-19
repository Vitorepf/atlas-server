<?php

return [
    'schema_prefix' => 'atlas.arena',

    // Perfil = 8 suítes de ENGENHARIA. inspect_evals (Q&A de conhecimento) e
    // tau2_bench (diálogo/atendimento) foram removidos: o Atlas é executor de
    // engenharia e não tem runtime de resposta pura — o "com Atlas" lá só podia
    // ser o modelo cru disfarçado (fraude que o RuntimeProofAttacher recusa) ou
    // o cérebro de código forçado numa Q&A (erro de categoria → número que
    // mente). Medir o Atlas onde ele NÃO opera é ruído, não capacidade. Os
    // adapters/registry seguem em config/atlas_rivals.php p/ reativar se um dia
    // existir um runtime de resposta governado. Ver docs/rivals-warroom.md §7.
    'suites' => [
        'terminal_bench',
        'bfcl',
        'senior_swe_bench',
        'swe_bench_live',
        'live_code_bench',
        'hal_harness',
        'aider_polyglot',
        'swe_marathon',
    ],

    // Public, versioned composite weights. Must sum to 1.0. Renormalizado para
    // 8 suítes ao remover inspect_evals(0.10)+tau2_bench(0.08); ordem preservada
    // (terminal maior; bfcl/lcb os menores).
    'weights' => [
        'terminal_bench' => 0.18,
        'bfcl' => 0.11,
        'senior_swe_bench' => 0.12,
        'swe_bench_live' => 0.12,
        'live_code_bench' => 0.11,
        'hal_harness' => 0.12,
        'aider_polyglot' => 0.12,
        'swe_marathon' => 0.12,
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
    // ≥3 = mínimo do claim gate (repetitions_below_min); 1 media com claim
    // eternamente bloqueado.
    'worker_repetitions' => (int) env('ATLAS_ARENA_WORKER_REPETITIONS', 3),

    'capability_map' => [
        'terminal_bench' => [
            ['capability' => 'terminal_operation', 'weight' => 0.70],
            ['capability' => 'code_editing', 'weight' => 0.30],
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
        'tool_use' => 'Uso de ferramentas',
        'long_horizon' => 'Trabalho de longo prazo',
    ],
];
