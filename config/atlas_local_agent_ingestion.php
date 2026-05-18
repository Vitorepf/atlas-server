<?php

/**
 * Atlas Local Agent Memory Ingestion — operator-controlled allowlist.
 *
 * Roots must be declared HERE (or via env) by an operator. The ingestion
 * pipeline refuses to scan anything that is not in `roots`. Every root has
 * a safe alias (used in receipts/evidence) and an absolute path (kept local;
 * never echoed back in receipts).
 *
 * Default = empty list. The pipeline reports "no_roots_configured" and
 * produces a no-op receipt instead of failing silently.
 */
return [
    'roots' => [
        // Example shape (commented out — operator must opt in explicitly):
        // [
        //     'alias' => 'codex_sessions',
        //     'path' => '/Users/example/.codex/sessions',
        //     'enabled' => true,
        // ],
    ],

    /**
     * Default per-file size cap (bytes). Files larger than this are recorded
     * as `skipped` with reason `size_exceeded` and never read past this point.
     */
    'max_file_bytes' => env('ATLAS_LAI_MAX_FILE_BYTES', 1_048_576),

    /**
     * Hard cap on files processed per run. Prevents accidental flood when an
     * operator points at a huge tree. Above this, the run records
     * `discovery_truncated=true` and stops walking.
     */
    'max_files_per_run' => env('ATLAS_LAI_MAX_FILES_PER_RUN', 500),

    /**
     * Filename patterns (case-insensitive substrings) that are NEVER read.
     * Defensive defaults — operator can extend via env override.
     */
    'denylist_patterns' => [
        '.env',
        'id_rsa', 'id_ed25519', 'id_ecdsa',
        '.pem', '.key', '.crt', '.pfx', '.p12',
        '.kdbx',
        'credentials.json',
        'service-account',
        'authorized_keys',
    ],

    /**
     * Filename extension allowlist. Only files matching one of these get
     * scanned. Anything else is recorded as `skipped` with `extension_not_allowed`.
     */
    'allowlist_extensions' => [
        'md', 'markdown', 'txt', 'json', 'jsonl', 'log', 'patch', 'diff',
        'yml', 'yaml', 'toml', 'sh', 'py', 'php', 'ts', 'tsx', 'js', 'jsx',
    ],

    /**
     * If set, dry-run is the default for `php artisan atlas:local-agent:ingest`.
     */
    'dry_run_default' => env('ATLAS_LAI_DRY_RUN_DEFAULT', true),
];
