<?php

return [
    'schema_prefix' => 'atlas.arena',

    // Perfil = só o que roda NATIVO e CONFIÁVEL no Mac arm64 com braço Atlas real.
    // As suítes de Docker/x86 (terminal_bench, swe_bench_live, senior_swe_bench,
    // swe_marathon, hal_harness) rodam por emulação x86 e CORROMPEM o resultado no
    // arm64 (provado: até o patch gold do SWE-bench falha) — ficam estacionadas até
    // haver x86, fora do perfil, pra nunca mostrar número falso (era o "-10" de
    // Operação de terminal). inspect_evals/tau2_bench são conhecimento/diálogo (não
    // engenharia) e não têm braço Atlas — saem também (eram as capacidades órfãs).
    // Ver docs/rivals-warroom.md, memória swe-bench-arm64-emulacao e benchmarks/.
    'suites' => [
        'bfcl',
        'live_code_bench',
        'aider_polyglot',
    ],

    // Public, versioned composite weights. Must sum to 1.0.
    'weights' => [
        'aider_polyglot' => 0.40,
        'live_code_bench' => 0.35,
        'bfcl' => 0.25,
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

    // Piso de casos (por braço) para uma capacidade valer como MEDIDA no app.
    // Abaixo disto o perfil marca confidence='low' e o app mostra "baixa
    // confiança" em vez de cravar o número — lei: número não confiável = não
    // medido, nunca falso. A cada braço acompanha seu N e o IC 95% (Wilson), que
    // é a verdade contínua; este piso só separa "medido" de "poucos casos".
    'min_cases_for_confidence' => (int) env('ATLAS_ARENA_MIN_CASES_CONFIDENCE', 10),

    // O perfil (ArenaCapabilityProfileService) só mostra uma capacidade quando a
    // suíte tem casos medidos — então mapear uma suíte sem dado é inócuo (não
    // aparece até rodar). Suítes BINÁRIAS (pass@1) usam Wilson; a CONTÍNUA
    // (archbench = rougeL) usa média + IC normal (o store carrega score_sum/sumsq/n,
    // measurement_type='continuous') — nunca a taxa binária de "completou".
    'capability_map' => [
        // Integradas (2 braços provados, dado limpo).
        'bfcl' => [
            ['capability' => 'tool_use', 'weight' => 1.00],
        ],
        'live_code_bench' => [
            ['capability' => 'code_editing', 'weight' => 0.50],
            ['capability' => 'reasoning', 'weight' => 0.50],
        ],
        'aider_polyglot' => [
            ['capability' => 'code_editing', 'weight' => 1.00],
        ],
        // Nativas de engenharia (braço com-Atlas = atlas:cli:dev governado, prova
        // v2 verificada em archbench/cruxeval). Métrica binária pass@1. Aparecem
        // no app conforme drenam (cruxeval já tem dado; as outras enchem por rodada).
        'cruxeval' => [
            ['capability' => 'code_reasoning', 'weight' => 1.00],
        ],
        'evalplus' => [
            ['capability' => 'code_generation', 'weight' => 1.00],
        ],
        'bigcodebench' => [
            ['capability' => 'code_generation', 'weight' => 1.00],
        ],
        'classeval' => [
            ['capability' => 'code_generation', 'weight' => 1.00],
        ],
        'deveval' => [
            ['capability' => 'code_generation', 'weight' => 1.00],
        ],
        'debug_gym' => [
            ['capability' => 'debugging', 'weight' => 1.00],
        ],
        // Contínua (rougeL): tratada como média + IC normal no perfil.
        'archbench' => [
            ['capability' => 'architecture_design', 'weight' => 1.00],
        ],
    ],

    'capability_labels_pt' => [
        'code_editing' => 'Edição de código',
        'reasoning' => 'Raciocínio',
        'tool_use' => 'Uso de ferramentas',
        'code_reasoning' => 'Raciocínio sobre código',
        'code_generation' => 'Geração de código',
        'debugging' => 'Depuração',
        'architecture_design' => 'Arquitetura & design',
    ],
];
