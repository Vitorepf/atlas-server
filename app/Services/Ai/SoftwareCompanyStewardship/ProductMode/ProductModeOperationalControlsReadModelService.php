<?php

declare(strict_types=1);

namespace App\Services\Ai\SoftwareCompanyStewardship\ProductMode;

use App\Services\Ai\SoftwareCompanyStewardship\ProductMode\Support\ProductModeOperationalControlsProjectionSupport;
use DateTimeImmutable;
use DateTimeInterface;
use DateTimeZone;

/**
 * Product Mode operational controls read model (AP-754).
 *
 * This is the control-plane projection for the Product Mode cockpit. It
 * exposes repo onboarding, autonomy tier, budget, risk, branch-review,
 * evidence-inspector and kill-switch state without mutating any repo or
 * granting execution authority.
 *
 * Pure section builders / claim policy / hash identity live in
 * ProductModeOperationalControlsProjectionSupport. This class only stamps
 * generated_at (time side effect).
 */
final class ProductModeOperationalControlsReadModelService
{
    public const SCHEMA = ProductModeOperationalControlsProjectionSupport::SCHEMA;

    public const STATUS_READY = ProductModeOperationalControlsProjectionSupport::STATUS_READY;

    public const STATUS_BLOCKED = ProductModeOperationalControlsProjectionSupport::STATUS_BLOCKED;

    public const STATUS_REVIEW = ProductModeOperationalControlsProjectionSupport::STATUS_REVIEW;

    /**
     * @param  array<string,mixed>  $input
     * @return array<string,mixed>
     */
    public function project(string $areaId = 'agentic_engineering_os', string $portfolioId = 'atlas_software_company', array $input = []): array
    {
        $payload = ProductModeOperationalControlsProjectionSupport::project($areaId, $portfolioId, $input);
        $payload['generated_at'] = (new DateTimeImmutable('now', new DateTimeZone('UTC')))
            ->format(DateTimeInterface::ATOM);

        return $payload;
    }
}
