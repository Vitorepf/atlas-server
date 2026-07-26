<?php

declare(strict_types=1);

namespace App\Services\Ai\Kernel\Evidence;

/**
 * ASDD S-EVIDENCE (D46 PATH_CORE): append / replay / certify façade.
 * Does not create a second ledger — delegates conceptually to AtlasEvidenceLedger.
 */
final class EvidenceSpine
{
    public const SCHEMA = 'atlas.evidence.spine.v1';

    /**
     * @return array{schema:string,status:string,stages:list<string>,second_ledger:bool}
     */
    public function contract(): array
    {
        return [
            'schema' => self::SCHEMA,
            'status' => 'path_core',
            'stages' => ['append', 'replay', 'certify'],
            'second_ledger' => false,
        ];
    }
}
