<?php

declare(strict_types=1);

namespace App\Services\Ai\SoftwareCompanyStewardship\AreaFocusLoop\ForgeAuthority;

/**
 * AP-789 port for the REAL Atlas Decide live decision receipt
 * ({@see \App\Services\Ai\AtlasDecideService}).
 *
 * Runtime authority MUST come from the real service. A live decision receipt is
 * never simulated; Fakes/TestDoubles implementing this port are confined to
 * tests and never cross into runtime, canonical docs or claim_policy.
 */
interface ForgeLiveDecideReceiptPort
{
    /**
     * @param  array<string,mixed>  $options
     * @return array<string,mixed>
     */
    public function receiptForTrace(array $options, string $selectedProvider, ?string $model = null): array;
}
