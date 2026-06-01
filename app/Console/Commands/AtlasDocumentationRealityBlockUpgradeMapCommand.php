<?php

namespace App\Console\Commands;

use App\Services\Ai\Aaeos\Generated\AtlasDocumentationRealityBlockUpgradeMapService;
use Illuminate\Console\Command;
use Throwable;

/**
 * Read-only runtime surface for the Documentation Reality Block Upgrade Map.
 *
 * With safe defaults (a bare, named-only block) it runs the readiness-ladder
 * classifier and the Block Readiness Gate, demonstrating that a block with no
 * declared contract sits at L0_named and is refused runtime entry below the
 * L2_testable floor. Proves the doc's contract is live: classify and gate, never
 * authorize a mutation.
 *
 * @see docs/engineering-knowledge-base/atlas-documentation-reality-block-upgrade-map.md
 */
class AtlasDocumentationRealityBlockUpgradeMapCommand extends Command
{
    protected $signature = 'atlas:aaeos:documentation-reality-block-upgrade-map {--json : Print machine-readable JSON}';

    protected $description = 'Classify a documentation-reality block on the L0..L5 readiness ladder and gate its runtime entry (read-only, never mutates).';

    public function handle(AtlasDocumentationRealityBlockUpgradeMapService $service): int
    {
        try {
            // Safe default: a named-only block with no declared contract fields.
            // It must classify as L0_named and be refused runtime entry.
            $contract = ['block' => 'Block Readiness Gate'];
            $classification = $service->classifyBlock($contract);
            $gate = $service->runtimeEntryGate($contract, 'read_only');

            $payload = [
                'ok' => true,
                'schema_version' => AtlasDocumentationRealityBlockUpgradeMapService::SCHEMA_VERSION,
                'classification' => $classification,
                'runtime_entry_gate' => $gate,
            ];
        } catch (Throwable $e) {
            $payload = [
                'ok' => false,
                'error' => $e->getMessage(),
                'schema_version' => AtlasDocumentationRealityBlockUpgradeMapService::SCHEMA_VERSION,
            ];

            if ((bool) $this->option('json')) {
                $this->line(json_encode($payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) ?: '{}');
            } else {
                $this->error($e->getMessage());
            }

            return self::FAILURE;
        }

        if ((bool) $this->option('json')) {
            $this->line(json_encode($payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) ?: '{}');

            return self::SUCCESS;
        }

        $this->components->twoColumnDetail('schema_version', (string) $payload['schema_version']);
        $this->components->twoColumnDetail('block', (string) $classification['block']);
        $this->components->twoColumnDetail('readiness_level', (string) $classification['level_label']);
        $this->components->twoColumnDetail('missing_contract_fields', (string) count($classification['missing_contract_fields']));
        $this->components->twoColumnDetail('runtime_entry_allowed', $gate['allowed'] ? 'true' : 'false');
        $this->components->twoColumnDetail('runtime_entry_decision', (string) $gate['decision']);

        return self::SUCCESS;
    }
}
