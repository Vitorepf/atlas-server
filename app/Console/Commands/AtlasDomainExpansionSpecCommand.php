<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Services\Ai\Aaeos\Generated\AtlasDomainExpansionSpecService;
use Illuminate\Console\Command;
use Throwable;

/**
 * Atlas Domain Expansion Spec decider CLI.
 *
 *   php artisan atlas:aaeos:domain-expansion-spec [--json]
 *
 * Read-only and deterministic. Validates a new-domain proposal against the
 * canonical contract: the atlas.domain.v1 schema, the 12-item mandatory
 * checklist, the cross-dept dependency matrix and the sovereignty Security gate.
 * With safe defaults (a bare proposal — nothing filled) it demonstrates the
 * contract: the verdict is "refine" (a domain can never be born above L0 without
 * its full checklist) and every blocking reason is named.
 *
 * @see docs/engineering-knowledge-base/atlas-domain-expansion-spec.md
 */
class AtlasDomainExpansionSpecCommand extends Command
{
    protected $signature = 'atlas:aaeos:domain-expansion-spec {--json : machine-readable JSON output (default true)}';

    protected $description = 'Atlas Domain Expansion Spec: validate a new-domain proposal (schema + 12-item checklist + cross-dept + sovereignty gate).';

    public function handle(AtlasDomainExpansionSpecService $service): int
    {
        try {
            // Safe default proposal: an empty proposal proves nothing, so the
            // verdict must be "refine" and routing must stay disabled (no doc).
            $decision = $service->evaluateProposal([
                'kind' => 'programming',
            ]);

            $this->line((string) json_encode(
                ['ok' => true, 'decision' => $decision],
                JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES,
            ));

            return self::SUCCESS;
        } catch (Throwable $e) {
            $this->line((string) json_encode([
                'ok' => false,
                'error' => 'domain_expansion_spec_failed',
                'message' => $e->getMessage(),
            ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));

            return self::FAILURE;
        }
    }
}
