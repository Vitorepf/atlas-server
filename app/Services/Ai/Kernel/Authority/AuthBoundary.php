<?php

declare(strict_types=1);

namespace App\Services\Ai\Kernel\Authority;

/**
 * ASDD S-AUTH-BOUNDARY (D45 PATH_CORE): admit + recheck before mutative effect.
 *
 * Live owners remain DecisionReceiptIssuer / RuntimeGuard / AWIS. This façade
 * documents the target double-check contract for all modes.
 */
final class AuthBoundary
{
    public const SCHEMA = 'atlas.auth.boundary.v1';

    /**
     * @return array{schema:string,status:string,stages:list<string>,cutover_config:string}
     */
    public function contract(): array
    {
        return [
            'schema' => self::SCHEMA,
            'status' => 'path_core',
            'stages' => ['admit', 'recheck'],
            'cutover_config' => 'atlas.ai.decision_receipt_v3_cutover_enabled',
        ];
    }
}
