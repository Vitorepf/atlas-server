<?php

namespace App\Console\Commands;

use App\Services\Ai\Aaeos\Generated\AtlasSelfConstructionOsGapAuditService;
use Illuminate\Console\Command;
use Throwable;

/**
 * Read-only runtime surface for the Atlas Self-Construction OS Gap Audit v1 doc.
 * With no args it renders the audit snapshot: all 15 Section 8 claims forbidden,
 * the frozen Section 7 observed-evidence invariants, and the single next build
 * slice. Proves the audit never auto-releases a forbidden claim.
 *
 * @see docs/engineering-knowledge-base/self-construction/audits/atlas-self-construction-os-gap-audit-v1.md
 */
class AtlasSelfConstructionOsGapAuditCommand extends Command
{
    protected $signature = 'atlas:aaeos:self-construction-os-gap-audit {--json : Print machine-readable JSON}';

    protected $description = 'Render the read-only Self-Construction OS gap audit (15 forbidden claims gated, observed-evidence invariants frozen).';

    public function handle(AtlasSelfConstructionOsGapAuditService $service): int
    {
        try {
            // Safe default: no promotion bundles supplied, so every Section 8
            // claim stays forbidden exactly as the audit states.
            $payload = $service->snapshot();
        } catch (Throwable $e) {
            $payload = [
                'ok' => false,
                'error' => $e->getMessage(),
                'schema_version' => AtlasSelfConstructionOsGapAuditService::SCHEMA_VERSION,
            ];

            if ((bool) $this->option('json')) {
                $this->line(json_encode($payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) ?: '{}');
            } else {
                $this->error($e->getMessage());
            }

            return self::FAILURE;
        }

        if ((bool) $this->option('json')) {
            $this->line(json_encode($payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) ?: '{}');

            return self::SUCCESS;
        }

        $this->components->twoColumnDetail('schema_version', (string) $payload['schema_version']);
        $this->components->twoColumnDetail('audit_window', (string) $payload['audit_window']);
        $this->components->twoColumnDetail('forbidden_claim_count', (string) $payload['forbidden_claim_count']);
        $this->components->twoColumnDetail('released_claim_count', (string) $payload['released_claim_count']);
        $this->components->twoColumnDetail('all_claims_forbidden', $payload['all_claims_forbidden'] ? 'true' : 'false');
        $this->components->twoColumnDetail('next_build_slice', (string) $payload['next_build_slice']);

        return self::SUCCESS;
    }
}
