<?php

return [
    // Governed generative ideation (the only LLM path in the sector).
    // NEVER inline on a hot path — runs only via `atlas:venture ideate-generate`.
    // null provider key falls back to the runtime default provider.
    'ideation_provider_key' => env('ATLAS_VENTURE_IDEATION_PROVIDER', null),
    'ideation_model' => env('ATLAS_VENTURE_IDEATION_MODEL', null),
    'ideation_timeout_seconds' => (int) env('ATLAS_VENTURE_IDEATION_TIMEOUT', 180),
    'ideation_max_ideas' => (int) env('ATLAS_VENTURE_IDEATION_MAX_IDEAS', 5),

    // Execution bridge: review gaps -> draft missions (AUTONOMY_SUGGEST).
    'bridge_max_missions_per_review' => (int) env('ATLAS_VENTURE_BRIDGE_MAX_MISSIONS', 3),

    // Strategist qualitative analysis (LLM, grounded fail-closed). Explicit
    // spend: runs only with `strategist-review --analyze` or when the weekly
    // cycle is configured with cycle_analyze=true.
    'analysis_provider_key' => env('ATLAS_VENTURE_ANALYSIS_PROVIDER', null),
    'analysis_model' => env('ATLAS_VENTURE_ANALYSIS_MODEL', null),
    'analysis_timeout_seconds' => (int) env('ATLAS_VENTURE_ANALYSIS_TIMEOUT', 180),

    // Weekly strategist cadence (Monday). Deterministic by default (no
    // provider spend); flip cycle_analyze to include the qualitative opinion.
    'weekly_review_enabled' => (bool) env('ATLAS_VENTURE_WEEKLY_REVIEW_ENABLED', true),
    'cycle_analyze' => (bool) env('ATLAS_VENTURE_CYCLE_ANALYZE', false),
    'cycle_min_interval_days' => (int) env('ATLAS_VENTURE_CYCLE_MIN_INTERVAL_DAYS', 6),

    // Growth trajectory scenarios: annual growth rates used for the
    // deterministic years-to-target projection. No synthetic revenue claims:
    // projection is blocked while no ARR observation exists.
    'trajectory_scenarios' => [
        'conservative' => 0.5,
        'base' => 1.0,
        'aggressive' => 2.0,
    ],
    'trajectory_horizons_years' => [3, 5, 7, 10],

    // Company Success Engine (meta >=70%). Sucesso = receita recorrente
    // sustentada: MRR >= threshold mantido por >= N meses consecutivos.
    // Medido so contra observacoes persistidas (nunca auto-declarado).
    'success_metric_key' => env('ATLAS_VENTURE_SUCCESS_METRIC', 'mrr'),
    'success_mrr_threshold' => (float) env('ATLAS_VENTURE_SUCCESS_MRR_THRESHOLD', 1000.0),
    'success_min_consecutive_months' => (int) env('ATLAS_VENTURE_SUCCESS_MIN_MONTHS', 3),

    // Gate de admissao: default-OFF. Quando ON, promote()/criacao so admite
    // venture sem risco existencial aberto e com marco de sucesso declarado.
    'admission_gate_enabled' => (bool) env('ATLAS_VENTURE_ADMISSION_GATE_ENABLED', false),
];
