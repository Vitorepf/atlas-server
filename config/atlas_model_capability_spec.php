<?php

declare(strict_types=1);

/*
 * ELEV-29s — Spec de capacidades de modelo por função (Atlas ACOS Max, LOTE 3).
 *
 * Neutralidade executável: cada função de modelo referenciada por slices MAXA/MAXB/RAGX
 * declara o CONTRATO por capacidade (não por nome do modelo). O modelo é substituível
 * sem reescrever aceites, desde que satisfaça a spec.
 *
 * Cada função nomeia apenas campos MÍNIMOS obrigatórios (spec frouxa=insegura;
 * spec rígida=trava evolução). MAXA-03 (`embedding_model` por vetor) já garante a
 * troca detectável, então a spec aqui só governa o contrato de capacidade.
 */

return [
    'schema_version' => 'atlas.model_capability_spec.v1',

    'functions' => [
        'dense_embed' => [
            'purpose' => 'Vector embedding for memory recall + AURG semantic paths.',
            'min_ctx_tokens' => 256,
            'dim' => [384, 768, 1024],
            'pooling' => ['mean', 'cls'],
            'multilingual_pt' => true,
            'deterministic' => true,
            'license_allowed' => ['apache-2.0', 'mit', 'bsd-3-clause', 'openrail-m'],
            'consumed_by_slices' => ['MAXA-02', 'MAXA-03', 'MAXA-07', 'MAXA-10', 'MAXB-01'],
        ],

        'late_chunk' => [
            'purpose' => 'Token-level embeddings exposed for late-chunking retrievers.',
            'min_ctx_tokens' => 512,
            'token_embeddings_exposed' => true,
            'dim' => [384, 768, 1024],
            'pooling' => ['none'],
            'multilingual_pt' => true,
            'deterministic' => true,
            'license_allowed' => ['apache-2.0', 'mit', 'bsd-3-clause', 'openrail-m'],
            'consumed_by_slices' => ['MAXA-05'],
        ],

        'rerank' => [
            'purpose' => 'Cross-encoder pair scoring for top-K rerank.',
            'min_ctx_tokens' => 512,
            'pair_scoring' => true,
            'latency_per_pair_ms_p95' => 25,
            'output_range' => [0.0, 1.0],
            'deterministic' => true,
            'license_allowed' => ['apache-2.0', 'mit', 'bsd-3-clause', 'openrail-m'],
            'consumed_by_slices' => ['MAXB-04'],
        ],

        'sparse' => [
            'purpose' => 'Sparse lexical term weighting (SPLADE-family) for hybrid recall.',
            'min_ctx_tokens' => 256,
            'term_weights_exposed' => true,
            'output_range' => [0.0, INF],
            'deterministic' => true,
            'license_allowed' => ['apache-2.0', 'mit', 'bsd-3-clause', 'openrail-m'],
            'consumed_by_slices' => ['RAGX-05'],
        ],
    ],
];
