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

    // INSTRUMENTO DESCALIBRADO (denylist auditável). Run cujo braço mediu um
    // defeito PROVADO do harness de medição — nunca capacidade — é invalidado
    // POR INTEIRO e SIMETRICAMENTE (vitórias saem junto com derrotas, o oposto
    // de sobrevivência). Entrada exige: run_id + braço + razão com prova
    // (commit do conserto + warroom). As unidades viram contador próprio
    // (instrument_defect), visível no payload, FORA do guarda de seleção —
    // o guarda vigia descarte seletivo de falha; isto é invalidação total.
    // Caso bfcl 21/07 (prova: correlação 10/10 ponto-no-nome × falha; resposta
    // semanticamente perfeita; checker 'wrong_func_name'; pós-fix 30/30):
    'instrument_defect_runs' => [
        '20260720_040826_25f0be53' => ['arm' => 'with_atlas', 'reason' => 'bfcl_fc_name_packaging_defect_c58ff1a7b2'],
        '20260720_040826_a90c9a6d' => ['arm' => 'with_atlas', 'reason' => 'bfcl_fc_name_packaging_defect_c58ff1a7b2'],
        '20260720_161203_c507bfe0' => ['arm' => 'with_atlas', 'reason' => 'bfcl_fc_name_packaging_defect_c58ff1a7b2'],
        '20260720_185502_6974f290' => ['arm' => 'with_atlas', 'reason' => 'bfcl_fc_name_packaging_defect_c58ff1a7b2'],
        '20260720_225014_0b52c0d8' => ['arm' => 'with_atlas', 'reason' => 'bfcl_fc_name_packaging_defect_c58ff1a7b2'],
        '20260721_003602_d3edda73' => ['arm' => 'with_atlas', 'reason' => 'bfcl_fc_name_packaging_defect_c58ff1a7b2'],
        '20260721_023733_5feb4cd0' => ['arm' => 'with_atlas', 'reason' => 'bfcl_fc_name_packaging_defect_c58ff1a7b2'],
    ],

    // GUARDA DE SELEÇÃO. Descartar unidade bloqueada no setup é honesto (não é falha
    // de capacidade), mas se quase toda FALHA de um braço for descartada, o que sobra
    // não é amostra — é seleção, e a nota sobe sozinha. Provado em aider_polyglot:
    // braço Atlas com 30 contadas (todas sucesso) e 45 descartadas (todas falhas)
    // produzia "Atlas +0.455". Acima de `low` a nota vira baixa confiança; acima de
    // `unmeasured` ela não é número. Mexer nestes valores afrouxa a honestidade do
    // perfil — o caminho certo é BAIXAR o descarte destravando as unidades.
    'max_exclusion_rate_low' => (float) env('ATLAS_ARENA_MAX_EXCLUSION_LOW', 0.30),
    'max_exclusion_rate_unmeasured' => (float) env('ATLAS_ARENA_MAX_EXCLUSION_UNMEASURED', 0.50),

    // O perfil (ArenaCapabilityProfileService) só mostra uma capacidade quando a
    // suíte tem casos medidos — então mapear uma suíte sem dado é inócuo (não
    // aparece até rodar). Suítes BINÁRIAS (pass@1) usam Wilson; a CONTÍNUA
    // (archbench = rougeL) usa média + IC normal (o store carrega score_sum/sumsq/n,
    // measurement_type='continuous') — nunca a taxa binária de "completou".
    // ── Taxonomia v2 (aprovada pelo operador 20/07, validada por 3 auditores
    // independentes: grounding, taxonomista, anti-Goodhart). Área única por ora;
    // outras áreas (marketing, finanças…) entram como novas áreas, nunca
    // misturadas nesta. Regras pétreas herdadas dos auditores:
    //  • bigcodebench alimenta SÓ function_generation (dupla contagem com
    //    tool_calling distorcia o placar de capacidades);
    //  • live_code_bench entra em function_generation como tier "inédito"
    //    (raciocínio algorítmico separado media a mesma coisa 2×);
    //  • long_code_arena fica GATED até o feed ser consertado (100% inválido
    //    em 20/07) e a métrica ganhar componente de precisão;
    //  • rótulos declaram o TETO do instrumento (aider não mede refactor;
    //    testeval mede cobertura, não QA; archbench mede similaridade a ADR).
    'capability_area' => 'software_engineering',
    'capability_area_label_pt' => 'Engenharia de Software',

    'capability_groups_pt' => [
        'construction' => 'Construção',
        'comprehension' => 'Compreensão',
        'quality' => 'Qualidade & Manutenção',
        'agentic' => 'Agêntico',
    ],

    'capability_group_map' => [
        'function_generation' => 'construction',
        'module_implementation' => 'construction',
        'repo_implementation' => 'construction',
        'context_completion' => 'construction',
        'code_reasoning' => 'comprehension',
        'code_localization' => 'comprehension',
        'long_context_engineering' => 'comprehension',
        'code_editing' => 'quality',
        'debugging' => 'quality',
        'test_generation' => 'quality',
        'architecture_design' => 'quality',
        'tool_use' => 'agentic',
    ],

    // Capacidade gated: aparece na lista com a razão, NUNCA com número — o
    // feed está quebrado/sem precisão e expor valor seria mentir com rótulo.
    'capability_gated' => [
        'long_context_engineering' => 'instrumento em preparação (feed inválido; métrica sem precisão)',
    ],

    'capability_map' => [
        // ── Construção
        'evalplus' => [
            ['capability' => 'function_generation', 'weight' => 1.00],
        ],
        'bigcodebench' => [
            ['capability' => 'function_generation', 'weight' => 1.00],
        ],
        // Tier "problemas inéditos" da geração (anti-contaminação Codeforces).
        'live_code_bench' => [
            ['capability' => 'function_generation', 'weight' => 1.00],
        ],
        'classeval' => [
            ['capability' => 'module_implementation', 'weight' => 1.00],
        ],
        'deveval' => [
            ['capability' => 'repo_implementation', 'weight' => 1.00],
        ],
        'repobench' => [
            ['capability' => 'context_completion', 'weight' => 1.00],
        ],
        'crosscodeeval' => [
            ['capability' => 'context_completion', 'weight' => 1.00],
        ],
        // ── Compreensão
        'cruxeval' => [
            ['capability' => 'code_reasoning', 'weight' => 1.00],
        ],
        'reval' => [
            ['capability' => 'code_reasoning', 'weight' => 1.00],
        ],
        'locagent' => [
            ['capability' => 'code_localization', 'weight' => 1.00],
        ],
        'long_code_arena' => [
            ['capability' => 'long_context_engineering', 'weight' => 1.00],
        ],
        // ── Qualidade & Manutenção
        'aider_polyglot' => [
            ['capability' => 'code_editing', 'weight' => 1.00],
        ],
        'debug_gym' => [
            ['capability' => 'debugging', 'weight' => 1.00],
        ],
        'testeval' => [
            ['capability' => 'test_generation', 'weight' => 1.00],
        ],
        // Contínua (rougeL): média + IC normal; similaridade textual a ADR de
        // referência — nunca vender como "qualidade de design".
        'archbench' => [
            ['capability' => 'architecture_design', 'weight' => 1.00],
        ],
        // ── Agêntico
        'bfcl' => [
            ['capability' => 'tool_use', 'weight' => 1.00],
        ],
    ],

    'capability_labels_pt' => [
        'function_generation' => 'Geração de funções',
        'module_implementation' => 'Classes & módulos',
        'repo_implementation' => 'Implementação em repositório',
        'context_completion' => 'Completar código em contexto',
        'code_reasoning' => 'Raciocínio sobre execução',
        'code_localization' => 'Achar onde mexer',
        'long_context_engineering' => 'Trabalho em contexto longo',
        'code_editing' => 'Edição dirigida de código',
        'debugging' => 'Depuração',
        'test_generation' => 'Geração de testes (cobertura)',
        'architecture_design' => 'Design de arquitetura (similaridade)',
        'tool_use' => 'Chamada de ferramentas',
    ],
];
