<?php

declare(strict_types=1);

/*
 * ELEV-27 — Orçamento CONJUNTO de recursos da máquina (Atlas ACOS Max, LOTE 3).
 *
 * O Atlas roda LOCAL num MBP de 48GiB. Caps por componente existem (ASI-03,
 * ASI-16, ASI-18) — o orçamento CONJUNTO garante que a soma dos residentes
 * + o motor externo (Claude/Codex) cabem sem OOM.
 *
 * ram_cap_mb / disk_cap_mb são caps declarados; o serviço lê REAL (RSS/du) e
 * compara — cap de papel nunca certifica. `probe_hint` documenta como o WDG
 * mede o real (`ps rss`/`du`/`pg`); é advisory (o serviço aceita `probe`
 * customizado nos testes).
 *
 * `host_ram_gib` = memória física declarada da máquina alvo.
 * `engine_floor_gib` = piso livre exigido para o motor externo (Claude/Codex)
 * — a soma dos componentes + engine_floor precisa ficar SOB host_ram_gib.
 */

return [
    'schema_version' => 'atlas.resource_budget.v1',
    'host_ram_gib' => 48,
    'engine_floor_gib' => 12,

    'components' => [
        [
            'name' => 'postgres_16',
            'purpose' => 'canonical memory store (ASI-03 tuned).',
            'ram_cap_mb' => 8192,
            'disk_cap_mb' => 32768,
            'cpu_share' => 'shared',
            'probe_hint' => 'pg_stat_database + ps rss on `postgres` process group',
        ],
        [
            'name' => 'semantic_rag_daemon',
            'purpose' => 'resident Python embedding daemon (MAXA-01).',
            'ram_cap_mb' => 3072,
            'disk_cap_mb' => 4096,
            'cpu_share' => 'shared',
            'probe_hint' => 'ps rss on socket-owning python process',
        ],
        [
            'name' => 'pgvector_hnsw',
            'purpose' => 'HNSW indexes on 4 vector tables (MAXA-07).',
            'ram_cap_mb' => 512,
            'disk_cap_mb' => 4096,
            'cpu_share' => 'shared',
            'probe_hint' => 'pg_relation_size on hnsw indexes',
        ],
        [
            'name' => 'mcp_atlas_open_brain',
            'purpose' => 'resident MCP server for external AIs.',
            'ram_cap_mb' => 512,
            'disk_cap_mb' => 512,
            'cpu_share' => 'shared',
            'probe_hint' => 'ps rss on `atlas open-brain mcp` process',
        ],
        [
            'name' => 'aobg_hooks',
            'purpose' => 'PreToolUse/PostToolUse/UserPromptSubmit hook family.',
            'ram_cap_mb' => 256,
            'disk_cap_mb' => 128,
            'cpu_share' => 'shared',
            'probe_hint' => 'ps rss on hook shell/php children',
        ],
        [
            'name' => 'laravel_workers',
            'purpose' => '10-15 queue/native workers for autonomos musculo.',
            'ram_cap_mb' => 3072,
            'disk_cap_mb' => 256,
            'cpu_share' => 'shared',
            'probe_hint' => 'ps rss on `php artisan queue:work` process group',
        ],
    ],
];
