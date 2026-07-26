<?php

declare(strict_types=1);

namespace App\Services\Ai\SelfConstruction\LandLearn;

use App\Services\Ai\SelfConstruction\AtlasTaskServingService;

/**
 * ASDD S-LAND-LEARN: land entry seam — report path is the shipped TaskServing owner.
 */
final class LandLearnSpine
{
    public const SCHEMA = 'atlas.land_learn.spine.v1';

    public function __construct(
        private readonly ?AtlasTaskServingService $serving = null,
    ) {}

    /**
     * @return array{schema:string,status:string,stages:list<string>,land_owner:string}
     */
    public function contract(): array
    {
        return [
            'schema' => self::SCHEMA,
            'status' => 'wired_report',
            'stages' => ['report', 'record', 'propose'],
            'land_owner' => AtlasTaskServingService::class,
        ];
    }

    /**
     * Invoke the live land report path (intake validation is part of the shipped contract).
     *
     * @param  array<string,mixed>  $payload
     * @return array<string,mixed>
     */
    public function report(string $clientId, string $taskPacketId, string $leaseId, array $payload = []): array
    {
        return $this->serving()->report($clientId, $taskPacketId, $leaseId, $payload);
    }

    private function serving(): AtlasTaskServingService
    {
        return $this->serving ?? app(AtlasTaskServingService::class);
    }
}
