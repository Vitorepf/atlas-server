<?php

declare(strict_types=1);

namespace App\Services\Ai\SelfConstruction\NativeWorker;

use App\Services\Ai\SelfConstruction\AtlasTaskServingService;
use App\Services\Ai\SelfConstruction\NativeImplementation\AtlasSelfConstructionNativePatchMaterializer;

/**
 * Productive Atlas-native callbacks for claim/execute/report.
 *
 * Elite Factory v2 P0-19: production apply mode must not depend on test-only
 * container callbacks. These callables bind to the live Task Serving contract.
 */
final class AtlasNativeWorkerProductionCallbacks
{
    public const SCHEMA_VERSION = 'atlas.native_worker.production_callbacks.v1';

    public function __construct(
        private readonly AtlasTaskServingService $serving,
        private readonly AtlasSelfConstructionNativePatchMaterializer $patchMaterializer,
    ) {}

    /**
     * @return array{
     *   claim_callback: callable(): ?array,
     *   report_callback: callable(array): array,
     *   patch_materializer: callable(array): array
     * }
     */
    public function forClient(string $clientId = 'atlas-native-worker'): array
    {
        return [
            'claim_callback' => function () use ($clientId): ?array {
                $next = $this->serving->next($clientId, ['runtime_owner' => 'atlas_native']);
                if (($next['status'] ?? '') !== 'leased' && ($next['event'] ?? '') !== 'leased') {
                    return null;
                }

                return is_array($next) ? $next : null;
            },
            'report_callback' => function (array $outcome) use ($clientId): array {
                $taskPacketId = (string) ($outcome['task_packet_id'] ?? '');
                $leaseId = (string) ($outcome['lease_id'] ?? '');

                return $this->serving->report($clientId, $taskPacketId, $leaseId, $outcome);
            },
            'patch_materializer' => function (array $patchPlan): array {
                return $this->patchMaterializer->materialize($patchPlan);
            },
        ];
    }
}
