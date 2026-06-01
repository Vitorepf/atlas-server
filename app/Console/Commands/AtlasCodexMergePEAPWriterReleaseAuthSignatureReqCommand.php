<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Services\Ai\Aaeos\Generated\AtlasCodexMergePEAPWriterReleaseAuthSignatureReqService;
use Illuminate\Console\Command;
use Throwable;

/**
 * Codex Merge Post-Execution Action Persistence Writer Release AUTHORIZATION
 * Signature Request CLI.
 *
 *   php artisan atlas:aaeos:codex-merge-peap-writer-release-auth-signature-req [--json]
 *
 * Read-only, deterministic. Answers "what exactly would a human or external
 * validator need to sign?" by constructing the documented ten-component
 * signable payload and a stable payload hash — and nothing else. It signs
 * nothing, accepts nothing, validates nothing, persists nothing, approves
 * nothing, merges nothing and dispatches nothing — the hard boundary
 * (signature_valid / receipt_signed / receipt_persisted / merge_allowed / ...)
 * stays false, and the payload hash is explicitly NOT a signature.
 *
 * @see docs/engineering-knowledge-base/self-construction/codex-merge-post-execution-action-persistence-writer-release-authorization-signature-request.md
 */
class AtlasCodexMergePEAPWriterReleaseAuthSignatureReqCommand extends Command
{
    protected $signature = 'atlas:aaeos:codex-merge-peap-writer-release-auth-signature-req {--json : machine-readable JSON output (default true)}';

    protected $description = 'Atlas self-construction · writer release authorization signature request — read-only surface that builds the signable payload, accepting/validating/authorizing nothing.';

    public function handle(AtlasCodexMergePEAPWriterReleaseAuthSignatureReqService $service): int
    {
        try {
            // Safe defaults: empty input => documented components default to
            // empty and a stable payload hash is computed. Nothing is signed,
            // accepted, validated, persisted, approved, merged or dispatched.
            $result = $service->evaluate([]);

            $this->line((string) json_encode(
                ['ok' => true, 'result' => $result],
                JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES,
            ));

            // Success means the boundary held (and the hash is deterministic),
            // not that any signature or authorization exists.
            return (($result['boundary_held'] ?? false) === true
                && ($result['payload_hash_deterministic'] ?? false) === true)
                ? self::SUCCESS
                : self::FAILURE;
        } catch (Throwable $e) {
            $this->line((string) json_encode([
                'ok' => false,
                'error' => 'codex_merge_peap_writer_release_auth_signature_req_failed',
                'message' => $e->getMessage(),
            ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));

            return self::FAILURE;
        }
    }
}
