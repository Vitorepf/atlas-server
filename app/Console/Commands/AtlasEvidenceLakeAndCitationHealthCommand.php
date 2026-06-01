<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Services\Ai\Aaeos\Generated\AtlasEvidenceLakeAndCitationHealthService;
use Illuminate\Console\Command;
use Throwable;

/**
 * Evidence Lake & Citation Health gate CLI.
 *
 *   php artisan atlas:aaeos:evidence-lake-and-citation-health
 *     [--support=supports|contradicts|mentions|insufficient]
 *     [--source-type=docs|paper|github|news|social|...]
 *     [--authority=primary|lead|secondary|unknown]
 *     [--claim-kind=fact|inference|recommendation]
 *     [--critical] [--time-sensitive]
 *     [--url-invented] [--no-url] [--unstable-canonical]
 *     [--unretrievable] [--snapshot] [--content-changed]
 *     [--claim-stronger] [--stale] [--superseded] [--contradiction]
 *     [--no-raw-evidence]
 *     [--json]
 *
 * Read-only, deterministic. Emits the promote/repair/block citation decision
 * plus the eight-question health audit and a receipt.
 *
 * @see docs/engineering-knowledge-base/research-self-improvement/evidence-lake-and-citation-health.md
 */
class AtlasEvidenceLakeAndCitationHealthCommand extends Command
{
    protected $signature = 'atlas:aaeos:evidence-lake-and-citation-health
        {--support= : verifier support label (supports|contradicts|mentions|insufficient)}
        {--source-type= : raw evidence source_type (docs|paper|github|news|social|dataset|repo|internal)}
        {--authority= : authority_level (primary|lead|secondary|unknown)}
        {--claim-kind= : fact|inference|recommendation}
        {--critical : mark the claim critical}
        {--time-sensitive : claim depends on fresh state}
        {--url-invented : the citation URL was fabricated}
        {--no-url : URL or repo path does not resolve}
        {--unstable-canonical : canonical URL is not stable}
        {--unretrievable : source cannot be retrieved right now}
        {--snapshot : an archive/local snapshot is preserved}
        {--content-changed : source hash changed since retrieval}
        {--claim-stronger : the claim overstates what the source says}
        {--stale : source is stale}
        {--superseded : a newer source supersedes this one}
        {--contradiction : a contradicting source/claim exists}
        {--no-raw-evidence : raw evidence was not preserved (Storage Principle)}
        {--json : machine-readable JSON output (default true)}';

    protected $description = 'Atlas evidence · citation-health gate for one citation (promote|repair|block).';

    public function handle(AtlasEvidenceLakeAndCitationHealthService $service): int
    {
        try {
            $citation = [
                'support_label' => $this->option('support') ?? AtlasEvidenceLakeAndCitationHealthService::SUPPORT_SUPPORTS,
                'source_type' => $this->option('source-type') ?? 'docs',
                'authority_level' => $this->option('authority') ?? 'primary',
                'claim_kind' => $this->option('claim-kind') ?? 'fact',
                'critical' => (bool) $this->option('critical'),
                'time_sensitive' => (bool) $this->option('time-sensitive'),
                'url_invented' => (bool) $this->option('url-invented'),
                'url_exists' => ! (bool) $this->option('no-url'),
                'canonical_url_stable' => ! (bool) $this->option('unstable-canonical'),
                'retrievable' => ! (bool) $this->option('unretrievable'),
                'snapshot_exists' => (bool) $this->option('snapshot'),
                'content_changed' => (bool) $this->option('content-changed'),
                'claim_stronger_than_source' => (bool) $this->option('claim-stronger'),
                'source_stale' => (bool) $this->option('stale'),
                'newer_source_supersedes' => (bool) $this->option('superseded'),
                'contradiction_exists' => (bool) $this->option('contradiction'),
                'raw_evidence_preserved' => ! (bool) $this->option('no-raw-evidence'),
            ];

            $decision = $service->decide($citation);

            $this->line((string) json_encode(
                ['ok' => true, 'decision' => $decision],
                JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES,
            ));

            return self::SUCCESS;
        } catch (Throwable $e) {
            $this->line((string) json_encode([
                'ok' => false,
                'error' => 'citation_health_failed',
                'message' => $e->getMessage(),
            ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));

            return self::FAILURE;
        }
    }
}
