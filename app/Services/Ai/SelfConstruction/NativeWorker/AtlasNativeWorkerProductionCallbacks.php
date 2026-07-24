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
final class AtlasNativeWorkerProductionCallbacks implements AtlasNativeWorkerRecoverableProductionRuntime
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
                $next = $this->claimEnvelope($clientId);
                if (! in_array((string) ($next['status'] ?? ''), ['leased', 'served'], true)
                    && (string) ($next['event'] ?? '') !== 'leased') {
                    return null;
                }

                return $next;
            },
            'report_callback' => function (array $outcome) use ($clientId): array {
                return $this->report($clientId, $outcome);
            },
            'patch_materializer' => function (array $patchPlan): array {
                return $this->patchMaterializer->materialize($patchPlan);
            },
        ];
    }

    /** @return array<string,mixed>|null */
    public function claim(string $clientId): ?array
    {
        $next = $this->claimEnvelope($clientId);

        return in_array((string) ($next['status'] ?? ''), ['leased', 'served'], true) ? $next : null;
    }

    /** @return array<string,mixed> */
    public function claimEnvelope(string $clientId): array
    {
        return $this->serving->next($clientId, ['runtime_owner' => 'atlas_native']);
    }

    /** @param array<string,mixed> $outcome @return array<string,mixed> */
    public function report(string $clientId, array $outcome): array
    {
        if ($clientId === 'atlas-self-construction-runtime-daemon' && (bool) ($outcome['commit'] ?? false)) {
            return [
                'schema' => AtlasTaskServingService::REPORT_SCHEMA,
                'status' => 'invalid_report',
                'client_id' => $clientId,
                'reason' => 'autonomos_direct_commit_forbidden',
                'lease_closed' => false,
            ];
        }

        return $this->serving->report(
            $clientId,
            (string) ($outcome['task_packet_id'] ?? ''),
            (string) ($outcome['lease_id'] ?? ''),
            $outcome,
        );
    }

    /** @param array<string,mixed> $patchPlan @return array<string,mixed> */
    public function materialize(array $patchPlan): array
    {
        return $this->patchMaterializer->materialize($patchPlan);
    }

    /** @return array<string,mixed>|null */
    public function resume(string $clientId): ?array
    {
        return $this->serving->resume($clientId);
    }

    public function renew(string $clientId, string $taskPacketId, string $leaseId): bool
    {
        return $this->serving->renew($clientId, $taskPacketId, $leaseId);
    }
}
