<?php

declare(strict_types=1);

namespace App\Services\Ai\SoftwareCompanyStewardship\AreaFocusLoop\OwnerFlow;

/**
 * Seam for the AP-786 full owner-runtime flow.
 *
 * The default (non-legacy) AP-786 execute path must run through the real Atlas
 * owner-flow chain — AP-747 release, AP-748 outcome, AP-749 consumption gate,
 * AP-758 execution adapter, AP-759 sandbox runtime runner and AP-750 result
 * bridge — instead of invoking a provider driver directly. This interface lets
 * {@see \App\Services\Ai\SoftwareCompanyStewardship\AreaFocusLoop\AutonomousEvolutionSessionService}
 * compose that chain and lets tests inject a recording double.
 */
interface Ap786OwnerFlowRunner
{
    /**
     * @param  array<string,mixed>  $input
     * @return array<string,mixed>
     */
    public function execute(array $input): array;
}
