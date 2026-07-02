<?php

declare(strict_types=1);

namespace App\Services\Ai\AutonomousEvolution\Permissions;

use App\Services\Ai\AutonomousEvolution\AtlasLoopWorkspaceMaterializerSupport2;
use App\Services\Ai\EngineeringKernel\Adapters\JsonlReceiptStore;
use RuntimeException;

/**
 * Append-only, byte-deterministic ledger for every Enforcer (P2) decision.
 *
 * Storage: a single JSON-Lines snapshot file (default
 * storage_path('atlas/loop/permission-receipts.jsonl')). One line per record() call; ksort'd keys
 * + JSON_UNESCAPED_SLASHES|UNICODE so two PHP processes serialize identically. NEVER writes
 * inside app_path() (sandbox floor); master switch OFF makes record() a byte-identical no-op.
 */
final class AtlasLoopPermissionLevelReceiptLedger
{
    /** @var callable():bool */
    private $masterEnabledReader;

    public function __construct(
        private readonly ?string $ledgerPath = null,
        ?callable $masterEnabledReader = null,
    ) {
        $this->masterEnabledReader = $masterEnabledReader ?? static function (): bool {
            if (! function_exists('config')) {
                return false;
            }

            return (bool) config('atlas.loop.master_enabled', false);
        };
    }

    public function record(AtlasLoopPermissionReceipt $receipt): void
    {
        if (! ($this->masterEnabledReader)()) {
            return; // byte-identical OFF
        }

        $path = $this->resolvePath();
        $this->assertSandboxFloor($path);

        $row = json_decode($receipt->toJsonLine(), true);
        if (! is_array($row)) {
            throw new RuntimeException('atlas_loop_permission_ledger_write_failed');
        }
        (new JsonlReceiptStore($path))->append($row);
    }

    public function path(): string
    {
        return $this->resolvePath();
    }

    private function resolvePath(): string
    {
        if ($this->ledgerPath !== null && $this->ledgerPath !== '') {
            return $this->ledgerPath;
        }
        if (function_exists('storage_path')) {
            return storage_path('atlas/loop/permission-receipts.jsonl');
        }

        return sys_get_temp_dir().'/atlas-permission-receipts.jsonl';
    }

    private function assertSandboxFloor(string $path): void
    {
        // Re-uses the pétreo sandbox floor: refuses any write under app_path().
        AtlasLoopWorkspaceMaterializerSupport2::assertOutsideLiveSource($path);
    }
}
