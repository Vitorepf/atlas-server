<?php

declare(strict_types=1);

namespace App\Services\Ai\ControlPlane;

/**
 * ASDD S-OPERATOR: operator truth seam — snapshot via shipped control-plane owner.
 * Non-gating for engineering promote (cockpit/read model only).
 */
final class OperatorTruth
{
    public const SCHEMA = 'atlas.operator.truth.v1';

    public function __construct(
        private readonly ?AtlasControlPlaneSnapshotService $snapshots = null,
    ) {}

    /**
     * @return array{schema:string,status:string,scopes:list<string>,eng_gating:bool,snapshot_owner:string}
     */
    public function contract(): array
    {
        return [
            'schema' => self::SCHEMA,
            'status' => 'wired_snapshot',
            'scopes' => ['eng', 'company', 'readiness', 'si', 'aaeos'],
            'eng_gating' => false,
            'snapshot_owner' => AtlasControlPlaneSnapshotService::class,
        ];
    }

    /**
     * @return array<string,mixed>
     */
    public function snapshot(): array
    {
        return $this->snapshots()->snapshot();
    }

    private function snapshots(): AtlasControlPlaneSnapshotService
    {
        return $this->snapshots ?? app(AtlasControlPlaneSnapshotService::class);
    }
}
