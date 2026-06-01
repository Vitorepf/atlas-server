<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Services\Ai\Aaeos\Generated\AtlasCanonicalIndexAuthorityMapService;
use Illuminate\Console\Command;
use Throwable;

/**
 * Atlas AI Canonical Authority Map decider CLI.
 *
 *   php artisan atlas:aaeos:canonical-index-authority-map [--json]
 *
 * Read-only, deterministic. Demonstrates the three documented decisions:
 *   - resolve a known subject to its existing authority owner;
 *   - resolve an unknown subject (gap) and prove it is NOT cleared to create a
 *     new authority surface (doc "## Rule");
 *   - reject a claim that Genesis supersedes Autonomous Holding (doc boundary).
 *
 * @see docs/engineering-knowledge-base/canonical-index/authority-map.md
 */
class AtlasCanonicalIndexAuthorityMapCommand extends Command
{
    protected $signature = 'atlas:aaeos:canonical-index-authority-map
        {--json : machine-readable JSON output (default true)}';

    protected $description = 'Atlas canonical-index · authority map decider (subject->owner lookup, no-new-surface rule, non-supersession boundaries).';

    public function handle(AtlasCanonicalIndexAuthorityMapService $service): int
    {
        try {
            $known = $service->resolveSubject('Knowledge governance');
            $gap = $service->resolveSubject('some unmapped subject not in the index');
            $supersession = $service->checkSupersession(
                'atlas-autonomous-company-os-genesis-initiative.md',
                true,
            );

            $this->line((string) json_encode([
                'ok' => true,
                'map' => $service->map(),
                'known_subject' => $known,
                'gap_subject' => $gap,
                'supersession_check' => $supersession,
            ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));

            return self::SUCCESS;
        } catch (Throwable $e) {
            $this->line((string) json_encode([
                'ok' => false,
                'error' => 'canonical_index_authority_map_failed',
                'message' => $e->getMessage(),
            ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));

            return self::FAILURE;
        }
    }
}
