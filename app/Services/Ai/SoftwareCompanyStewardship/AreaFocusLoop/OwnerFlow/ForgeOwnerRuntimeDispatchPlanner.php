<?php

declare(strict_types=1);

namespace App\Services\Ai\SoftwareCompanyStewardship\AreaFocusLoop\OwnerFlow;

/**
 * AP-787 seam. Plans a REAL, governed Atlas Forge owner-runtime dispatch command
 * for the AP-786 owner flow so `owner=forge` runs through the canonical Forge/Obra
 * path (an allowlisted AP-759 command inside the AP-756 worktree) instead of
 * blocking blindly or falling back to a direct provider driver.
 *
 * It NEVER fabricates an Obra UUID and NEVER claims execution: a `runtime-dispatch`
 * command only prepares a governed plan (`plan_only=true`), so the caller must
 * report it as planned — never completed — unless a real owner runtime result with
 * allowed changed files comes back.
 */
interface ForgeOwnerRuntimeDispatchPlanner
{
    /**
     * @param  array<string,mixed>  $input
     * @return array<string,mixed>  ok|blocker + command/receipt_extra/plan_only/dispatch_kind
     */
    public function plan(array $input): array;
}
