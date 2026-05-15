<?php

declare(strict_types=1);

/**
 * Atlas Code · Ed25519 signing configuration.
 *
 * Provides the keypair used to sign human decision receipts. The keypair is
 * a 128-byte sodium "sign" keypair (libsodium `sodium_crypto_sign_keypair`),
 * base64-encoded. When absent, the signer reports `signing_status:
 * 'unavailable'` honestly — Atlas never silently signs with a synthetic
 * keypair.
 *
 * Generate a keypair locally:
 *   php -r "echo base64_encode(sodium_crypto_sign_keypair()).PHP_EOL;"
 *
 * Then put the result in ATLAS_DECISION_SIGNING_KEYPAIR_BASE64 (env).
 */

return [
    'keypair_base64' => env('ATLAS_DECISION_SIGNING_KEYPAIR_BASE64'),
    'signer_id' => env('ATLAS_DECISION_SIGNER_ID', 'atlas-code-operator'),
    'audit_log_path' => env('ATLAS_DECISION_AUDIT_LOG_PATH', storage_path('app/atlas-code/decision-receipts.jsonl')),
];
