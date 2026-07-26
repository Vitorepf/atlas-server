<?php

declare(strict_types=1);

namespace App\Services\Ai\OpenBrain;

use App\Services\Ai\AtlasOpenBrainContextPackService;

/**
 * ASDD S-CONTEXT: brain membrane entry seam — delegates pack assembly to the
 * shipped Open Brain context pack owner (no parallel pack implementation).
 */
final class BrainMembrane
{
    public const SCHEMA = 'atlas.brain.membrane.v1';

    public function __construct(
        private readonly ?AtlasOpenBrainContextPackService $packs = null,
    ) {}

    /**
     * @return array{schema:string,status:string,sources:list<string>,delegate:string}
     */
    public function contract(): array
    {
        return [
            'schema' => self::SCHEMA,
            'status' => 'wired_pack_for',
            'sources' => ['code_graph', 'reality', 'memory', 'workspace', 'operator', 'aobg'],
            'delegate' => AtlasOpenBrainContextPackService::class,
        ];
    }

    /**
     * Assemble a provider-safe context pack via the live shipped service.
     *
     * @param  array<string,mixed>  $opts
     * @return array<string,mixed>
     */
    public function assemble(string $task, array $opts = []): array
    {
        return $this->packs()->packFor($task, $opts);
    }

    private function packs(): AtlasOpenBrainContextPackService
    {
        return $this->packs ?? app(AtlasOpenBrainContextPackService::class);
    }
}
