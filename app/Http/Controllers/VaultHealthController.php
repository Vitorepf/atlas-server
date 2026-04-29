<?php

namespace App\Http\Controllers;

use App\Http\Resources\VaultHealthSnapshotResource;
use App\Services\Semantic\VaultGovernanceService;

class VaultHealthController extends Controller
{
    public function show(VaultGovernanceService $governance): VaultHealthSnapshotResource
    {
        return new VaultHealthSnapshotResource($governance->latest() ?? $governance->snapshot());
    }

    public function recompute(VaultGovernanceService $governance): VaultHealthSnapshotResource
    {
        return new VaultHealthSnapshotResource($governance->snapshot());
    }
}
