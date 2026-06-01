<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Services\Ai\Aaeos\Generated\AtlasCapabilityOwnershipMapService;
use Illuminate\Console\Command;
use Throwable;

/**
 * Atlas AI Architecture Audit Capability Ownership Map decider CLI.
 *
 *   php artisan atlas:aaeos:capability-ownership-map [--json]
 *
 * Read-only and deterministic. Emits the ownership-table snapshot plus a worked
 * Placement Rule example: the "Tools" capability (executes external commands,
 * touches every surface and domain) is NOT surface-ownable and resolves to the
 * Runtime / Tool Runtime layer, proving the doc's anti-duplication contract.
 *
 * @see docs/engineering-knowledge-base/architecture-audit/capability-ownership-map.md
 */
class AtlasCapabilityOwnershipMapCommand extends Command
{
    protected $signature = 'atlas:aaeos:capability-ownership-map {--json : machine-readable JSON output (default true)}';

    protected $description = 'Atlas architecture-audit capability ownership map with Placement Rule and duplication audit.';

    public function handle(AtlasCapabilityOwnershipMapService $service): int
    {
        try {
            // Safe default worked example: "Tools" is owned by Super Tool Runtime
            // and, because it executes external commands across every surface and
            // domain, the Placement Rule forbids surface ownership.
            $owner = $service->ownerOf('Tools');
            $placement = $service->placeCapability([
                'executes_external_commands' => true,
                'surface_count' => 3,
                'domain_count' => 3,
            ]);
            $audit = $service->auditOwnershipClaim('surface', [
                'executes_external_commands' => true,
                'surface_count' => 3,
                'domain_count' => 3,
            ]);

            $this->line((string) json_encode(
                [
                    'ok' => true,
                    'map' => $service->map(),
                    'owner_example' => $owner,
                    'placement_example' => $placement,
                    'audit_example' => $audit,
                ],
                JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES,
            ));

            return self::SUCCESS;
        } catch (Throwable $e) {
            $this->line((string) json_encode([
                'ok' => false,
                'error' => 'capability_ownership_map_failed',
                'message' => $e->getMessage(),
            ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));

            return self::FAILURE;
        }
    }
}
