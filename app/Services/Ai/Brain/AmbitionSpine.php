<?php

declare(strict_types=1);

namespace App\Services\Ai\Brain;

/**
 * ASDD S-AMBITION (D47 PATH_CORE): originate → enqueue (brain:seed only).
 * ACDE/loop is not an origin path.
 */
final class AmbitionSpine
{
    public const SCHEMA = 'atlas.ambition.spine.v1';

    /**
     * @return array{schema:string,status:string,stages:list<string>,operate_path:string,acde_allowed:bool}
     */
    public function contract(): array
    {
        return [
            'schema' => self::SCHEMA,
            'status' => 'path_core',
            'stages' => ['originate', 'enqueue'],
            'operate_path' => 'atlas:brain:* → atlas:task:*',
            'acde_allowed' => false,
        ];
    }
}
