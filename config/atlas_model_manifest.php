<?php

declare(strict_types=1);

/*
 * ELEV-19 — Manifest de integridade de modelos locais (Atlas ACOS Max, LOTE 3).
 *
 * Supply-chain pin do próprio cérebro: cada artefato local usado por slices
 * MAXA/MAXB/RAGX declara {model_id, sha256, dim, pooling, license, source_url,
 * path, function}. O runtime verifica sha256 no boot; mismatch = degrade honesto
 * + alerta WDG, NUNCA carga silenciosa.
 *
 * "sha256_pin" ausente/vazio marca o artefato como `unpinned`: o serviço trata
 * como advisory até o pin ser adicionado (a integridade não é forjada).
 *
 * O manifest é commit normal — o pin é contra mudança SILENCIOSA, não contra
 * atualização legítima. Bump = commit de humano/ADR.
 */

return [
    'schema_version' => 'atlas.model_integrity_manifest.v1',

    'artifacts' => [
        [
            'model_id' => 'sentence-transformers/paraphrase-multilingual-MiniLM-L12-v2',
            'function' => 'dense_embed',
            'dim' => 384,
            'pooling' => 'mean',
            'ctx_tokens' => 128,
            'multilingual_pt' => true,
            'deterministic' => true,
            'license' => 'apache-2.0',
            'source_url' => 'https://huggingface.co/sentence-transformers/paraphrase-multilingual-MiniLM-L12-v2',
            'path' => env('ATLAS_MODEL_MINILM_PATH', ''),
            'sha256_pin' => env('ATLAS_MODEL_MINILM_SHA256', ''),
            'notes' => 'default_semantic_rag_model; pin bump requires human/ADR',
        ],
    ],
];
