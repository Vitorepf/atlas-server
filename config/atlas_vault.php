<?php

/*
 * Configuração da Atlas Truth Cartography.
 *
 * Leitura apenas — a cartografia jamais escreve nesses paths.
 */

return [
    'repo_docs_path' => env(
        'ATLAS_VAULT_REPO_DOCS_PATH',
        base_path('docs/engineering-knowledge-base')
    ),

    'obsidian_vault_path' => env(
        'ATLAS_VAULT_OBSIDIAN_PATH',
        '/Users/vitorepf/Library/Mobile Documents/iCloud~md~obsidian/Documents/AtlasVault'
    ),

    'cache_seconds' => (int) env('ATLAS_VAULT_CACHE_SECONDS', 2),

    'recent_changes_limit' => (int) env('ATLAS_VAULT_RECENT_LIMIT', 8),
];
