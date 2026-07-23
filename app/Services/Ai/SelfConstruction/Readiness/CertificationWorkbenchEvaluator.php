<?php

declare(strict_types=1);

namespace App\Services\Ai\SelfConstruction\Readiness;

use App\Services\Ai\SelfConstruction\Readiness\Catalog\CertificationWorkbenchEntries;
use InvalidArgumentException;

/**
 * Catalog-driven evaluator for the certification-workbench quartet family
 * (GOD-DEBULK Phase 2, ARCH blueprint SelfConstructionReadiness §2.2).
 *
 * Replaces the per-route buildCertificationWorkbenchQuartet call sites in
 * AtlasSelfConstructionReadinessService (A1-SC-0005): the route data lives
 * once in CertificationWorkbenchEntries and every contract / preflight /
 * implementation_packet stage is projected through this single entrypoint.
 *
 * Fail-closed (A1-SC-0004): an unknown capability key or stage is a typed
 * error, never a silently-ready payload.
 */
final class CertificationWorkbenchEvaluator
{
    /** @var list<string> */
    public const STAGES = ['contract', 'preflight', 'implementation_packet'];

    /** @return array<string, mixed> */
    public static function certify(string $keyPrefix, string $stage): array
    {
        $entry = CertificationWorkbenchEntries::entries()[$keyPrefix] ?? null;
        if ($entry === null) {
            throw new InvalidArgumentException(
                "Unknown certification workbench capability [{$keyPrefix}]; not present in CertificationWorkbenchEntries."
            );
        }
        if (! in_array($stage, self::STAGES, true)) {
            throw new InvalidArgumentException(
                "Unknown certification workbench stage [{$stage}] for capability [{$keyPrefix}]."
            );
        }

        return ReadinessCertificationWorkbenchQuartetBuilder::build(
            $keyPrefix,
            $entry['label'],
            $entry['schema_version'],
            $entry['service_class'],
            $stage,
        );
    }
}
