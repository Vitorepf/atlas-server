<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Services\Ai\Governance\GovernanceFloorRegistry;
use Illuminate\Console\Command;

final class AtlasGovernanceAmendmentsCommand extends Command
{
    /** @var string */
    protected $signature = 'atlas:governance:amendments
        {--json : Emit machine-readable JSON}';

    /** @var string */
    protected $description = 'List governance floor amendment history and effective registry floors.';

    public function handle(GovernanceFloorRegistry $registry): int
    {
        $payload = [
            'status' => 'ok',
            'schema_version' => GovernanceFloorRegistry::SCHEMA_VERSION,
            'ledger_schema_version' => $registry->ledger()::SCHEMA_VERSION,
            'ledger_path' => $registry->ledger()->path(),
            'floors' => $registry->floors(),
            'amendments' => $registry->ledger()->history(),
        ];

        $this->line((string) json_encode(
            $payload,
            JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR,
        ));

        return self::SUCCESS;
    }
}
