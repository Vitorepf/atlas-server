<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Services\Ai\Aaeos\Generated\AtlasAgenticSoftwareEngineeringAuthorityMapService;
use Illuminate\Console\Command;
use Throwable;

/**
 * Atlas Agentic Software Engineering Authority Map decider CLI.
 *
 *   php artisan atlas:aaeos:agentic-software-engineering-authority-map [--json]
 *
 * Read-only, deterministic. Demonstrates the authority map on a placement that
 * violates a documented gate (an Atlas Code surface doc that claims to be the
 * whole OS and to govern the runtime) so the emitted verdict surfaces the
 * blocked status and the exact gate violation.
 *
 * @see docs/engineering-knowledge-base/atlas-agentic-software-engineering-authority-map.md
 */
class AtlasAgenticSoftwareEngineeringAuthorityMapCommand extends Command
{
    protected $signature = 'atlas:aaeos:agentic-software-engineering-authority-map
        {--json : machine-readable JSON output (default true)}';

    protected $description = 'Atlas agentic engineering · authority map decider (tier order, doc-class taxonomy, placement gates).';

    public function handle(AtlasAgenticSoftwareEngineeringAuthorityMapService $service): int
    {
        try {
            // A placement that breaks the surface-only gate: an Atlas Code doc
            // claiming to be the whole OS and to govern the runtime.
            $placement = $service->evaluatePlacement([
                'doc' => 'atlas-code-long-session-programming-cockpit',
                'claims_whole_os' => true,
                'governs_runtime' => true,
                'has_canonical_parent' => true,
                'active' => true,
            ]);

            // A representative authority face-off: a surface doc can never win
            // over the programming-law contract even though both are real docs.
            $faceoff = $service->compareAuthority(
                'atlas-code-long-session-programming-cockpit',
                'atlas-programming-governance-system',
            );

            $this->line((string) json_encode([
                'ok' => true,
                'area' => AtlasAgenticSoftwareEngineeringAuthorityMapService::AREA_NAME,
                'mother_system' => AtlasAgenticSoftwareEngineeringAuthorityMapService::MOTHER_SYSTEM,
                'placement' => $placement,
                'faceoff' => $faceoff,
                'route_unknown_intent' => $service->routeDecision('something-not-listed'),
            ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));

            return self::SUCCESS;
        } catch (Throwable $e) {
            $this->line((string) json_encode([
                'ok' => false,
                'error' => 'authority_map_failed',
                'message' => $e->getMessage(),
            ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));

            return self::FAILURE;
        }
    }
}
