<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Services\Ai\SoftwareCompanyStewardship\AreaFocusLoop\Rsi\ComponentValueLedgerService;
use Illuminate\Console\Command;

/**
 * Governed RSI · Part B · value-per-token measure command.
 *
 * The REAL, reproducible measure command a SELF gap's outcome_contract points
 * at. It replays the append-only ComponentValueLedger for an area/focus and
 * prints the named component's current value-per-token as
 * {"metric": <value_per_token>} (the dot-path the measured-or-reverted keystone
 * reads). It is READ-ONLY: it never proposes, merges, reverts, calls a provider
 * or writes state — it only folds the existing ledger and emits one number, so
 * the keystone can prove (or revert) a self-improvement that claims to have
 * raised a component's value-per-token.
 *
 * A component with no finite value-per-token (no proven, token-spending cycles)
 * emits metric=0 — honest: there is nothing to have improved yet.
 */
final class AtlasRsiComponentValuePerTokenCommand extends Command
{
    protected $signature = 'atlas:rsi:component-value-per-token
        {component : the catalogued loop component id}
        {--area=agentic_engineering_os : ledger area_id scope}
        {--focus=dev_forge : ledger focus scope}';

    protected $description = 'Replay the ComponentValueLedger and print a component value-per-token as {"metric": n} (RSI measure command).';

    public function handle(ComponentValueLedgerService $ledger): int
    {
        $component = (string) $this->argument('component');
        $area = (string) $this->option('area');
        $focus = (string) $this->option('focus');

        $stats = $ledger->valuePerTokenByComponent($area, $focus);
        $valuePerToken = $stats[$component]['value_per_token'] ?? null;

        $this->line(json_encode([
            'metric' => is_numeric($valuePerToken) ? (float) $valuePerToken : 0.0,
            'component_id' => $component,
            'area_id' => $area,
            'focus' => $focus,
            'proven_cycles' => (int) ($stats[$component]['proven_cycles'] ?? 0),
        ], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));

        return self::SUCCESS;
    }
}
