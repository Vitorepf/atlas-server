<?php

namespace App\Console\Commands;

use App\Http\Resources\VaultHealthSnapshotResource;
use App\Services\Semantic\VaultGovernanceService;
use Illuminate\Console\Command;

class SemanticGovernCommand extends Command
{
    protected $signature = 'atlas:semantic:govern';

    protected $description = 'Compute the semantic memory vault health snapshot.';

    public function handle(VaultGovernanceService $governance): int
    {
        $snapshot = $governance->snapshot();

        $this->line(json_encode((new VaultHealthSnapshotResource($snapshot))->resolve(), JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));

        return self::SUCCESS;
    }
}
